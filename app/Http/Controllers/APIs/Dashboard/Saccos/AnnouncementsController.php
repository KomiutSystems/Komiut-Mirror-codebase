<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverSaccoAnnouncement;
use App\Models\SaccoAnnouncement;
use App\Models\Vehicle;
use App\Services\Platform\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group SACCO — messages to the crew
 *
 * A SACCO had no way to reach its own drivers and conductors. The notification
 * machinery has always fanned one dispatch out to in-app, realtime and push,
 * but nothing on the SACCO side could trigger it — every domain notification
 * came from an event (a paid booking), never from a person. So "no service on
 * Mashujaa Day" or "the fuel levy changes Monday" travelled by WhatsApp group,
 * to whoever happened to be in it.
 *
 * The send is a queued fan-out, not an inline loop: a large SACCO is two
 * hundred drivers, each costing three channel jobs, and holding the admin's
 * request open for that would time out precisely the SACCOs that need it most.
 */
class AnnouncementsController extends Controller
{
    use PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Send an announcement to the crew
     *
     * Targets the whole SACCO by default. `vehicle_id` narrows it to one bus's
     * crew — the difference between "we are closed tomorrow" and "KDA 123X, go
     * to the garage".
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->can('Send Crew Announcements')) {
            return response()->json(['error' => 'You do not have permission to message the crew.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:2000',
            'vehicle_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $saccoId = $actor->sacco_id;
        if (! $saccoId) {
            return response()->json(['error' => 'Your account is not attached to a SACCO.'], 422);
        }

        // Global scopes stay ON, so find() is the tenant boundary: an admin
        // cannot message another SACCO's bus by guessing its id.
        $vehicleId = null;
        if ($request->filled('vehicle_id')) {
            $vehicle = Vehicle::find((int) $request->input('vehicle_id'));
            if ($vehicle === null) {
                return response()->json(['error' => 'Vehicle not found.'], 404);
            }
            $vehicleId = (int) $vehicle->id;
        }

        $announcement = SaccoAnnouncement::create([
            'sacco_id' => $saccoId,
            'user_id' => $actor->id,
            'vehicle_id' => $vehicleId,
            'title' => (string) $request->input('title'),
            'body' => (string) $request->input('body'),
        ]);

        // Audited BEFORE the fan-out. A message that reaches two hundred phones
        // cannot be unsent, so the record of who sent what must not depend on
        // the delivery job succeeding.
        AuditLogger::record(
            action: 'sacco.announcement.sent',
            data: [
                'announcement_id' => (int) $announcement->id,
                'vehicle_id' => $vehicleId,
                'title' => $announcement->title,
            ],
            saccoId: (int) $saccoId,
        );

        DeliverSaccoAnnouncement::dispatch((int) $announcement->id);

        return response()->json([
            'success' => 'Announcement queued for delivery.',
            'announcement' => $this->payload($announcement),
        ], 201);
    }

    /**
     * What this SACCO has already sent
     *
     * Exists so the same notice does not go out three times because nobody could
     * tell whether it landed.
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('Send Crew Announcements')) {
            return response()->json(['error' => 'You do not have permission to view crew announcements.'], 403);
        }

        $perPage = $this->perPage($request);
        $query = SaccoAnnouncement::with('vehicle:id,plate')->orderByDesc('id');
        $meta = $this->pageMeta($query, $request, $perPage);

        $rows = $query->skip(($meta['current_page'] - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'announcements' => $rows->map(fn ($a) => $this->payload($a))->all(),
            'meta' => $meta,
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(SaccoAnnouncement $a): array
    {
        return [
            'id' => (int) $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'vehicle' => $a->vehicle?->plate,
            // 0 until the delivery job has run — the client should read this as
            // "not delivered yet", not as "reached nobody".
            'recipients' => (int) $a->recipients,
            'sent_at' => optional($a->created_at)->toIso8601String(),
        ];
    }
}
