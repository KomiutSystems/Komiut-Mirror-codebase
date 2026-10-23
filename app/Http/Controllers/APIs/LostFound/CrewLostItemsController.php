<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\LostFound;

use App\Auth\CrewSession;
use App\Enums\LostItemStatus;
use App\Http\Controllers\Concerns\ExplainsFailures;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\LostItemReportResource;
use App\Models\LostItemReport;
use App\Models\VehicleUser;
use App\Services\LostFound\LostFoundNotifier;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * The crew's side: what passengers reported on the bus they are crewing today,
 * and their answer.
 *
 * The crew never see who reported an item — no name, no phone. A report for any
 * bus other than today's is a 404.
 */
class CrewLostItemsController extends Controller
{
    use ExplainsFailures, PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $vehicleId = $this->todaysVehicle($request->user());

        if ($vehicleId === null) {
            return response()->json(['reports' => [], 'total' => 0, 'per_page' => 20, 'current_page' => 1, 'last_page' => 1]);
        }

        // Open first, then newest: the crew are looking for what still needs an
        // answer, not a history.
        $query = LostItemReport::where('vehicle_id', $vehicleId)
            ->with(['vehicle', 'events'])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        $meta = $this->pageMeta($query, $request, 20);
        $page = max((int) $request->input('page', 1), 1);

        $reports = $query->skip(($page - 1) * 20)->take(20)->get();

        return response()->json(array_merge(
            ['reports' => LostItemReportResource::collection($reports)],
            $meta,
        ));
    }

    public function found(Request $request, string $id, LostFoundNotifier $notifier): JsonResponse
    {
        $report = $this->reportOnMyBus($request, $id);

        if ($report === null) {
            return response()->json(['error' => 'That report was not found.'], 404);
        }

        if (! $report->status->isAnswerable()) {
            return $this->refuse('This report was already answered.', 'already_answered');
        }

        $validator = Validator::make($request->all(), [
            'where' => 'required|in:'.implode(',', LostItemStatus::foundWheres()),
            'place_name' => 'required_if:where,at_stage|nullable|string|max:120',
            'note' => 'nullable|string|max:200',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png|max:5120',
        ], [
            'where.required' => 'Say where the item is.',
            'place_name.required_if' => 'Name the stage you left it at.',
            'note.max' => 'Keep the note under 200 characters.',
            'photo.max' => 'That photo is larger than 5 MB.',
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $path = $request->hasFile('photo')
            ? $request->file('photo')->store('lost-found', config('lostfound.photo_disk', 'local'))
            : null;

        $found = Carbon::now();

        $report->forceFill([
            'status' => LostItemStatus::Found->value,
            'found_where' => $request->input('where'),
            'found_place_name' => $request->input('place_name'),
            'found_note' => $request->input('note'),
            'found_photo_path' => $path ?: null,
            'found_by' => $request->user()->id,
            'found_at' => $found,
            'keep_until' => $found->copy()->addDays(LostItemReport::KEEP_DAYS)->toDateString(),
        ])->save();

        $report->recordEvent(LostItemStatus::Found, $request->input('note'), (int) $request->user()->id);

        $notifier->notifyPassenger($report->load(['vehicle', 'user']));

        return response()->json(['report' => new LostItemReportResource($report->load(['vehicle', 'events']))]);
    }

    public function notFound(Request $request, string $id, LostFoundNotifier $notifier): JsonResponse
    {
        $report = $this->reportOnMyBus($request, $id);

        if ($report === null) {
            return response()->json(['error' => 'That report was not found.'], 404);
        }

        if (! $report->status->isAnswerable()) {
            return $this->refuse('This report was already answered.', 'already_answered');
        }

        $validator = Validator::make($request->all(), [
            'note' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $report->forceFill([
            'status' => LostItemStatus::NotFound->value,
            'found_note' => $request->input('note'),
        ])->save();

        $report->recordEvent(LostItemStatus::NotFound, $request->input('note'), (int) $request->user()->id);

        $notifier->notifyPassenger($report->load(['vehicle', 'user']));

        return response()->json(['report' => new LostItemReportResource($report->load(['vehicle', 'events']))]);
    }

    private function reportOnMyBus(Request $request, string $id): ?LostItemReport
    {
        $key = LostItemReport::parseId($id);
        $vehicleId = $this->todaysVehicle($request->user());

        if ($key === null || $vehicleId === null) {
            return null;
        }

        return LostItemReport::where('id', $key)
            ->where('vehicle_id', $vehicleId)
            ->with(['vehicle', 'events'])
            ->first();
    }

    /**
     * The bus this crew member is on today. A plate session names it outright;
     * a crew member who signed in with a password is read from their open
     * assignment instead, which is the same bus the driver app would show.
     */
    private function todaysVehicle(?Authenticatable $user): ?int
    {
        $fromPlate = CrewSession::vehicleId($user);

        if ($fromPlate !== null) {
            return $fromPlate;
        }

        if ($user === null) {
            return null;
        }

        $id = VehicleUser::withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->whereNull('end_date')
            ->where('status', true)
            ->latest('id')
            ->value('vehicle_id');

        return $id === null ? null : (int) $id;
    }
}
