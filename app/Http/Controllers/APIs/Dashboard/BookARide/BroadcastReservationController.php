<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Enums\BookingType;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Jobs\SendFCMJob;
use App\Models\Booking;
use App\Models\FirebaseToken;
use App\Models\Place;
use App\Models\Queue;
use App\Models\RouteStage;
use App\Models\SeatArrangement;
use App\Models\SeatBooking;
use App\Models\VehicleLocation;
use App\Services\Fares\FareResolver;
use App\Services\Location\VehicleLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Reserve a seat on a vehicle that is already on the road.
 *
 * "Pick as you go" is the core flow: a driver broadcasting a live position IS
 * the offer of a seat. A passenger standing down the route sees the approaching
 * matatu on the map (`book_a_ride/nearby`), reserves here, pays, and the driver
 * stops for them. Boarding at a terminus does not use this endpoint — that is
 * the queue-based `book_a_ride/booking/add`, which requires a queue position and
 * explicit route stops the roadside passenger does not have.
 *
 * What this endpoint has that the queue flow does not: a raw GPS point instead
 * of a pickup stop id, and a seat *count* instead of a chosen seat map. What it
 * deliberately keeps identical: the Booking row it writes and the
 * server-resolved fare — so payment, ticketing, the trip manifest and loyalty
 * all keep working with no downstream changes.
 *
 * A booking here is a passenger count, not a seat hold. The driver broadcasting
 * is the statement that there is room; nothing is refused for capacity and no
 * seat is locked while the passenger pays (decided 2026-09-12).
 *
 * @group Book a ride
 */
final class BroadcastReservationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Reserve seat(s) on a broadcasting vehicle
     *
     * The vehicle must be broadcasting *now* — a position older than the live-map
     * freshness window (VehicleLocationService::FRESH_SECONDS) is treated as no
     * longer on the road, because a stale ping means the driver's app died or the
     * trip ended and the passenger would be waiting for a bus that never comes.
     *
     * The pickup GPS point is snapped to the nearest stop on the run's route, so
     * the fare table and the driver's manifest keep speaking in stops. Seats are
     * a count -- at most Booking::MAX_SEATS -- never a claim on a physical seat.
     *
     * @authenticated
     *
     * @bodyParam vehicle_id integer required The broadcasting vehicle, as returned by `book_a_ride/nearby`. Example: 3
     * @bodyParam seats integer required How many seats to reserve. Example: 1
     * @bodyParam pickup_latitude number required Where the passenger is standing. Example: -1.2921
     * @bodyParam pickup_longitude number required Where the passenger is standing. Example: 36.8219
     * @bodyParam dropoff_place_id integer Where they are getting off; must be a stop on the run's route. Defaults to the route's destination. Example: 18
     * @bodyParam name string Passenger name; defaults to the signed-in account's name. Example: Jane Doe
     * @bodyParam phone string Payer phone (10–12 digits); defaults to the account's phone. Example: 0712345678
     * @bodyParam payment_method string The rail to charge: mpesa, ncba_till, coop_till, wallet or loyalty_points. Example: mpesa
     *
     * @response 200 {"success": "Seat reserved!", "booking_id": 41, "booking_type": "pickAsYouGo", "queue_id": 7, "amount": 200, "fare_per_seat": 200, "passengers": 1, "seats": [1], "pickup": {"place_id": 12, "name": "Ruiru", "snapped_distance_km": 0.42}, "dropoff": {"place_id": 18, "name": "Thika"}, "vehicle": {"id": 3, "plate": "KDA001A"}}
     * @response 409 {"error": "This vehicle is not on the road right now.", "reason": "not_broadcasting"}
     */
    public function reserve(Request $request, FareResolver $fares): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|integer|min:1',
            // The booker and four others -- Booking::MAX_SEATS.
            'seats' => 'required|integer|min:1|max:'.Booking::MAX_SEATS,
            'pickup_latitude' => 'required|numeric|between:-90,90',
            'pickup_longitude' => 'required|numeric|between:-180,180',
            'pickup_place_id' => 'integer|min:1|nullable',
            'dropoff_place_id' => 'integer|min:1|nullable',
            'name' => 'string|nullable',
            'phone' => 'nullable|digits_between:10,12',
            'payment_method' => ['nullable', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // Name and phone default to the signed-in passenger: this is a one-tap
        // roadside action, not a form. Phone gets no invented fallback — the
        // column is NOT NULL and the STK prompt goes to it, and social sign-ins
        // can have no phone on the account at all (see profile/update).
        $user = auth()->user();
        $name = trim((string) ($request->name ?? trim($user->firstname.' '.$user->lastname)));
        $phone = $this->normalisePhone($request->phone ?? $user->phone);
        if ($name === '' || $phone === null) {
            return response()->json([
                'error' => 'Add a phone number to your profile before booking.',
                'reason' => 'phone_required',
            ], 422);
        }

        // Visibility is decided here and nowhere else, with the SAME scopes the
        // live map uses: if `book_a_ride/nearby` would not show you this vehicle,
        // you cannot reserve on it. Every read after this point drops SaccoScope,
        // because from here on we are counting the whole bus's occupancy, and a
        // count filtered to the caller's own SACCO would report an empty vehicle
        // and oversell it.
        $location = VehicleLocation::with('vehicle.seat')
            ->where('vehicle_id', (int) $request->vehicle_id)
            ->first();

        if ($location === null) {
            return response()->json([
                'error' => 'This vehicle is not on the road right now.',
                'reason' => 'not_broadcasting',
            ], 409);
        }
        if (! $location->broadcasting) {
            return response()->json([
                'error' => 'This vehicle is not on the road right now.',
                'reason' => 'not_broadcasting',
            ], 409);
        }
        // Same freshness definition as the map that offered this vehicle, so a
        // vehicle can never be reservable while being invisible (or vice versa).
        $cutoff = now()->subSeconds(VehicleLocationService::FRESH_SECONDS);
        if ($location->recorded_at === null || $location->recorded_at->lt($cutoff)) {
            return response()->json([
                'error' => 'We have lost this vehicle\'s position. Pick another vehicle.',
                'reason' => 'stale_position',
            ], 409);
        }

        $runId = $this->runId($location);
        if ($runId === null) {
            return response()->json([
                'error' => 'This vehicle is not on a trip we can book onto yet.',
                'reason' => 'no_active_trip',
            ], 422);
        }

        try {
            $result = DB::transaction(fn (): array => $this->reserveOnRun($request, $fares, $runId, $name, $phone));
        } catch (\Throwable $e) {
            Log::error('broadcast reserve failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Unable to reserve a seat!'], 400);
        }

        if ($result['status'] !== 200) {
            return response()->json($result['body'], $result['status']);
        }

        // Outside the transaction: the crew must not be paged from inside a lock.
        $this->notifyCrew($result['queue'], $result['booking']);

        return response()->json($result['body']);
    }

    /**
     * The seat check + write, running inside the transaction with the run's row
     * locked. Returns an HTTP status and body rather than throwing, so a business
     * refusal (no seats, no fare) commits nothing but is not logged as a failure.
     *
     * @return array{status: int, body: array<string, mixed>, queue?: Queue, booking?: Booking}
     */
    private function reserveOnRun(
        Request $request,
        FareResolver $fares,
        int $runId,
        string $name,
        string $phone,
    ): array {
        // Lock the QUEUE row — the very row the terminus flow (addBooking) locks.
        // That is what makes the two flows serialise against each other: locking
        // `vehicle_locations` instead would leave a roadside reservation and a
        // terminus booking free to sell the same last seat simultaneously.
        // Scopes are dropped because visibility was already settled on the
        // location read above, and the lock must not depend on who is asking.
        $queue = Queue::withoutGlobalScopes()->lockForUpdate()->find($runId);
        if ($queue === null) {
            return ['status' => 422, 'body' => ['error' => 'This trip has gone away.', 'reason' => 'no_active_trip']];
        }
        $queue->load('route.from', 'route.to', 'vehicle.sacco', 'vehicle.seat');

        if ($queue->route === null || $queue->vehicle === null) {
            return ['status' => 422, 'body' => ['error' => 'This trip has no route set.', 'reason' => 'no_route']];
        }

        // No capacity gate: a vehicle with no seating configured is still
        // bookable, because a booking is a passenger count, not a seat (see
        // below). It simply carries no seat labels.

        // Dropoff first, because it bounds where the pickup may be snapped to.
        $toId = (int) $queue->route->to_id;
        if ($request->filled('dropoff_place_id')) {
            $onRoute = RouteStage::where('route_id', $queue->route_id)
                ->where('place_id', (int) $request->dropoff_place_id)
                ->exists();
            if (! $onRoute) {
                return ['status' => 422, 'body' => [
                    'error' => 'That drop-off point is not on this vehicle\'s route.',
                    'reason' => 'dropoff_not_on_route',
                ]];
            }
            $toId = (int) $request->dropoff_place_id;
        }

        // A PASSENGER BOARDS AT A STOP, NOT AT THE ROADSIDE.
        //
        // `pickup_place_id` is the honest way to say where you are: the app
        // shows the stops on this route and the passenger picks one. It is also
        // the only way to reach a third of them -- 6 of 18 route_stages carry no
        // coordinates at all, so GPS can never snap onto those however close you
        // stand.
        //
        // Without one, GPS still snaps to the nearest stop, but BOUNDED. It used
        // to snap at any distance and fall back to the route's origin when
        // nothing matched, so a passenger twenty kilometres off-route was booked
        // from the start of the line: charged for a journey they were not on,
        // and shown to the driver as waiting at a stop they were nowhere near.
        // Refusing is the honest answer -- walk to a stop.
        if ($request->filled('pickup_place_id')) {
            $stage = RouteStage::where('route_id', $queue->route_id)
                ->where('place_id', (int) $request->pickup_place_id)
                ->first(['place_id', 'sequence']);

            if ($stage === null) {
                return ['status' => 422, 'body' => [
                    'error' => 'That pick-up point is not a stop on this vehicle\'s route.',
                    'reason' => 'pickup_not_on_route',
                ]];
            }

            $fromId = (int) $stage->place_id;
        } else {
            $snapped = $this->snapToStop(
                (int) $queue->route_id,
                (float) $request->pickup_latitude,
                (float) $request->pickup_longitude,
                $toId,
            );

            $limitKm = (float) config('booking.max_pickup_km', 1.0);

            if ($snapped === null || $snapped['distance_km'] > $limitKm) {
                return ['status' => 422, 'body' => [
                    'error' => 'You are not at a stop on this route. Walk to the nearest one, or choose it from the list.',
                    'reason' => 'not_at_a_stop',
                    'nearest_stop_km' => $snapped['distance_km'] ?? null,
                    'limit_km' => $limitKm,
                ]];
            }

            $fromId = (int) $snapped['place_id'];
        }

        if ($fromId === $toId) {
            return ['status' => 422, 'body' => [
                'error' => 'You are already at your destination.',
                'reason' => 'invalid_segment',
            ]];
        }

        // ---------------------------------------------------------------------
        // What "this run" means when there is no queue to stand in
        // ---------------------------------------------------------------------
        // A roaming vehicle holds no queue POSITION, but a bookable one still
        // carries a queue ROW. "This run" is that queue -- the trip the driver
        // is broadcasting -- and `bookings.queue_id` being NOT NULL means it is
        // also the only run identity a Booking can record. runId() finds it or
        // refuses with `no_active_trip`: a route-only broadcast is visible on
        // the map and not bookable, deliberately, because minting a queue from
        // a passenger's tap would fabricate a stage position the bus never took.
        //
        // NO CAPACITY, NO HOLDS. Decided 2026-09-12. This used to count seats --
        // segment-aware occupancy plus a passenger floor -- and refuse with
        // 409 `no_seats` when the run was full, holding each unpaid reservation's
        // seats for the hold window. That is not how a matatu sells: the driver
        // broadcasting IS the statement that there is room, a booking is a count
        // of passengers to pick up, and nobody's seat is locked while they find
        // their PIN. The seat ids handed back below are labels for the ticket
        // and the manifest, taken from the seat map in order, not a claim on a
        // physical seat. The reasoning that used to live here is in git history
        // (BroadcastReservationController before this date) for the day numbered
        // seats come back.
        $seats = (int) $request->seats;

        // Server-authoritative fare, exactly as the terminus flow resolves it —
        // the passenger never sets the price.
        $amount = $fares->resolve(
            (int) $queue->vehicle->sacco_id,
            (int) $queue->route_id,
            $fromId,
            $toId,
        );
        if ($amount === null) {
            return ['status' => 422, 'body' => [
                'error' => 'No fare is set for this route yet. Please contact the SACCO.',
                'reason' => 'no_fare',
            ]];
        }

        $booking = new Booking;
        // Always pick-as-you-go by definition: this endpoint only exists for a
        // vehicle already on the road, so the driver app must show the map.
        $booking->booking_type = BookingType::PickAsYouGo;
        $booking->name = $name;
        $booking->phone = $phone;
        $booking->passengers = $seats;
        $booking->user_id = $request->user()->id;
        $booking->queue_id = $queue->id;
        $booking->from_id = $fromId;
        $booking->to_id = $toId;
        // Keep the passenger's ACTUAL position alongside the snapped stop. from_id
        // is the nearest stage (needed for fare and for the existing manifest);
        // these two are where the driver must physically pull over. Without them
        // the snap silently loses the only thing that makes a roadside pickup
        // findable on a route whose stages are kilometres apart.
        $booking->pickup_latitude = (float) $request->pickup_latitude;
        $booking->pickup_longitude = (float) $request->pickup_longitude;
        // The whole fare, per-seat x seats -- see addBooking for why.
        $booking->amount = round((float) $amount * max(1, $seats), 2);
        if ($request->filled('payment_method')) {
            $booking->payment_method = $request->payment_method;
        }
        $booking->created_by = $request->user()->id;
        $booking->save();

        // Labels from the seat map, in order -- the same ids another booking may
        // carry. A vehicle with no seat map gets no seat rows, like a cash boarding.
        $allocated = array_slice($this->seatLabels($queue), 0, $seats);
        foreach ($allocated as $seatId) {
            SeatBooking::create(['booking_id' => $booking->id, 'seat_id' => $seatId, 'status' => true]);
        }

        return [
            'status' => 200,
            'queue' => $queue,
            'booking' => $booking,
            'body' => [
                'success' => 'Seat reserved!',
                'booking_id' => $booking->id,
                'booking_type' => BookingType::PickAsYouGo->apiLabel(),
                'queue_id' => (int) $queue->id,
                // What the booking costs -- all seats -- and the per-seat fare.
                'amount' => (float) $booking->amount,
                'fare_per_seat' => (float) $amount,
                'passengers' => $seats,
                'seats' => $allocated,
                'pickup' => [
                    'place_id' => $fromId,
                    'name' => Place::find($fromId)?->name,
                    'snapped_distance_km' => $snapped['distance_km'] ?? null,
                ],
                'dropoff' => [
                    'place_id' => $toId,
                    'name' => Place::find($toId)?->name,
                ],
                'vehicle' => [
                    'id' => (int) $queue->vehicle_id,
                    'plate' => $queue->vehicle->plate,
                ],
            ],
        ];
    }

    /**
     * The run the vehicle is currently broadcasting. Normally the queue stamped
     * on its live position row by the driver's own pings; if that is ever absent
     * (the column is nullable) fall back to the vehicle's newest live queue, so
     * the reservation degrades to "the trip it is obviously on" rather than 500.
     */
    private function runId(VehicleLocation $location): ?int
    {
        if ($location->queue_id !== null) {
            return (int) $location->queue_id;
        }

        $queueId = Queue::withoutGlobalScopes()
            ->where('vehicle_id', $location->vehicle_id)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Active', 'Pending']))
            ->orderByDesc('id')
            ->value('id');

        return $queueId !== null ? (int) $queueId : null;
    }

    /**
     * The vehicle's seat-map ids, in a stable order, for labelling a ticket and
     * the driver's manifest. Empty when the layout has no seat map at all.
     *
     * Not availability: a booking is not a seat hold (see reserveOnRun), so the
     * same label can sit on two bookings and that is fine.
     *
     * @return array<int, int>
     */
    private function seatLabels(Queue $queue): array
    {
        return SeatArrangement::where('seat_id', $queue->vehicle->seat_id)
            ->where('status', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Nearest stop on the route to a GPS point.
     *
     * Stops with no coordinates are skipped rather than snapped to (0,0), which
     * would otherwise put every roadside pickup in the Gulf of Guinea. The
     * haversine is inlined here rather than reached for in VehicleLocationService,
     * whose copy is private and whose file belongs to the live-map work.
     *
     * @return array{place_id: int, distance_km: float}|null
     */
    private function snapToStop(int $routeId, float $latitude, float $longitude, ?int $beforePlaceId): ?array
    {
        // Ordered so ties resolve deterministically to the earlier stop rather
        // than to whatever PostgreSQL happens to return first.
        $stages = RouteStage::where('route_id', $routeId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['place_id', 'latitude', 'longitude', 'sequence']);

        // You cannot board at or beyond where you are getting off, so stops from
        // the dropoff onwards are not candidates. Without this, a passenger who
        // is already near their destination would snap onto it and the segment
        // would collapse to zero length.
        $limit = $beforePlaceId !== null
            ? $stages->firstWhere('place_id', $beforePlaceId)?->sequence
            : null;

        $best = null;
        foreach ($stages as $stage) {
            if ($limit !== null && (int) $stage->sequence >= (int) $limit) {
                continue;
            }
            $distance = $this->haversineKm($latitude, $longitude, (float) $stage->latitude, (float) $stage->longitude);
            if ($best === null || $distance < $best['distance_km']) {
                $best = ['place_id' => (int) $stage->place_id, 'distance_km' => round($distance, 3)];
            }
        }

        return $best;
    }

    /** Great-circle distance in km. */
    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371.0088 * 2 * asin(min(1.0, sqrt($a)));
    }

    /** Kenyan MSISDN in the 254XXXXXXXXX form the payment rails expect. */
    private function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) < 12) {
            $digits = '254'.(int) $digits;
        }

        return $digits;
    }

    /**
     * Tell the crew someone is waiting down the road. Mirrors the terminus flow's
     * notification so the driver app's existing handler needs no change.
     */
    private function notifyCrew(Queue $queue, Booking $booking): void
    {
        $pickup = optional(Place::find($booking->from_id))->name ?? '';
        $tokens = FirebaseToken::whereHas('user.vehicle_users', function ($query) use ($queue) {
            $query->where('vehicle_id', $queue->vehicle_id)->where('status', true);
        })->pluck('firebase_token');

        if ($tokens->isEmpty()) {
            return;
        }

        $title = 'Pickup on your route';
        $message = $booking->name.' is waiting at '.$pickup.' for '
            .$queue->vehicle->plate.' ('.$booking->passengers.' seat(s)). Awaiting payment!';

        foreach ($tokens as $token) {
            dispatch(new SendFCMJob($token, $title, $message, 'bookings_screen', 0));
        }
    }
}
