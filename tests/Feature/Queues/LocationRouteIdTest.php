<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\User;
use App\Models\VehicleLocation;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * `route_id` on a location ping — the return-leg case.
 *
 * A driver is not always on a queue. They finish a trip, turn around and drive
 * back; or they go live while parked at the stage before joining one. In both,
 * the bus is on a ROUTE without being on a TRIP, and the route is what tells a
 * passenger looking at the map which way it is heading. `nearby` both returns
 * `route_id` and filters on it, so this is the field that makes a direction
 * filter possible at all.
 *
 * Written because the driver app is now sending this field and the docblock did
 * not mention it — so the only way to learn whether it was honoured, ignored or
 * rejected was to read VehicleLocationService::update. A parameter another team
 * depends on should not be discoverable solely from the implementation, and it
 * should not be able to disappear silently. These pin the three answers: it is
 * accepted, it is stored, and the queue wins when both are in play.
 *
 * NOTE THE AUTHORISATION ASYMMETRY, which these tests discovered the hard way and
 * which the driver app needs to know. WITH a queue, crews() admits the vehicle's
 * OWNER as well as its crew. WITHOUT one, the only boundary left is the caller's
 * own open `vehicle_users` assignment (ResolvesDriverVehicle::vehicle), so an
 * owner holding no assignment gets 403 "You have no active vehicle assignment."
 * A driver going live OFF-TRIP must therefore hold an active assignment — which
 * the real KDQ 658D driver does.
 */
final class LocationRouteIdTest extends QueueTestCase
{
    private const URL = '/api/auth/book_a_ride/location';

    /** A driver with a live crew assignment — the no-queue path's only boundary. */
    private function crew(array $world): User
    {
        $driver = $this->makeUser([], $world['sacco']);

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return $driver;
    }

    #[Test]
    public function a_route_id_is_accepted_and_stored_when_there_is_no_queue(): void
    {
        $world = $this->makeWorld();

        Sanctum::actingAs($this->crew($world));
        $this->postJson(self::URL, [
            'latitude' => -1.2833,
            'longitude' => 36.8167,
            'route_id' => $world['route']->id,
        ])->assertStatus(202)->assertJsonPath('status', 'broadcasting');

        $location = VehicleLocation::where('vehicle_id', $world['vehicle']->id)->first();

        $this->assertNotNull($location);
        $this->assertSame((int) $world['route']->id, (int) $location->route_id,
            'the return leg has no queue, so route_id is the only thing saying which way the bus is going');
        $this->assertNull($location->queue_id);
        $this->assertTrue((bool) $location->broadcasting);
    }

    #[Test]
    public function an_unknown_route_id_is_a_400_not_a_silent_drop(): void
    {
        // `exists:routes,id` — a stale route id fails loudly rather than recording
        // a position with no direction. 400, NOT 422: the controller returns its
        // own response from Validator::make rather than letting Laravel's 422
        // through, so a client checking for 422 would read a rejection as success.
        $world = $this->makeWorld();

        Sanctum::actingAs($this->crew($world));
        $this->postJson(self::URL, [
            'latitude' => -1.2833,
            'longitude' => 36.8167,
            'route_id' => 999999999,
        ])->assertStatus(400)->assertJsonStructure(['errors' => ['route_id']]);
    }

    #[Test]
    public function the_queues_own_route_wins_when_the_bus_is_actually_on_a_trip(): void
    {
        // VehicleLocationService::update resolves `$queue?->route_id ?? $routeId`.
        // A client that sends route_id on every ping — the simplest thing to
        // build — must not be able to relabel a live trip's direction.
        $world = $this->makeWorld();
        $active = $this->makeQueueStatus('rid-active', 'Active');
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner'],
        );

        // A DIFFERENT route, so "the queue's route won" is distinguishable from
        // "route_id happened to match".
        $other = $this->makeRoute($world['to'], $world['from'], $world['sacco']);

        Sanctum::actingAs($world['owner']);
        $this->postJson(self::URL, [
            'latitude' => -1.2833,
            'longitude' => 36.8167,
            'queue_id' => $queue->id,
            'route_id' => $other->id,
        ])->assertStatus(202);

        $location = VehicleLocation::where('vehicle_id', $world['vehicle']->id)->first();

        $this->assertSame((int) $queue->route_id, (int) $location->route_id,
            "the trip's own route is authoritative; a ping may not relabel it");
        $this->assertNotSame((int) $other->id, (int) $location->route_id);
        $this->assertSame((int) $queue->id, (int) $location->queue_id);
    }

    #[Test]
    public function a_ping_with_neither_queue_nor_route_still_records_a_position(): void
    {
        // Being live and being on a trip are independent — the controller says so
        // explicitly. A bus with no route context is still on the map.
        $world = $this->makeWorld();

        Sanctum::actingAs($this->crew($world));
        $this->postJson(self::URL, [
            'latitude' => -1.2833,
            'longitude' => 36.8167,
        ])->assertStatus(202);

        $location = VehicleLocation::where('vehicle_id', $world['vehicle']->id)->first();

        $this->assertNotNull($location);
        $this->assertTrue((bool) $location->broadcasting);
    }

    #[Test]
    public function an_owner_with_no_assignment_cannot_broadcast_off_trip(): void
    {
        // The asymmetry itself, pinned. This is the 403 that made the first draft
        // of this file fail, and it is correct: off-trip, the assignment is the
        // only thing proving the caller is actually driving that bus.
        $world = $this->makeWorld();

        Sanctum::actingAs($world['owner']);
        $this->postJson(self::URL, [
            'latitude' => -1.2833,
            'longitude' => 36.8167,
            'route_id' => $world['route']->id,
        ])->assertStatus(403)->assertJsonPath('error', 'You have no active vehicle assignment.');
    }
}
