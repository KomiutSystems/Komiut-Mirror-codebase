<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\Platform\AuditLogger;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds tills that have stopped earning, and payments arriving for tills we do
 * not know.
 *
 * Written because KDY 599G ran for a MONTH before anyone noticed. Its till was
 * live at Safaricom and taking money, but the C2B confirmation URL for its
 * shortcode was never registered, so not one payment reached us. Its record
 * looked perfect; only the absence of rows gave it away, and nothing was
 * looking at absences.
 *
 * Two directions, because the failure has two shapes:
 *
 *   IDLE      a vehicle with a till and no payments in N days. Either it is off
 *             the road, or its callback is misregistered and the money is
 *             invisible. Both are worth a human deciding about.
 *   UNMATCHED payments whose BusinessShortCode matches no vehicle. These
 *             already hit reportUnmatchedPayment, which files them somewhere
 *             nobody reads. Counting them here puts them in front of someone.
 *
 * Findings are written to the audit log rather than only printed, so the
 * platform console can surface them and so a run leaves evidence.
 */
class CheckIdleTills extends Command
{
    protected $signature = 'tills:check-idle
        {--days=7 : A till silent this long is reported}
        {--quiet-report : Write the audit row without printing the table}';

    protected $description = 'Report tills that have gone quiet, and payments for unknown tills';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = Carbon::now()->subDays($days);

        $idle = $this->idleTills($since);
        $unmatched = $this->unmatchedPayments($since);

        if (! $this->option('quiet-report')) {
            $this->line("Tills silent since {$since->toDateString()} ({$days} days):");
            if ($idle->isEmpty()) {
                $this->info('  none — every configured till has earned.');
            } else {
                $this->table(
                    ['Plate', 'SACCO', 'Financier', 'Till', 'Shortcode', 'Last payment'],
                    $idle->map(fn ($v) => [
                        $v->plate, $v->sacco_name ?? '—', $v->financier ?? '—',
                        $v->till_number ?? '—', $v->merchant_short_code ?? '—',
                        $v->last_payment ?? 'NEVER',
                    ])->all(),
                );
            }

            $this->newLine();
            $this->line('Payments for shortcodes no vehicle claims:');
            if ($unmatched->isEmpty()) {
                $this->info('  none.');
            } else {
                $this->table(['Shortcode', 'Payments', 'Total', 'Last seen'],
                    $unmatched->map(fn ($r) => [$r->code, $r->payments, number_format((float) $r->total, 2), $r->last_seen])->all());
            }
        }

        // Recorded even when clean: "we checked and found nothing" is itself
        // worth being able to prove later.
        AuditLogger::record(
            action: 'tills.idle_check',
            data: [
                'days' => $days,
                'idle_count' => $idle->count(),
                'never_earned' => $idle->where('last_payment', null)->count(),
                'unmatched_shortcodes' => $unmatched->count(),
                'idle_plates' => $idle->take(50)->pluck('plate')->all(),
            ],
            actor: ['type' => 'system', 'id' => null, 'label' => 'tills:check-idle'],
        );

        // Non-zero so a scheduler or CI step can treat findings as actionable
        // rather than having to parse the output.
        return $idle->isEmpty() && $unmatched->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Vehicles that can take money but have not, recently.
     *
     * A LEFT JOIN with the window in the JOIN condition, not the WHERE: putting
     * it in WHERE would drop the vehicles that have never been paid at all,
     * which are exactly the ones worth finding.
     */
    private function idleTills(Carbon $since)
    {
        return Vehicle::withoutGlobalScopes()
            ->leftJoin('saccos', 'saccos.id', 'vehicles.sacco_id')
            ->leftJoin('transactions', function ($join) use ($since): void {
                $join->on('transactions.vehicle_id', 'vehicles.id')
                    ->where('transactions.trans_date', '>=', $since);
            })
            ->where('vehicles.status', true)
            ->where(function ($q): void {
                $q->whereNotNull('vehicles.till_number')
                    ->orWhereNotNull('vehicles.merchant_short_code');
            })
            ->groupBy('vehicles.id', 'vehicles.plate', 'saccos.name', 'vehicles.financier',
                'vehicles.till_number', 'vehicles.merchant_short_code')
            ->havingRaw('COUNT(transactions.id) = 0')
            ->select('vehicles.plate', 'vehicles.financier', 'vehicles.till_number', 'vehicles.merchant_short_code')
            ->selectRaw('saccos.name as sacco_name')
            ->selectRaw('(SELECT MAX(t2.trans_date) FROM transactions t2 WHERE t2.vehicle_id = vehicles.id) as last_payment')
            ->orderBy('vehicles.plate')
            ->get();
    }

    /**
     * Settlement, not fares.
     *
     * A SACCO's head-office account receives one Organization To Organization
     * Transfer per vehicle per night — the day's takings being swept up. Those
     * arrive on a shortcode no VEHICLE owns, which is the exact shape this
     * command hunts for, so without this list every HO account is reported as a
     * lost till every week until people stop reading the report.
     *
     * Only `Customer Merchant Payment` is a passenger paying a matatu; these are
     * money moving between accounts that have already been paid.
     */
    private const SETTLEMENT_TYPES = [
        'Organization To Organization Transfer',
        'OD Payment Transfer',
    ];

    /**
     * Money arriving for a shortcode no vehicle claims.
     *
     * The mirror image of an idle till: the payment reached us, we could not
     * attribute it, and it is sitting in `mpesas` attached to nothing.
     */
    private function unmatchedPayments(Carbon $since)
    {
        $known = Vehicle::withoutGlobalScopes()
            ->whereNotNull('merchant_short_code')->pluck('merchant_short_code')
            ->merge(Vehicle::withoutGlobalScopes()->whereNotNull('till_number')->pluck('till_number'))
            ->filter()->unique()->values();

        // `mpesas` carries Safaricom's own CamelCase column names, and PostgreSQL
        // folds an unquoted identifier to lower case -- so raw SQL naming
        // TransAmount asks for a column called `transamount`, which does not
        // exist. The builder's own methods wrap identifiers for us; only the two
        // aggregates below are raw, so they borrow the same grammar rather than
        // hard-coding quotes that MySQL would then reject.
        $grammar = DB::connection()->getQueryGrammar();
        $amount = $grammar->wrap('TransAmount');
        $time = $grammar->wrap('TransTime');

        return DB::table('mpesas')
            ->where('TransTime', '>=', $since)
            ->whereNotNull('BusinessShortCode')
            ->where('BusinessShortCode', '<>', '')
            // Nulls are kept deliberately. A bare whereNotIn() would drop them
            // too -- `NULL NOT IN (...)` is NULL, not true -- and 34k rows carry
            // no TransactionType at all. Those are exactly the ones we cannot
            // classify, so they must stay visible rather than be filtered out by
            // a rule aimed at settlement.
            ->where(function ($q): void {
                $q->whereNull('TransactionType')
                    ->orWhereNotIn('TransactionType', self::SETTLEMENT_TYPES);
            })
            ->when($known->isNotEmpty(), fn ($q) => $q->whereNotIn('BusinessShortCode', $known->all()))
            ->groupBy('BusinessShortCode')
            ->select('BusinessShortCode as code')
            ->selectRaw('COUNT(*) as payments')
            ->selectRaw("SUM(CAST(NULLIF({$amount}, '') AS DECIMAL(15,2))) as total")
            ->selectRaw("MAX({$time}) as last_seen")
            ->orderByRaw('COUNT(*) DESC')
            ->limit(25)
            ->get();
    }
}
