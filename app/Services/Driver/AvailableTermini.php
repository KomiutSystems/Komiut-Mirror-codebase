<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Models\Route;
use App\Models\SaccoRoute;
use App\Models\Terminus;
use App\Models\SaccoTerminus;
use App\Models\TerminusUser;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The stages a driver may queue at.
 *
 * The driver app reads `termini` from POST /user and that list is the ONLY
 * source for its terminus picker — an empty list means the driver can never
 * join a queue, so no trip, no bookings and no fare. It came straight from
 * `terminus_users`, a per-driver assignment table that the new system never
 * writes to: 450 crew assignments in Frankfurt and ZERO terminus rows. Every
 * driver would have opened the app to an empty picker.
 *
 * Same failure shape as the empty TerminusSeeder and the SACCO-scoped
 * ExpenseFee before it: a reference table nobody populates, and a screen that
 * silently offers nothing rather than erroring.
 *
 * Three sources, most specific first, so this improves on its own as the data
 * arrives rather than needing to be revisited:
 *
 *   1. The driver's OWN terminus_users rows. Legacy assignments still win — if
 *      somebody has deliberately pinned a driver to a stage, honour it.
 *   2. The origins of their SACCO's routes. Where a matatu's routes start IS
 *      where its driver queues. Needs `sacco_routes`, which is currently empty
 *      in Frankfurt pending the reference-data migration.
 *   3. Every active terminus. Not ideal, but a driver picking from a slightly
 *      long list can work; a driver picking from an empty one cannot.
 */
class AvailableTermini
{
    /**
     * Shaped like the TerminusUser rows this replaces — `[{terminus: {...}}]` —
     * because the app reads `termini[].terminus.id`. Changing the envelope would
     * break the picker just as thoroughly as leaving it empty.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forDriver(User $driver): Collection
    {
        $assigned = TerminusUser::with('terminus.place')
            ->where('user_id', $driver->id)
            ->where('status', true)
            ->get();

        if ($assigned->isNotEmpty()) {
            return $assigned->map(fn (TerminusUser $row) => ['terminus' => $row->terminus])
                ->filter(fn (array $row) => $row['terminus'] !== null)
                ->values();
        }

        return $this->wrap($this->saccoTermini($driver) ?? $this->allTermini());
    }

    /**
     * The stages this driver's SACCO works out of, or null when nothing usable
     * narrows the list.
     *
     * CONFIGURATION FIRST. `sacco_termini` is the SACCO's own answer to "which
     * stages are ours", and it is what the dashboard's terminus screen has
     * always read. This service ignored it entirely and re-derived the list from
     * route ORIGINS, so the two surfaces disagreed: a SACCO could link a
     * terminus at Thika, see it on the dashboard, and find the driver's picker
     * still offering only the origins. Deriving is the FALLBACK, for a SACCO
     * that has not configured anything yet — not the primary answer.
     *
     * @return Collection<int, Terminus>|null
     */
    private function saccoTermini(User $driver): ?Collection
    {
        if ($driver->sacco_id === null) {
            return null;
        }

        $linked = Terminus::with('place')
            ->whereIn('id', SaccoTerminus::withoutGlobalScopes()
                ->where('sacco_id', $driver->sacco_id)->select('terminus_id'))
            ->orderBy('name')
            ->get();

        // An explicit link is an explicit answer, so status is not filtered
        // here — the same rule TerminusAPIController applies to its tier 1.
        if ($linked->isNotEmpty()) {
            return $linked;
        }

        $routeIds = SaccoRoute::withoutGlobalScopes()
            ->where('sacco_id', $driver->sacco_id)
            ->where('status', true)
            ->pluck('route_id');

        if ($routeIds->isEmpty()) {
            return null;
        }

        $origins = Route::withoutGlobalScopes()
            ->whereIn('id', $routeIds)
            ->pluck('from_id')
            ->filter()
            ->unique();

        if ($origins->isEmpty()) {
            return null;
        }

        $termini = Terminus::with('place')
            ->whereIn('place_id', $origins)
            ->where('status', true)
            ->orderBy('name')
            ->get();

        // An empty result here is a real answer ("this SACCO's origins have no
        // terminus"), but it is not a USABLE one, so fall through rather than
        // hand the driver an empty picker.
        return $termini->isEmpty() ? null : $termini;
    }

    /** @return Collection<int, Terminus> */
    private function allTermini(): Collection
    {
        return Terminus::with('place')->where('status', true)->orderBy('name')->get();
    }

    /**
     * @param  Collection<int, Terminus>  $termini
     * @return Collection<int, array<string, mixed>>
     */
    private function wrap(Collection $termini): Collection
    {
        return $termini->map(fn (Terminus $t) => ['terminus' => $t])->values();
    }
}
