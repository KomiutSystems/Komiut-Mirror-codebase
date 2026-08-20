<?php

namespace App\Http\Controllers\APIs\Dashboard\Bookings;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Services\SendFCMMessageController;
use App\Http\Controllers\Services\SendSMSController;
use App\Jobs\SendFCMJob;
use App\Jobs\SendSMSJob;
use App\Models\Booking;
use App\Models\FirebaseToken;
use App\Models\Parcel;
use App\Models\Queue;
use App\Models\QueuePlace;
use App\Models\QueueStatus;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class BookingsAPIController extends Controller
{
    use PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getPassengerBookings(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != '' ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $bookings = Booking::with([
            'from',
            'to',
            'creator',
            'queue.vehicle.sacco',
            'queue.vehicle.seat',
            'queue.route.from',
            'queue.route.to',
            'queue.terminus.place',
            'queue.queue_status',
            'seats.seat',
        ])
            ->whereBetween('created_at', [$from_date, $to_date])
            // Same vocabulary the driver app uses, from the same model scope:
            // failed | boarded | confirmed | reserved. Without a filter the
            // listing shows every state, including the cancelled ones the
            // unpaid sweep produced -- which were previously indistinguishable.
            ->statusIs($request->input('booking_status'));
        if (! auth()->user()->can('View Passengers')) {
            $bookings = $bookings->where('user_id', Auth::user()->id);
        } else {
            $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
            $all_vehicles = [];

            foreach ($vehicles as $vehicle) {
                $v = trim($vehicle);
                if ($v != '') {
                    array_push($all_vehicles, trim($vehicle));
                }
            }
            if (count($all_vehicles) > 0) {
                $bookings = $bookings->whereHas('queue', function ($query) use ($all_vehicles) {
                    $query->whereIn('vehicle_id', $all_vehicles);
                });
            }
        }
        if ($request->sacco > 0) {
            $bookings = $bookings->whereHas('queue.vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if ($request->from > 0) {
            $bookings = $bookings->where('from_id', $request->from);
        }
        if ($request->to > 0) {
            $bookings = $bookings->where('to_id', $request->to);
        }
        // NOTE: a second `where('sacco_id', $request->sacco)` used to sit here.
        // `bookings` has no sacco_id column — a booking reaches its SACCO through
        // queue.vehicle, which is exactly what the block above already does. On
        // PostgreSQL the stray filter was a hard error (42703 undefined column),
        // so EVERY request carrying ?sacco=N returned a 500. MySQL would have
        // failed too; it simply was never exercised with that parameter.
        // Only filter when a term was actually typed. An empty box turns this
        // into LIKE '%%' on every column in the group, OR'd with any whereHas
        // below — none of it indexable. The guard wraps the WHOLE group on
        // purpose: guarding one column leaves the orWhere siblings matching
        // unconditionally, which is worse than no guard.
        if (filled($request->search)) {
            $bookings = $bookings->where(function ($query) use ($request) {
                $query->whereHas('queue.vehicle', function ($query) use ($request) {
                    $query->where('plate', LikeSql::op(), '%'.$request->search.'%');
                })->orWhere('name', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('phone', LikeSql::op(), '%'.$request->search.'%');
            });
        }
        $__meta = $this->pageMeta($bookings, $request, 20);
        $bookings = $bookings->skip($offset)->take(20)
            ->orderBy('created_at', 'DESC')->get();

        return response()->json(array_merge(['bookings' => $bookings], $__meta));
    }

    public function getPassengerBooking(Request $request)
    {
        $booking = Booking::with([
            'from',
            'to',
            'creator',
            'queue.vehicle.sacco',
            'queue.vehicle.seat',
            'queue.route.from',
            'queue.route.to',
            'queue.terminus.place',
            'queue.queue_status',
            'seats.seat',
            'mpesa_booking_callbacks',
        ])->where('id', $request->id);
        if (! auth()->user()->can('View Passengers')) {
            $booking = $booking->where('user_id', Auth::user()->id);
        }
        // The null check has to run on the RESULT, not the builder: a query
        // builder is never == null, so an unknown (or not-yours) id used to fall
        // through and return 200 {"booking": null}. Resolve first, then guard.
        $booking = $booking->first();
        if ($booking == null) {
            return response()->json(['error' => 'Invalid booking id'], 404);
        }

        return response()->json(['booking' => $booking]);
    }

    /**
     * Cancel a booking and release its seat.
     *
     * A passenger may cancel only their OWN booking, and only while it is a live
     * UNPAID hold. A paid/confirmed seat needs a refund flow, not a self-service
     * cancel; a boarded booking cannot be undone. Staff with Edit Passengers may
     * cancel any booking from the dashboard. Cancelling flips `status` to false,
     * which the seat-occupancy query already treats as free -- the same release
     * the expired-hold sweep performs.
     */
    public function cancelBooking(Request $request, $id)
    {
        $booking = Booking::where('id', $id)->first();
        if ($booking == null) {
            return response()->json(['error' => 'Invalid booking id'], 404);
        }

        $isStaff = auth()->user()->can('Edit Passengers');
        if (! $isStaff && (int) $booking->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'This booking is not yours.'], 403);
        }
        if ((bool) $booking->boarded) {
            return response()->json(['error' => 'A boarded booking cannot be cancelled.'], 422);
        }
        if ((bool) $booking->paid) {
            return response()->json(['error' => 'A paid booking cannot be cancelled here. Contact support for a refund.'], 422);
        }
        if (! (bool) $booking->status) {
            // Already released/cancelled -- idempotent, so a double-tap is safe.
            return response()->json(['success' => 'Booking already cancelled.', 'booking_id' => (int) $booking->id]);
        }

        $booking->status = false;
        $booking->save();

        return response()->json(['success' => 'Booking cancelled and seat released.', 'booking_id' => (int) $booking->id]);
    }

    public function getParcels(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;

        $from_date = $request->date != '' ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $parcels = Parcel::with(['from', 'to', 'creator', 'vehicle.sacco'])
            ->whereBetween('created_at', [$from_date, $to_date]);
        // Only filter when a term was actually typed. An empty box turns this
        // into LIKE '%%' on every column in the group, OR'd with any whereHas
        // below — none of it indexable. The guard wraps the WHOLE group on
        // purpose: guarding one column leaves the orWhere siblings matching
        // unconditionally, which is worse than no guard.
        if (filled($request->search)) {
            $parcels = $parcels->where(function ($q) use ($request) {
                $q->where('recipient_name', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('recipient_phone', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('recipient_idno', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('sender_name', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('sender_phone', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('sender_idno', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('name', LikeSql::op(), '%'.$request->search.'%');
            });
        }
        if ($request->vehicle > 0) {
            $parcels = $parcels->where('vehicle_id', $request->vehicle);
        }
        if ($request->sacco > 0) {
            $parcels = $parcels->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if ($request->from > 0) {
            $parcels = $parcels->where('from_id', $request->from);
        }
        if ($request->to > 0) {
            $parcels = $parcels->where('to_id', $request->to);
        }
        $parcels = $parcels->skip($offset)->take(20)
            ->orderBy('created_at', 'DESC')->get();

        return response()->json(['parcels' => $parcels]);
    }

    public function pickPassenger(Request $request)
    {
        $booking = Booking::with('queue.vehicle.sacco', 'from')->where('id', $request->id)->first();
        if ($booking != null) {
            $myBooking = Booking::find($request->id);
            $myBooking->boarded = true;
            $myBooking->start_time = Carbon::now();
            if ($myBooking->save()) {
                // send message
                $message = "Hi $booking->name. Your vehicle, ".$booking->queue->vehicle->plate.', has arrived at your pickup, '.$booking->from->name.
                    '. We wish you a safe journey. Thank you for travelling with '.$booking->queue->vehicle->sacco->name;
                dispatch(new SendSMSJob($booking->phone, $message));
                /* new SendSMSJob($booking->phone, $message); */
                // (new SendSMSController)->sendSMS($booking->phone, $message);

                $tokens = FirebaseToken::where('user_id', $booking->user_id)->pluck('firebase_token');
                if (! empty($tokens)) {
                    // send FCM message
                    // $message = $booking->name . " has booked ".$queue->vehicle->plate." from $departure  to $destination. Book is awaiting payments!";
                    $title = $booking->queue->vehicle->plate.' Arrived at '.$booking->from->name;
                    foreach ($tokens as $token) {
                        dispatch(new SendFCMJob($token, $title, $message, 'bookings_screen', $booking->id));
                    }
                    /* new SendFCMJob($tokens, $title, $message); */
                    // (new SendFCMMessageController)->sendFCMNotification($tokens, $title, $message, 'bookings_screen');
                }

                return response()->json(['success' => 'Passenger Picked Successfully!']);
            }
        } else {
            return response()->json(['error' => 'Invalid booking id'], 400);
        }
    }

    public function pickPassengers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'queueId' => 'integer|required|exists:queues,id',
            'pickupId' => 'integer|required|exists:queue_places,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 401);
        }
        $queuePlace = QueuePlace::with('route_stage.place')->where('id', $request->pickupId)->first();
        $queue = Queue::with('vehicle.sacco', 'route.to')->where('id', $request->queueId)->first();
        $bookings = Booking::with(['queue.vehicle', 'from', 'user.firebase_tokens'])->where('queue_id', $request->queueId)
            ->where('from_id', $queuePlace->route_stage->place->id);
        if ($bookings->count() > 0) {
            $tokens = FirebaseToken::select('firebase_token')->whereIn('user_id', $bookings->get()->pluck('user.id'))->groupBy('firebase_token')->pluck('firebase_token');

            $phones = $bookings->select('phone')->groupBy('phone')->pluck('phone');
            $phones = str_replace('[', '', $phones);
            $phones = str_replace(']', '', $phones);
            $phones = str_replace('"', '', $phones);
            // send message
            $message = 'Vehicle, '.$queue->vehicle->plate.', has arrived at your pickup, '.$queuePlace->route_stage->place->name.
                '. We wish you a safe journey. Thank you for travelling with '.$queue->vehicle->sacco->name;
            // dispatch(new SendSMSJob($booking->phone, $message));
            // new SendSMSJob($booking->phone, $message);

            // (new SendSMSController)->sendSMS($phones, $message);

            if (! empty($tokens)) {
                // send FCM message
                // $message = $booking->name . " has booked ".$queue->vehicle->plate." from $departure  to $destination. Book is awaiting payments!";
                $title = $queue->vehicle->plate.' Arrived at '.$queuePlace->route_stage->place->name;
                foreach ($tokens as $token) {
                    dispatch(new SendFCMJob($token, $title, $message, 'bookings_screen', $queue->id));
                }
                // new SendFCMJob($tokens, $title, $message);
                // (new SendFCMMessageController)->sendFCMNotification($tokens, $title, $message, 'bookings_screen');
            }
            Booking::with(['queue.vehicle', 'from', 'user.firebase_tokens'])->where('queue_id', $request->queueId)
                ->where('from_id', $queuePlace->route_stage->place->id)->update(['start_time' => Carbon::now(), 'boarded' => true]);
            // return response()->json(['success' => 'Passengers Picked Successfully!']);

        }
        $bookings = Booking::with(['queue.vehicle', 'from', 'user.firebase_tokens'])->where('queue_id', $request->queueId)
            ->where('to_id', $queuePlace->route_stage->place->id);
        if ($bookings->count() > 0) {
            $tokens = FirebaseToken::select('firebase_token')->whereIn('user_id', $bookings->get()->pluck('user.id'))->groupBy('firebase_token')->pluck('firebase_token');

            $phones = $bookings->select('phone')->groupBy('phone')->pluck('phone');
            $phones = str_replace('[', '', $phones);
            $phones = str_replace(']', '', $phones);
            $phones = str_replace('"', '', $phones);
            // send message
            $message = 'Vehicle, '.$queue->vehicle->plate.', has arrived at your dropoff, '.$queuePlace->route_stage->place->name.
                '. You can now alight. Thank you for travelling with '.$queue->vehicle->sacco->name;
            // dispatch(new SendSMSJob($booking->phone, $message));
            // new SendSMSJob($booking->phone, $message);*/

            // (new SendSMSController)->sendSMS($phones, $message);

            if (! empty($tokens)) {
                // send FCM message
                // $message = $booking->name . " has booked ".$queue->vehicle->plate." from $departure  to $destination. Book is awaiting payments!";
                $title = $queue->vehicle->plate.' Arrived at '.$queuePlace->route_stage->place->name;
                foreach ($tokens as $token) {
                    dispatch(new SendFCMJob($token, $title, $message, 'bookings_screen', $queue->id));
                }
                // new SendFCMJob($tokens, $title, $message);
                // (new SendFCMMessageController)->sendFCMNotification($tokens, $title, $message, 'bookings_screen');
            }
            Booking::with(['queue.vehicle', 'from', 'user.firebase_tokens'])->where('queue_id', $request->queueId)
                ->where('from_id', $queuePlace->route_stage->place->id)->update(['stop_time' => Carbon::now()]);
        }
        if ($queue->route->to_id == $queuePlace->route_stage->place->id) {
            $myQueue = Queue::find($queue->id);
            $queueStatus = QueueStatus::where('status', 'Completed')->first();
            $myQueue->queue_status_id = $queueStatus->id;
            $myQueue->save();
        }

        return response()->json(['success' => 'Passengers Picked Successfully!']);
    }
}
