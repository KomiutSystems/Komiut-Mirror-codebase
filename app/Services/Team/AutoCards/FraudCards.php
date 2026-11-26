<?php

declare(strict_types=1);

namespace App\Services\Team\AutoCards;

use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\Team\TeamCard;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A card per bus or SACCO with an open fraud flag in the console's
 * notification centre (PlatformNotification, raised by PlatformNotifier).
 *
 * WHICH FLAGS. The ones that point at a bus or a SACCO somebody can go and
 * look at, and that say "someone may be cheating":
 *   driver.reassigned.rapid       one driver attached to several buses
 *   driver.login.failed_burst     password-guessing on a plate
 *   driver.login.suspicious_success  ...and then a login that got in
 *   driver.id_number.duplicate    one national ID on several driver accounts
 *   onboarding.velocity.spike     a burst of sign-ups from one origin
 *   access.login.failed_burst     password-guessing on a SACCO admin account
 *   vehicle.till.duplicate        two buses collecting into one till
 *   vehicle.plate.cross_sacco     one plate registered under two SACCOs
 *   loyalty.redemption.spike      points being drained at one SACCO
 * Left in the notification centre only:
 *   payment.callback.refused      a callback from an unknown IP: names a
 *                                 source address, no bus or SACCO to link;
 *   vehicle.payment_details.changed  fires on every legitimate till edit --
 *                                 an audit trail, not a suspicion;
 *   partner.*, the other access.* events, ledger and reconciliation alerts
 *                                 -- bank and staff accounts or money
 *                                 integrity, not fraud on a bus or SACCO.
 *
 * WHAT A FLAG LINKS TO, in order: the bus it is about (its subject or
 * data.vehicleId); for a successful login after a burst, the bus the burst
 * was on; the first bus a driver was attached to (the list only grows, so
 * the first stays put as the flag ticks); the SACCO it is about; the first
 * SACCO it names; the SACCO of the driver or admin it is about. A flag with
 * none of these stays in the notification centre.
 *
 * ONE CARD, MANY FLAGS. Different flags on one bus share its card; a new
 * one adds a comment. The card closes when every open flag that links to
 * that bus or SACCO is resolved -- never on the first of several.
 *
 * Only flags raised in the last OPEN_WITHIN_DAYS open cards: an unresolved
 * flag from months ago is history the notification centre already holds,
 * and the board is for live work.
 */
class FraudCards extends AutoCardSource
{
    public const EVENTS = [
        'driver.reassigned.rapid',
        'driver.login.failed_burst',
        'driver.login.suspicious_success',
        'driver.id_number.duplicate',
        'onboarding.velocity.spike',
        'access.login.failed_burst',
        'vehicle.till.duplicate',
        'vehicle.plate.cross_sacco',
        'loyalty.redemption.spike',
    ];

    public const OPEN_WITHIN_DAYS = 14;

    /** How many open flags are read to decide a close; past it, nothing is closed. */
    private const OPEN_FLAGS_LIMIT = 1000;

    /** @var array<int, ?int> users.id => sacco_id, for this run */
    private array $userSaccos = [];

    /** @var array<string, ?int> plate => vehicle id of its latest burst, for this run */
    private array $burstVehicles = [];

    public function source(): string
    {
        return 'fraud';
    }

    public static function watches(?string $event): bool
    {
        return in_array($event, self::EVENTS, true);
    }

    /**
     * One flag was raised or resolved. $new: a fresh flag (not a repeat that
     * only ticked its count), so an already-open card on the same thing is
     * told about it.
     */
    public function sync(int $notificationId, bool $new = false): void
    {
        $flag = PlatformNotification::query()->find($notificationId);
        if ($flag === null || ! self::watches($flag->event)) {
            return;
        }

        $link = $this->linkFor($flag);
        if ($link === null) {
            return;
        }

        if ($flag->resolved_at === null) {
            if ($flag->occurred_at !== null && $flag->occurred_at->lt(now()->subDays(self::OPEN_WITHIN_DAYS))) {
                return;
            }
            $this->raiseFor($link, $flag, sweep: false, again: $new ? 'Also flagged: '.$flag->title.' -- '.$flag->summary : null);

            return;
        }

        $open = $this->openFlags();
        if ($open === null) {
            return;
        }
        $this->closeIfClear($link, $open);
    }

    public function sweep(): array
    {
        $opened = 0;
        $closed = 0;

        $flags = $this->openFlags();
        if ($flags === null) {
            Log::warning('team auto cards: more open fraud flags than one sweep reads; closing skipped', ['limit' => self::OPEN_FLAGS_LIMIT]);
        }

        $cards = $this->openCards('vehicle')->map(fn (TeamCard $c) => ['vehicle', (int) $c->link_id])->values()
            ->merge($this->openCards('sacco')->map(fn (TeamCard $c) => ['sacco', (int) $c->link_id])->values());
        $carded = $cards->mapWithKeys(fn (array $l) => [$l[0].':'.$l[1] => true]);

        // Missing cards: newest flag first, so a card describes the latest.
        $since = now()->subDays(self::OPEN_WITHIN_DAYS);
        foreach ($flags ?? $this->recentOpenFlags() as $flag) {
            if ($opened >= self::SWEEP_OPEN_LIMIT || $flag->occurred_at === null || $flag->occurred_at->lt($since)) {
                continue;
            }
            $link = $this->linkFor($flag);
            if ($link === null || isset($carded[$link[0].':'.$link[1]])) {
                continue;
            }
            $card = $this->raiseFor($link, $flag, sweep: true);
            $carded[$link[0].':'.$link[1]] = true;
            $opened += $card !== null && $card->wasRecentlyCreated ? 1 : 0;
        }

        if ($flags !== null) {
            foreach ($cards as $link) {
                $closed += $this->closeIfClear($link, $flags) ? 1 : 0;
            }
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    /** @param  array{0:string,1:int}  $link */
    private function raiseFor(array $link, PlatformNotification $flag, bool $sweep, ?string $again = null): ?TeamCard
    {
        [$type, $id] = $link;
        $label = $type === 'vehicle'
            ? (Vehicle::withoutGlobalScopes()->whereKey($id)->value('plate') ?? 'Bus #'.$id)
            : (Sacco::withoutGlobalScopes()->whereKey($id)->value('name') ?? 'SACCO #'.$id);

        return $this->raise(
            $type,
            $id,
            $label.' · '.$flag->title,
            implode("\n", [
                (string) $flag->summary,
                'Flag #'.$flag->id.' ('.$flag->event.'), raised '.$flag->occurred_at?->format('j M Y H:i').'.',
                'Resolve the flag in the notification centre once it is dealt with; this card closes itself when every open flag on this '.($type === 'vehicle' ? 'bus' : 'SACCO').' is resolved.',
            ]),
            match ($flag->severity) {
                'critical' => 'urgent',
                'high' => 'high',
                default => 'normal',
            },
            $sweep,
            $again,
        );
    }

    /**
     * Close the card on this thing if no open flag links to it any more.
     *
     * @param  array{0:string,1:int}  $link
     * @param  Collection<int, PlatformNotification>  $openFlags
     */
    private function closeIfClear(array $link, Collection $openFlags): bool
    {
        foreach ($openFlags as $flag) {
            if ($this->linkFor($flag) === $link) {
                return false;
            }
        }

        return $this->resolve($link[0], $link[1], 'every fraud flag on it is resolved in the notification centre.');
    }

    /**
     * Every unresolved flag this source watches, newest first -- or null when
     * there are more than one read holds, in which case "no open flag links
     * here" cannot be known and nothing may be closed on it. Served by the
     * partial index on platform_notifications (delivery_class, occurred_at)
     * WHERE resolved_at IS NULL.
     *
     * @return Collection<int, PlatformNotification>|null
     */
    private function openFlags(): ?Collection
    {
        $flags = PlatformNotification::query()
            ->whereNull('resolved_at')
            ->where('delivery_class', 'alert')
            ->whereIn('event', self::EVENTS)
            ->orderByDesc('occurred_at')
            ->limit(self::OPEN_FLAGS_LIMIT + 1)
            ->get(['id', 'event', 'severity', 'title', 'summary', 'subject', 'data', 'occurred_at', 'resolved_at']);

        return $flags->count() > self::OPEN_FLAGS_LIMIT ? null : $flags;
    }

    /** @return Collection<int, PlatformNotification> the recent ones, when the full set is too big to read */
    private function recentOpenFlags(): Collection
    {
        return PlatformNotification::query()
            ->whereNull('resolved_at')
            ->where('delivery_class', 'alert')
            ->whereIn('event', self::EVENTS)
            ->where('occurred_at', '>=', now()->subDays(self::OPEN_WITHIN_DAYS))
            ->orderByDesc('occurred_at')
            ->limit(self::OPEN_FLAGS_LIMIT)
            ->get(['id', 'event', 'severity', 'title', 'summary', 'subject', 'data', 'occurred_at', 'resolved_at']);
    }

    /**
     * The bus or SACCO a flag is about (see the class note for the order).
     *
     * @return array{0:'vehicle'|'sacco', 1:int}|null
     */
    public function linkFor(PlatformNotification $flag): ?array
    {
        $subject = is_array($flag->subject) ? $flag->subject : [];
        $data = is_array($flag->data) ? $flag->data : [];
        $subjectId = self::id($subject['id'] ?? null);

        if (($subject['type'] ?? null) === 'vehicle' && $subjectId !== null) {
            return ['vehicle', $subjectId];
        }
        if (($id = self::id($data['vehicleId'] ?? null)) !== null) {
            return ['vehicle', $id];
        }
        if ($flag->event === 'driver.login.suspicious_success' && ($id = $this->burstVehicle((string) ($data['plate'] ?? ''))) !== null) {
            return ['vehicle', $id];
        }
        if (($id = self::id(is_array($data['vehicleIds'] ?? null) ? ($data['vehicleIds'][0] ?? null) : null)) !== null) {
            return ['vehicle', $id];
        }
        if (($subject['type'] ?? null) === 'sacco' && $subjectId !== null) {
            return ['sacco', $subjectId];
        }
        if (($id = self::id($data['saccoId'] ?? null)) !== null) {
            return ['sacco', $id];
        }
        if (($id = self::id(is_array($data['saccoIds'] ?? null) ? ($data['saccoIds'][0] ?? null) : null)) !== null) {
            return ['sacco', $id];
        }

        $person = in_array($subject['type'] ?? null, ['driver', 'user'], true) ? $subjectId : null;
        $person ??= self::id($data['driverId'] ?? $data['userId'] ?? null);
        if ($person !== null && ($id = $this->userSacco($person)) !== null) {
            return ['sacco', $id];
        }

        return null;
    }

    /** The bus the latest failed-login burst on this plate was raised on. */
    private function burstVehicle(string $plate): ?int
    {
        if ($plate === '') {
            return null;
        }

        if (! array_key_exists($plate, $this->burstVehicles)) {
            // dedupe_key is indexed (with resolved_at); the burst's own key.
            $data = PlatformNotification::query()
                ->where('dedupe_key', 'driver.login.failed_burst:'.$plate)
                ->orderByDesc('id')
                ->value('data');
            $data = is_string($data) ? json_decode($data, true) : $data;
            $this->burstVehicles[$plate] = self::id(is_array($data) ? ($data['vehicleId'] ?? null) : null);
        }

        return $this->burstVehicles[$plate];
    }

    private function userSacco(int $userId): ?int
    {
        if (! array_key_exists($userId, $this->userSaccos)) {
            $this->userSaccos[$userId] = self::id(User::withoutGlobalScopes()->whereKey($userId)->value('sacco_id'));
        }

        return $this->userSaccos[$userId];
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
