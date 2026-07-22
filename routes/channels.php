<?php

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
    $bookedIt = \App\Models\Booking::withoutGlobalScopes()
        ->where('queue_id', $queueId)
        ->where('user_id', $user->id)
        ->exists();
    if ($bookedIt) {
        return true;
    }

    $queue = \App\Models\Queue::withoutGlobalScopes()->find($queueId);
    if ($queue === null) {
        return false;
    }

    return \App\Models\VehicleUser::where('vehicle_id', $queue->vehicle_id)
        ->where('user_id', $user->id)
        ->where('status', true)
        ->exists();
});
