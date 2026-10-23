<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\LostFound;

use App\Enums\LostItemStatus;
use App\Http\Controllers\Concerns\ExplainsFailures;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\LostItemReportResource;
use App\Models\Booking;
use App\Models\LostItemReport;
use App\Models\Queue;
use App\Models\Vehicle;
use App\Services\LostFound\LostFoundNotifier;
use App\Services\Sql\PlateSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A passenger reporting something left on a matatu, and following what the crew
 * said about it.
 *
 * Every read is pinned to the caller's own user_id and a report belonging to
 * anyone else is a 404, never a 403, so ids cannot be probed for existence.
 */
class LostItemsController extends Controller
{
    use ExplainsFailures, PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum')->except('photo');
    }

    public function store(Request $request, LostFoundNotifier $notifier): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required_without:plate|nullable|integer',
            'plate' => 'required_without:booking_id|nullable|string|max:20',
            'trip_date' => [
                'required', 'date_format:Y-m-d',
                'before_or_equal:'.Carbon::today()->toDateString(),
                'after_or_equal:'.Carbon::today()->subDays(14)->toDateString(),
            ],
            'category' => 'required|in:'.implode(',', LostItemStatus::categories()),
            'description' => 'required|string|min:3|max:200',
            'contact_phone' => 'nullable|string|max:20',
        ], [
            'booking_id.required_without' => 'Tell us the trip or the number plate.',
            'plate.required_without' => 'Tell us the trip or the number plate.',
            'trip_date.before_or_equal' => 'That date is in the future.',
            'trip_date.after_or_equal' => 'We can only take reports from the last 14 days.',
            'description.min' => 'Describe the item in a few more words.',
            'description.max' => 'Keep the description under 200 characters.',
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $open = LostItemReport::where('user_id', $request->user()->id)
            ->whereIn('status', [LostItemStatus::Open->value, LostItemStatus::Found->value])
            ->count();

        if ($open >= LostItemReport::MAX_OPEN) {
            return $this->refuse(
                sprintf('You already have %d open reports.', LostItemReport::MAX_OPEN),
                'too_many_open',
            );
        }

        $vehicle = $request->filled('booking_id')
            ? $this->vehicleFromBooking($request)
            : $this->vehicleFromPlate((string) $request->input('plate'));

        if ($vehicle === null) {
            return $request->filled('booking_id')
                ? response()->json(['error' => 'We could not find that trip on your account.'], 404)
                : $this->refuse(
                    sprintf('We could not find a matatu with plate %s.', (string) $request->input('plate')),
                    'unknown_plate',
                );
        }

        $report = LostItemReport::create([
            'user_id' => $request->user()->id,
            'vehicle_id' => $vehicle->id,
            'booking_id' => $request->input('booking_id'),
            'sacco_id' => $vehicle->sacco_id,
            'category' => $request->input('category'),
            'description' => $request->input('description'),
            'contact_phone' => $request->input('contact_phone') ?: $request->user()->phone,
            'trip_date' => $request->input('trip_date'),
            'route_label' => $this->routeLabelFor($vehicle, (string) $request->input('trip_date')),
            'status' => LostItemStatus::Open->value,
        ]);

        $report->recordEvent(LostItemStatus::Open, null, (int) $request->user()->id);

        // Nobody crewing it right now means nobody to tell yet; the crew get it
        // when they next sign in (LostFoundNotifier::notifyPendingFor).
        if ($notifier->notifyCrew($report->load('vehicle'))) {
            $report->forceFill(['crew_notified_at' => Carbon::now()])->save();
        }

        return response()->json(
            ['report' => new LostItemReportResource($report->load(['vehicle', 'events']))],
            201,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $query = LostItemReport::where('user_id', $request->user()->id)
            ->with(['vehicle', 'events'])
            ->orderByDesc('id');

        $meta = $this->pageMeta($query, $request, 20);
        $page = max((int) $request->input('page', 1), 1);

        $reports = $query->skip(($page - 1) * 20)->take(20)->get();

        return response()->json(array_merge(
            ['reports' => LostItemReportResource::collection($reports)],
            $meta,
        ));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $report = $this->ownReport($request, $id);

        if ($report === null) {
            return response()->json(['error' => 'That report was not found.'], 404);
        }

        return response()->json(['report' => new LostItemReportResource($report)]);
    }

    /** "I've got it" — only meaningful once the crew said they found it. */
    public function collected(Request $request, string $id): JsonResponse
    {
        $report = $this->ownReport($request, $id);

        if ($report === null) {
            return response()->json(['error' => 'That report was not found.'], 404);
        }

        if ($report->status !== LostItemStatus::Found) {
            return $this->refuse('This report is not waiting for collection.', 'not_collectable');
        }

        return $this->close($request, $report, LostItemStatus::Collected);
    }

    /** The passenger found it at home, or no longer needs it looked for. */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $report = $this->ownReport($request, $id);

        if ($report === null) {
            return response()->json(['error' => 'That report was not found.'], 404);
        }

        if (! in_array($report->status, [LostItemStatus::Open, LostItemStatus::Found], true)) {
            return $this->refuse('This report is already closed.', 'already_closed');
        }

        return $this->close($request, $report, LostItemStatus::Closed);
    }

    /**
     * The found photo, behind a signed link rather than a public path: it is a
     * photo of someone's belongings, and the local disk cannot mint temporary
     * URLs, so it is streamed here instead.
     */
    public function photo(Request $request, int $report): StreamedResponse|JsonResponse
    {
        $row = LostItemReport::withoutGlobalScopes()->find($report);

        if ($row === null || $row->found_photo_path === null) {
            return response()->json(['error' => 'That photo was not found.'], 404);
        }

        $disk = Storage::disk(config('lostfound.photo_disk', 'local'));

        if (! $disk->exists($row->found_photo_path)) {
            return response()->json(['error' => 'That photo was not found.'], 404);
        }

        return $disk->response($row->found_photo_path);
    }

    private function close(Request $request, LostItemReport $report, LostItemStatus $to): JsonResponse
    {
        $report->forceFill(['status' => $to->value])->save();
        $report->recordEvent($to, null, (int) $request->user()->id);

        return response()->json([
            'report' => new LostItemReportResource($report->load(['vehicle', 'events'])),
        ]);
    }

    private function ownReport(Request $request, string $id): ?LostItemReport
    {
        $key = LostItemReport::parseId($id);

        if ($key === null) {
            return null;
        }

        return LostItemReport::where('id', $key)
            ->where('user_id', $request->user()->id)
            ->with(['vehicle', 'events'])
            ->first();
    }

    /** The booking must be the caller's own, or this is a 404 above. */
    private function vehicleFromBooking(Request $request): ?Vehicle
    {
        $booking = Booking::where('id', (int) $request->input('booking_id'))
            ->where('user_id', $request->user()->id)
            ->with('queue.vehicle')
            ->first();

        return $booking?->queue?->vehicle;
    }

    private function vehicleFromPlate(string $plate): ?Vehicle
    {
        return Vehicle::whereRaw(PlateSql::matchSql('plate'), [PlateSql::matchBinding($plate)])->first();
    }

    /** What the bus was running that day, stored so a later route edit cannot rewrite it. */
    private function routeLabelFor(Vehicle $vehicle, string $tripDate): ?string
    {
        $queue = Queue::where('vehicle_id', $vehicle->id)
            ->with('route.from', 'route.to')
            ->whereDate('created_at', $tripDate)
            ->latest('id')
            ->first();

        $route = $queue?->route;

        if ($route?->from === null || $route?->to === null) {
            return null;
        }

        return $route->from->name.' → '.$route->to->name;
    }
}
