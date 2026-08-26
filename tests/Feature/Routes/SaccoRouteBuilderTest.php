<?php

declare(strict_types=1);

namespace Tests\Feature\Routes;

use App\Enums\UserType;
use App\Models\Place;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\SaccoRoute;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Building a SACCO's route in one call, and the tenancy that goes with it.
 *
 * `routes` stopped being a global catalogue and became SACCO-owned. That was
 * both the feature the owner asked for ("routes should be per sacco, no shared
 * routes") and the fix for five proven cross-tenant writes, where an id plus a
 * permission check and no ownership test let any SACCO Admin re-destination
 * another SACCO's live route — taking its fares and running queues with it.
 */
final class SaccoRouteBuilderTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/saccos/routes/build';

    private function admin(array $world, array $perms = ['Add Routes', 'Edit Routes']): User
    {
        $u = $this->makeUser($perms, $world['sacco']);
        $u->type = UserType::Admin;
        $u->save();

        return $u->fresh();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nairobi CBD - Thika Main Stage',
            'fare' => 150,
            'stops' => [
                ['name' => 'Nairobi CBD Stage', 'latitude' => -1.2864, 'longitude' => 36.8172],
                ['name' => 'Thika Main Stage', 'latitude' => -1.0396, 'longitude' => 37.0900],
            ],
        ], $overrides);
    }

    #[Test]
    public function it_creates_the_route_its_stops_and_its_fare_in_one_call(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $body = $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(201)
            ->json('route');

        $this->assertSame('Nairobi CBD - Thika Main Stage', $body['name']);
        $this->assertSame(150.0, (float) $body['fare']);
        $this->assertCount(2, $body['stops']);

        $route = Route::withoutGlobalScopes()->find($body['id']);
        $this->assertSame($world['sacco']->id, $route->sacco_id, 'the route must be owned');

        // The fare landed on sacco_routes.amount — the tier that had no writer
        // at all before this, and so was permanently 0 for anything the
        // dashboard created.
        $this->assertSame(150.0, (float) SaccoRoute::withoutGlobalScopes()
            ->where('route_id', $route->id)->value('amount'));

        $this->assertSame(2, RouteStage::where('route_id', $route->id)->count());
    }

    #[Test]
    public function distance_increases_along_the_route_so_the_segment_search_can_work(): void
    {
        // route_stages.distance is not decoration: book_a_ride/routes decides
        // whether a route serves a journey by testing pickup < dropoff. If it
        // does not increase, the route is unfindable or sold backwards.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $id = $this->postJson(self::ENDPOINT, $this->payload([
            'stops' => [
                ['name' => 'Stop A '.$this->nextSequence(), 'latitude' => -1.2864, 'longitude' => 36.8172],
                ['name' => 'Stop B '.$this->nextSequence(), 'latitude' => -1.1800, 'longitude' => 36.9300],
                ['name' => 'Stop C '.$this->nextSequence(), 'latitude' => -1.0396, 'longitude' => 37.0900],
            ],
        ]))->assertStatus(201)->json('route.id');

        $distances = RouteStage::where('route_id', $id)->orderBy('sequence')->pluck('distance')
            ->map(fn ($d) => (float) $d)->all();

        $this->assertSame(0.0, $distances[0], 'the origin is nought km along');
        $this->assertGreaterThan($distances[0], $distances[1]);
        $this->assertGreaterThan($distances[1], $distances[2]);

        // Nairobi to Thika is roughly 40 km; this proves it is real geography
        // and not the placeholder ordinal.
        $this->assertGreaterThan(25.0, $distances[2]);
        $this->assertLessThan(60.0, $distances[2]);
    }

    #[Test]
    public function a_new_stop_without_a_pin_is_refused(): void
    {
        // Every one of the 1,980 places in production has NULL coordinates,
        // which is why nothing can be drawn or measured. New stops do not get
        // to grow that hole.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $this->postJson(self::ENDPOINT, $this->payload([
            'stops' => [
                ['name' => 'No pin here'],
                ['name' => 'Thika Main Stage', 'latitude' => -1.0396, 'longitude' => 37.0900],
            ],
        ]))->assertStatus(422);
    }

    #[Test]
    public function a_coordinate_out_of_range_is_refused(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $this->postJson(self::ENDPOINT, $this->payload([
            'stops' => [
                ['name' => 'Bad', 'latitude' => 999, 'longitude' => 36.8],
                ['name' => 'Worse', 'latitude' => -1.03, 'longitude' => 37.09],
            ],
        ]))->assertStatus(400);
    }

    #[Test]
    public function an_existing_stop_is_reused_rather_than_duplicated(): void
    {
        // `places` is 1,980 rows for 120 distinct names because every caller
        // created instead of matching. Two routes pointing at two rows for the
        // same real place never compare equal, so segment search silently fails.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $existing = Place::create([
            'name' => 'Ruiru Stage', 'latitude' => -1.1500, 'longitude' => 36.9600, 'status' => true,
        ]);
        $before = Place::count();

        $this->postJson(self::ENDPOINT, $this->payload([
            'stops' => [
                ['place_id' => $existing->id],
                ['name' => 'ruiru stage', 'latitude' => -1.15, 'longitude' => 36.96],  // same name, different case
            ],
        ]))->assertStatus(422);   // same stop twice on one route

        $this->assertSame($before, Place::count(), 'no place should have been minted');
    }

    #[Test]
    public function coordinates_backfill_onto_an_existing_stop_that_has_none(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $blank = Place::create(['name' => 'Juja Stage', 'status' => true]);
        $this->assertNull($blank->latitude);

        $this->postJson(self::ENDPOINT, $this->payload([
            'stops' => [
                ['name' => 'Juja Stage', 'latitude' => -1.1000, 'longitude' => 37.0100],
                ['name' => 'Thika Main Stage', 'latitude' => -1.0396, 'longitude' => 37.0900],
            ],
        ]))->assertStatus(201);

        $this->assertSame(-1.1, (float) $blank->fresh()->latitude);
    }

    #[Test]
    public function another_saccos_route_is_invisible_and_uneditable(): void
    {
        // The cross-tenant write, closed by ownership rather than by a guard
        // every future writer has to remember.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        Sanctum::actingAs($this->admin($mine));

        $this->assertNull(
            Route::find($theirs['route']->id),
            "another SACCO's route must not resolve"
        );

        $ids = Route::query()->pluck('id')->all();
        $this->assertContains($mine['route']->id, $ids);
        $this->assertNotContains($theirs['route']->id, $ids);
    }

    #[Test]
    public function two_saccos_may_both_run_the_same_corridor(): void
    {
        // The whole point of per-SACCO routes. Nairobi-Thika is not one row that
        // two SACCOs fight over; it is a row each, priced independently.
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        Sanctum::actingAs($this->admin($a));
        $this->postJson(self::ENDPOINT, $this->payload(['fare' => 150]))->assertStatus(201);

        Sanctum::actingAs($this->admin($b));
        $this->postJson(self::ENDPOINT, $this->payload(['fare' => 130]))->assertStatus(201);

        $this->assertSame(2, Route::withoutGlobalScopes()
            ->where('name', 'Nairobi CBD - Thika Main Stage')->count());
    }

    #[Test]
    public function the_same_sacco_cannot_run_the_same_pair_twice(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(201);
        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(409);
    }

    #[Test]
    public function a_route_needs_at_least_two_stops(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $this->postJson(self::ENDPOINT, $this->payload([
            'stops' => [['name' => 'Only one', 'latitude' => -1.28, 'longitude' => 36.81]],
        ]))->assertStatus(400);
    }

    #[Test]
    public function it_needs_the_route_permission(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world, ['View Routes']));

        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(403);
    }

    #[Test]
    public function naming_another_sacco_is_refused_rather_than_quietly_rewritten(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        Sanctum::actingAs($this->admin($mine));

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $theirs['sacco']->id]))
            ->assertStatus(403);
    }
}
