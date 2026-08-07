<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Models\VehicleLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The live-map read: where this SACCO's fleet is now.
 *
 * Until now the platform could only WRITE locations (book_a_ride/location and
 * .../stop), so a map had to fall back to the vehicles list, which carries no
 * position at all.
 *
 * Scoping is the model's, not this controller's: VehicleLocation reaches sacco
 * and brand through its vehicle, so an admin cannot read another SACCO's fleet
 * by asking for its vehicle ids — the filter below narrows an already-scoped
 * set and can never widen it.
 *
 * NOTE ON SPEED: the frontend map asked for speed, and the table has no such
 * column — only heading. Deriving it would need a previous fix per vehicle, and
 * this table keeps exactly one row per vehicle (vehicle_id is unique), so there
 * is no previous fix to derive from. Returning an invented value on a driver
 * safety display would be worse than returning none, so `speed` is absent
 * rather than null: adding it means recording location history first.
 */
final class VehicleLocationsReadController extends Controller
{
    /** Positions older than this are stale enough to be misleading on a map. */
    private const DEFAULT_MAX_AGE_MINUTES = 15;

    /**
     * Authentication is applied here, not on the route.
     *
     * The dashboard routes sit in a group carrying only ResolveBrand and
     * CheckAPIUserStatus; every controller in it adds auth:sanctum itself. This
     * one was registered without it, so an unauthenticated request reached the
     * body and died on a null user with a 500 instead of being refused with a
     * 401 -- and, worse, a request that got that far would have had no SACCO to
     * scope the fleet positions to.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $query = VehicleLocation::query()->with('vehicle:id,plate,fleet_no');

        // `since` supports incremental polling: the map asks only for what has
        // moved since its last read instead of refetching the whole fleet.
        if ($request->filled('since')) {
            try {
                $since = Carbon::parse((string) $request->input('since'));
            } catch (\Throwable) {
                return response()->json(['message' => 'Invalid `since` timestamp'], 422);
            }
            $query->where('recorded_at', '>', $since);
        } else {
            $maxAge = (int) $request->input('max_age_minutes', self::DEFAULT_MAX_AGE_MINUTES);
            $maxAge = max(1, min($maxAge, 1440));
            $query->where(function ($q) use ($maxAge): void {
                $q->where('recorded_at', '>=', now()->subMinutes($maxAge))
                    ->orWhereNull('recorded_at');
            });
        }

        if ($request->filled('vehicle_ids')) {
            $ids = collect(explode(',', (string) $request->input('vehicle_ids')))
                ->map(fn ($id) => (int) trim($id))
                ->filter()
                ->all();

            if ($ids !== []) {
                $query->whereIn('vehicle_id', $ids);
            }
        }

        if ($request->boolean('broadcasting_only')) {
            $query->where('broadcasting', true);
        }

        $rows = $query->orderByDesc('recorded_at')->limit(1000)->get();

        return response()->json([
            'data' => $rows->map(fn (VehicleLocation $row): array => [
                'vehicle_id' => $row->vehicle_id,
                'plate' => $row->vehicle?->plate,
                'fleet_no' => $row->vehicle?->fleet_no,
                'latitude' => $row->latitude,
                'longitude' => $row->longitude,
                'heading' => $row->heading,
                'broadcasting' => $row->broadcasting,
                'route_id' => $row->route_id,
                'queue_id' => $row->queue_id,
                'recorded_at' => $row->recorded_at?->toIso8601String(),
            ])->values()->all(),
            // Echo back so the map can pass it straight to the next poll.
            'polled_at' => now()->toIso8601String(),
        ]);
    }
}
