<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\Sql\PlateSql;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A session opened with a phone number and a NUMBER PLATE.
 *
 * A plate is painted on the side of the bus, so whoever holds such a session
 * is treated as that bus's crew and nothing more: confined to that one bus's
 * money (ScopesToOwnedVehicles) and to the routes the crew app calls
 * (CrewSessionLimits) -- whatever other roles the account holds. An
 * owner-driver who is also an Investor, or a conductor promoted to Queue
 * Supervisor, sees their investor or stage screens only after signing in with
 * their PASSWORD.
 *
 * Every plate session is a Sanctum token named "driver-<plate>" (driver/login,
 * and mobile/login's crew half, which also marks it with the `crew` ability).
 */
final class CrewSession
{
    /** The plate this session was opened with; null when it is not a plate session. */
    public static function plate(?Authenticatable $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $name = is_string($token->name ?? null) ? $token->name : '';
        $abilities = is_array($token->abilities ?? null) ? $token->abilities : [];
        // Password sessions are named "<firstname>-AuthToken" (TokenPair), so
        // an account whose first name is "driver" must not read as a plate.
        $crew = (str_starts_with($name, 'driver-') && ! str_ends_with($name, '-AuthToken'))
            || (in_array('crew', $abilities, true) && ! in_array('*', $abilities, true));

        return $crew ? substr($name, strlen('driver-')) : null;
    }

    public static function is(?Authenticatable $user): bool
    {
        return self::plate($user) !== null;
    }

    /** The one bus a plate session may see; null when it cannot be told. */
    public static function vehicleId(?Authenticatable $user): ?int
    {
        $plate = PlateSql::normalise((string) self::plate($user));
        if ($plate === '') {
            return null;
        }

        $id = Vehicle::withoutGlobalScopes()
            ->whereRaw(PlateSql::normaliseColumn('plate').' = ?', [$plate])
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
