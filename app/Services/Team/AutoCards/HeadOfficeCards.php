<?php

declare(strict_types=1);

namespace App\Services\Team\AutoCards;

use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
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
 * live connection. (A bus with neither is a needs_head_office problem, and
 * that is a till card.) The link is the bus's SACCO, not the head office's:
 * that is who the team calls, and an imported head office can be shared.
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

        [$blocked, $waiting] = $this->blocked($saccoId);

        if ($blocked === []) {
            $why = $waiting === 0
                ? 'no bus waits on a head office without keys any more.'
                : 'the head office has its keys and is live now. Press Register tills on it to register the '.self::count($waiting, 'bus', 'buses').' still marked as waiting.';

            return $this->resolve('sacco', $saccoId, $why) ? 'closed' : null;
        }

        $buses = array_sum(array_column($blocked, 'buses'));
        $title = count($blocked) === 1
            ? $sacco->name.' · '.self::count($buses, 'bus', 'buses').' waiting on head office '.$blocked[0]['label'].' · '.$blocked[0]['reason']
            : $sacco->name.' · '.self::count($buses, 'bus', 'buses').' waiting on '.count($blocked).' head offices without keys';

        $lines = array_map(
            fn (array $b) => 'Head office '.$b['label'].': '.$b['reason'].' -- '.self::count($b['buses'], 'bus', 'buses').' waiting.',
            $blocked,
        );
        $lines[] = 'Add the API keys, or switch the head office to live, under the SACCO\'s M-Pesa settings, then press Register tills on it. Until then fares on these buses are not recorded. This card closes itself once the head office is ready.';

        $card = $this->raise('sacco', $saccoId, $title, implode("\n", $lines), 'high', $sweep);

        return $card !== null && $card->wasRecentlyCreated ? 'opened' : null;
    }

    /**
     * The head offices holding this SACCO's waiting buses back, and how many
     * buses wait at all. Two or three queries, whatever the fleet size.
     *
     * @return array{0: list<array{label:string, reason:string, buses:int}>, 1: int}
     */
    private function blocked(int $saccoId): array
    {
        $groups = Vehicle::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->whereIn('till_registration_status', self::WAITING)
            ->groupBy('mpesa_payment_setting_id')
            ->get(['mpesa_payment_setting_id', DB::raw('count(*) as buses')]);

        $waiting = (int) $groups->sum('buses');
        if ($waiting === 0) {
            return [[], 0];
        }

        $linked = $groups->pluck('mpesa_payment_setting_id')->filter()->map(fn ($id) => (int) $id)->all();
        $connections = MpesaPaymentSetting::withoutGlobalScopes()->whereIn('id', $linked)->get()->keyBy('id');

        // Buses whose own link is missing or gone fall back, as the registrar
        // does, to the SACCO's only live connection -- if there is exactly one.
        $fallback = null;
        $unlinked = $groups->filter(fn ($g) => $g->mpesa_payment_setting_id === null || ! $connections->has((int) $g->mpesa_payment_setting_id));
        if ($unlinked->isNotEmpty()) {
            $live = MpesaPaymentSetting::withoutGlobalScopes()
                ->where('sacco_id', $saccoId)->where('is_live', true)->where('status', true)
                ->orderBy('id')->limit(2)->get();
            $fallback = $live->count() === 1 ? $live->first() : null;
        }

        /** @var array<int, array{connection: MpesaPaymentSetting, buses: int}> $byConnection */
        $byConnection = [];
        foreach ($groups as $g) {
            $connection = $connections->get((int) $g->mpesa_payment_setting_id) ?? $fallback;
            if ($connection === null) {
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
            }
        }

        return [$blocked, $waiting];
    }
}
