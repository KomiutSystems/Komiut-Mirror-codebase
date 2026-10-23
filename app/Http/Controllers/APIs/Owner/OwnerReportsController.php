<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Owner;

use App\Auth\Roles;
use App\Http\Controllers\Concerns\ExplainsFailures;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\OwnerNotificationSetting;
use App\Models\OwnerStatement;
use App\Models\VehicleAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * An owner's nightly statement, the alerts about their buses, and the settings
 * for both.
 *
 * Not an owner is a 404, not a 403 — these paths should not confirm that an
 * owner surface exists to an account that has no business on it.
 */
class OwnerReportsController extends Controller
{
    use ExplainsFailures, PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function statements(Request $request): JsonResponse
    {
        if (! $this->isOwner($request)) {
            return $this->notAnOwner();
        }

        $query = OwnerStatement::where('owner_id', $request->user()->id)->orderByDesc('date');

        $meta = $this->pageMeta($query, $request, 20);
        $page = max((int) $request->input('page', 1), 1);

        $rows = $query->skip(($page - 1) * 20)->take(20)->get()->map(fn (OwnerStatement $s): array => [
            'date' => $s->date->toDateString(),
            'collections' => (int) ($s->totals['collections'] ?? 0),
            'trips' => (int) ($s->totals['trips'] ?? 0),
            'expenses' => (int) ($s->totals['expenses'] ?? 0),
            'net' => (int) ($s->totals['net'] ?? 0),
            'buses' => count($s->vehicles ?? []),
        ]);

        return response()->json(array_merge(['statements' => $rows], $meta));
    }

    /** One day in full, or an explicit null before the sweep has built it. */
    public function statement(Request $request, string $date): JsonResponse
    {
        if (! $this->isOwner($request)) {
            return $this->notAnOwner();
        }

        if (Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->fails()) {
            return $this->invalidField('date', 'Use a date like 2026-10-21.');
        }

        $statement = OwnerStatement::where('owner_id', $request->user()->id)
            ->whereDate('date', $date)
            ->first();

        // Nothing to show is 200 with null, never a 404 (contract §0.6).
        if ($statement === null) {
            return response()->json(['statement' => null]);
        }

        return response()->json(['statement' => [
            'date' => $statement->date->toDateString(),
            'generated_at' => $statement->generated_at?->toIso8601String(),
            'currency' => 'KES',
            'totals' => $statement->totals,
            'vehicles' => $statement->vehicles,
        ]]);
    }

    public function alerts(Request $request): JsonResponse
    {
        if (! $this->isOwner($request)) {
            return $this->notAnOwner();
        }

        // Open first, then newest: an owner opens this to see what is wrong now.
        $query = VehicleAlert::where('owner_id', $request->user()->id)
            ->with('vehicle')
            ->orderByRaw('CASE WHEN resolved_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('raised_at');

        $meta = $this->pageMeta($query, $request, 20);
        $page = max((int) $request->input('page', 1), 1);

        $rows = $query->skip(($page - 1) * 20)->take(20)->get()->map(fn (VehicleAlert $a): array => [
            'id' => $a->publicId(),
            'type' => $a->type,
            'vehicle_id' => (int) $a->vehicle_id,
            'plate' => $a->vehicle?->plate,
            'raised_at' => $a->raised_at?->toIso8601String(),
            'resolved_at' => $a->resolved_at?->toIso8601String(),
            'details' => $a->details ?? [],
        ]);

        return response()->json(array_merge(['alerts' => $rows], $meta));
    }

    public function settings(Request $request): JsonResponse
    {
        if (! $this->isOwner($request)) {
            return $this->notAnOwner();
        }

        return response()->json(['settings' => OwnerNotificationSetting::forOwner((int) $request->user()->id)]);
    }

    /** Any subset; the whole set comes back. */
    public function updateSettings(Request $request): JsonResponse
    {
        if (! $this->isOwner($request)) {
            return $this->notAnOwner();
        }

        $validator = Validator::make($request->all(), [
            'statement_enabled' => 'sometimes|boolean',
            'statement_time' => 'sometimes|date_format:H:i',
            'offline_alert_enabled' => 'sometimes|boolean',
            // The app offers exactly these three.
            'offline_after_minutes' => 'sometimes|integer|in:15,30,60',
            'noon_alert_enabled' => 'sometimes|boolean',
        ], [
            'statement_time.date_format' => 'Use a time like 21:00.',
            'offline_after_minutes.in' => 'Choose 15, 30 or 60 minutes.',
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $ownerId = (int) $request->user()->id;

        // Merge onto what they ALREADY have, not onto the defaults: a PATCH
        // carrying one field must not silently reset the other four.
        OwnerNotificationSetting::updateOrCreate(
            ['owner_id' => $ownerId],
            array_merge(OwnerNotificationSetting::forOwner($ownerId), $validator->validated()),
        );

        return response()->json(['settings' => OwnerNotificationSetting::forOwner($ownerId)]);
    }

    private function isOwner(Request $request): bool
    {
        return $request->user()?->hasRole(Roles::INVESTOR) === true;
    }

    private function notAnOwner(): JsonResponse
    {
        return response()->json(['error' => 'Not found.'], 404);
    }
}
