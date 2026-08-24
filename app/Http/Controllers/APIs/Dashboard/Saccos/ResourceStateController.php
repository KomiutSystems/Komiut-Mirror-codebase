<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Route;
use App\Models\Terminus;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Platform\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suspend or restore a SACCO's resources.
 *
 * The API had exactly ONE delete route across the whole surface
 * (saccos/fares/delete). Everything else was add-or-edit, so taking a vehicle
 * off the road, standing down a route or disabling a member meant re-submitting
 * the entire record through its "add" endpoint with status = 0 — undiscoverable,
 * and it silently overwrote every other field with whatever the form happened
 * to hold.
 *
 * These are SUSPENSIONS, not deletions, and deliberately so. A vehicle is
 * referenced by transactions and summaries, a route by queues, a member by
 * assignments: hard-deleting any of them either fails on a foreign key or
 * orphans money. `status` is the switch the rest of this codebase already reads,
 * so flipping it is what "remove from service" actually means here.
 *
 * Every change is audited — suspending a vehicle stops its takings appearing,
 * so somebody will eventually ask who did it and when.
 */
class ResourceStateController extends Controller
{
    /**
     * resource => [model, permission, human name, platform-level?]
     *
     * The fourth element is the tenancy answer for that model, and it is not
     * cosmetic. Only Vehicle carries BelongsToSacco here. Terminus, Place and
     * Route are SHARED platform records — `termini` has no sacco_id column at
     * all, and every SACCO queueing at Machakos Country Bus is looking at the
     * same row. Marked true, they are superadmin-only: a SACCO's Operations
     * Manager holds 'Edit Termini' legitimately (they add and edit termini), but
     * suspending one takes it away from all 48 SACCOs at once.
     */
    private const RESOURCES = [
        'vehicles' => [Vehicle::class, 'Edit Vehicles', 'Vehicle', false],
        'routes' => [Route::class, 'Edit Routes', 'Route', true],
        'places' => [Place::class, 'Edit Places', 'Place', true],
        'termini' => [Terminus::class, 'Edit Termini', 'Terminus', true],
        'members' => [User::class, 'Edit Sacco Members', 'Member', false],
    ];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Suspend or restore one resource
     *
     * `suspend=false` restores. The response reports the resulting state rather
     * than echoing the request, so a client that retries a suspension does not
     * have to guess whether the first attempt landed.
     */
    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        if (! isset(self::RESOURCES[$resource])) {
            return response()->json(['error' => 'Unknown resource.'], 404);
        }

        [$model, $permission, $label, $platformLevel] = self::RESOURCES[$resource];
        $actor = $request->user();

        if (! $actor->can($permission)) {
            return response()->json(['error' => "You do not have permission to change {$label} status."], 403);
        }

        // Shared records need the platform tier, not just the edit permission.
        // The permission answers "may you maintain termini/places/routes", which
        // a SACCO's Operations Manager may. It does NOT answer "may you take one
        // out of service for everybody", and there is no tenancy to fall back on:
        // suspending Machakos Country Bus stands down every SACCO that queues
        // there. Kept as a suspension right, moved to the tier that owns the
        // shared record.
        if ($platformLevel && ! $actor->isSuperAdmin()) {
            return response()->json([
                'error' => "{$label} records are shared across SACCOs; only a platform administrator can change their status.",
            ], 403);
        }

        // Global scopes stay ON, but they are only PART of the boundary — the
        // previous comment here claimed they were all of it, which was false for
        // four of the five resources.
        //
        //   vehicles — Vehicle has BelongsToSacco, so find() really is the
        //              boundary: another SACCO's vehicle resolves to null.
        //   routes / places / termini — no tenancy trait, and `termini` has no
        //              sacco_id column to add one to. Handled by the
        //              platform-level gate above, not by find().
        //   members  — User has NO tenancy trait either (it is the model the
        //              scope reads its sacco FROM), so find() would happily
        //              return another SACCO's staff member. Confined below.
        $row = $model::find($id);
        if ($row === null) {
            return response()->json(['error' => "{$label} not found."], 404);
        }

        // A member is only yours to suspend if they are in your SACCO. Without
        // this any SACCO admin holding 'Edit Sacco Members' could disable any
        // account on the platform by id — including another SACCO's admin.
        // Reported as 404, matching what SaccoScope does for a scoped model:
        // "not found" rather than "found, but not yours".
        if ($resource === 'members' && ! $this->ownsMember($actor, $row)) {
            return response()->json(['error' => "{$label} not found."], 404);
        }

        // A member is a person, and suspending yourself locks you out of the
        // dashboard you would need to undo it.
        if ($resource === 'members' && (int) $row->id === (int) $actor->id) {
            return response()->json(['error' => 'You cannot suspend your own account.'], 422);
        }

        $suspend = $request->boolean('suspend', true);
        $row->status = ! $suspend;
        $row->save();

        AuditLogger::record(
            action: $suspend ? 'sacco.resource.suspended' : 'sacco.resource.restored',
            data: [
                'resource' => $resource,
                'id' => (int) $row->id,
                'label' => $row->plate ?? $row->name ?? ($row->firstname ?? null),
            ],
            subject: ['type' => $resource, 'id' => (string) $row->id],
            saccoId: $actor->currentSaccoId() !== null ? (int) $actor->currentSaccoId() : null,
        );

        return response()->json([
            'success' => $suspend ? "{$label} suspended." : "{$label} restored.",
            'resource' => $resource,
            'id' => (int) $row->id,
            'status' => (bool) $row->status,
        ]);
    }

    /**
     * Is this member in the actor's own SACCO? A superadmin sits above the
     * boundary; a saccoless non-super owns nobody, so they fail closed here
     * rather than matching every other saccoless account.
     */
    private function ownsMember(User $actor, User $member): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        $own = $actor->currentSaccoId();

        return $own !== null && (int) $member->sacco_id === (int) $own;
    }
}
