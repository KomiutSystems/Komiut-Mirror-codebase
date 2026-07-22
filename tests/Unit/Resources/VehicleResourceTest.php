<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Guards the VehicleResource response contract. Uses an in-memory model (never
 * persisted) and resolve() so unloaded whenLoaded() relations are stripped,
 * mirroring real serialization without booting the framework/DB.
 */
class VehicleResourceTest extends TestCase
{
    public function test_it_exposes_the_expected_scalar_columns(): void
    {
        $vehicle = new Vehicle([
            'plate' => 'KDA 001A',
            'fleet_no' => '12',
            'till_number' => '567890',
            'merchant_short_code' => '174379',
            'sacco_id' => 3,
            'user_id' => 7,
            'seat_id' => 2,
            'mpesa_payment_setting_id' => 1,
            'status' => 1,
        ]);
        $vehicle->id = 99;

        $data = (new VehicleResource($vehicle))->resolve(Request::create('/'));

        foreach ([
            'id', 'plate', 'fleet_no', 'till_number', 'merchant_short_code',
            'sacco_id', 'user_id', 'seat_id', 'mpesa_payment_setting_id',
            'status', 'created_at', 'updated_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "missing key: {$key}");
        }

        $this->assertSame('KDA 001A', $data['plate']);
        $this->assertSame(99, $data['id']);

        // Relations were never loaded, so whenLoaded() must strip them entirely.
        $this->assertArrayNotHasKey('sacco', $data);
        $this->assertArrayNotHasKey('user', $data);
        $this->assertArrayNotHasKey('seat', $data);
    }

    public function test_a_loaded_relation_key_appears(): void
    {
        $vehicle = new Vehicle(['plate' => 'KDB 002B', 'status' => 1]);
        $vehicle->id = 100;
        $vehicle->setRelation('sacco', null);

        $data = (new VehicleResource($vehicle))->resolve(Request::create('/'));

        $this->assertArrayHasKey('sacco', $data);
    }
}
