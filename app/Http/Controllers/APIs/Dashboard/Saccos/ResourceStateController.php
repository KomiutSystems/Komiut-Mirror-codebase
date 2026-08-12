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
    /** resource => [model, permission, human name] */
    private const RESOURCES = [
        'vehicles' => [Vehicle::class, 'Edit Vehicles', 'Vehicle'],
        'routes' => [Route::class, 'Edit Routes', 'Route'],
        'places' => [Place::class, 'Edit Places', 'Place'],
        'termini' => [Terminus::class, 'Edit Termini', 'Terminus'],
        'members' => [User::class, 'Edit Sacco Members', 'Member'],
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

        [$model, $permission, $label] = self::RESOURCES[$resource];
        $actor = $request->user();

        if (! $actor->can($permission)) {
            return response()->json(['error' => "You do not have permission to change {$label} status."], 403);
        }

        // Global scopes stay ON: SaccoScope is what stops a SACCO admin
        // suspending another SACCO's vehicle, and find() honouring it is the
        // whole tenant boundary here.
        $row = $model::find($id);
        if ($row === null) {
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
}
