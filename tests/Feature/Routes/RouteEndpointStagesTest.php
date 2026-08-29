<?php

declare(strict_types=1);

namespace Tests\Feature\Routes;

use App\Models\Route;
use App\Models\RouteStage;
use App\Services\Routes\RouteEndpointStages;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A route's own endpoints must be stages, or the route cannot be booked.
 *
 * book_a_ride/routes decides whether a route serves a journey by joining
 * `route_stages` twice and requiring `pickup.distance < dropoff.distance`.
 * Setting `routes.from_id`/`to_id` does nothing for that join, so a route
 * created the old way is invisible to the app while looking healthy on the
 * dashboard — which is exactly prod route 1972.
 */
final class RouteEndpointStagesTest extends QueueTestCase
{
    #[Test]
    public function a_route_with_no_stages_gains_both_endpoints(): void
    {
        $from = $this->makePlace('Ambassadeur');
        $to = $this->makePlace('Alsops');
        $route = $this->makeRoute($from, $to);
        RouteStage::where('route_id', $route->id)->delete();

        $this->assertSame(2, app(RouteEndpointStages::class)->ensure($route));

        $places = RouteStage::where('route_id', $route->id)->orderBy('distance')->pluck('place_id')->all();
        $this->assertSame([$from->id, $to->id], $places);
    }

    #[Test]
    public function the_origin_sits_at_zero_and_the_destination_last(): void
    {
        $from = $this->makePlace('Origin');
        $to = $this->makePlace('Destination');
        $route = $this->makeRoute($from, $to);
        RouteStage::where('route_id', $route->id)->delete();

        // A mid-route stop that already exists, as on prod route 1972.
        $middle = $this->makePlace('Middle');
        RouteStage::create([
            'route_id' => $route->id, 'place_id' => $middle->id,
            'distance' => 1.0, 'sequence' => 1, 'status' => true,
        ]);

        app(RouteEndpointStages::class)->ensure($route);

        $ordered = RouteStage::where('route_id', $route->id)->orderBy('distance')->get();
        $this->assertSame($from->id, (int) $ordered->first()->place_id, 'origin first');
        $this->assertSame($to->id, (int) $ordered->last()->place_id, 'destination last');

        // sequence must agree with distance, or SegmentSeatAvailability (which
        // reads sequence) and the segment search (which reads distance) disagree.
        $this->assertSame([1, 2, 3], $ordered->pluck('sequence')->map(fn ($s) => (int) $s)->all());
    }

    #[Test]
    public function a_repaired_route_becomes_findable_by_pickup_and_dropoff(): void
    {
        $world = $this->makeWorld();
        $from = $this->makePlace('Broken origin');
        $to = $this->makePlace('Broken end');
        $route = $this->makeRoute($from, $to);
        // routes is SACCO-owned now; without this the scope hides it from the
        // caller and the search returns nothing for a reason unrelated to stages.
        $route->forceFill(['sacco_id' => $world['sacco']->id])->save();
        RouteStage::where('route_id', $route->id)->delete();
        $this->makeSaccoRoute($world['sacco'], $route, $world['owner'], 100);

        Sanctum::actingAs($this->makeUser([], $world['sacco']));
        $url = '/api/auth/book_a_ride/routes?from_place_id='.$from->id.'&to_place_id='.$to->id;

        // Unbookable: the pickup/dropoff join has nothing to match.
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'routes');

        app(RouteEndpointStages::class)->ensure($route);

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'routes');
    }

    #[Test]
    public function a_route_saved_through_the_api_gets_its_endpoints_immediately(): void
    {
        $world = $this->makeWorld();
        $from = $this->makePlace('New origin');
        $to = $this->makePlace('New end');

        Sanctum::actingAs($this->makeUser(['Add Routes'], $world['sacco']));

        $this->postJson('/api/auth/routes/add', [
            'id' => 0, 'name' => 'New origin - New end',
            'from' => $from->name, 'to' => $to->name, 'status' => 1,
        ])->assertOk();

        $route = Route::withoutGlobalScopes()->where('from_id', $from->id)->firstOrFail();
        $places = RouteStage::where('route_id', $route->id)->pluck('place_id')
            ->map(fn ($id) => (int) $id)->all();

        $this->assertEqualsCanonicalizing([$from->id, $to->id], $places);
    }

    #[Test]
    public function a_whole_route_is_left_alone(): void
    {
        $world = $this->makeWorld(); // already has both endpoints as stages

        $before = RouteStage::where('route_id', $world['route']->id)->get();
        $this->assertSame(0, app(RouteEndpointStages::class)->ensure($world['route']));
        $after = RouteStage::where('route_id', $world['route']->id)->get();

        $this->assertSame($before->pluck('id')->all(), $after->pluck('id')->all());
    }

    #[Test]
    public function the_backfill_repairs_only_the_broken_routes(): void
    {
        $world = $this->makeWorld(); // whole
        $from = $this->makePlace('Orphan origin');
        $to = $this->makePlace('Orphan end');
        $broken = $this->makeRoute($from, $to);
        RouteStage::where('route_id', $broken->id)->delete();

        Artisan::call('routes:backfill-endpoint-stages');

        $this->assertSame(2, RouteStage::where('route_id', $broken->id)->count());
        $this->assertSame(2, RouteStage::where('route_id', $world['route']->id)->count());
    }

    #[Test]
    public function the_backfill_writes_nothing_on_a_dry_run(): void
    {
        $from = $this->makePlace('Dry origin');
        $to = $this->makePlace('Dry end');
        $route = $this->makeRoute($from, $to);
        RouteStage::where('route_id', $route->id)->delete();

        Artisan::call('routes:backfill-endpoint-stages', ['--dry-run' => true]);

        $this->assertSame(0, RouteStage::where('route_id', $route->id)->count());
    }
}
