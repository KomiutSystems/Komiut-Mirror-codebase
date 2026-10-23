<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token, with last_used_at written at most every few minutes.
 *
 * Sanctum stamps last_used_at during authentication on EVERY request: a
 * synchronous UPDATE and WAL flush (~3ms) before the controller runs, and 1%
 * of the database's load. The column is still worth having -- it is the only
 * per-token activity trail when a token's use has to be investigated -- so
 * instead of switching it off, a stamp less than STALE_AFTER_MINUTES old is
 * left as it is. Any other change to a token saves exactly as before.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** The same granularity as TouchLastActive's users.last_active_at. */
    public const STALE_AFTER_MINUTES = 5;

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isOnlyAFreshLastUsedStamp()) {
            $this->setRawAttributes($this->getRawOriginal());   // keep the model as the row is

            return true;
        }

        return parent::save($options);
    }

    private function isOnlyAFreshLastUsedStamp(): bool
    {
        $dirty = array_keys($this->getDirty());
        if ($dirty === [] || array_diff($dirty, ['last_used_at', 'updated_at']) !== []) {
            return false;
        }

        $previous = $this->getOriginal('last_used_at');

        return $previous !== null && $previous->gt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
