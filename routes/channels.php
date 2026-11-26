<?php

use App\Models\Booking;
use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleUser;
use App\Services\Buses\SavedBuses;
use App\Services\Money\SaccoLiveTakings;
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

/*
 * One bus's live position, for anyone who saved it in the passenger app.
 * See docs/school-bus-tracking.md ("Saved buses").
 *
 * Named by the bus's KEY (SavedBuses::key: its id and a server signature),
 * never its bare id: ids are sequential, and a channel named by id would let
 * anyone walk them and listen to every school bus without knowing a plate.
 * The key comes only from buses/search, by plate.
 *
 * Admitted exactly when buses/live would answer for that key
 * (SavedBuses::admitsToChannel): a key this server made, for a bus in
 * service, behind the same brand wall -- a matatu only from its own brand's
 * app, a school bus from either -- so the channel is never a way round
 * `missing`. The brand is read off this request's X-App-Key (or host), as
 * ResolveBrand would; when neither names a brand, no matatu is admitted.
 * Capped per user per minute: /broadcasting/auth has no throttle of its own.
 *
 * Read with the query builder, for the reason the trip channel documents.
 */
Broadcast::channel('bus.{busKey}', function ($user, string $busKey) {
    return $user instanceof User
        && app(SavedBuses::class)->admitsToChannel($user, $busKey, SavedBuses::brandOf(request()));
});

/*
 * A SACCO's takings, live on its web dashboard (SaccoLiveTakings): its own office
 * staff who may see its money, and super admins. Subscribing is what marks the
 * SACCO watched, so fares are only published while a dashboard is open.
 */
Broadcast::channel('sacco.{saccoId}', function ($user, int $saccoId) {
    if (! $user instanceof User || ! SaccoLiveTakings::mayListen($user, $saccoId)) {
        return false;
    }
    SaccoLiveTakings::markWatched($saccoId);

    return true;
});
