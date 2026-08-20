<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The routes a crew can pick to serve.
 *
 * The boundary worth pinning down is WHOSE routes a driver sees. The list is
 * keyed on the vehicle from the caller's current assignment, so a driver sees
 * exactly their own SACCO's routes — not the brand-wide list, and never another
 * SACCO's.
 */
final class DriverRoutesTest extends QueueTestCase
{
    /** Assign a Driver to the vehicle of a freshly-built world. */
    private function driverFor(Vehicle $vehicle, Sacco $sacco): User
    {
        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return $driver;
    }

    #[Test]
    public function an_assigned_driver_sees_their_saccos_routes_with_from_to_and_fare(): void
    {
        $world = $this->makeWorld();
        $driver = $this->driverFor($world['vehicle'], $world['sacco']);

        Sanctum::actingAs($driver);

        $body = $this->getJson('/api/v1/auth/driver/routes')
            ->assertOk()
            ->assertJsonStructure([
                'routes' => [['id', 'name', 'from' => ['id', 'name'], 'to' => ['id', 'name'], 'fare']],
            ])
            ->json();

        $this->assertCount(1, $body['routes'], 'Only the SACCO\'s own routes may appear.');

        $route = $body['routes'][0];
        $this->assertSame($world['route']->id, $route['id']);
        $this->assertSame($world['route']->name, $route['name']);
        $this->assertSame($world['from']->id, $route['from']['id']);
        $this->assertSame($world['from']->name, $route['from']['name']);
        $this->assertSame($world['to']->id, $route['to']['id']);
        $this->assertSame($world['to']->name, $route['to']['name']);
        // The SACCO's flat fare from sacco_routes.amount (makeWorld seeds 200).
        $this->assertSame(200.0, (float) $route['fare']);
    }

    #[Test]
    public function another_saccos_route_is_never_returned(): void
    {
        // The defect this guards: the brand-wide `routes` list ignored the SACCO
        // entirely, so a crew saw every other SACCO's routes.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $driver = $this->driverFor($mine['vehicle'], $mine['sacco']);

        Sanctum::actingAs($driver);

        $ids = collect($this->getJson('/api/v1/auth/driver/routes')->assertOk()->json('routes'))
            ->pluck('id')->all();

        $this->assertContains($mine['route']->id, $ids);
        $this->assertNotContains($theirs['route']->id, $ids, 'A different SACCO\'s route must not leak.');
    }

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/driver/routes')->assertStatus(401);
    }
}
