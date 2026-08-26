<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use App\Services\Super\Money\CrossSystemReconciler;
use App\Services\Super\Money\LegacyPaymentSource;
use App\Services\Super\Money\MysqlLegacyPaymentSource;
use App\Services\Super\Money\ReconciliationReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * payment.cross_system.deficit — is this system missing any payment that legacy has?
 *
 * The one measurement that makes the migration falsifiable. Every component on
 * both sides reports success whether or not the money arrived, so the only way to
 * see a loss is to compare the two systems directly; see CrossSystemReconciler
 * for how, and for the timezone facts the comparison depends on.
 *
 * READ-ONLY on both databases. It never writes to legacy at all, and writes here
 * only the audit row for a run that found something. Safe to run repeatedly and
 * on overlapping windows — two runs over the same minutes reach the same answer
 * and the notifier collapses the repeats onto one open row.
 *
 * INERT UNTIL CONFIGURED. There is currently no route from this host to legacy
 * MySQL, so the connection has no credentials and this command fails closed
 * rather than guessing (see config/database.php, `legacy_mysql`). While that is
 * the case it emits a once-a-day review notification, because the failure mode of
 * a monitoring tool nobody wired up is that everyone assumes it is watching.
 */
class ReconcileLegacyPayments extends Command
{
    protected $signature = 'payments:reconcile-legacy
        {--minutes=60 : Width of the window to compare, in minutes}
        {--lag=10 : Trailing minutes to exclude so in-flight payments are not called missing}
        {--ending= : Window end as EAT "Y-m-d H:i" — for calibrating against a known window}
        {--drill-limit=20000 : Hard cap on payments pulled per side when drilling into ids}
        {--no-alert : Report only; do not emit a platform notification}
        {--json : Emit the machine-readable report instead of tables}';

    protected $description = 'Compare legacy payments against this system and report anything missing here';

    public function handle(PlatformNotifier $notifier, LegacyPaymentSource $legacy): int
    {
        if (! $legacy->isAvailable()) {
            return $this->reportUnconfigured($notifier);
        }

        $window = $this->window();
        if ($window === null) {
            $this->error('--ending must look like "2026-08-26 09:00" (EAT).');

            return self::INVALID;
        }
        [$from, $to] = $window;

        $reconciler = new CrossSystemReconciler($legacy);

        try {
            $report = $reconciler->reconcile($from, $to, (int) $this->option('drill-limit'));
        } catch (Throwable $e) {
            return $this->reportUnreachable($notifier, $e);
        }

        $this->option('json') ? $this->renderJson($report) : $this->render($report);

        if ($report->isClean()) {
            $this->info(sprintf(
                'Reconciled clean: %d payments / KES %s on both sides.',
                $report->legacyCount,
                number_format($report->legacyValue, 2),
            ));

            return self::SUCCESS;
        }

        // Audited only when there is a finding. A green run every few minutes
        // would bury the audit log in "nothing happened", and the scheduler's own
        // output already evidences that the check ran.
        $audit = AuditLogger::record(
            action: 'payments.cross_system_deficit',
            data: $report->toArray(),
            actor: ['type' => 'system', 'id' => null, 'label' => 'payments:reconcile-legacy'],
            subject: ['type' => 'platform', 'id' => 'legacy-reconciliation'],
        );

        if (! $this->option('no-alert')) {
            $this->alert_($notifier, $report, $audit->id);
        }

        // Non-zero so a human run, or a CI step during the cutover, can treat a
        // deficit as a failure without parsing the output. Same convention as
        // tills:check-idle.
        return self::FAILURE;
    }

    /**
     * The window, in EAT — the clock `mpesas.TransTime` is kept in on both
     * systems. Building it in UTC would slide it three hours into a period both
     * systems settled long ago and report green forever; CrossSystemReconciler's
     * docblock has the measurement.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null null when --ending is unparseable
     */
    private function window(): ?array
    {
        $minutes = max(1, (int) $this->option('minutes'));

        if (($ending = (string) $this->option('ending')) !== '') {
            try {
                // Carbon signals a bad format by throwing on some inputs and
                // returning null on others, so both are treated as "unparseable".
                $to = CarbonImmutable::createFromFormat('Y-m-d H:i', $ending, CrossSystemReconciler::PAYMENT_CLOCK);
            } catch (Throwable) {
                return null;
            }
            if ($to === null) {
                return null;
            }
            $to = $to->startOfMinute();
        } else {
            // The trailing exclusion. A payment recorded here a moment after
            // legacy saw it is in flight, not lost, and counting it as missing
            // would make the check cry wolf on every single run.
            //
            // Measured on the live database 2026-08-26 over ~9,000 payments:
            // recording lands a median of 1s and a maximum of 22s after
            // TransTime, with p99.9 at 14s and NOT ONE row later than 60s. Ten
            // minutes is therefore ~27x the worst observed lag — deliberately far
            // more headroom than the data needs, because the cost of too little
            // is a permanently noisy check and the cost of too much is ten
            // minutes of detection delay.
            $to = CarbonImmutable::now(CrossSystemReconciler::PAYMENT_CLOCK)
                ->subMinutes(max(0, (int) $this->option('lag')))
                ->startOfMinute();
        }

        return [$to->subMinutes($minutes), $to];
    }

    private function render(ReconciliationReport $report): void
    {
        $this->line('Cross-system payment reconciliation');
        $this->line("  window   {$report->fromEat} .. {$report->toEat} EAT");
        $this->line('  legacy   '.$report->legacyDescription);
        $this->newLine();

        $this->table(['System', 'Payments', 'Value (KES)'], [
            ['legacy', number_format($report->legacyCount), number_format($report->legacyValue, 2)],
            ['this system', number_format($report->localCount), number_format($report->localValue, 2)],
            ['DEFICIT', number_format($report->missingCount()), number_format($report->missingValue(), 2)],
        ]);

        // Before the clean-window return, because a window can be perfectly clean
        // in the direction that matters and still have moved tills worth seeing.
        // During the cutover this line is the progress report.
        if ($report->localOnlyCount > 0) {
            // Not a fault: a till re-registered to this host stops reaching
            // legacy, so its payments legitimately exist only here.
            $this->line(sprintf(
                'Note: %d payment(s) / KES %s exist here but not in legacy — expected for tills already re-registered to this host.',
                $report->localOnlyCount,
                number_format($report->localOnlyValue, 2),
            ));
        }

        // Also before the clean-window return: a body that arrived and could not
        // be parsed is worth a human's attention whether or not this window shows
        // a deficit, and it is the one thing that can make NEVER ARRIVED overstate
        // the transport problem.
        if ($report->unattributableLogs > 0) {
            $this->warn(sprintf(
                '%d delivery log(s) in this window carry no TransID — they arrived but were unreadable. '
                .'Any that failed to record are counted as NEVER ARRIVED; check their raw bodies.',
                $report->unattributableLogs,
            ));
        }

        if ($report->isClean()) {
            return;
        }

        $never = $report->neverArrived();
        $lost = $report->arrivedNotRecorded();

        $this->newLine();
        $this->line(sprintf(
            'Missing %d payments (%.2f%% of legacy) across %d minute(s):',
            $report->missingCount(),
            $report->missingRatio() * 100,
            $report->deficitMinutes,
        ));

        // The split that decides who owns the problem.
        $this->table(['Class', 'Payments', 'Value (KES)', 'Means'], [
            [
                'NEVER ARRIVED', count($never),
                number_format(array_sum(array_column($never, 'amount')), 2),
                'no mpesa_logs row — the confirmation never reached this host (transport)',
            ],
            [
                'ARRIVED, NOT RECORDED', count($lost),
                number_format(array_sum(array_column($lost, 'amount')), 2),
                'raw body IS in mpesa_logs but no mpesas row — we dropped it (recording)',
            ],
        ]);

        $rows = [];
        foreach ($report->byShortcode() as $code => $totals) {
            $rows[] = [$code, $totals['count'], number_format($totals['value'], 2)];
        }
        $this->line('By shortcode (worst first):');
        $this->table(['Shortcode', 'Missing', 'Value (KES)'], array_slice($rows, 0, 25));

        $this->line('Sample TransIDs: '.implode(', ', array_slice(array_column($report->missing, 'transId'), 0, 15)));

        if ($report->truncated) {
            $this->warn('Drill limit reached — the id-level diff is incomplete. Re-run with a shorter --minutes or a larger --drill-limit.');
        }
    }

    private function renderJson(ReconciliationReport $report): void
    {
        $this->line((string) json_encode($report->toArray(100), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Underscored because Command::alert() already exists and is a console
     * formatter — overriding it would quietly change output everywhere.
     */
    private function alert_(PlatformNotifier $notifier, ReconciliationReport $report, int $auditId): void
    {
        $threshold = (array) Thresholds::get(null, 'legacy_payment_deficit');
        $minCount = (int) ($threshold['min_count'] ?? 5);
        $criticalRatio = (float) ($threshold['critical_ratio'] ?? 0.01);

        // A stray one or two inside the window is in-flight noise, not a loss.
        if ($report->missingCount() < $minCount) {
            $this->info("Deficit below the alert threshold ({$minCount}) — reported, not alerted.");

            return;
        }

        $never = count($report->neverArrived());
        $lost = count($report->arrivedNotRecorded());

        $notifier->dispatch(new PlatformEvent(
            event: 'payment.cross_system.deficit',
            severity: $report->missingRatio() >= $criticalRatio ? 'critical' : 'high',
            class: 'alert',
            title: 'Payments missing against legacy',
            summary: mb_substr(sprintf(
                '%d payments (KES %s) in legacy are absent here for %s..%s EAT — %d never arrived, %d arrived but were not recorded.',
                $report->missingCount(),
                number_format($report->missingValue(), 0),
                substr($report->fromEat, 11, 5),
                substr($report->toEat, 11, 5),
                $never,
                $lost,
            ), 0, 140),
            brand: null,
            subject: ['type' => 'platform', 'id' => 'legacy-reconciliation'],
            data: $report->toArray(),
            dedupeKey: 'payment.cross_system.deficit',
            // Overlapping windows mean consecutive runs re-report the same
            // payments. The notifier folds those onto one open row and bumps its
            // count, so on-call sees a persisting problem rather than a queue of
            // identical alerts.
            windowMinutes: 60,
            auditId: $auditId,
        ));

        $this->warn('Platform alert emitted: payment.cross_system.deficit');
    }

    private function reportUnconfigured(PlatformNotifier $notifier): int
    {
        $this->error('Legacy reconciliation is not configured — refusing to guess.');
        $this->line('Set these for the app, then re-run:');
        $this->line('  LEGACY_DB_HOST      host of the legacy MySQL (komiut_latest_app)');
        $this->line('  LEGACY_DB_USERNAME  a user granted SELECT and nothing else');
        $this->line('  LEGACY_DB_PASSWORD');
        $this->line('  LEGACY_DB_DATABASE  optional, defaults to komiut_latest_app');
        $this->newLine();
        $this->line('It must be komiut_latest_app, NOT komiut_payments — see config/database.php.');

        if (! $this->option('no-alert')) {
            // Review, not alert, and once a day: this is a deploy state a human
            // has to resolve, not an incident. It exists at all because a check
            // that was never wired up is indistinguishable from a check that
            // keeps passing.
            $notifier->dispatch(new PlatformEvent(
                event: 'payment.cross_system.unconfigured',
                severity: 'normal',
                class: 'review',
                title: 'Legacy reconciliation not configured',
                summary: 'payments:reconcile-legacy cannot run: LEGACY_DB_* is unset, so nothing is comparing this system against legacy.',
                brand: null,
                subject: ['type' => 'platform', 'id' => 'legacy-reconciliation'],
                data: ['connection' => MysqlLegacyPaymentSource::CONNECTION],
                dedupeKey: 'payment.cross_system.unconfigured',
                windowMinutes: 1440,
            ));
        }

        return self::FAILURE;
    }

    private function reportUnreachable(PlatformNotifier $notifier, Throwable $e): int
    {
        $this->error('Could not read legacy: '.$e->getMessage());

        if (! $this->option('no-alert')) {
            $notifier->dispatch(new PlatformEvent(
                event: 'payment.cross_system.unreachable',
                severity: 'high',
                class: 'alert',
                title: 'Legacy reconciliation could not run',
                summary: mb_substr('The legacy database could not be read, so nothing is currently verifying that payments reach this system.', 0, 140),
                brand: null,
                subject: ['type' => 'platform', 'id' => 'legacy-reconciliation'],
                // The message can name a host and a database. No credentials:
                // PDO does not put them in the message, and this payload is
                // emailed and cached.
                data: ['error' => mb_substr($e->getMessage(), 0, 300)],
                dedupeKey: 'payment.cross_system.unreachable',
                windowMinutes: 60,
            ));
        }

        return self::FAILURE;
    }
}
