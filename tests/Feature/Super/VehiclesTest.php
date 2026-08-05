<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Super-admin vehicles list: till_conflict flag, driver attachment, filters.
 */
final class VehiclesTest extends QueueTestCase
{
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    #[Test]
    public function till_conflict_is_true_for_a_shared_till_and_false_otherwise(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $seat = $this->makeSeat();

        $vehicleA = $this->makeVehicle($sacco, $owner, $seat);
        $vehicleA->update(['till_number' => 'SHARED123']);

        $vehicleB = $this->makeVehicle($sacco, $owner, $seat);
        $vehicleB->update(['till_number' => 'SHARED123']);

        $vehicleC = $this->makeVehicle($sacco, $owner, $seat);
        $vehicleC->update(['till_number' => 'UNIQUE999']);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/vehicles')->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($rows[$vehicleA->id]['till_conflict']);
        $this->assertTrue($rows[$vehicleB->id]['till_conflict']);
        $this->assertFalse($rows[$vehicleC->id]['till_conflict']);
    }

    #[Test]
    public function the_till_conflict_filter_returns_only_conflicting_vehicles(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $seat = $this->makeSeat();

        $vehicleA = $this->makeVehicle($sacco, $owner, $seat);
        $vehicleA->update(['till_number' => 'SHARED456']);

        $vehicleB = $this->makeVehicle($sacco, $owner, $seat);
        $vehicleB->update(['till_number' => 'SHARED456']);

        $vehicleC = $this->makeVehicle($sacco, $owner, $seat);
        $vehicleC->update(['till_number' => 'LONELY777']);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/vehicles?till_conflict=1')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($vehicleA->id));
        $this->assertTrue($ids->contains($vehicleB->id));
        $this->assertFalse($ids->contains($vehicleC->id));
    }

    #[Test]
    public function it_reports_the_vehicles_current_open_driver(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $seat = $this->makeSeat();
        $vehicle = $this->makeVehicle($sacco, $owner, $seat);

        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'start_date' => now(),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/vehicles?q='.$vehicle->plate)->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $vehicle->id);

        $this->assertNotNull($row['driver']);
        $this->assertSame($driver->id, $row['driver']['id']);
        $this->assertSame($driver->firstname.' '.$driver->lastname, $row['driver']['name']);
    }

    #[Test]
    public function it_returns_the_slim_page_envelope(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $seat = $this->makeSeat();
        $this->makeVehicle($sacco, $owner, $seat);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/vehicles')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'per_page', 'current_page', 'last_page']);
    }
}
