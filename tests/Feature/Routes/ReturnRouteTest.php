<?php

declare(strict_types=1);

namespace Tests\Feature\Routes;

use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RouteStage;
use App\Models\SaccoRoute;
use App\Services\Routes\ReturnRouteBuilder;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A matatu run is there-and-back, but a route row holds one direction.
 *
 * Both queue writers require `route.from_id === terminus.place_id`, so a bus at
 * the far end cannot queue for the trip home until that direction exists as its
 * own route. NICCO had three routes, no return legs, and therefore three
 * terminus rows at destinations that no bus could ever use.
 */
final class ReturnRouteTest extends QueueTestCase
{
    /** CBD -> Ruiru -> Thika, priced flat 150 with one segment at 50. */
    private function outbound(array $world): Route
    {
        $middle = $this->makePlace('Ruiru Stage');
        $this->makeRouteStage($world['route'], $middle, 21.96);

        RouteFare::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id,
            'route_id' => $world['route']->id,
            'from_place_id' => $world['from']->id,
            'to_place_id' => $middle->id,
            'amount' => 50,
            'status' => true,
        ]);

        return $world['route'];
    }

    #[Test]
    public function the_return_leg_runs_the_other_way(): void
    {
        $world = $this->makeWorld();
        $out = $this->outbound($world);

        $return = app(ReturnRouteBuilder::class)->ensureFor($out, $world['sacco']->id, $world['owner']->id);

        $this->assertNotNull($return);
        $this->assertSame($out->to_id, $return->from_id);
        $this->assertSame($out->from_id, $return->to_id);
        $this->assertSame($world['to']->name.' - '.$world['from']->name, $return->name);
    }

    #[Test]
    public function the_stops_are_reversed_and_re_measured_from_the_new_origin(): void
    {
        $world = $this->makeWorld();
        $out = $this->outbound($world);

        $return = app(ReturnRouteBuilder::class)->ensureFor($out, $world['sacco']->id, $world['owner']->id);

        $stages = RouteStage::where('route_id', $return->id)->orderBy('distance')->get();

        // Distance is measured FROM THE ORIGIN, so it must be recomputed, not
        // copied — otherwise the segment search offers the journey backwards.
        $this->assertSame($world['to']->id, (int) $stages->first()->place_id, 'the far end is now the origin');
        $this->assertSame(0.0, (float) $stages->first()->distance);
        $this->assertSame($world['from']->id, (int) $stages->last()->place_id);

        // and sequence agrees with travel order
        $this->assertSame(
            range(1, $stages->count()),
            $stages->pluck('sequence')->map(fn ($s) => (int) $s)->all()
        );
    }

    #[Test]
    public function the_flat_fare_and_every_stop_pair_come_with_it(): void
    {
        $world = $this->makeWorld(); // flat 200
        $out = $this->outbound($world);
        $middle = RouteStage::where('route_id', $out->id)->orderBy('distance')->get()[1];

        $return = app(ReturnRouteBuilder::class)->ensureFor($out, $world['sacco']->id, $world['owner']->id);

        $this->assertSame(
            (float) SaccoRoute::withoutGlobalScopes()->where('route_id', $out->id)->value('amount'),
            (float) SaccoRoute::withoutGlobalScopes()->where('route_id', $return->id)->value('amount'),
        );

        // A segment costs what it costs whichever way you ride it.
        $mirrored = RouteFare::withoutGlobalScopes()
            ->where('route_id', $return->id)
            ->where('from_place_id', $middle->place_id)
            ->where('to_place_id', $world['from']->id)
            ->first();

        $this->assertNotNull($mirrored, 'the 50/= segment should exist in reverse');
        $this->assertSame(50.0, (float) $mirrored->amount);
    }

    #[Test]
    public function a_passenger_can_find_the_journey_home(): void
    {
        $world = $this->makeWorld();
        $out = $this->outbound($world);
        app(ReturnRouteBuilder::class)->ensureFor($out, $world['sacco']->id, $world['owner']->id);

        \Laravel\Sanctum\Sanctum::actingAs($this->makeUser([], $world['sacco']));

        // The whole point: Thika -> CBD is now a bookable direction.
        $ids = $this->getJson('/api/auth/book_a_ride/routes?from_place_id='.$world['to']->id
            .'&to_place_id='.$world['from']->id)->assertOk()->json('routes.*.id');

        $this->assertNotEmpty($ids, 'the return journey should be findable');
    }

    #[Test]
    public function building_it_twice_does_not_make_two(): void
    {
        $world = $this->makeWorld();
        $out = $this->outbound($world);
        $builder = app(ReturnRouteBuilder::class);

        $first = $builder->ensureFor($out, $world['sacco']->id, $world['owner']->id);
        $second = $builder->ensureFor($out, $world['sacco']->id, $world['owner']->id);

        $this->assertSame($first->id, $second->id);
    }

    #[Test]
    public function the_command_does_not_ping_pong_back_onto_the_outbound(): void
    {
        $world = $this->makeWorld();
        $this->outbound($world);
        $operator = $world['owner'];

        Artisan::call('routes:create-return', ['--sacco' => $world['sacco']->id, '--user' => $operator->id]);
        $after = Route::withoutGlobalScopes()->where('sacco_id', $world['sacco']->id)->count();

        // Running it again must not create the reverse of the reverse.
        Artisan::call('routes:create-return', ['--sacco' => $world['sacco']->id, '--user' => $operator->id]);

        $this->assertSame($after, Route::withoutGlobalScopes()->where('sacco_id', $world['sacco']->id)->count());
    }

    #[Test]
    public function the_command_refuses_without_an_author(): void
    {
        $world = $this->makeWorld();

        $this->assertSame(1, Artisan::call('routes:create-return', ['--sacco' => $world['sacco']->id]));
    }
}
