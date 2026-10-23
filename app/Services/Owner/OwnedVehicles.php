<?php

declare(strict_types=1);

namespace App\Services\Owner;

use App\Auth\Roles;
use App\Models\Scopes\SaccoScope;
use App\Models\User;
use App\Models\VehicleUser;
use Illuminate\Support\Collection;

/**
 * Which buses a person owns, stated once.
 *
 * ScopesToOwnedVehicles answers this for the CURRENT request. The nightly
 * statement and the alert detectors need the same answer for a user they were
 * handed, with no session at all, so the rule lives here and the concern
 * delegates to it — two copies would drift, and the thing they decide is who
 * may see a bus's money.
 */
final class OwnedVehicles
{
    /**
     * Ownership lives in vehicle_users, never vehicles.user_id: that column
     * records whoever last saved the row, and at NICCO 168 of 180 point at the
     * migration account.
     *
     * @return array<int, int>
     */
    public static function forUser(User $user): array
    {
        return VehicleUser::query()
            // vehicle_users.sacco_id is nullable and predates being populated,
            // so scoping on it would silently drop a real investor's buses. The
            // boundary is taken from the authoritative side instead:
            // whereHas('vehicle') runs Vehicle's own Sacco/Brand/Financier
            // scopes, so an assignment pointing outside them cannot survive.
            ->withoutGlobalScope(SaccoScope::class)
            ->where('user_id', $user->id)
            ->where('status', true)
            ->whereNull('end_date')
            ->whereHas('vehicle')
            ->pluck('vehicle_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Everyone who holds the Investor role, for the scheduled sweeps. */
    public static function all(): Collection
    {
        return User::role(Roles::INVESTOR)->get();
    }
}
