<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A SACCO's takings, live on its web dashboard ("Collected today").
 *
 * The dashboard polled every 60 seconds; a fare now reaches it the moment it is
 * recorded, on the private channel `sacco.{id}` (event `takings.recorded`). The
 * 60-second poll stays as the backstop and the source of truth: the event only
 * says "refresh now".
 *
 * WHO MAY LISTEN: the SACCO's own office -- a user whose SACCO this is and who
 * may see its money (View Summaries or View Transactions, as the dashboard's
 * money cards require) -- and a super admin. Not a passenger or driver, and not
 * a bank: a bank sees only the buses it financed, and this channel carries the
 * whole SACCO.
 *
 * ONLY WHILE SOMEONE IS LOOKING. Around 40,000 fares a day land on the
 * platform; publishing each to a channel nobody has open would be work for the
 * socket server for nothing. Subscribing marks the SACCO watched, and a fare is
 * published only for a watched SACCO. The mark lasts WATCH_SECONDS and every
 * published fare extends it; a dashboard left open through a long quiet spell
 * is still covered by its poll until it reconnects and marks it again.
 */
final class SaccoLiveTakings
{
    /** An office tab is open all day; one subscription covers a working day. */
    public const WATCH_SECONDS = 43_200;

    /**
     * Only money that just arrived is announced. A backfill or an import of
     * last month's takings writes the same rows and must stay silent.
     */
    public const LIVE_WINDOW_MINUTES = 30;

    public static function mayListen(User $user, int $saccoId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->currentSaccoId() === $saccoId
            && ($user->can('View Summaries') || $user->can('View Transactions'));
    }

    public static function markWatched(int $saccoId): void
    {
        try {
            self::store()?->put(self::key($saccoId), true, self::WATCH_SECONDS);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Fails OPEN: when the store cannot say, the fare is published. */
    public static function isWatched(int $saccoId): bool
    {
        $store = self::store();
        if ($store === null) {
            return true;
        }

        try {
            return $store->touch(self::key($saccoId), self::WATCH_SECONDS);
        } catch (Throwable $e) {
            report($e);

            return true;
        }
    }

    private static function key(int $saccoId): string
    {
        return 'sacco-takings-watched:'.$saccoId;
    }

    /**
     * The store every brand and host shares (see SavedBuses::watchStore): the
     * office subscribes through one brand's host while fares are recorded under
     * whichever brand delivered them.
     */
    private static function store(): ?CacheRepository
    {
        return match (config('cache.default')) {
            'redis' => Cache::store('shared'),
            'array' => Cache::store('array'),
            default => null,
        };
    }
}
