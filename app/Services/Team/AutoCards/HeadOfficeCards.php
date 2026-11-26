<?php

declare(strict_types=1);

namespace App\Services\Team\AutoCards;

use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A card per SACCO whose buses cannot be registered because the head office
 * they sit under (an MpesaPaymentSetting) has no API keys, or is set to
 * Safaricom's test system.
 *
 * Those buses carry no_keys / not_live (TillRegistrar::precheck). Whether
 * they are STILL blocked is read from the head office itself, not from the
 * buses: saving the keys does not touch the buses -- someone presses
 * Register tills afterwards -- and the card's job is the head office. So the
 * card closes when every head office the waiting buses sit under has keys
 * and is live (the closing comment says to press Register tills), or when no
 * bus waits any more.
 *
 * Which head office a waiting bus sits under follows TillRegistrar::
 * connectionFor: its own link if that still exists, else the SACCO's only
 * live connection. A waiting bus with neither -- its head office deleted, or
 * the SACCO has none or several live ones -- is still held back, and keeps
 * the card open saying so: pressing Register tills would only turn it into
 * needs_head_office. Only when every head office the buses resolve to is
 * ready does the card say "has its keys and is live". The link is the bus's
 * SACCO, not the head office's: that is who the team calls, and an imported
 * head office can be shared.
 */
class HeadOfficeCards extends AutoCardSource
{
    public const WAITING = [TillRegistrar::NO_KEYS, TillRegistrar::NOT_LIVE];

    public function source(): string
    {
        return 'head_office';
    }

    public function sync(int $saccoId, bool $event = true): void
    {
        $this->apply($saccoId, sweep: ! $event);
    }

    /**
     * The SACCOs with buses waiting on this head office -- its own, and any
     * whose buses link to it (a shared, imported head office). For a head
     * office whose keys or environment just changed.
     *
     * @return list<int>
     */
    public function saccosWaitingOn(int $connectionId, ?int $ownSaccoId): array
    {
        $ids = Vehicle::withoutGlobalScopes()
            ->where('mpesa_payment_setting_id', $connectionId)
            ->whereIn('till_registration_status', self::WAITING)
            ->whereNotNull('sacco_id')
            ->distinct()->limit(100)
            ->pluck('sacco_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_filter([...$ids, $ownSaccoId])));
    }

    public function sweep(): array
    {
        $opened = 0;
        $closed = 0;
        $open = $this->openCards('sacco');

        $waiting = Vehicle::withoutGlobalScopes()
            ->whereIn('till_registration_status', self::WAITING)
            ->whereNotNull('sacco_id')
            ->distinct()
            ->pluck('sacco_id')->map(fn ($id) => (int) $id)->all();

        foreach (array_values(array_unique([...$waiting, ...$open->keys()->all()])) as $saccoId) {
            if ($opened >= self::SWEEP_OPEN_LIMIT && ! $open->has($saccoId)) {
                continue;
            }
            $outcome = $this->apply($saccoId, sweep: true);
            $opened += $outcome === 'opened' ? 1 : 0;
            $closed += $outcome === 'closed' ? 1 : 0;
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    /** @return 'opened'|'closed'|null */
    private function apply(int $saccoId, bool $sweep): ?string
    {
        $sacco = Sacco::withoutGlobalScopes()->find($saccoId, ['id', 'name']);
        if ($sacco === null) {
            return $this->resolve('sacco', $saccoId, 'the SACCO was deleted.') ? 'closed' : null;
        }

        [$blocked, $waiting, $since] = $this->blocked($saccoId);

        if ($blocked === []) {
            $why = $waiting === 0
                ? 'no bus waits on a head office without keys any more.'
                : 'the head office has its keys and is live now. Press Register tills on it to register the '.self::count($waiting, 'bus', 'buses').' still marked as waiting.';

            return $this->resolve('sacco', $saccoId, $why) ? 'closed' : null;
        }

        $buses = array_sum(array_column($blocked, 'buses'));
        $waitingOn = self::count($buses, 'bus', 'buses').' waiting';
        $title = match (true) {
            count($blocked) > 1 => $sacco->name.' · '.$waitingOn.' on '.count($blocked).' head-office problems',
            $blocked[0]['label'] === null => $sacco->name.' · '.$waitingOn.' · '.$blocked[0]['reason'],
            default => $sacco->name.' · '.$waitingOn.' on head office '.$blocked[0]['label'].' · '.$blocked[0]['reason'],
        };

        $lines = array_map(
            fn (array $b) => ($b['label'] === null ? 'No head office' : 'Head office '.$b['label']).': '.$b['reason'].' -- '.self::count($b['buses'], 'bus', 'buses').' waiting.',
            $blocked,
        );
        $lines[] = 'Add the API keys, or switch the head office to live, under the SACCO\'s M-Pesa settings (for buses with no head office, choose the one they register under), then press Register tills on it. Until then fares on these buses are not recorded. This card closes itself once the head office is ready.';

        $card = $this->raise('sacco', $saccoId, $title, implode("\n", $lines), 'high', $sweep, since: $since);

        return $card !== null && $card->wasRecentlyCreated ? 'opened' : null;
    }

    /**
     * The head offices holding this SACCO's waiting buses back, how many
     * buses wait at all, and since when the newest of those problems has
     * stood (a bus that started waiting, a head office changed -- null when
     * unknown). Two or three queries, whatever the fleet size.
     *
     * @return array{0: list<array{label:?string, reason:string, buses:int}>, 1: int, 2: ?CarbonInterface}
     */
    private function blocked(int $saccoId): array
    {
        $groups = Vehicle::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->whereIn('till_registration_status', self::WAITING)
            ->groupBy('mpesa_payment_setting_id')
            ->get(['mpesa_payment_setting_id', DB::raw('count(*) as buses'), DB::raw('max(till_registration_status_at) as since')]);

        $waiting = (int) $groups->sum('buses');
        if ($waiting === 0) {
            return [[], 0, null];
        }

        $linked = $groups->pluck('mpesa_payment_setting_id')->filter()->map(fn ($id) => (int) $id)->all();
        $connections = MpesaPaymentSetting::withoutGlobalScopes()->whereIn('id', $linked)->get()->keyBy('id');

        // Buses whose own link is missing or gone fall back, as the registrar
        // does, to the SACCO's only live connection -- if there is exactly one.
        $fallback = null;
        $live = 0;
        $unlinked = $groups->filter(fn ($g) => $g->mpesa_payment_setting_id === null || ! $connections->has((int) $g->mpesa_payment_setting_id));
        if ($unlinked->isNotEmpty()) {
            $candidates = MpesaPaymentSetting::withoutGlobalScopes()
                ->where('sacco_id', $saccoId)->where('is_live', true)->where('status', true)
                ->orderBy('id')->limit(2)->get();
            $live = $candidates->count();
            $fallback = $live === 1 ? $candidates->first() : null;
        }

        $since = null;
        $later = function (mixed $at) use (&$since): void {
            if ($at !== null && $at !== '') {
                $at = Carbon::parse($at);
                $since = $since === null || $at->gt($since) ? $at : $since;
            }
        };

        /** @var array<int, array{connection: MpesaPaymentSetting, buses: int}> $byConnection */
        $byConnection = [];
        $nowhere = 0;
        foreach ($groups as $g) {
            $later($g->since);
            $connection = $connections->get((int) $g->mpesa_payment_setting_id) ?? $fallback;
            if ($connection === null) {
                $nowhere += (int) $g->buses;

                continue;
            }
            $byConnection[(int) $connection->id] ??= ['connection' => $connection, 'buses' => 0];
            $byConnection[(int) $connection->id]['buses'] += (int) $g->buses;
        }

        $blocked = [];
        foreach ($byConnection as ['connection' => $c, 'buses' => $buses]) {
            $reason = match (true) {
                ! $c->canRegisterTills() => 'no API keys',
                ! $c->is_live => 'on the Safaricom test system',
                default => null,
            };
            if ($reason !== null) {
                $blocked[] = [
                    'label' => filled($c->business_short_code) ? (string) $c->business_short_code : (filled($c->name) ? (string) $c->name : '#'.$c->id),
                    'reason' => $reason,
                    'buses' => $buses,
                ];
                // Keys cleared, or switched back to test, after a Done.
                $later($c->updated_at);
            }
        }

        // Not ready either: there is no head office to have keys. Saying
        // "has its keys and is live" would send someone to press a button
        // that only turns these into needs_head_office.
        if ($nowhere > 0) {
            $blocked[] = [
                'label' => null,
                'reason' => $live > 1 ? 'several live head offices, none chosen for them' : 'no head office to register them under',
                'buses' => $nowhere,
            ];
        }

        return [$blocked, $waiting, $since];
    }
}
