<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Give every bus saved before the till registrar existed (2026-11-25) a
 * registration status it can show, from evidence only -- never by asking
 * Safaricom, and never by guessing.
 *
 *   - a till its bank delivers (NCBA paybill, a bank collection account): not_ours
 *   - a till whose payments reached us in the last 30 days: registered
 *   - otherwise what TillRegistrar::precheck can tell without Safaricom: no
 *     store number, no head office chosen, no keys, not live, or registered
 *     here by its own record
 *   - a till that could be registered but has shown no payment is left blank:
 *     nothing proves either way, and the next save or "Register till" decides.
 *
 * Dry run unless --write.
 */
class BackfillTillRegistrationStatus extends Command
{
    protected $signature = 'tills:backfill-status {--write : Record the statuses (default: report only)}';

    protected $description = 'Fill in till registration status for buses saved before the registrar existed';

    public function handle(TillRegistrar $registrar): int
    {
        $buses = Vehicle::withoutGlobalScopes()
            ->whereNull('till_registration_status')
            ->where(fn ($q) => $q->whereNotNull('merchant_short_code')->orWhereNotNull('coop_till')->orWhereNotNull('ncba_till'))
            ->orderBy('id')
            ->get();

        // One query: which store numbers delivered a payment through our own
        // C2B receiver lately (mpesa_setting_id is set only there).
        $codes = $buses->map(fn (Vehicle $v) => $registrar->shortCode($v))->filter()->unique()->values()->all();
        $delivering = $codes === [] ? [] : array_flip(DB::table('mpesas')
            ->whereIn('BusinessShortCode', $codes)
            ->whereNotNull('mpesa_setting_id')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->distinct()
            ->pluck('BusinessShortCode')
            ->map(fn ($c) => (string) $c)
            ->all());

        $counts = [];
        foreach ($buses as $bus) {
            $status = match (true) {
                ! $registrar->isOurs($bus) => $registrar->bankDelivers($bus) ?? TillRegistrar::NOT_OURS,
                isset($delivering[$registrar->shortCode($bus)]) => TillRegistrar::REGISTERED,
                default => $registrar->precheck($bus),
            };

            if ($status === TillRegistrar::PENDING) {
                $status = 'left blank';
            } elseif ($this->option('write')) {
                $registrar->note($bus, $status);
            }

            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        ksort($counts);
        $this->table(['status', 'buses'], collect($counts)->map(fn ($n, $s) => [$s, $n])->values()->all());
        $this->info(($this->option('write') ? 'Recorded' : 'DRY RUN -- re-run with --write to record').": {$buses->count()} buses without a status.");

        return self::SUCCESS;
    }
}
