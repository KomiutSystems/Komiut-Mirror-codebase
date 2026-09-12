<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Models\Booking;
use App\Models\SaccoRoute;
use App\Models\SeatBooking;
use App\Models\User;
use App\Models\VehicleLocation;
use App\Services\Fares\FareResolver;
use App\Services\Location\VehicleLocationService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The gap the mobile team is blocked on: reserving a seat on a roaming vehicle.
 *
 * `book_a_ride/booking/add` is queue-based — it needs a queue position and stop
 * ids the roadside passenger does not have. `book_a_ride/broadcast/reserve` sells
 * the seat the driver's live position is already advertising, and must refuse
 * every way the advertisement can be false: not broadcasting, position gone
 * stale, or the bus already full.
 */
final class BroadcastReservationTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/book_a_ride/broadcast/reserve';

    /**
     * A vehicle mid-trip on a three-stop route, with its live position pinged now.
     *
     * makeWorld() puts every stop at the same coordinates, which would make the
     * GPS snap meaningless (and its result order-dependent), so the stops are
     * spread out here: origin in the CBD, a mid-route stop at Ruiru, destination
     * at Thika. The default pickup point sits beside the Ruiru stop.
     *
     * @return array<string, mixed>
     */
    private function broadcastingWorld(int $capacity = 4, ?int $ageSeconds = 0, bool $broadcasting = true): array
    {
        $world = $this->makeWorld();

        $world['stages'][0]->update(['latitude' => -1.2833, 'longitude' => 36.8167]);
        $world['stages'][1]->update(['latitude' => -1.0333, 'longitude' => 37.0693]);
        $mid = $this->makePlace('Ruiru '.$this->nextSequence());
        $midStage = $this->makeRouteStage($world['route'], $mid, 20);
        $midStage->update(['latitude' => -1.1500, 'longitude' => 36.9600]);

        $seat = $this->makeSeat($capacity);
        $arrangements = $this->makeSeatArrangements($seat, $capacity);
        $vehicle = $this->makeVehicle($world['sacco'], $world['owner'], $seat);

        $status = $this->makeQueueStatus('On the road', 'Active');
        $queue = $this->makeQueue($vehicle, $world['terminus'], $world['route'], $status, $world['owner']);

        VehicleLocation::create([
            'vehicle_id' => $vehicle->id,
            'route_id' => $world['route']->id,
            'queue_id' => $queue->id,
            'latitude' => -1.28,
            'longitude' => 36.8,
            'heading' => 90,
            'broadcasting' => $broadcasting,
            'recorded_at' => $ageSeconds === null ? null : now()->subSeconds($ageSeconds),
        ]);

        // array_replace, not `+`: makeWorld() already supplies seat/vehicle keys
        // and `+` would silently keep those instead of the broadcasting ones.
        return array_replace($world, [
            'seat' => $seat,
            'arrangements' => $arrangements,
            'vehicle' => $vehicle,
            'queue' => $queue,
            'mid' => $mid,
        ]);
    }

    /** A passenger: no SACCO of their own, so SaccoScope does not narrow them. */
    private function passenger(): User
    {
        return $this->makeUser();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $world, array $overrides = []): array
    {
        return array_merge([
            'vehicle_id' => $world['vehicle']->id,
            'seats' => 1,
            // A few hundred metres from the Ruiru stop, far from CBD and Thika.
            'pickup_latitude' => -1.1520,
            'pickup_longitude' => 36.9615,
        ], $overrides);
    }

    #[Test]
    public function a_passenger_reserves_a_seat_on_a_broadcasting_vehicle(): void
    {
        $world = $this->broadcastingWorld();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $response = $this->postJson(self::ENDPOINT, $this->payload($world, ['seats' => 2]))
            ->assertOk()
            ->assertJsonPath('booking_type', 'pickAsYouGo')
            ->assertJsonPath('queue_id', $world['queue']->id)
            ->assertJsonPath('passengers', 2)
            // Fare is the SACCO's flat route price, never the client's number,
            // and `amount` is the WHOLE fare: two seats at KES 200. A whole
            // shilling serialises as JSON `400`, not `400.0`.
            ->assertJsonPath('amount', 400)
            ->assertJsonPath('fare_per_seat', 200)
            ->assertJsonPath('vehicle.id', $world['vehicle']->id);

        $bookingId = $response->json('booking_id');

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'queue_id' => $world['queue']->id,
            'user_id' => $passenger->id,
            'passengers' => 2,
            'booking_type' => 'pick_as_you_go',
            'paid' => false,
        ]);

        // Seat rows exist so the ticket and the driver's manifest name seats the
        // same way a terminus booking does.
        $this->assertCount(2, $response->json('seats'));
        $this->assertSame(2, SeatBooking::where('booking_id', $bookingId)->count());

        // The GPS point is snapped onto the NEAREST stop on the run's route (not
        // simply the route origin), and the dropoff defaults to the destination.
        $this->assertSame($world['mid']->id, $response->json('pickup.place_id'));
        $this->assertSame($world['to']->id, $response->json('dropoff.place_id'));
        $this->assertLessThan(1.0, $response->json('pickup.snapped_distance_km'));
    }

    #[Test]
    public function a_vehicle_that_is_not_broadcasting_is_refused(): void
    {
        // The driver ended the trip: the seat was never on offer.
        $world = $this->broadcastingWorld(broadcasting: false);
        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, $this->payload($world))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'not_broadcasting');

        $this->assertDatabaseCount('bookings', 0);
    }

    #[Test]
    public function a_stale_position_is_refused(): void
    {
        // One second past the live-map freshness window: the map would already
        // have dropped this vehicle, so reserving on it would strand a passenger.
        $world = $this->broadcastingWorld(ageSeconds: VehicleLocationService::FRESH_SECONDS + 1);
        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, $this->payload($world))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'stale_position');

        $this->assertDatabaseCount('bookings', 0);
    }

    #[Test]
    public function a_position_inside_the_freshness_window_is_still_reservable(): void
    {
        // Guards the boundary from the other side, so "stale" can't creep to "any".
        $world = $this->broadcastingWorld(ageSeconds: VehicleLocationService::FRESH_SECONDS - 5);
        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, $this->payload($world))->assertOk();
    }

    #[Test]
    public function a_vehicle_with_no_live_position_at_all_is_refused(): void
    {
        $world = $this->makeWorld();
        $status = $this->makeQueueStatus('On the road', 'Active');
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $status, $world['owner']);

        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, $this->payload($world))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'not_broadcasting');
    }

    #[Test]
    public function a_ping_with_no_queue_stamped_falls_back_to_the_vehicles_live_trip(): void
    {
        // The coupling this endpoint rests on: broadcastLocation requires a
        // queue_id, so `vehicle_locations.queue_id` is always stamped and IS the
        // run. The column is nullable though, so if that ever changes the run is
        // resolved from the vehicle's own live queue rather than 500ing.
        $world = $this->broadcastingWorld();
        VehicleLocation::where('vehicle_id', $world['vehicle']->id)->update(['queue_id' => null]);

        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, $this->payload($world))
            ->assertOk()
            ->assertJsonPath('queue_id', $world['queue']->id);
    }

    #[Test]
    public function a_broadcasting_vehicle_on_no_live_trip_at_all_is_refused(): void
    {
        // Nothing to book onto: refuse rather than invent a run, since
        // `bookings.queue_id` is NOT NULL and a booking with no trip is unusable.
        $world = $this->broadcastingWorld();
        VehicleLocation::where('vehicle_id', $world['vehicle']->id)->update(['queue_id' => null]);
        $world['queue']->queue_status->update(['status' => 'Completed']);

        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, $this->payload($world))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'no_active_trip');

        $this->assertDatabaseCount('bookings', 0);
    }

    #[Test]
    public function a_booking_is_a_passenger_count_not_a_seat_hold(): void
    {
        // Decided 2026-09-12. The driver broadcasting IS the statement that
        // there is room: nothing is refused for capacity, no seat is locked
        // while a passenger pays, and the seat ids on the ticket are labels.
        // Until then this endpoint counted segment-aware occupancy against the
        // vehicle's seat map and answered 409 no_seats when the run was full.
        $world = $this->broadcastingWorld(capacity: 1);

        // Three passengers, one physical seat, three bookings. Each carries the
        // same seat label, and that is fine -- it is a label.
        foreach (range(1, 3) as $i) {
            Sanctum::actingAs($this->passenger());
            $this->postJson(self::ENDPOINT, $this->payload($world))->assertOk()->assertJsonPath('passengers', 1);
        }
        $this->assertSame(3, Booking::withoutGlobalScopes()->where('queue_id', $world['queue']->id)->count());

        // A terminus booking on the same run does not reduce anything either.
        $existing = $this->makeBooking($world['queue'], $this->passenger(), $world['from'], $world['to']);
        $existing->update(['passengers' => 3]);
        Sanctum::actingAs($this->passenger());
        $this->postJson(self::ENDPOINT, $this->payload($world, ['seats' => 2]))
            ->assertOk()
            ->assertJsonPath('passengers', 2)
            ->assertJsonPath('amount', 400)
            ->assertJsonMissingPath('seats_remaining');
    }

    #[Test]
    public function the_only_cap_is_the_five_seat_booking_limit(): void
    {
        $world = $this->broadcastingWorld(capacity: 14);
        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, $this->payload($world, ['seats' => 6]))
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['seats']]);
        $this->postJson(self::ENDPOINT, $this->payload($world, ['seats' => 5]))->assertOk()->assertJsonPath('passengers', 5);
    }

    #[Test]
    public function a_vehicle_with_no_seat_map_is_still_bookable_and_carries_no_seat_labels(): void
    {
        $world = $this->broadcastingWorld(capacity: 0);
        Sanctum::actingAs($this->passenger());

        $response = $this->postJson(self::ENDPOINT, $this->payload($world))->assertOk();

        $this->assertSame([], $response->json('seats'));
        $this->assertSame(0, SeatBooking::where('booking_id', $response->json('booking_id'))->count());
    }

    #[Test]
    public function an_unauthenticated_request_is_refused_with_401_not_500(): void
    {
        // The exact bug a sibling endpoint shipped: CheckAPIUserStatus reads
        // Auth::user()->status with no null guard, so a route registered inside
        // that group answers an anonymous caller with a 500.
        $world = $this->broadcastingWorld();

        $this->postJson(self::ENDPOINT, $this->payload($world))->assertStatus(401);

        $this->assertDatabaseCount('bookings', 0);
    }

    #[Test]
    public function an_inactive_account_is_refused(): void
    {
        $world = $this->broadcastingWorld();
        Sanctum::actingAs($this->makeUser([], null, status: false));

        $this->postJson(self::ENDPOINT, $this->payload($world))->assertStatus(403);
    }

    #[Test]
    public function a_missing_pickup_point_is_a_validation_error(): void
    {
        $world = $this->broadcastingWorld();
        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, ['vehicle_id' => $world['vehicle']->id, 'seats' => 1])
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['pickup_latitude', 'pickup_longitude']]);
    }

    #[Test]
    public function a_dropoff_that_is_not_on_the_route_is_refused(): void
    {
        $world = $this->broadcastingWorld();
        $elsewhere = $this->makePlace('Mombasa');
        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, $this->payload($world, ['dropoff_place_id' => $elsewhere->id]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'dropoff_not_on_route');
    }

    #[Test]
    public function a_route_the_sacco_has_not_priced_is_refused_rather_than_guessed(): void
    {
        $world = $this->broadcastingWorld();
        SaccoRoute::withoutGlobalScopes()
            ->where('route_id', $world['route']->id)
            ->update(['status' => false]);
        app(FareResolver::class)->forget((int) $world['sacco']->id, (int) $world['route']->id);

        Sanctum::actingAs($this->passenger());

        $this->postJson(self::ENDPOINT, $this->payload($world))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'no_fare');

        $this->assertDatabaseCount('bookings', 0);
    }

    #[Test]
    public function a_passenger_may_name_the_stop_they_are_waiting_at(): void
    {
        // The honest way to say where you are: the app lists the stops on this
        // route and the passenger picks one, instead of the server guessing from
        // a GPS fix.
        $world = $this->broadcastingWorld();
        Sanctum::actingAs($this->passenger());

        $this->postJson('/api/v1/auth/book_a_ride/broadcast/reserve', $this->payload($world, [
            'pickup_place_id' => $world['mid']->id,
            // Deliberately nowhere near it — the named stop must win over GPS.
            'pickup_latitude' => -1.9000,
            'pickup_longitude' => 37.5000,
        ]))->assertOk();

        $this->assertDatabaseHas('bookings', [
            'queue_id' => $world['queue']->id,
            'from_id' => $world['mid']->id,
        ]);
    }

    #[Test]
    public function a_stop_that_is_not_on_this_route_is_refused(): void
    {
        $world = $this->broadcastingWorld();
        $elsewhere = $this->makePlace('Machakos '.$this->nextSequence());

        Sanctum::actingAs($this->passenger());

        $this->postJson('/api/v1/auth/book_a_ride/broadcast/reserve', $this->payload($world, [
            'pickup_place_id' => $elsewhere->id,
        ]))->assertStatus(422)->assertJsonPath('reason', 'pickup_not_on_route');
    }

    #[Test]
    public function a_passenger_nowhere_near_a_stop_is_told_to_walk_to_one(): void
    {
        // THE HOLE THIS CLOSES. snapToStop had no distance limit and fell back
        // to the route's ORIGIN when nothing matched, so a passenger twenty
        // kilometres off-route was booked from the start of the line: charged
        // for a journey they were not on, and shown to the driver as waiting at
        // a stop they were nowhere near.
        $world = $this->broadcastingWorld();
        Sanctum::actingAs($this->passenger());

        $this->postJson('/api/v1/auth/book_a_ride/broadcast/reserve', $this->payload($world, [
            'pickup_latitude' => -1.9000,
            'pickup_longitude' => 37.5000,
        ]))->assertStatus(422)->assertJsonPath('reason', 'not_at_a_stop');

        $this->assertDatabaseMissing('bookings', ['queue_id' => $world['queue']->id]);
    }

    #[Test]
    public function a_stop_with_no_coordinates_is_still_boardable_by_name(): void
    {
        // Six of eighteen route_stages carry no coordinates, so GPS can never
        // snap onto them however close a passenger stands. Naming the stop is
        // the only way to board there, which is most of why it exists.
        $world = $this->broadcastingWorld();
        $blind = $this->makePlace('Kalimoni '.$this->nextSequence());
        $this->makeRouteStage($world['route'], $blind, 10);

        Sanctum::actingAs($this->passenger());

        $this->postJson('/api/v1/auth/book_a_ride/broadcast/reserve', $this->payload($world, [
            'pickup_place_id' => $blind->id,
        ]))->assertOk();

        $this->assertDatabaseHas('bookings', [
            'queue_id' => $world['queue']->id,
            'from_id' => $blind->id,
        ]);
    }
}
