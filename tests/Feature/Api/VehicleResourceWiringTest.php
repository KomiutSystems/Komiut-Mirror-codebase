<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Proves the reference Resource wiring: GET /api/v1/auth/vehicles returns the
 * VehicleResource shape under the same {"vehicles":[...]} envelope the apps
 * already expect (no "data" wrapper), and honours the versioned path.
 */
final class VehicleResourceWiringTest extends QueueTestCase
{
    #[Test]
    public function vehicles_endpoint_returns_the_resource_shape_without_a_data_wrapper(): void
    {
        $sacco = $this->makeSacco();
        $admin = $this->makeUser(['View Vehicles'], $sacco);
        $vehicle = $this->makeVehicle($sacco, $admin, $this->makeSeat());

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/auth/vehicles');

        $response->assertOk();

        // Bare envelope, not {"data": ...}.
        $response->assertJsonMissingPath('data');
        $response->assertJsonStructure([
            'vehicles' => [
                ['id', 'plate', 'fleet_no', 'till_number', 'merchant_short_code', 'sacco_id', 'user_id', 'seat_id', 'status', 'created_at', 'updated_at'],
            ],
        ]);

        $first = $response->json('vehicles.0');
        $this->assertSame($vehicle->id, $first['id']);
        $this->assertSame($vehicle->plate, $first['plate']);
    }

    #[Test]
    public function the_legacy_unversioned_path_still_works(): void
    {
        $sacco = $this->makeSacco();
        $admin = $this->makeUser(['View Vehicles'], $sacco);
        $this->makeVehicle($sacco, $admin, $this->makeSeat());

        Sanctum::actingAs($admin);

        // Transition alias must keep working until the apps move to v1.
        $this->getJson('/api/auth/vehicles')->assertOk()->assertJsonStructure(['vehicles']);
    }
}
