<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use App\Services\Super\Money\TillLedgerAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * payment.till_ledger.deficit — does Safaricom's own arithmetic agree with ours?
 *
 * Every C2B confirmation carries the till's balance immediately after it, so
 * consecutive confirmations must differ by exactly the amount between them.
 * Where they do not, money entered the till that never reached us. See
 * TillLedgerAudit for the method and its limits.
 *
 * WHY THIS EXISTS ALONGSIDE payments:reconcile-legacy. That command compares us
 * against the Mumbai system, which is the right check while Mumbai is running
 * and useless the moment it is switched off — this week. This one compares us
 * against SAFARICOM, needs no second system, and survives the cutover.
 *
 * It also sees more. On 2026-08-30, a closed day, the legacy comparison put the
 * shortfall at KES 8,745 and this ledger put it at KES 20,890: the difference is
 * money neither system recorded, which by construction no us-versus-legacy check
 * can ever find.
 *
 * READ-ONLY. It writes an audit row when it finds something, and nothing else.
 *
 * The clock is EAT, matching TransTime as Safaricom sends it and as both systems
 * store it — the same reasoning ReconcileLegacyPayments documents at length.
 */
class AuditTillLedger extends Command
{
    /** Safaricom's clock, and the clock TransTime is stored in. */
    public const PAYMENT_CLOCK = 'Africa/Nairobi';

    protected $signature = 'payments:audit-till-ledger
        {--date= : Audit this Y-m-d (EAT). Default: the day that just closed}
        {--hours= : Instead of a day, the trailing N hours}
        {--lag=15 : Trailing minutes to exclude so in-flight payments are not called missing}
        {--no-alert : Report only; do not raise a platform notification}
        {--json : Emit the machine-readable result}';

    protected $description = "Check our payment records against Safaricom's own running till balance";

    public function handle(PlatformNotifier $notifier, TillLedgerAudit $audit): int
    {
        [$from, $to] = $this->window();

        $result = $audit->audit($from, $to);

        if ($this->option('json')) {
            $this->line((string) json_encode($result + [
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
            ], JSON_PRETTY_PRINT));
        } else {
            $this->render($result, $from, $to);
        }

        if ($result['lost_fares'] <= 0.0) {
            $this->info('Clean: every balance movement is accounted for.');

            return self::SUCCESS;
        }

        // Recorded only on a finding. A green run every few hours would bury the
        // audit log in "nothing happened", and the scheduler evidences the run.
        $auditRow = AuditLogger::record(
            action: 'payments.till_ledger_deficit',
            data: [
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
                'lost_fares' => $result['lost_fares'],
                'large_credits' => $result['large_credits'],
                'tills_affected' => $result['tills_affected'],
                'tills_checked' => $result['tills_checked'],
                'confirmations' => $result['confirmations'],
            ],
            actor: ['type' => 'system', 'id' => null, 'label' => 'payments:audit-till-ledger'],
            subject: ['type' => 'platform', 'id' => 'till-ledger'],
        );

        if (! $this->option('no-alert')) {
            $this->raise($notifier, $result, $from, $to, $auditRow->id);
        }

        // Non-zero so a human run, or a cutover checklist step, can treat a
        // deficit as a failure without parsing output — same convention as
        // payments:reconcile-legacy and tills:check-idle.
        return self::FAILURE;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(): array
    {
        $now = CarbonImmutable::now(self::PAYMENT_CLOCK);

        if (($hours = $this->option('hours')) !== null) {
            // The trailing exclusion: a payment Safaricom has settled but whose
            // confirmation is still in flight is not missing, and counting it as
            // missing would alarm every single run.
            $to = $now->subMinutes(max(0, (int) $this->option('lag')));

            return [$to->subHours(max(1, (int) $hours)), $to];
        }

        $date = $this->option('date');
        $day = $date !== null
            ? CarbonImmutable::parse($date, self::PAYMENT_CLOCK)->startOfDay()
            : $now->subDay()->startOfDay();

        return [$day, $day->addDay()];
    }

    /** @param array<string,mixed> $r */
    private function render(array $r, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $this->info(sprintf('Till ledger audit  %s .. %s EAT', $from->toDateTimeString(), $to->toDateTimeString()));
        $this->newLine();

        $this->table(['', ''], [
            ['Confirmations checked', number_format($r['confirmations'])],
            ['Tills checked', number_format($r['tills_checked'])],
            ['MONEY UNACCOUNTED FOR', 'KES '.number_format($r['lost_fares'], 2)],
            ['  across tills', number_format($r['tills_affected'])],
            ['Large credits (not assumed fares)', 'KES '.number_format($r['large_credits'], 2).' in '.$r['large_credit_count'].' jump(s)'],
            ['Rows with no stored balance', number_format($r['unchecked_rows'])],
        ]);

        $worst = array_slice($r['per_till'], 0, 10, true);
        $rows = [];
        foreach ($worst as $till => $t) {
            if ($t['lost'] <= 0.0) {
                continue;
            }
            $rows[] = [$till, number_format($t['confirmations']), 'KES '.number_format($t['lost'], 2)];
        }

        if ($rows !== []) {
            $this->newLine();
            $this->line('Worst tills:');
            $this->table(['Till', 'Confirmations', 'Unaccounted'], $rows);
        }

        if ($r['unchecked_rows'] > 0) {
            $this->warn(sprintf(
                '%s payment(s) had no stored balance and were skipped — run payments:backfill-balances.',
                number_format($r['unchecked_rows'])
            ));
        }
    }

    /** @param array<string,mixed> $r */
    private function raise(PlatformNotifier $notifier, array $r, CarbonImmutable $from, CarbonImmutable $to, int $auditId): void
    {
        $threshold = (array) Thresholds::get(null, 'till_ledger_deficit');
        $minValue = (float) ($threshold['min_value'] ?? 500);
        $criticalValue = (float) ($threshold['critical_value'] ?? 20000);

        // Small residues are charges and rounding, not a payment outage.
        if ($r['lost_fares'] < $minValue) {
            $this->info(sprintf(
                'KES %s is below the alert threshold (%s) — reported, not alerted.',
                number_format($r['lost_fares'], 2),
                number_format($minValue, 2)
            ));

            return;
        }

        $notifier->dispatch(new PlatformEvent(
            event: 'payment.till_ledger.deficit',
            severity: $r['lost_fares'] >= $criticalValue ? 'critical' : 'high',
            class: 'alert',
            title: "Money missing against Safaricom's own balance",
            summary: mb_substr(sprintf(
                'KES %s entered %d till(s) between %s and %s EAT without reaching us, across %s confirmations.',
                number_format($r['lost_fares'], 0),
                $r['tills_affected'],
                $from->format('d M H:i'),
                $to->format('d M H:i'),
                number_format($r['confirmations'])
            ), 0, 140),
            brand: null,
            actor: ['type' => 'system', 'id' => null, 'label' => 'payments:audit-till-ledger'],
            subject: ['type' => 'platform', 'id' => 'till-ledger'],
            // No till numbers or names: these are emailed and cached.
            data: [
                'lost_fares' => $r['lost_fares'],
                'tills_affected' => $r['tills_affected'],
                'tills_checked' => $r['tills_checked'],
                'confirmations' => $r['confirmations'],
                'large_credits' => $r['large_credits'],
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
            ],
            dedupeKey: 'till-ledger:'.$from->toDateString(),
            auditId: $auditId,
        ));
    }
}
