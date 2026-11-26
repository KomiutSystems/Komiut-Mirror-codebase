<?php

declare(strict_types=1);

namespace App\Services\Team\AutoCards;

use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\Team\TeamCard;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Driver\VehicleAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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
 * was on; the lowest-numbered bus it names; the SACCO it is about; the
 * lowest-numbered SACCO it names; the SACCO of the driver or admin it is
 * about. A flag with none of these stays in the notification centre. The
 * lowest, not the first: a repeat rewrites a flag's data, and a list built
 * from an unordered query can come back in another order.
 *
 * ONE CARD, MANY FLAGS. Different flags on one bus share its card; a new
 * one adds a comment. A flag that names a bus or SACCO which already has an
 * open card joins that card rather than opening a second one elsewhere.
 *
 * CLOSING. A card closes when no open flag NAMES its bus or SACCO any more --
 * any bus or SACCO in the flag, not only the one it links to, so a flag whose
 * link moved (its data rewritten by a repeat, its driver moved by a merge)
 * never lets a card close claiming every flag on it is resolved. That is one
 * indexed query over the open flags that could name this one card (see
 * stillFlagged), never a read of every open flag: a backlog of old unresolved
 * flags elsewhere must not stop cards closing, nor make a Resolve click slow.
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

    /** Flags one sweep reads to open missing cards: newest first, the rest wait an hour. */
    private const SWEEP_FLAGS_LIMIT = 1000;

    /** A flag naming more buses or SACCOs than this is a list, not a lead; the rest are not followed. */
    private const TARGETS_LIMIT = 50;

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

        $targets = $this->targets($flag);
        if ($targets === []) {
            return;
        }

        if ($flag->resolved_at === null) {
            if ($flag->occurred_at !== null && $flag->occurred_at->lt(now()->subDays(self::OPEN_WITHIN_DAYS))) {
                return;
            }
            $link = $this->openAmong($targets)[0] ?? $targets[0];
            $this->raiseFor($link, $flag, sweep: false, again: $new ? 'Also flagged: '.$flag->title.' -- '.$flag->summary : null);

            return;
        }

        // Resolved: every open card this flag could have been keeping open.
        foreach ($this->openAmong($targets) as $link) {
            $this->closeIfClear($link);
        }
    }

    public function sweep(): array
    {
        $opened = 0;
        $closed = 0;

        // toBase: an Eloquent collection cannot merge plain arrays (an empty
        // one stays Eloquent after map).
        $cards = $this->openCards('vehicle')->toBase()->map(fn (TeamCard $c) => ['vehicle', (int) $c->link_id])->values()
            ->merge($this->openCards('sacco')->toBase()->map(fn (TeamCard $c) => ['sacco', (int) $c->link_id])->values());
        $carded = $cards->mapWithKeys(fn (array $l) => [$l[0].':'.$l[1] => true])->all();

        // Missing cards, one per thing: described by its newest flag, and
        // dated by the newest problem on it (the latest first occurrence), so
        // a person's Done is only respected for flags that were there then.
        $missing = [];
        foreach ($this->recentOpenFlags() as $flag) {
            $targets = $this->targets($flag);
            if ($targets === [] || array_filter($targets, fn (array $t) => isset($carded[$t[0].':'.$t[1]])) !== []) {
                continue;
            }
            $key = $targets[0][0].':'.$targets[0][1];
            $missing[$key] ??= ['link' => $targets[0], 'flag' => $flag, 'since' => null];
            $started = $flag->first_occurred_at ?? $flag->occurred_at;
            if ($started !== null && ($missing[$key]['since'] === null || $started->gt($missing[$key]['since']))) {
                $missing[$key]['since'] = $started;
            }
        }
        foreach ($missing as ['link' => $link, 'flag' => $flag, 'since' => $since]) {
            if ($opened >= self::SWEEP_OPEN_LIMIT) {
                break;
            }
            $card = $this->raiseFor($link, $flag, sweep: true, since: $since);
            $opened += $card !== null && $card->wasRecentlyCreated ? 1 : 0;
        }

        foreach ($cards as $link) {
            $closed += $this->closeIfClear($link) ? 1 : 0;
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    /** @param  array{0:string,1:int}  $link */
    private function raiseFor(array $link, PlatformNotification $flag, bool $sweep, ?string $again = null, ?CarbonInterface $since = null): ?TeamCard
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
            $since,
        );
    }

    /**
     * Close the card on this thing if no open flag names it any more.
     *
     * @param  array{0:string,1:int}  $link
     */
    private function closeIfClear(array $link): bool
    {
        if ($this->stillFlagged($link)) {
            return false;
        }

        [$type, $id] = $link;
        $why = 'every fraud flag on it is resolved in the notification centre.';
        if ($type === 'sacco') {
            $status = Sacco::withoutGlobalScopes()->whereKey($id)->value('status');
            // A merge moves the SACCO's people with a bulk update: a flag
            // about one of them now names their new SACCO, not this one.
            $why = match (true) {
                $status === null => 'the SACCO was deleted.',
                (int) $status !== 1 => 'the SACCO was deactivated (rejected, or merged into another). Any flag still open about its people now sits with their new SACCO.',
                default => $why,
            };
        } elseif (! Vehicle::withoutGlobalScopes()->whereKey($id)->exists()) {
            $why = 'the bus was deleted.';
        }

        return $this->resolve($type, $id, $why);
    }

    /**
     * Is any open flag naming this bus or SACCO -- anywhere linkFor or
     * targets would find it? Asked in SQL, one card at a time, so the answer
     * never depends on how many open flags there are elsewhere. Served by the
     * partial index on open watched flags (2026_11_26_160200); each branch
     * is a test on the row in hand, plus a primary-key lookup on users for
     * a flag about a person, or a dedupe_key lookup for a login after a burst.
     *
     * @param  array{0:string,1:int}  $link
     */
    private function stillFlagged(array $link): bool
    {
        [$type, $id] = $link;
        $person = "coalesce(case when platform_notifications.subject->>'type' in ('driver', 'user') then platform_notifications.subject->>'id' end, platform_notifications.data->>'driverId', platform_notifications.data->>'userId')";

        return PlatformNotification::query()
            ->whereNull('resolved_at')
            ->where('delivery_class', 'alert')
            ->whereIn('event', self::EVENTS)
            ->where(function ($q) use ($type, $id, $person): void {
                $key = $type === 'vehicle' ? 'vehicleId' : 'saccoId';
                $q->whereRaw("(subject->>'type' = ? and subject->>'id' = ?)", [$type, (string) $id])
                    ->orWhereRaw("data->>'{$key}' = ?", [(string) $id])
                    ->orWhereRaw("(data->'{$key}s')::jsonb @> ?::jsonb", [(string) $id]);

                if ($type === 'vehicle') {
                    $plate = (string) Vehicle::withoutGlobalScopes()->whereKey($id)->value('plate');
                    // A login that got in after a burst on this bus: by plate,
                    // or by the burst its plate points at (as burstVehicle).
                    $q->orWhereRaw(
                        "(event = 'driver.login.suspicious_success' and (data->>'plate' = ? or (select b.data->>'vehicleId' from platform_notifications b where b.dedupe_key = 'driver.login.failed_burst:' || (platform_notifications.data->>'plate') order by b.id desc limit 1) = ?))",
                        [$plate === '' ? null : VehicleAssignment::normalisePlate($plate), (string) $id],
                    );
                } else {
                    $q->orWhereRaw(
                        "exists (select 1 from users u where u.sacco_id = ? and u.id = (case when {$person} ~ '^[0-9]{1,18}$' then ({$person})::bigint end))",
                        [$id],
                    );
                }
            })
            ->exists();
    }

    /**
     * The open cards of this source among these things, in their order.
     *
     * @param  list<array{0:string,1:int}>  $targets
     * @return list<array{0:string,1:int}>
     */
    private function openAmong(array $targets): array
    {
        $byType = [];
        foreach ($targets as [$type, $id]) {
            $byType[$type][] = $id;
        }

        $open = TeamCard::query()
            ->where('source', $this->source())->where('status', '!=', 'done')
            ->where(function ($q) use ($byType): void {
                foreach ($byType as $type => $ids) {
                    $q->orWhere(fn ($q) => $q->where('link_type', $type)->whereIn('link_id', $ids));
                }
            })
            ->get(['link_type', 'link_id'])
            ->mapWithKeys(fn (TeamCard $c) => [$c->link_type.':'.$c->link_id => true]);

        return array_values(array_filter($targets, fn (array $t) => $open->has($t[0].':'.$t[1])));
    }

    /** @return Collection<int, PlatformNotification> the open flags new enough to open a card, newest first */
    private function recentOpenFlags(): Collection
    {
        return PlatformNotification::query()
            ->whereNull('resolved_at')
            ->where('delivery_class', 'alert')
            ->whereIn('event', self::EVENTS)
            ->where('occurred_at', '>=', now()->subDays(self::OPEN_WITHIN_DAYS))
            ->orderByDesc('occurred_at')
            ->limit(self::SWEEP_FLAGS_LIMIT)
            ->get(['id', 'event', 'severity', 'title', 'summary', 'subject', 'data', 'first_occurred_at', 'occurred_at', 'resolved_at']);
    }

    /**
     * Every bus and SACCO a flag names, its link (linkFor) first. What a
     * card on any of them is kept open by (stillFlagged asks the same).
     *
     * @return list<array{0:'vehicle'|'sacco', 1:int}>
     */
    public function targets(PlatformNotification $flag): array
    {
        $link = $this->linkFor($flag);
        if ($link === null) {
            return [];
        }

        $subject = is_array($flag->subject) ? $flag->subject : [];
        $data = is_array($flag->data) ? $flag->data : [];
        $out = [$link[0].':'.$link[1] => $link];
        $add = function (string $type, mixed $value) use (&$out): void {
            if (count($out) < self::TARGETS_LIMIT && ($id = self::id($value)) !== null) {
                $out[$type.':'.$id] ??= [$type, $id];
            }
        };

        foreach (['vehicle', 'sacco'] as $type) {
            if (($subject['type'] ?? null) === $type) {
                $add($type, $subject['id'] ?? null);
            }
            $add($type, $data[$type.'Id'] ?? null);
            foreach (is_array($data[$type.'Ids'] ?? null) ? $data[$type.'Ids'] : [] as $value) {
                $add($type, $value);
            }
        }
        if (($person = $this->person($subject, $data)) !== null) {
            $add('sacco', $this->userSacco($person));
        }

        return array_values($out);
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
        if (($id = self::lowest($data['vehicleIds'] ?? null)) !== null) {
            return ['vehicle', $id];
        }
        if (($subject['type'] ?? null) === 'sacco' && $subjectId !== null) {
            return ['sacco', $subjectId];
        }
        if (($id = self::id($data['saccoId'] ?? null)) !== null) {
            return ['sacco', $id];
        }
        if (($id = self::lowest($data['saccoIds'] ?? null)) !== null) {
            return ['sacco', $id];
        }

        $person = $this->person($subject, $data);
        if ($person !== null && ($id = $this->userSacco($person)) !== null) {
            return ['sacco', $id];
        }

        return null;
    }

    /**
     * The driver or admin a flag is about, if any.
     *
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $data
     */
    private function person(array $subject, array $data): ?int
    {
        $person = in_array($subject['type'] ?? null, ['driver', 'user'], true) ? self::id($subject['id'] ?? null) : null;

        return $person ?? self::id($data['driverId'] ?? $data['userId'] ?? null);
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

    /** The lowest id in a list: the same whatever order the list was built in. */
    private static function lowest(mixed $values): ?int
    {
        $ids = is_array($values) ? array_filter(array_map(self::id(...), $values)) : [];

        return $ids === [] ? null : min($ids);
    }
}
