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
 * The settlement TransactionTypes mirror CheckIdleTills::SETTLEMENT_TYPES; keep
 * the two in step (a shared source is a fair follow-up refactor).
 */
final class AttributeCoopSettlements extends Command
{
    protected $signature = 'app:attribute-coop-settlements {--dry-run : Report what would happen, write nothing}';

    protected $description = 'Attribute unmatched bank HO settlement sweeps to settlement-only buses.';

    /** @var array<int, string> the O2O transfer types a nightly sweep arrives as */
    private const SETTLEMENT_TYPES = [
        'Organization To Organization Transfer',
        'OD Payment Transfer',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $deposits = Mpesa::whereIn('TransactionType', self::SETTLEMENT_TYPES)
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
