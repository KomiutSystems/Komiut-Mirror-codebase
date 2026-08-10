<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\BankCollectionsStatement;
use App\Models\AuditLog;
use App\Services\Platform\AuditLogger;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Emails each financier bank what its vehicles collected over a period.
 *
 * The bank finances these matatus and reconciles repayments against the
 * takings, so this is the basis of a money decision on their side. Three things
 * follow from that:
 *
 *  - It FAILS CLOSED. No configured address means no send, never a fallback to
 *    some other inbox: a statement lists every financed vehicle's daily takings.
 *  - It is IDEMPOTENT per (bank, period). A scheduler retry, a second instance,
 *    or someone re-running it by hand must not put two different-looking
 *    statements for the same month in a bank's inbox.
 *  - Every send is audited BEFORE it leaves, so there is a record even if the
 *    mail transport then fails.
 *
 * Vehicles are selected by `financier`, not by brand. Brand says which portal
 * shows a vehicle; financier says who banks it, and a SACCO can span both.
 */
class SendBankCollectionsStatement extends Command
{
    protected $signature = 'bank:send-statement
        {--partner= : ncba or coop; omit for every configured partner}
        {--period= : YYYY-MM, defaults to LAST month}
        {--dry-run : Build and report the statement, send nothing}
        {--force : Send again even if this period was already sent}';

    protected $description = 'Email each financier bank its vehicles\' collections for a period';

    /** Which `vehicles.financier` values belong to which configured partner. */
    private const FINANCIER = ['ncba' => 'NCBA', 'coop' => 'coop-bank'];

    public function handle(): int
    {
        // Default to LAST month: run on the 1st, a statement for the month that
        // just closed. Billing periods people act on are always complete ones.
        $period = $this->option('period')
            ? Carbon::createFromFormat('Y-m', (string) $this->option('period'))->startOfMonth()
            : Carbon::now()->subMonthNoOverflow()->startOfMonth();

        $from = $period->copy()->startOfMonth();
        $to = $from->copy()->addMonth();          // half-open
        $label = $from->format('Y-m');

        $only = $this->option('partner');
        $partners = (array) config('bank_portal.partners', []);
        $failures = 0;

        foreach ($partners as $key => $partner) {
            if ($only !== null && $key !== $only) {
                continue;
            }

            $bankLabel = (string) ($partner['label'] ?? $key);
            $email = $partner['email'] ?? null;
            $financier = self::FINANCIER[$key] ?? null;

            if ($financier === null) {
                $this->warn("  {$bankLabel}: no financier mapping — skipped.");

                continue;
            }

            if (blank($email)) {
                // Not an error worth failing the run over — a partner may be
                // configured for the portal but not yet for statements.
                $this->warn("  {$bankLabel}: no email configured (BANK_EMAIL_".strtoupper($key)."), skipped.");

                continue;
            }

            if (! $this->option('force') && $this->alreadySent($key, $label)) {
                $this->line("  {$bankLabel}: {$label} already sent — skipping. Use --force to resend.");

                continue;
            }

            [$rows, $totals] = $this->collections($financier, $from, $to);

            $this->line(sprintf('  %s %s: %d vehicle(s), %s payment(s), KES %s',
                $bankLabel, $label, $totals['vehicles'],
                number_format($totals['payments']), number_format($totals['collected'], 2)));

            if ($this->option('dry-run')) {
                continue;
            }

            // Audit BEFORE sending: if the transport dies we still know a
            // statement for this period was raised, and the idempotency check
            // above reads this same record.
            AuditLogger::record(
                action: 'bank.statement.sent',
                data: [
                    'bank' => $key,
                    'period' => $label,
                    'vehicles' => $totals['vehicles'],
                    'payments' => $totals['payments'],
                    'collected' => $totals['collected'],
                    'to' => $email,
                ],
                actor: ['type' => 'system', 'id' => null, 'label' => 'bank:send-statement'],
            );

            try {
                Mail::to($email)->send(new BankCollectionsStatement(
                    $bankLabel, $label, $rows, $totals, $this->csv($rows, $totals),
                ));
                $this->info("  {$bankLabel}: sent to {$email}");
            } catch (\Throwable $e) {
                // Reported, not thrown: one bank's mail failing must not stop
                // the other bank's statement going out.
                $failures++;
                $this->error("  {$bankLabel}: send FAILED — ".$e->getMessage());
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Has this exact (bank, period) already gone out? */
    private function alreadySent(string $bank, string $period): bool
    {
        return AuditLog::where('action', 'bank.statement.sent')
            ->where('data->bank', $bank)
            ->where('data->period', $period)
            ->exists();
    }

    /**
     * Per-vehicle collections for one financier over a half-open window.
     *
     * Half-open on purpose: transactions.trans_date is a timestamp, so an
     * inclusive between() counts the next period's first instant into both.
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<string,mixed>}
     */
    private function collections(string $financier, Carbon $from, Carbon $to): array
    {
        $tillColumn = $financier === 'NCBA' ? 'vehicles.ncba_till' : 'vehicles.coop_till';

        $rows = DB::table('vehicles')
            ->leftJoin('saccos', 'saccos.id', 'vehicles.sacco_id')
            ->leftJoin('transactions', function ($join) use ($from, $to): void {
                $join->on('transactions.vehicle_id', 'vehicles.id')
                    ->where('transactions.trans_date', '>=', $from)
                    ->where('transactions.trans_date', '<', $to);
            })
            ->where('vehicles.financier', $financier)
            ->groupBy('vehicles.id', 'vehicles.plate', 'saccos.name', $tillColumn, 'vehicles.till_number')
            ->select('vehicles.plate', 'saccos.name as sacco', 'vehicles.till_number as daraja_till')
            ->selectRaw($tillColumn.' as bank_till')
            ->selectRaw('COUNT(transactions.id) as payments')
            ->selectRaw('COALESCE(SUM(transactions.amount), 0) as collected')
            ->orderByRaw('COALESCE(SUM(transactions.amount), 0) DESC')
            ->get()
            ->map(fn ($r) => [
                'plate' => $r->plate,
                'sacco' => $r->sacco,
                'bank_till' => $r->bank_till,
                'daraja_till' => $r->daraja_till,
                'payments' => (int) $r->payments,
                'collected' => (float) $r->collected,
            ])->all();

        return [$rows, [
            'vehicles' => count($rows),
            'payments' => array_sum(array_column($rows, 'payments')),
            'collected' => array_sum(array_column($rows, 'collected')),
        ]];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function csv(array $rows, array $totals): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Plate', 'SACCO', 'Bank till', 'Daraja till', 'Payments', 'Collected (KES)']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['plate'], $r['sacco'], $r['bank_till'], $r['daraja_till'],
                $r['payments'], number_format($r['collected'], 2, '.', ''),
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['TOTAL', $totals['vehicles'].' vehicle(s)', '', '',
            $totals['payments'], number_format($totals['collected'], 2, '.', '')]);
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
