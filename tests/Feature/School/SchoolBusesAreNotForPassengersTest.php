<?php

declare(strict_types=1);

namespace Tests\Feature\School;

use App\Models\Booking;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A school bus is never offered to passengers (docs/school-bus-tracking.md,
 * rule 1). It goes live on a route exactly like a matatu -- same driver app,
 * same LiveRun -- so every passenger path that lists or books live buses has
 * to leave it out, in either brand.
 *
 * Every test here puts a normal matatu live alongside the school bus and
 * proves the matatu is still offered: a filter that hid both would pass the
 * "school bus absent" half on its own.
 */
final class SchoolBusesAreNotForPassengersTest extends QueueTestCase
{
    /** @return array{matatu: array, school: array, matatuRun: Queue, schoolRun: Queue} */
    private function bothLive(): array
    {
        $active = $this->makeQueueStatus('Active', 'Active');

        $matatu = $this->makeWorld();
        $school = $this->makeWorld();
        $school['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();

        $matatuRun = $this->makeQueue($matatu['vehicle'], $matatu['terminus'], $matatu['route'], $active, $matatu['owner'], 'LIVE');
        $schoolRun = $this->makeQueue($school['vehicle'], $school['terminus'], $school['route'], $active, $school['owner'], 'LIVE');
        $this->pingFrom($matatu['vehicle'], $matatuRun);
        $this->pingFrom($school['vehicle'], $schoolRun);

        return compact('matatu', 'school', 'matatuRun', 'schoolRun');
    }

    #[Test]
    public function the_passenger_trip_list_leaves_out_a_live_school_bus(): void
    {
        $w = $this->bothLive();
        Sanctum::actingAs($this->makeUser());

        $ids = collect($this->getJson('/api/v1/auth/book_a_ride/queues')->assertOk()->json('queues'))->pluck('id')->all();
        $this->assertSame([$w['matatuRun']->id], $ids);

        // Searching its plate does not find it either.
        $this->getJson('/api/v1/auth/book_a_ride/queues?search='.urlencode($w['school']['vehicle']->plate))
            ->assertOk()->assertJsonCount(0, 'queues');
        // Nor naming its stops.
        $this->getJson('/api/v1/auth/book_a_ride/queues?from_id='.$w['school']['from']->id.'&to_id='.$w['school']['to']->id)
            ->assertOk()->assertJsonCount(0, 'queues');
    }

    #[Test]
    public function nearby_leaves_out_a_school_bus(): void
    {
        $w = $this->bothLive();
        Sanctum::actingAs($this->makeUser());

        $ids = collect($this->getJson('/api/v1/auth/book_a_ride/nearby?latitude=-1.28&longitude=36.82')
            ->assertOk()->json('vehicles'))->pluck('vehicle_id')->all();

        $this->assertSame([$w['matatu']['vehicle']->id], $ids);
    }

    #[Test]
    public function the_route_catalogue_leaves_out_a_schools_routes_and_its_buses(): void
    {
        $w = $this->bothLive();
        Sanctum::actingAs($this->makeUser());

        $routes = collect($this->getJson('/api/v1/auth/book_a_ride/routes')->assertOk()->json('routes'));
        $this->assertSame([$w['matatu']['route']->id], $routes->pluck('id')->all());
        $this->assertSame([$w['matatuRun']->id], collect($routes->first()['queues'])->pluck('id')->all());

        $saccoRoutes = collect($this->getJson('/api/v1/auth/book_a_ride/route_saccos')->assertOk()->json('sacco_routes'));
        $this->assertSame([$w['matatu']['sacco']->id], $saccoRoutes->pluck('sacco_id')->unique()->values()->all());

        $popular = collect($this->getJson('/api/v1/auth/book_a_ride/routes/popular')->assertOk()->json('routes'));
        $this->assertSame([$w['matatu']['route']->id], $popular->pluck('id')->all());

        // A stop only the school's route serves is not offered as a stop.
        $stops = collect($this->getJson('/api/v1/auth/book_a_ride/stops')->assertOk()->json('stops'))->pluck('id');
        $this->assertTrue($stops->contains($w['matatu']['from']->id));
        $this->assertFalse($stops->contains($w['school']['from']->id));
    }

    #[Test]
    public function a_school_bus_trip_has_no_seat_map_for_a_passenger(): void
    {
        $w = $this->bothLive();
        Sanctum::actingAs($this->makeUser());

        // The same answer as a trip the caller cannot see.
        $this->getJson('/api/v1/auth/book_a_ride/seats?bus_id='.$w['school']['vehicle']->id.'&id='.$w['schoolRun']->id)
            ->assertNotFound()->assertExactJson(['error' => 'Trip not found.']);

        // A school bus's seat map is not handed out under a matatu's trip either.
        $this->getJson('/api/v1/auth/book_a_ride/seats?bus_id='.$w['school']['vehicle']->id.'&id='.$w['matatuRun']->id)
            ->assertOk()->assertJsonPath('seats', null);

        $this->getJson('/api/v1/auth/book_a_ride/seats?bus_id='.$w['matatu']['vehicle']->id.'&id='.$w['matatuRun']->id)
            ->assertOk()->assertJsonPath('seats.id', $w['matatu']['vehicle']->id);
    }

    #[Test]
    public function a_school_bus_cannot_be_booked_and_is_refused_like_a_bus_that_is_not_live(): void
    {
        $w = $this->bothLive();
        Sanctum::actingAs($this->makeUser());
        $body = fn (Queue $run) => ['id' => $run->id, 'passengers' => 1, 'name' => 'Tom', 'phone' => '0722123456'];

        $this->postJson('/api/v1/auth/book_a_ride/booking/add', $body($w['schoolRun']))
            ->assertStatus(422)
            ->assertExactJson(['error' => 'This vehicle is not live for booking. Pick a bus that is live on your route.']);
        $this->assertSame(0, Booking::withoutGlobalScopes()->where('queue_id', $w['schoolRun']->id)->count());

        $this->postJson('/api/v1/auth/book_a_ride/booking/add', $body($w['matatuRun']))->assertOk();
    }

    #[Test]
    public function a_school_bus_cannot_be_reserved_at_the_roadside(): void
    {
        $w = $this->bothLive();
        Sanctum::actingAs($this->makeUser());
        $body = fn (array $world) => [
            'vehicle_id' => $world['vehicle']->id, 'seats' => 1,
            'pickup_latitude' => -1.28, 'pickup_longitude' => 36.8,
        ];

        // Answered as a bus that is not on the road, which is what nearby implied.
        $this->postJson('/api/v1/auth/book_a_ride/broadcast/reserve', $body($w['school']))
            ->assertStatus(409)->assertJsonPath('reason', 'not_broadcasting');
        $this->assertSame(0, Booking::withoutGlobalScopes()->where('queue_id', $w['schoolRun']->id)->count());

        $this->postJson('/api/v1/auth/book_a_ride/broadcast/reserve', $body($w['matatu']))->assertOk();
    }

    #[Test]
    public function the_live_map_read_hides_school_buses_from_a_passenger_but_not_from_the_school(): void
    {
        $w = $this->bothLive();

        Sanctum::actingAs($this->makeUser());
        $ids = collect($this->getJson('/api/v1/auth/vehicle_locations')->assertOk()->json('data'))->pluck('vehicle_id')->all();
        $this->assertSame([$w['matatu']['vehicle']->id], $ids);

        // The school's own staff are confined to their fleet by SaccoScope,
        // and that fleet is school buses: they see them.
        Sanctum::actingAs($this->makeUser([], $w['school']['sacco']));
        $ids = collect($this->getJson('/api/v1/auth/vehicle_locations')->assertOk()->json('data'))->pluck('vehicle_id')->all();
        $this->assertSame([$w['school']['vehicle']->id], $ids);
    }

    #[Test]
    public function a_school_bus_driver_still_goes_live_on_a_route(): void
    {
        // Hiding it from passengers must not stop the school's own operation:
        // the ping is recorded and the run is created, for its followers.
        $this->makeQueueStatus('Active', 'Active');
        $school = $this->makeWorld();
        $school['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();
        $driver = $this->makeUser([], $school['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $school['vehicle']->id,
            'sacco_id' => $school['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        Sanctum::actingAs($driver);
        $ping = $this->postJson('/api/v1/auth/book_a_ride/location', [
            'latitude' => -1.2, 'longitude' => 36.9, 'route_id' => $school['route']->id,
        ])->assertStatus(202);

        $this->assertNotNull($ping->json('queue_id'));
        $this->assertTrue(Queue::withoutGlobalScopes()->whereKey($ping->json('queue_id'))->exists());

        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/auth/book_a_ride/queues')->assertOk()->assertJsonCount(0, 'queues');
    }
}
