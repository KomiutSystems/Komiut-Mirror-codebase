<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Mpesa;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Services\Sql\PlateSql;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Attributes a bank Head-Office SETTLEMENT deposit to the correct bus — but only
 * for a bus that has no live collection of its own.
 *
 * Co-op (and NCBA) sweep each matatu's daily takings once a night into the
 * SACCO's HO account as an "Organization To Organization Transfer", identifying
 * the bus only by NAME in FirstName (e.g. "NICCO MOVERS-KDY 599G"). That lands
 * on a shortcode no vehicle owns, so the normal matcher — which keys on
 * BusinessShortCode → vehicles.merchant_short_code — cannot attribute it.
 *
 * For a bus that ALSO collects live on its own till, that sweep is the same money
 * a second time; CheckIdleTills already treats it as such and never flags it as
 * lost, and attributing it here would DOUBLE-COUNT. So the guard below is the
 * whole point: a settlement is attributed only when the bus has no transaction of
 * its own — i.e. the sweep is the ONLY record of that bus's money. The day a
 * bus's own till starts working, its sweeps stop being attributed automatically.
 *
 * Idempotent: only ever touches settlements that have no transaction yet.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS COMMAND CANNOT DO, BECAUSE IT IS MISTAKEN FOR THE FIX EVERY TIME.
 *
 * The selection is `mpesas` WITH NO TRANSACTION ROW AT ALL. A settlement that
 * already has a transaction is invisible here, whatever state that transaction is
 * in -- including a transaction with `vehicle_id` NULL, which is precisely the
 * shape unattributed money takes.
 *
 * Measured 2026-09-09 on Frankfurt:
 *   - 195 settlements have no transaction. Every one is TransTime 2026-07-31 to
 *     2026-08-08, i.e. the legacy:import-money backfill, KES 3,652,572.09. Zero
 *     fall inside the 7-day window, so on the hourly schedule this command is
 *     currently a NO-OP -- and that is the correct outcome, not a bug. Its job is
 *     to catch settlements that arrive with no transaction in future.
 *   - Separately, 335 transactions hold `vehicle_id` NULL and `summarized` false,
 *     KES 5,515,605.74, from 2026-08-27 onward. 333 of them are O2O settlements.
 *     ALL 335 already have an mpesa_id, so NONE is reachable from here.
 *
 * That second set is a different defect with a different cause: something already
 * creates a transaction for every O2O settlement and leaves roughly thirty a day
 * unattributed. On 2026-09-08 there were 521 O2O rows, 521 transactions, and 491
 * with a vehicle. Attributing that money means finding the writer that leaves
 * vehicle_id NULL -- NOT widening this command's window, which would only reach
 * the backfill and attribute history nobody asked for.
 * ---------------------------------------------------------------------------
 *
 * The settlement TransactionTypes mirror CheckIdleTills::SETTLEMENT_TYPES; keep
 * the two in step (a shared source is a fair follow-up refactor).
 */
final class AttributeCoopSettlements extends Command
{
    protected $signature = 'app:attribute-coop-settlements
        {--dry-run : Report what would happen, write nothing}
        {--since= : Only settlements with TransTime at or after this. Default 7 days back. Widen ONLY for a reviewed one-off.}';

    protected $description = 'Attribute unmatched bank HO settlement sweeps to settlement-only buses.';

    /**
     * How far back an unattended run will reach.
     *
     * THIS BOUND IS WHY THE COMMAND IS SAFE TO SCHEDULE. It used to have none:
     * it swept EVERY settlement in `mpesas` with no transaction, whatever its
     * TransTime. That was harmless while this host had only been receiving
     * confirmations for a few days, and became dangerous the moment
     * legacy:import-money dropped ~1.29M historical rows (TransTime
     * 2026-07-08..2026-08-08) into the same table -- the next hourly tick
     * would have written transactions and mutated summaries for months in the
     * past, inside an hour, reviewed by nobody. That is why Kernel.php had the
     * line commented out from 2026-08-26.
     *
     * collectsLive() cannot stand in for this. It asks whether the bus has EVER
     * collected on its own till -- a present-tense question -- so applied to
     * history it is wrong in both directions: a bus whose till works today has
     * its genuinely-unrecorded past sweeps suppressed, and a bus that never had
     * a working till gets years of sweeps attributed in a single pass.
     *
     * Seven days is chosen to be several times the gap the hourly schedule
     * could ever leave (a scheduler outage, a redeploy, a weekend) while still
     * ending far short of the backfill. To go further back, pass --since
     * explicitly, and run it with --dry-run first.
     */
    private const DEFAULT_WINDOW_DAYS = 7;

    /** @var array<int, string> the O2O transfer types a nightly sweep arrives as */
    private const SETTLEMENT_TYPES = [
        'Organization To Organization Transfer',
        'OD Payment Transfer',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $cutoff = $this->cutoff();

        // Printed every run, including --dry-run: the window is the difference
        // between attributing this week's sweeps and attributing the backfill,
        // so an operator reading the output must be able to see which they got.
        $this->line('  window            : TransTime >= '.$cutoff->toDateTimeString()
            .($this->option('since') ? '  (--since given)' : '  (default '.self::DEFAULT_WINDOW_DAYS.'d)'));

        $deposits = Mpesa::whereIn('TransactionType', self::SETTLEMENT_TYPES)
            ->where('TransTime', '>=', $cutoff)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw('1'))->from('transactions')
                    ->whereColumn('transactions.mpesa_id', 'mpesas.id');
            })
            ->orderBy('id')
            ->get();

        $attributed = 0;
        $skippedLive = 0;
        $skippedNoVehicle = 0;
        $recovered = 0.0;

        foreach ($deposits as $m) {
            $vehicle = $this->vehicleFromName($m->FirstName);

            if ($vehicle === null) {
                $skippedNoVehicle++;
                $this->line("  SKIP (no vehicle) : {$m->FirstName}");

                continue;
            }

            if ($this->collectsLive($vehicle)) {
                $skippedLive++;
                $this->line("  SKIP (live bus)   : {$vehicle->plate}  {$m->TransAmount}");

                continue;
            }

            $recovered += (float) $m->TransAmount;
            $attributed++;

            if ($dry) {
                $this->line("  WOULD ATTRIBUTE   : {$vehicle->plate}  {$m->TransAmount}  {$m->TransTime}");

                continue;
            }

            $this->attribute($m, $vehicle);
            $this->line("  ATTRIBUTED        : {$vehicle->plate}  {$m->TransAmount}  {$m->TransTime}");
        }

        $verb = $dry ? 'Would attribute' : 'Attributed';
        $this->info("{$verb} {$attributed} = ".number_format($recovered, 2)
            .". Skipped {$skippedLive} live-bus, {$skippedNoVehicle} unrecognised.");

        return self::SUCCESS;
    }

    /**
     * The earliest TransTime an unattended run will touch.
     *
     * `--since` accepts anything Carbon parses, so a reviewed one-off can reach
     * back deliberately: `--since=2026-07-01 --dry-run` first, then without.
     *
     * THE 3-HOUR SKEW IS DELIBERATE AND ERRS INCLUSIVE. `TransTime` is stored as
     * Safaricom sends it, in EAT, while `now()` on this host is UTC -- the two
     * columns are the same PHP type and three hours apart in meaning. Comparing
     * them directly makes the cutoff land three hours EARLIER in EAT terms than
     * the literal arithmetic suggests, so the window is 7 days and 3 hours rather
     * than exactly 7. That direction is the safe one: it can only ever include a
     * recent settlement that a precise window would have dropped, and a sweep
     * silently skipped is money no SACCO can see. Do not "fix" it by adding three
     * hours without also fixing the direction.
     */
    private function cutoff(): Carbon
    {
        $since = $this->option('since');

        return $since
            ? Carbon::parse((string) $since)
            : now()->subDays(self::DEFAULT_WINDOW_DAYS);
    }

    /** Resolve the bus named in a settlement's FirstName ("… KDY 599G") to a vehicle. */
    private function vehicleFromName(?string $name): ?Vehicle
    {
        if ($name === null || ! preg_match('/([A-Za-z]{3})\s*(\d{3})\s*([A-Za-z])\s*$/', trim($name), $mm)) {
            return null;
        }

        $plate = PlateSql::normalise($mm[1].$mm[2].$mm[3]);

        return Vehicle::withoutGlobalScopes()
            ->whereRaw(PlateSql::normaliseColumn('plate').' = ?', [$plate])
            ->first();
    }

    /**
     * True when the bus already records payments on its OWN till — meaning the
     * settlement is a duplicate and must not be attributed. A settlement we
     * previously attributed does not count: its mpesa carries the HO shortcode,
     * not the vehicle's own, so this stays idempotent.
     */
    private function collectsLive(Vehicle $vehicle): bool
    {
        if (empty($vehicle->merchant_short_code)) {
            return false;
        }

        return Transaction::query()
            ->join('mpesas', 'mpesas.id', '=', 'transactions.mpesa_id')
            ->where('transactions.vehicle_id', $vehicle->id)
            ->where('mpesas.BusinessShortCode', $vehicle->merchant_short_code)
            ->exists();
    }

    /** Record the settlement as a transaction + day summary, mirroring CoopRestPaymentsController. */
    private function attribute(Mpesa $m, Vehicle $vehicle): void
    {
        DB::transaction(function () use ($m, $vehicle): void {
            $date = Carbon::parse($m->TransTime)->format('Y-m-d');

            $summary = Summary::where('vehicle_id', $vehicle->id)->where('trans_date', $date)->first()
                ?? new Summary([
                    'vehicle_id' => $vehicle->id, 'mpesa_amount' => 0, 'cash_amount' => 0,
                    'mpesa_txn' => 0, 'cash_txn' => 0, 'expense_fee_amount' => 0, 'trans_date' => $date,
                ]);
            $summary->mpesa_amount = (float) $summary->mpesa_amount + (float) $m->TransAmount;
            $summary->mpesa_txn = (int) $summary->mpesa_txn + 1;
            $summary->save();

            Transaction::create([
                'vehicle_id' => $vehicle->id,
                'mpesa_id' => $m->id,
                'amount' => $m->TransAmount,
                'trans_date' => $m->TransTime,
                'summarized' => true,
            ]);
        });
    }
}
