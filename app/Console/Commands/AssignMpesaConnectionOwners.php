<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MpesaPaymentSetting;
use App\Services\Mpesa\PaymentConnectionMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give each unowned M-Pesa connection to the SACCO whose buses use it.
 *
 * ImportLegacyMpesaSettings brought the legacy tier's credentials over with
 * their ids but no SACCO (legacy kept none), so a SACCO admin could see none of
 * the ~30 head-office connections their buses collect through. Ownership is
 * decided from evidence, never guessed:
 *
 *   - buses linked to the connection (vehicles.mpesa_payment_setting_id);
 *   - payments that arrived through it in the last 90 days, credited to the
 *     SACCO of the bus whose store received them. "Through it" is read by
 *     PaymentConnectionMap: the URL id on most confirmations is the legacy
 *     payments server's app id, not ours, and taking it as ours handed a
 *     busy head office's payments to an unrelated connection;
 *   - a bus whose store number IS the connection's short code.
 *
 * A connection goes to a SACCO only when that SACCO holds >= 90% of the
 * evidence. No evidence, or split evidence, is reported and left alone -- as
 * is a connection that already has an owner, even when the evidence disagrees
 * (that is a decision for a person, and it is listed so one can make it).
 * `--assign=ID:SACCO` records such a decision explicitly.
 *
 * Two things are never done, explicit or not, because each would change what
 * buses pay through: a SACCO's DEFAULT connection is not moved to another
 * SACCO (its unlinked buses would lose their fallback -- make another the
 * default first), and a connection other SACCOs' buses are linked to is not
 * given to one SACCO (its owner could then change their credentials).
 *
 * An assigned connection is never a default, so never any bus's fallback.
 * Dry run unless --write.
 */
class AssignMpesaConnectionOwners extends Command
{
    protected $signature = 'mpesa:assign-connection-owners
        {--write : Apply the assignments. Without it nothing is written}
        {--assign=* : Explicit owner for a connection, as CONNECTION_ID:SACCO_ID (e.g. 20:4)}';

    protected $description = 'Assign unowned M-Pesa connections to the SACCO whose buses use them';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $explicit = [];
        foreach ((array) $this->option('assign') as $pair) {
            if (! preg_match('/^(\d+):(\d+)$/', (string) $pair, $m)) {
                $this->error("Bad --assign value '{$pair}', expected CONNECTION_ID:SACCO_ID.");

                return self::FAILURE;
            }
            $explicit[(int) $m[1]] = (int) $m[2];
        }

        $evidence = [];
        $add = function (int $sid, ?int $sacco, int $weight) use (&$evidence): void {
            if ($sacco !== null) {
                $evidence[$sid][$sacco] = ($evidence[$sid][$sacco] ?? 0) + $weight;
            }
        };

        foreach (DB::table('vehicles')->whereNotNull('mpesa_payment_setting_id')->whereNotNull('sacco_id')
            ->selectRaw('mpesa_payment_setting_id sid, sacco_id, count(*) n')->groupBy('mpesa_payment_setting_id', 'sacco_id')->get() as $r) {
            $add((int) $r->sid, (int) $r->sacco_id, 100 * (int) $r->n);
        }
        [$via, $viaBindings] = PaymentConnectionMap::load()->sql('m');
        foreach (DB::table('mpesas as m')->join('vehicles as v', 'v.merchant_short_code', '=', 'm.BusinessShortCode')
            ->whereNotNull('m.mpesa_setting_id')->whereNotNull('v.sacco_id')
            ->where('m.TransTime', '>=', now('Africa/Nairobi')->subDays(90)->format('Y-m-d H:i:s'))
            ->selectRaw("({$via}) sid, v.sacco_id, count(*) n", $viaBindings)->groupByRaw('1, 2')->get() as $r) {
            if ($r->sid !== null) {
                $add((int) $r->sid, (int) $r->sacco_id, (int) $r->n);
            }
        }

        // SACCOs whose buses are LINKED to each connection.
        $linkedSaccos = [];
        foreach (DB::table('vehicles')->whereNotNull('mpesa_payment_setting_id')
            ->select('mpesa_payment_setting_id', 'sacco_id')->distinct()->get() as $r) {
            $linkedSaccos[(int) $r->mpesa_payment_setting_id][] = $r->sacco_id === null ? null : (int) $r->sacco_id;
        }
        foreach (DB::table('mpesa_payment_settings as s')->join('vehicles as v', 'v.merchant_short_code', '=', 's.business_short_code')
            ->whereNotNull('v.sacco_id')->get(['s.id', 'v.sacco_id']) as $r) {
            $add((int) $r->id, (int) $r->sacco_id, 100);
        }

        $saccos = DB::table('saccos')->pluck('name', 'id');
        $rows = [];
        $applied = 0;

        foreach (MpesaPaymentSetting::withoutGlobalScopes()->orderBy('id')->get() as $c) {
            $ev = $evidence[$c->id] ?? [];
            arsort($ev);
            $total = array_sum($ev);
            $top = array_key_first($ev);
            $share = $total > 0 ? $ev[$top] / $total : 0;
            $label = fn (?int $id) => $id === null ? '-' : $id.' '.($saccos[$id] ?? '?');

            if (isset($explicit[$c->id])) {
                $target = $explicit[$c->id];
                $verdict = $c->sacco_id === null ? 'ASSIGN (explicit)' : 'REASSIGN (explicit)';
            } elseif ($c->sacco_id !== null) {
                $target = null;
                $verdict = ($top !== null && $top !== (int) $c->sacco_id && $share >= 0.9)
                    ? 'OWNER MISMATCH: used by '.$label($top).' - left for a person'
                    : 'owned';
            } elseif ($top === null) {
                $target = null;
                $verdict = 'no evidence - left unowned';
            } elseif ($share < 0.9) {
                $target = null;
                $verdict = 'split evidence - left unowned';
            } else {
                $target = $top;
                $verdict = 'ASSIGN';
            }

            // Naming the current owner is a no-op, not a write: the update
            // below would otherwise clear a default the SACCO still relies on.
            if ($target !== null && $c->sacco_id !== null && $target === (int) $c->sacco_id) {
                $verdict = 'already owned by '.$label($target);
                $target = null;
            }

            if ($target !== null && ! isset($saccos[$target])) {
                $verdict = "unknown SACCO {$target} - skipped";
                $target = null;
            }

            if ($target !== null && $c->is_default && $target !== (int) $c->sacco_id) {
                $verdict = 'is '.$label((int) $c->sacco_id).'\'s DEFAULT - make another connection its default first';
                $target = null;
            }

            $others = array_values(array_filter($linkedSaccos[$c->id] ?? [], fn ($s) => $s !== $target));
            if ($target !== null && $others !== []) {
                $verdict = 'buses of other SACCOs are linked to it ('.implode(', ', array_map($label, $others)).') - left for a person';
                $target = null;
            }

            if ($target !== null && $write) {
                // Never a default (see the class docblock): unowned rows are
                // not defaults, and a default is never moved above.
                MpesaPaymentSetting::withoutGlobalScopes()->whereKey($c->id)
                    ->update(['sacco_id' => $target, 'is_default' => false, 'updated_at' => now()]);
                $applied++;
            }

            $rows[] = [$c->id, $c->business_short_code, $label($c->sacco_id === null ? null : (int) $c->sacco_id),
                $target !== null ? $label($target) : '', $verdict, $total > 0 ? round($share * 100).'%' : '-'];
        }

        $this->table(['#', 'short code', 'owner now', 'assign to', 'verdict', 'top share'], $rows);
        $this->info($write ? "Assigned {$applied} connection(s)." : 'Dry run: nothing written. Re-run with --write to apply.');

        return self::SUCCESS;
    }
}
