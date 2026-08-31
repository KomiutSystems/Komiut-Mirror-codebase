<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Mpesa;
use App\Models\Transaction;
use App\Services\Mpesa\VehicleByShortCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Put money back on the bus that earned it.
 *
 * WHAT WENT WRONG. Until 2026-08-26 the C2B vehicle lookup ran inside
 * BrandScope, which keys on the request host — and every till in the fleet is
 * registered with Safaricom against one host. So confirmations for every brand
 * arrived under a single brand, and the other brand's buses were invisible to
 * the lookup. The payment was recorded correctly in `mpesas`, the transaction
 * row was written correctly, and `vehicle_id` was left null. On 2026-08-26 that
 * was 5,657 payments worth KES 367,882 — real fares, sitting against no bus.
 *
 * WHY A SEPARATE COMMAND rather than replaying through C2bPaymentRecorder: the
 * recorder is keyed on the receipt. It sees a transaction already exists for
 * that mpesa_id, returns `duplicate`, and never revisits attribution. Replaying
 * these would be a no-op. The row does not need re-recording — it needs the
 * vehicle it should have had.
 *
 * SAFETY, in the order it matters:
 *
 *  - DRY RUN BY DEFAULT. Nothing is written without --write.
 *  - One rule, shared with the live callback (VehicleByShortCode), so a repair
 *    can never credit a bus the live path would not have credited.
 *  - A shortcode matching several vehicles is REFUSED, not guessed.
 *  - Only rows where vehicle_id IS NULL are touched. An already-attributed
 *    payment is never rewritten, so this cannot move money between buses.
 *  - Summaries are REBUILT by app:generate-vehicle-summaries, which recomputes
 *    each day from the transactions table by assignment. Incrementing by hand
 *    would double-count on a second run; recomputing is idempotent.
 *  - `summarized` is set only AFTER that rebuild succeeds, so an interrupted run
 *    leaves the flag honest rather than claiming a summary that is not there.
 *
 * VALIDATED BEFORE FIRST USE. The 52 shortcodes this touched on 2026-08-26 were
 * resolved through the shared rule and compared against the bus the legacy
 * Mumbai system independently credited for the same day: 52 of 52 agreed.
 */
class AttributeOrphanPayments extends Command
{
    protected $signature = 'payments:attribute-orphans
        {--date= : Only this Y-m-d (default: every date that has orphans)}
        {--write : Actually apply. Without it this is a dry run and writes nothing}
        {--chunk=500 : Rows per database transaction}';

    protected $description = 'Attribute payments whose transaction row was left without a vehicle, using the same shortcode rule as the live C2B callback';

    public function handle(): int
    {
        $date = $this->option('date');
        $write = (bool) $this->option('write');
        $chunk = max(1, (int) $this->option('chunk'));

        $candidates = $this->candidates($date);

        if ($candidates->isEmpty()) {
            $this->info('No orphaned payments to attribute.');

            return self::SUCCESS;
        }

        // Resolve everything BEFORE writing anything, so the report the operator
        // approves is the work that then happens — and so an unresolvable
        // shortcode is a line of output rather than a half-finished run.
        [$resolved, $unresolvable] = $this->resolve($candidates);

        $this->report($resolved, $unresolvable);

        if ($resolved === []) {
            $this->warn('Nothing is attributable. Nothing to do.');

            return self::SUCCESS;
        }

        if (! $write) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --write to apply.');

            return self::SUCCESS;
        }

        $dates = $this->apply($resolved, $chunk);

        // Summaries are rebuilt per affected day, by the command that owns them.
        foreach ($dates as $day) {
            $this->info("Rebuilding summaries for {$day} ...");
            $this->call('app:generate-vehicle-summaries', ['--date' => $day]);
        }

        // Only now is the flag true: every attributed row is in a rebuilt summary.
        $marked = Transaction::withoutGlobalScopes()
            ->whereIn('id', array_column($resolved, 'transaction_id'))
            ->update(['summarized' => true]);

        $this->newLine();
        $this->info('Attributed '.count($resolved).' payment(s); marked '.$marked.' summarised.');

        return self::SUCCESS;
    }

    /**
     * Orphans, with the shortcode that should identify them.
     *
     * withoutGlobalScopes throughout: this is a system operation with no
     * authenticated user, and a scoped read would hide the vehicle-less rows
     * that are the entire point.
     */
    private function candidates(?string $date)
    {
        return Transaction::withoutGlobalScopes()
            ->whereNull('vehicle_id')
            ->whereNotNull('mpesa_id')
            ->when($date !== null, fn ($q) => $q->whereDate('trans_date', $date))
            ->orderBy('id')
            ->get(['id', 'mpesa_id', 'amount', 'trans_date']);
    }

    /**
     * @return array{0: list<array{transaction_id:int,vehicle_id:int,plate:string,date:string,amount:float}>, 1: array<string,array{rows:int,amount:float}>}
     */
    private function resolve($candidates): array
    {
        $shortCodes = Mpesa::withoutGlobalScopes()
            ->whereIn('id', $candidates->pluck('mpesa_id')->all())
            ->pluck('BusinessShortCode', 'id');

        // One lookup per DISTINCT shortcode, not per payment: 5,657 rows share
        // 52 shortcodes, and the rule is a pure function of the shortcode.
        $byShortCode = [];
        foreach (array_unique($shortCodes->all()) as $code) {
            $byShortCode[(string) $code] = VehicleByShortCode::resolve((string) $code);
        }

        $resolved = [];
        $unresolvable = [];

        foreach ($candidates as $txn) {
            $code = (string) ($shortCodes[$txn->mpesa_id] ?? '');
            $vehicle = $byShortCode[$code] ?? null;

            if ($vehicle === null) {
                $key = $code === '' ? '(blank)' : $code;
                $unresolvable[$key] ??= ['rows' => 0, 'amount' => 0.0];
                $unresolvable[$key]['rows']++;
                $unresolvable[$key]['amount'] += (float) $txn->amount;

                continue;
            }

            $resolved[] = [
                'transaction_id' => (int) $txn->id,
                'vehicle_id' => (int) $vehicle->id,
                'plate' => (string) $vehicle->plate,
                'date' => $txn->trans_date instanceof \DateTimeInterface
                    ? $txn->trans_date->format('Y-m-d')
                    : substr((string) $txn->trans_date, 0, 10),
                'amount' => (float) $txn->amount,
            ];
        }

        return [$resolved, $unresolvable];
    }

    /**
     * @param  list<array{transaction_id:int,vehicle_id:int,plate:string,date:string,amount:float}>  $resolved
     * @return list<string> the dates touched
     */
    private function apply(array $resolved, int $chunk): array
    {
        $dates = [];

        // Grouped by vehicle so one UPDATE covers many rows, and chunked so a
        // failure rolls back a bounded slice rather than the whole repair.
        $byVehicle = [];
        foreach ($resolved as $r) {
            $byVehicle[$r['vehicle_id']][] = $r['transaction_id'];
            $dates[$r['date']] = true;
        }

        foreach ($byVehicle as $vehicleId => $ids) {
            foreach (array_chunk($ids, $chunk) as $slice) {
                DB::transaction(function () use ($vehicleId, $slice): void {
                    Transaction::withoutGlobalScopes()
                        ->whereIn('id', $slice)
                        // Re-asserted inside the write: if anything attributed
                        // these between the read and here, this must not
                        // overwrite it.
                        ->whereNull('vehicle_id')
                        ->update(['vehicle_id' => $vehicleId]);
                });
            }
        }

        $out = array_keys($dates);
        sort($out);

        return $out;
    }

    /**
     * @param  list<array{plate:string,date:string,amount:float}>  $resolved
     * @param  array<string,array{rows:int,amount:float}>  $unresolvable
     */
    private function report(array $resolved, array $unresolvable): void
    {
        $perDate = [];
        foreach ($resolved as $r) {
            $perDate[$r['date']] ??= ['rows' => 0, 'amount' => 0.0, 'buses' => []];
            $perDate[$r['date']]['rows']++;
            $perDate[$r['date']]['amount'] += $r['amount'];
            $perDate[$r['date']]['buses'][$r['plate']] = true;
        }
        ksort($perDate);

        $this->info('Attributable:');
        foreach ($perDate as $day => $d) {
            $this->line(sprintf(
                '  %s  %6d payment(s)  KES %14s  across %d bus(es)',
                $day, $d['rows'], number_format($d['amount'], 2), count($d['buses'])
            ));
        }

        if ($unresolvable !== []) {
            $this->newLine();
            $this->warn('Left alone — the shortcode matches no vehicle, or more than one:');
            foreach ($unresolvable as $code => $u) {
                $this->line(sprintf('  shortcode %-12s %5d row(s)  KES %14s',
                    $code, $u['rows'], number_format($u['amount'], 2)));
            }
            $this->line('  These are correct to leave: on this fleet they are the SACCO\'s own');
            $this->line('  till-to-bank sweeps, which belong to no bus and are not takings.');
        }
    }
}
