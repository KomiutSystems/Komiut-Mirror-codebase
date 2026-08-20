<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Enums\BookingType;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Jobs\SendFCMJob;
use App\Models\Booking;
use App\Models\FirebaseToken;
use App\Models\Place;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\SeatArrangement;
use App\Models\SeatBooking;
use App\Services\Booking\SegmentSeatAvailability;
use App\Services\Fares\FareResolver;
use App\Services\Sql\LikeSql;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * @group Book a ride
 */
class BookARideQueuesAPIController extends Controller
{
    use PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * List available trips (queues)
     *
     * Live vehicles queued to depart, optionally narrowed to a pickup→dropoff
     * pair. Stops are matched by id (not name): a route qualifies only if it has
     * the pickup stop before the dropoff stop (by distance) on the same route.
     *
     * @authenticated
     *
     * @queryParam from_id integer Pickup stop (place) id. Example: 12
     * @queryParam to_id integer Dropoff stop (place) id. Example: 18
     * @queryParam sacco string Exact SACCO name to filter by. Example: Nairobi CBD SACCO
     * @queryParam search string Match vehicle plate or fleet number. Example: KDA
     * @queryParam page integer Page number (20 per page). Example: 1
     */
    public function getQueues(Request $request, SegmentSeatAvailability $seatAvailability)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $statuses = QueueStatus::where('status', 'Active')->orWhere('status', 'Pending')->pluck('id');
        $queues = Queue::select('queues.*')->with(['terminus', 'queue_status', 'vehicle.sacco',
            'vehicle.seat', 'route.route_stages.place', 'route.from', 'route.to',
            'terminus.place'])->whereIn('queue_status_id', $statuses);

        if ($request->sacco != '') {
            $queues = $queues->whereHas('vehicle.sacco', function ($query) use ($request) {
                $query->where('name', $request->sacco);
            });
        }
        // Only filter when a term was actually typed. An empty box turns this
        // into LIKE '%%' on every column in the group, OR'd with any whereHas
        // below — none of it indexable. The guard wraps the WHOLE group on
        // purpose: guarding one column leaves the orWhere siblings matching
        // unconditionally, which is worse than no guard.
        if (filled($request->search)) {
            $queues = $queues->whereHas('vehicle', function ($query) use ($request) {
                $query->where('plate', LikeSql::op(), '%'.$request->search.'%')->orWhere('fleet_no', LikeSql::op(), '%'.$request->search.'%');
            });
        }

        // Direction-aware pickup/dropoff filter by stop id.
        if (intval($request->from_id) > 0 && intval($request->to_id) > 0) {
            $queues = $queues->join('route_stages as pickup', 'pickup.route_id', 'queues.route_id')
                ->join('route_stages as dropoff', function ($join) {
                    $join->on('dropoff.route_id', 'pickup.route_id')->on('pickup.distance', '<', 'dropoff.distance');
                })
                ->where('pickup.place_id', intval($request->from_id))
                ->where('dropoff.place_id', intval($request->to_id))
                ->distinct();
        }

        $__meta = $this->pageMeta($queues, $request, 20);
        $queues = $queues->skip($offset)->take(20)
            ->orderBy('queues.created_at', 'DESC')->get();

        // Attach a REAL free-seat count per queue. Without it the app fell back
        // to raw vehicle capacity and could offer a full matatu as available.
        $this->attachAvailableSeats($queues, $seatAvailability, $request);

        return response()->json(array_merge(['queues' => $queues], $__meta));
    }

    /**
     * Stamp each queue with `total_seats` (physical capacity) and
     * `available_seats` (real seats free right now), so the app never falls
     * back to raw capacity and shows a full vehicle as bookable.
     *
     * Occupancy reuses SegmentSeatAvailability — the same segment-aware source
     * of truth the seat map and addBooking use — so what's shown free here can't
     * be rejected at booking time. When both pickup/dropoff are supplied the
     * count is for that segment; otherwise it's the whole route (the service's
     * conservative [0, ∞) fallback).
     *
     * N+1: the list can be long, and occupiedSeatIds() runs one query per queue.
     * We first fetch, in ONE query, the ids of queues that actually hold a live
     * seat row, and only run the per-queue occupancy query for those. Freshly
     * queued, still-empty matatus (the common case) cost no extra query and just
     * report full capacity. A single shared $seatAvailability instance also
     * memoises route-stage positions, so queues on the same route resolve their
     * stop order once rather than per row.
     *
     * @param  Collection<int, Queue>  $queues
     */
    private function attachAvailableSeats($queues, SegmentSeatAvailability $seatAvailability, Request $request): void
    {
        if ($queues->isEmpty()) {
            return;
        }

        // Segment-aware only when BOTH ends are known; otherwise count against
        // the whole route.
        $from = intval($request->from_id) > 0 ? intval($request->from_id) : null;
        $to = intval($request->to_id) > 0 ? intval($request->to_id) : null;
        if ($from === null || $to === null) {
            $from = $to = null;
        }

        // One query for the whole page: which of these queues holds any active
        // seat row at all. A queue with none cannot have an occupied seat, so we
        // skip its per-queue occupancy query and report full capacity.
        $bookedQueueIds = array_flip(
            Booking::withoutGlobalScopes()
                ->whereIn('queue_id', $queues->pluck('id')->all())
                ->where('status', true)
                ->whereHas('seats', fn ($q) => $q->where('status', true))
                ->distinct()
                ->pluck('queue_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        foreach ($queues as $queue) {
            $total = (int) ($queue->vehicle?->seat?->seats ?? 0);

            $occupied = isset($bookedQueueIds[(int) $queue->id])
                ? count($seatAvailability->occupiedSeatIds($queue, $from, $to))
                : 0;

            $queue->setAttribute('total_seats', $total);
            $queue->setAttribute('available_seats', max(0, $total - $occupied));
        }
    }

    /**
     * Create a booking (reserve seats)
     *
     * Reserves seats on a queue. The fare is resolved server-side from the SACCO's
     * pricing (the client's amount is ignored), the seat check + reservation run
     * inside a locked transaction so two passengers can't grab the same seat, and
     * the occupancy check shares one definition with the seat map. Returns the
     * server-set `amount` to charge next.
     *
     * @authenticated
     *
     * @bodyParam id integer required The queue (trip) id. Example: 7
     * @bodyParam seats string required Comma-separated seat-arrangement ids, e.g. "[3,4]". Example: [3,4]
     * @bodyParam name string required Passenger name. Example: Jane Doe
     * @bodyParam phone string required Payer phone (10–12 digits). Example: 0712345678
     * @bodyParam fromId integer Pickup stop (place) id; defaults to the route origin. Example: 12
     * @bodyParam toId integer Dropoff stop (place) id; defaults to the route destination. Example: 18
     * @bodyParam payment_method string The chosen rail: mpesa, ncba_till, coop_till, wallet or loyalty_points. Example: mpesa
     * @bodyParam booking_id integer Existing booking id to amend instead of creating. Example: 41
     *
     * @response 200 {"success": "Booking successful!", "booking_id": 41, "amount": 120}
     * @response 422 {"error": "No fare is set for this route yet. Please contact the SACCO."}
     */
    public function addBooking(Request $request, FareResolver $fares, SegmentSeatAvailability $seatAvailability)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|min:1|exists:queues,id', // queue id
            'booking_id' => 'integer|min:1|nullable',
            'seats' => 'required|string',
            'name' => 'required|string',
            'phone' => 'required|digits_between:10,12',
            'fromId' => 'integer|min:0|nullable',
            'toId' => 'integer|min:0|nullable',
            'payment_method' => ['nullable', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $phone = $request->phone;
        if (strlen($request->phone) < 12) {
            $phone = '254'.intval($request->phone);
        }

        $seats = explode(',', str_replace(']', '', str_replace('[', '', $request->seats)));
        $all_seats = array_map('trim', $seats);

        try {
            $result = DB::transaction(function () use ($request, $fares, $seatAvailability, $phone, $seats, $all_seats) {
                // Serialize all bookings on this queue so the seat check can't race.
                $queue = Queue::with('vehicle.sacco', 'route.from', 'route.to', 'queue_status')
                    ->lockForUpdate()->find($request->id);

                $from = intval($request->fromId) > 0 ? intval($request->fromId) : $queue->route->from_id;
                $to = intval($request->toId) > 0 ? intval($request->toId) : $queue->route->to_id;

                // Server-authoritative fare — the passenger cannot set the price.
                $amount = $fares->resolve(
                    (int) $queue->vehicle->sacco_id,
                    (int) $queue->route_id,
                    (int) $from,
                    (int) $to,
                );
                if ($amount === null) {
                    return ['status' => 422, 'body' => ['error' => 'No fare is set for this route yet. Please contact the SACCO.']];
                }

                // Seat availability — segment-aware (pick-as-you-go): a seat is
                // only taken for the pickup→dropoff span it overlaps. Same service
                // the seat map uses, so what's shown free can't be rejected here.
                $occupied = $seatAvailability->occupiedSeatIds(
                    $queue,
                    (int) $from,
                    (int) $to,
                    $request->booking_id > 0 ? (int) $request->booking_id : null,
                );
                foreach ($all_seats as $seatId) {
                    $seatArrangement = SeatArrangement::find($seatId);
                    if ($seatArrangement === null) {
                        return ['status' => 400, 'body' => ['error' => 'One of the selected seats does not exist.']];
                    }
                    if (in_array((int) $seatArrangement->id, $occupied, true)) {
                        return ['status' => 400, 'body' => ['error' => 'Seat '.$seatArrangement->name.' already booked. Try a different seat!']];
                    }
                }

                $booking = $request->booking_id > 0 ? Booking::find($request->booking_id) : new Booking;
                // Set the mode once, at creation: a queue that has already
                // departed (Active) is picking passengers up along the road;
                // otherwise the matatu is still at the terminus.
                if (! $booking->exists) {
                    $booking->booking_type = BookingType::forQueueStatus($queue->queue_status?->status);
                }
                $booking->name = $request->name;
                $booking->phone = $phone;
                $booking->passengers = count($seats);
                $booking->user_id = auth()->user()->id;
                $booking->queue_id = $request->id;
                $booking->from_id = $from;
                $booking->to_id = $to;
                $booking->amount = $amount;
                if ($request->filled('payment_method')) {
                    $booking->payment_method = $request->payment_method;
                }
                $booking->created_by = auth()->user()->id;
                $booking->save();

                // Sync the seat rows to exactly the selected set.
                SeatBooking::where('booking_id', $booking->id)->whereNotIn('seat_id', $all_seats)->delete();
                foreach ($all_seats as $seatId) {
                    SeatBooking::firstOrCreate(['booking_id' => $booking->id, 'seat_id' => $seatId], ['status' => true]);
                }

                return [
                    'status' => 200,
                    'booking' => $booking,
                    'queue' => $queue,
                    'from' => $from,
                    'to' => $to,
                    'amount' => $amount,
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('addBooking failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Unable to complete booking!'], 400);
        }

        if (isset($result['status']) && $result['status'] !== 200) {
            return response()->json($result['body'], $result['status']);
        }

        // Notify the crew + booker outside the transaction.
        $this->notifyCrew($result['queue'], $result['from'], $result['to']);

        return response()->json([
            'success' => 'Booking successful!',
            'booking_id' => $result['booking']->id,
            'amount' => $result['amount'],
        ]);
    }

    private function notifyCrew(Queue $queue, $from, $to): void
    {
        $pickup = Place::find($from);
        $dropoff = Place::find($to);
        $departure = $pickup !== null ? $pickup->name : ($queue->route->from->name ?? '');
        $destination = $dropoff !== null ? $dropoff->name : ($queue->route->to->name ?? '');

        $tokens = FirebaseToken::whereHas('user.vehicle_users', function ($query) use ($queue) {
            $query->where('vehicle_id', $queue->vehicle->id)->where('status', true);
        })->orWhere('user_id', Auth::user()->id)->pluck('firebase_token');

        if ($tokens->isEmpty()) {
            return;
        }
        $message = Auth::user()->firstname.' has booked '.$queue->vehicle->plate." from $departure to $destination. Booking is awaiting payment!";
        $title = 'Booking from '.Auth::user()->firstname;
        foreach ($tokens as $token) {
            dispatch(new SendFCMJob($token, $title, $message, 'bookings_screen', 0));
        }
    }
}
