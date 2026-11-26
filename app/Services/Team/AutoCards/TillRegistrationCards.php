<?php

declare(strict_types=1);

namespace App\Services\Team\AutoCards;

use App\Models\Sacco;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;

/**
 * A card per bus whose till registration ended somewhere a person has to act.
 * Until a till is registered Safaricom delivers its fares to nobody (see
 * TillRegistrar), so a stuck one is money going unrecorded.
 *
 * WHICH STATUSES. Per-bus failures only:
 *   refused, bad_keys, gave_up, already_registered -- Safaricom answered, or
 *     we stopped asking, and nothing will move until someone looks;
 *   needs_head_office, confirm_head_office -- the bus needs a head office
 *     chosen for it, which only a person can do (a wrong guess is permanent).
 * NOT here:
 *   no_keys, not_live -- the head office is missing its keys or is on the
 *     test system. That is one fix for every bus under it, so it is one
 *     head-office card for the SACCO (HeadOfficeCards), not thirty bus cards;
 *   pending, unreachable -- still in flight; the card, if any, is left as it
 *     is until the outcome lands (so a person pressing Register till does
 *     not close and reopen it);
 *   registered, bank_issued, not_ours, no_short_code -- nothing to do, and
 *     any open card closes, saying which.
 */
class TillRegistrationCards extends AutoCardSource
{
    public const FAILURES = [
        TillRegistrar::REFUSED,
        TillRegistrar::BAD_KEYS,
        TillRegistrar::GAVE_UP,
        TillRegistrar::ALREADY_REGISTERED,
        TillRegistrar::NEEDS_HEAD_OFFICE,
        TillRegistrar::CONFIRM_HEAD_OFFICE,
    ];

    /** Still being worked on by the registrar: leave the card alone. */
    public const IN_FLIGHT = [TillRegistrar::PENDING, TillRegistrar::UNREACHABLE];

    /** The short reason in a card title. */
    private const REASONS = [
        TillRegistrar::REFUSED => 'Safaricom refused it',
        TillRegistrar::BAD_KEYS => 'head office keys rejected',
        TillRegistrar::GAVE_UP => 'Safaricom unreachable, gave up',
        TillRegistrar::ALREADY_REGISTERED => 'registered somewhere else',
        TillRegistrar::NEEDS_HEAD_OFFICE => 'head office not chosen',
        TillRegistrar::CONFIRM_HEAD_OFFICE => 'confirm the head office',
    ];

    /** Columns every decision here reads. */
    private const COLUMNS = ['id', 'plate', 'sacco_id', 'till_number', 'merchant_short_code', 'till_registration_status', 'till_registration_note'];

    public function source(): string
    {
        return 'till_registration';
    }

    /**
     * Bring one bus's card in line with its till status. $event: a status
     * change just happened (not the sweep), so an already-open card is told.
     */
    public function sync(int $vehicleId, bool $event = true): void
    {
        $vehicle = Vehicle::withoutGlobalScopes()->find($vehicleId, self::COLUMNS);
        // The SACCO's name only goes in a card being raised.
        $sacco = $vehicle?->sacco_id === null || ! in_array($vehicle->till_registration_status, self::FAILURES, true)
            ? null
            : Sacco::withoutGlobalScopes()->find($vehicle->sacco_id, ['id', 'name']);

        $this->apply($vehicleId, $vehicle, $sacco, sweep: ! $event);
    }

    public function sweep(): array
    {
        $opened = 0;
        $closed = 0;
        $open = $this->openCards('vehicle');

        // Missing cards: every bus in a failure state without one. The partial
        // index on vehicles holds exactly these rows.
        Vehicle::withoutGlobalScopes()
            ->whereIn('till_registration_status', self::FAILURES)
            ->select(self::COLUMNS)
            ->chunkById(500, function ($buses) use ($open, &$opened): bool {
                $missing = $buses->reject(fn (Vehicle $v) => $open->has((int) $v->id));
                $saccos = $this->saccos($missing->pluck('sacco_id')->all());

                foreach ($missing as $bus) {
                    if ($opened >= self::SWEEP_OPEN_LIMIT) {
                        return false;
                    }
                    if ($this->apply((int) $bus->id, $bus, $saccos[(int) $bus->sacco_id] ?? null, sweep: true)) {
                        $opened++;
                    }
                }

                return true;
            });

        // Cards whose bus is no longer failing (or is gone).
        foreach ($open->keys()->chunk(500) as $ids) {
            $buses = Vehicle::withoutGlobalScopes()->whereIn('id', $ids->all())->get(self::COLUMNS)->keyBy('id');
            foreach ($ids as $id) {
                $bus = $buses->get($id);
                if ($bus !== null && (in_array($bus->till_registration_status, self::FAILURES, true) || in_array($bus->till_registration_status, self::IN_FLIGHT, true))) {
                    continue;
                }
                if ($this->apply((int) $id, $bus, null, sweep: true)) {
                    $closed++;
                }
            }
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    /** Returns whether a card was opened or closed. */
    private function apply(int $vehicleId, ?Vehicle $bus, ?Sacco $sacco, bool $sweep): bool
    {
        if ($bus === null) {
            return $this->resolve('vehicle', $vehicleId, 'the bus was deleted.');
        }

        $status = $bus->till_registration_status;

        if (in_array($status, self::IN_FLIGHT, true)) {
            return false;
        }

        if (! in_array($status, self::FAILURES, true)) {
            return $this->resolve('vehicle', $vehicleId, $this->why($bus));
        }

        $till = filled($bus->till_number) ? (string) $bus->till_number : (string) $bus->merchant_short_code;
        $reason = self::REASONS[$status];

        $card = $this->raise(
            'vehicle',
            $vehicleId,
            $bus->plate.' · till '.($till !== '' ? $till : '?').' · '.$reason,
            $this->describe($bus, $sacco),
            in_array($status, [TillRegistrar::NEEDS_HEAD_OFFICE, TillRegistrar::CONFIRM_HEAD_OFFICE], true) ? 'normal' : 'high',
            $sweep,
            again: 'Registration ran again: '.$reason.'. '.($bus->till_registration_note ?? ''),
        );

        return $card !== null && $card->wasRecentlyCreated;
    }

    private function describe(Vehicle $bus, ?Sacco $sacco): string
    {
        $lines = [];
        if ($sacco !== null) {
            $lines[] = 'SACCO: '.$sacco->name;
        }
        $lines[] = 'Store number: '.(filled($bus->merchant_short_code) ? $bus->merchant_short_code : 'none');
        // The registrar's own words; for a refusal, Safaricom's.
        $lines[] = 'Status: '.$bus->till_registration_status.' -- '.($bus->till_registration_note ?? TillRegistrar::MESSAGES[$bus->till_registration_status] ?? '');
        $lines[] = 'Until this till is registered, fares paid to it are not recorded. This card closes itself once it is.';

        return implode("\n", $lines);
    }

    /** Why the card is no longer needed, as the closing comment says it. */
    private function why(Vehicle $bus): string
    {
        return match ($bus->till_registration_status) {
            TillRegistrar::REGISTERED => 'the till is registered with Safaricom now.',
            TillRegistrar::BANK_ISSUED => 'the bank issued this till and delivers its payments, so it is no longer ours to register.',
            TillRegistrar::NOT_OURS => 'this till\'s payments are delivered by its bank, so it is no longer ours to register.',
            TillRegistrar::NO_SHORT_CODE => 'the bus no longer has a till (its store number was removed).',
            TillRegistrar::NO_KEYS, TillRegistrar::NOT_LIVE => 'it now waits on its head office\'s keys -- that is the head office card for the SACCO.',
            null => 'the bus no longer has a till registration to follow.',
            default => 'the till is now '.$bus->till_registration_status.'.',
        };
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, Sacco>
     */
    private function saccos(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : Sacco::withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id')->all();
    }
}
