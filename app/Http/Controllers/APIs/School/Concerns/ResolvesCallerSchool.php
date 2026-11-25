<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\School\Concerns;

use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The school a school-admin request acts for, and the bus it is about.
 *
 * Two walls, checked in this order so that neither answer says anything about
 * the other:
 *
 *   1. The caller's own SACCO must be a school, or every request is 403 --
 *      decided before any vehicle is looked at, so a matatu SACCO's admin
 *      learns nothing about which ids are school buses.
 *   2. The bus must be in THAT school, or it is 404 -- the same answer for a
 *      bus that does not exist, one in another school and one in a SACCO.
 *
 * The bus is confined explicitly by `vehicles.sacco_id`, not only by
 * SaccoScope: Vehicle opts into cross-tenant browsing for the passenger's
 * bus-finder, and these endpoints hand out the follow code, which is what
 * lets a stranger see where a bus full of children is.
 *
 * A SUPERADMIN has no school of their own. They act for the school a bus is
 * in (any school bus in the current brand), and on the list pass `school`
 * to say whose buses they mean; without it their own SACCO is used, and if
 * that is not a school the answer is the same 403 anyone else gets.
 */
trait ResolvesCallerSchool
{
    protected function notASchool(): JsonResponse
    {
        return response()->json(['error' => 'This is for school accounts.'], 403);
    }

    protected function busNotFound(): JsonResponse
    {
        return response()->json(['error' => 'No such bus in your school.'], 404);
    }

    /** The school the caller acts for on a list, or null (answer notASchool()). */
    protected function callerSchool(Request $request): ?Sacco
    {
        /** @var User $user */
        $user = $request->user();

        $id = $user->isSuperAdmin() && $request->filled('school')
            ? (int) $request->query('school')
            : $user->currentSaccoId();

        if ($id === null || $id <= 0) {
            return null;
        }

        $sacco = Sacco::withoutGlobalScopes()->find($id, ['id', 'name', 'kind']);

        return $sacco !== null && $sacco->isSchool() ? $sacco : null;
    }

    /**
     * The school bus this request is about, with its school loaded, or the
     * 403 / 404 to answer with.
     */
    protected function schoolBus(Request $request, int $vehicleId): Vehicle|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            // Vehicle::query() keeps BrandScope for a superadmin: the brand's
            // school buses, never the other brand's.
            $bus = Vehicle::query()->whereKey($vehicleId)->first(['id', 'plate', 'fleet_no', 'sacco_id', 'follow_code']);
            $school = $bus?->sacco_id === null ? null : Sacco::withoutGlobalScopes()->find($bus->sacco_id, ['id', 'name', 'kind']);

            if ($bus === null || $school === null || ! $school->isSchool()) {
                return $this->busNotFound();
            }

            return $bus->setRelation('sacco', $school);
        }

        $school = $this->callerSchool($request);
        if ($school === null) {
            return $this->notASchool();
        }

        $bus = Vehicle::query()
            ->whereKey($vehicleId)
            ->where('vehicles.sacco_id', $school->id)
            ->first(['id', 'plate', 'fleet_no', 'sacco_id', 'follow_code']);

        return $bus === null ? $this->busNotFound() : $bus->setRelation('sacco', $school);
    }
}
