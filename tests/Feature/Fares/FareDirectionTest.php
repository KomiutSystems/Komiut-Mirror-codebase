<?php

declare(strict_types=1);

namespace Tests\Feature\Fares;

use App\Models\RouteFare;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A stop-pair fare has to run the way the route runs.
 *
 * FareResolver matches "from:to" exactly, so a reversed pair prices a journey
 * nobody can take: the operator is certain they set a fare, every passenger
 * sees none, and nothing anywhere says why. Prod route 1972 sat like that,
 * priced Alsops -> Ambassadeur on a route running Ambassadeur -> Alsops.
 */
final class FareDirectionTest extends QueueTestCase
{
    private function actor(array $world): void
    {
        Sanctum::actingAs($this->makeUser(['Add Fares'], $world['sacco']));
    }

    #[Test]
    public function a_fare_in_travel_order_is_accepted(): void
    {
        $world = $this->makeWorld();
        $this->actor($world);

        $this->postJson('/api/auth/saccos/fares/add', [
            'route_id' => $world['route']->id,
            'from_place_id' => $world['from']->id,
            'to_place_id' => $world['to']->id,
            'amount' => 120,
        ])->assertOk();

        $this->assertSame(120.0, (float) RouteFare::withoutGlobalScopes()->firstOrFail()->amount);
    }

    #[Test]
    public function a_reversed_pair_is_refused_with_an_instruction(): void
    {
        $world = $this->makeWorld();
        $this->actor($world);

        $this->postJson('/api/auth/saccos/fares/add', [
            'route_id' => $world['route']->id,
            // Backwards: this route runs from -> to.
            'from_place_id' => $world['to']->id,
            'to_place_id' => $world['from']->id,
            'amount' => 80,
        ])->assertStatus(422)
          ->assertJsonPath('error', 'Those stops are the wrong way round for this route. Swap the pickup and the dropoff.');

        $this->assertSame(0, RouteFare::withoutGlobalScopes()->count(), 'nothing unusable was stored');
    }

    #[Test]
    public function a_stop_that_is_not_on_the_route_is_refused(): void
    {
        $world = $this->makeWorld();
        $elsewhere = $this->makePlace('Somewhere else');
        $this->actor($world);

        $this->postJson('/api/auth/saccos/fares/add', [
            'route_id' => $world['route']->id,
            'from_place_id' => $world['from']->id,
            'to_place_id' => $elsewhere->id,
            'amount' => 80,
        ])->assertStatus(422)
          ->assertJsonPath('error', 'Both stops must be on this route before you can price the journey between them.');

        $this->assertSame(0, RouteFare::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_mid_route_leg_is_accepted_in_order(): void
    {
        $world = $this->makeWorld();
        $middle = $this->makePlace('Halfway');
        $this->makeRouteStage($world['route'], $middle, 20);
        $this->actor($world);

        // origin -> middle, in order
        $this->postJson('/api/auth/saccos/fares/add', [
            'route_id' => $world['route']->id,
            'from_place_id' => $world['from']->id,
            'to_place_id' => $middle->id,
            'amount' => 60,
        ])->assertOk();

        // middle -> origin, against the run
        $this->postJson('/api/auth/saccos/fares/add', [
            'route_id' => $world['route']->id,
            'from_place_id' => $middle->id,
            'to_place_id' => $world['from']->id,
            'amount' => 60,
        ])->assertStatus(422);

        $this->assertSame(1, RouteFare::withoutGlobalScopes()->count());
    }
}
