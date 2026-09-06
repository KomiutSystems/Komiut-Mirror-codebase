<?php

use App\Models\Booking;
use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleUser;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
 * Live vehicle position for a trip. A passenger who has booked this queue — or
 * the crew driving its vehicle — may listen. Brand/sacco scopes are dropped here
 * because the /broadcasting/auth request carries no brand context; access is
 * decided purely by "did you book it / do you drive it".
 */
Broadcast::channel('trip.{queueId}', function ($user, int $queueId) {
    $bookedIt = Booking::withoutGlobalScopes()
        ->where('queue_id', $queueId)
        ->where('user_id', $user->id)
        ->exists();
    if ($bookedIt) {
        return true;
    }

    $queue = Queue::withoutGlobalScopes()->find($queueId);
    if ($queue === null) {
        return false;
    }

    return VehicleUser::where('vehicle_id', $queue->vehicle_id)
        ->where('user_id', $user->id)
        ->where('status', true)
        ->exists();
});

/*
 * The single cross-brand super-admin console channel. Only super admins may
 * listen — the platform role is above the brand boundary, so one channel carries
 * every brand's events (brand is a field on each). Base name 'super' authorises
 * subscriptions to 'private-super'.
 */
Broadcast::channel('super', function ($user) {
    return $user instanceof User && $user->isSuperAdmin();
});

/*
 * Payments landing on one bus, for the people working it.
 *
 * Authorised by the SAME rule every driver endpoint uses — an open assignment
 * in vehicle_users (status true, no end_date) — because crews rotate between
 * matatus and the money belongs to the till, not the person. A driver who came
 * off this bus yesterday must stop hearing its takings today.
 *
 * withoutGlobalScopes for the reason the trip channel documents: the
 * /broadcasting/auth request carries no brand context, so a scoped lookup would
 * fail closed for a caller who is genuinely on the bus. Access here is decided
 * purely by "is this your vehicle right now".
 *
 * Owners are attached through this same table, so an investor listening to
 * their own fleet authorises naturally — one of them holds 40 vehicles.
 */
Broadcast::channel('vehicle.{vehicleId}', function ($user, int $vehicleId) {
    return VehicleUser::withoutGlobalScopes()
        ->where('vehicle_id', $vehicleId)
        ->where('user_id', $user->id)
        ->where('status', true)
        ->whereNull('end_date')
        ->exists();
});
