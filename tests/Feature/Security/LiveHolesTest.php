<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\Terminus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Six holes found open in production on 2026-08-26, each verified against the
 * live database before being closed.
 *
 * They are grouped here rather than scattered because they share one shape: a
 * PERMISSION was being treated as an ANSWER. "Holds Add Vehicles" was read as
 * "may edit this vehicle", "holds View Queues" as "may read this trip's
 * passengers", "knows a booking id" as "owns that booking". A permission says
 * what kind of thing you may do; it never says which row you may do it to.
 */
final class LiveHolesTest extends QueueTestCase
{
    private function staffOf(array $world, array $permissions): User
    {
        $u = $this->makeUser($permissions, $world['sacco']);
        $u->type = UserType::Admin;
        $u->sacco_id = $world['sacco']->id;
        $u->save();

        return $u->fresh();
    }

    private function passenger(): User
    {
        $u = $this->makeUser([], null);
        $u->type = UserType::Passenger;
        $u->save();

        return $u->fresh();
    }

    // ---------------------------------------------------------------- S2

    #[Test]
    public function add_vehicles_alone_cannot_edit_an_existing_bus(): void
    {
        // The investor case. `Add Vehicles` is in the Investor bundle, and this
        // endpoint does both add and edit depending on whether an id is sent —
        // so OR-ing the two permissions let an investor rewrite till_number,
        // merchant_short_code, ncba_till and coop_till on all 180 of NICCO's
        // buses. Those fields decide which account the fares land in.
        $world = $this->makeWorld();
        $investor = $this->staffOf($world, ['Add Vehicles']);

        $vehicle = $world['vehicle'];
        $vehicle->forceFill(['till_number' => 111111])->save();

        Sanctum::actingAs($investor);

        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => $vehicle->id,
            'plate' => $vehicle->plate,
            'seat' => $world['seat']->name,
            'till_number' => 999999,
            'status' => 1,
        ])->assertStatus(401);

        $this->assertSame(111111, (int) $vehicle->fresh()->till_number, 'the till must not have moved');
    }

    #[Test]
    public function editing_a_vehicle_does_not_hand_the_editor_ownership(): void
    {
        // user_id was reassigned on every save, so it recorded the last person
        // to touch the row rather than who owns the bus — and an edit quietly
        // transferred ownership to whoever made it.
        $world = $this->makeWorld();
        $owner = $world['owner'];
        $editor = $this->staffOf($world, ['Edit Vehicles']);

        Sanctum::actingAs($editor);

        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => $world['vehicle']->id,
            'plate' => $world['vehicle']->plate,
            'seat' => $world['seat']->name,
            'status' => 1,
        ]);

        $this->assertSame(
            $owner->id,
            (int) $world['vehicle']->fresh()->user_id,
            'an edit must not transfer ownership to the editor'
        );
    }

    // ---------------------------------------------------------------- S3

    #[Test]
    public function the_stk_push_is_not_reachable_without_signing_in(): void
    {
        // It sat in the PUBLIC route group with no middleware while its own
        // status and cancel siblings were gated. Anyone could post a sequential
        // booking id and any phone number and raise a real PIN prompt on that
        // handset.
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/auth/mpesa/stk');

        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());

        // GET too: Route::any put a payment trigger somewhere a link preview
        // could fetch on its own.
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));
    }

    #[Test]
    public function one_passenger_cannot_push_an_stk_prompt_for_another_passengers_booking(): void
    {
        $world = $this->makeWorld();
        $victim = $this->passenger();
        $attacker = $this->passenger();

        $booking = $this->bookingFor($world, $victim, 150);

        Sanctum::actingAs($attacker);

        $this->postJson('/api/v1/auth/mpesa/stk', [
            'phone' => '0722000111',
            'booking_id' => $booking->id,
        ])->assertStatus(403);
    }

    // ---------------------------------------------------------------- S4

    #[Test]
    public function one_passenger_cannot_seize_and_reprice_another_passengers_booking(): void
    {
        // Booking::find on a caller-supplied id with no ownership test, followed
        // by an overwrite of name, phone, amount AND user_id — the row stopped
        // being the victim's and became the caller's, at a price they chose.
        $world = $this->makeWorld();
        $victim = $this->passenger();
        $attacker = $this->passenger();

        $booking = $this->bookingFor($world, $victim, 200);

        Sanctum::actingAs($attacker);

        $this->postJson('/api/v1/auth/book_a_ride/booking/add', [
            'id' => $booking->queue_id,
            'booking_id' => $booking->id,
            'name' => 'Attacker',
            'phone' => '0722000111',
            // A STRING, as the validator requires. Request-shape validation
            // runs before authorisation and should — a malformed request is a
            // 400 whoever sends it, and that leaks nothing. Sending an array
            // here made this test pass through the validator instead of
            // reaching the ownership check it exists to prove.
            'seats' => '[]',
            'amount' => 1,
        ])->assertStatus(403);

        $fresh = $booking->fresh();
        $this->assertSame($victim->id, (int) $fresh->user_id, 'ownership must not move');
        $this->assertSame(200.0, (float) $fresh->amount, 'the fare must not be rewritten');
    }

    // ---------------------------------------------------------------- S5

    #[Test]
    public function a_driver_cannot_read_another_buses_passenger_list_in_their_own_sacco(): void
    {
        // Confirmed live: driver 6802 (SACCO 42, crews vehicle 886) read queue 1,
        // which belongs to a different bus. `View Queues` is held by the
        // production Driver AND Conductor roles, and it was the only gate.
        $world = $this->makeWorld();
        $otherBus = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $driver = $this->makeUser(['View Queues'], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver, 'sacco_id' => $world['sacco']->id])->save();

        // They crew THIS bus...
        VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now()->subDay(),
        ]);

        // ...and the queue belongs to that OTHER one.
        $queue = $this->queueOn($world, $otherBus);

        Sanctum::actingAs($driver->fresh());

        // The PASSENGER LIST is the leak — names, phones, and a full creator
        // User row. queues/view is deliberately not gated the same way: it
        // returns the trip's vehicle, route and status and no passenger data.
        $this->getJson('/api/v1/auth/queues/bookings/view/'.$queue->id)->assertStatus(403);
    }

    #[Test]
    public function the_crew_on_a_trip_can_still_read_it(): void
    {
        // The fix must not lock the driver out of the bus they are running.
        $world = $this->makeWorld();

        $driver = $this->makeUser(['View Queues'], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver, 'sacco_id' => $world['sacco']->id])->save();

        VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now()->subDay(),
        ]);

        $queue = $this->queueOn($world, $world['vehicle']);

        Sanctum::actingAs($driver->fresh());

        $this->getJson('/api/v1/auth/queues/bookings/view/'.$queue->id)->assertOk();
    }

    #[Test]
    public function the_sacco_office_can_still_watch_every_trip(): void
    {
        // A dispatcher legitimately reads across the fleet.
        $world = $this->makeWorld();
        $dispatcher = $this->staffOf($world, ['View Queues', 'View Passengers']);
        $queue = $this->queueOn($world, $world['vehicle']);

        Sanctum::actingAs($dispatcher);

        $this->getJson('/api/v1/auth/queues/bookings/view/'.$queue->id)->assertOk();
    }

    // ---------------------------------------------------------------- S6

    #[Test]
    public function a_sacco_admin_cannot_rename_a_terminus_every_other_sacco_uses(): void
    {
        // `termini` has no sacco_id — 41 rows, shared by all 48 SACCOs — so
        // there was no ownership test to make, and `Edit Termini` is held by
        // SACCO Admin.
        $world = $this->makeWorld();
        $admin = $this->staffOf($world, ['Edit Termini', 'Add Termini']);

        $terminus = $world['terminus'];
        $original = $terminus->name;

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/routes/terminus/add', [
            'id' => $terminus->id,
            'name' => 'Renamed By Another Sacco',
            'place' => $world['from']->name,
            'status' => 0,
        ])->assertStatus(403);

        $this->assertSame($original, $terminus->fresh()->name);
        $this->assertTrue((bool) $terminus->fresh()->status, 'status must not have been switched off');
    }

    // ---------------------------------------------------------------- helpers

    private function bookingFor(array $world, User $owner, float $amount): Booking
    {
        $queue = $this->queueOn($world, $world['vehicle']);

        $booking = $this->makeBooking($queue, $owner, $world['from'], $world['to'], 'Victim');
        $booking->forceFill([
            'amount' => $amount,
            'created_by' => $owner->id,
            'paid' => false,
        ])->save();

        return $booking->fresh();
    }

    private function queueOn(array $world, Vehicle $vehicle): Queue
    {
        return $this->makeQueue(
            $vehicle,
            $world['terminus'],
            $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'),
            $world['owner'],
            'QN-'.$this->nextSequence()
        );
    }
}
