<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Super-admin console — cross-brand tills overview (super/tills) and the
 * vehicle.payment_details.changed audit projection (super/tills/changes).
 */
final class TillsOverviewTest extends QueueTestCase
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
    public function tills_groups_vehicles_by_till_number_and_flags_conflicts(): void
    {
        $sacco = $this->makeSacco();
        $vehicleA = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicleA->update(['till_number' => 'SHARED-1', 'merchant_short_code' => '9001']);
        $vehicleB = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicleB->update(['till_number' => 'SHARED-1', 'merchant_short_code' => '9001']);

        $lone = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $lone->update(['till_number' => 'SOLO-1']);

        // Volume for the shared till, within the trailing 30 days.
        $mpesa = Mpesa::create([
            'TransID' => 'TILL-VOL-1', 'MSISDN' => '254700000001', 'TransAmount' => 300,
            'TransTime' => now(), 'BusinessShortCode' => '9001',
        ]);
        Transaction::create(['mpesa_id' => $mpesa->id, 'vehicle_id' => $vehicleA->id, 'amount' => 300, 'trans_date' => now()]);

        Sanctum::actingAs($this->superAdmin());

        $tills = $this->getJson('/api/v1/super/tills')->assertOk()->json('data');

        $shared = collect($tills)->firstWhere('till_number', 'SHARED-1');
        $this->assertNotNull($shared);
        $this->assertTrue($shared['is_conflict'], 'A till used by 2 vehicles must be flagged as a conflict.');
        $this->assertCount(2, $shared['vehicles']);
        $this->assertSame($sacco->name, $shared['vehicles'][0]['sacco']);
        $this->assertEquals(300.0, $shared['volume_30d']);

        $solo = collect($tills)->firstWhere('till_number', 'SOLO-1');
        $this->assertNotNull($solo);
        $this->assertFalse($solo['is_conflict']);
        $this->assertCount(1, $solo['vehicles']);
    }

    #[Test]
    public function tills_changes_projects_an_existing_payment_details_audit_row(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());

        // No till_number at creation (the `created` event is silent by design) —
        // this update is a genuine change and VehiclePaymentObserver::updated()
        // writes the audit_logs row we're about to project.
        $vehicle->update(['till_number' => 'NEW-TILL-42']);

        $this->assertSame(
            1,
            AuditLog::where('action', 'vehicle.payment_details.changed')->count(),
            'The observer should have written exactly one audit row.'
        );

        Sanctum::actingAs($this->superAdmin());

        $rows = $this->getJson('/api/v1/super/tills/changes')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($vehicle->id, $row['vehicle']['id']);
        $this->assertSame($vehicle->plate, $row['vehicle']['plate']);
        $this->assertSame($sacco->id, $row['sacco']['id']);
        $this->assertSame($sacco->name, $row['sacco']['name']);
        $this->assertSame('till_number', $row['field']);
        $this->assertNull($row['from']);
        $this->assertSame('NEW-TILL-42', $row['to']);
        $this->assertNotNull($row['audit_id']);
        $this->assertArrayHasKey('actor', $row);
        $this->assertArrayHasKey('ip', $row['actor']);
    }

    #[Test]
    public function tills_changes_filters_by_vehicle_id(): void
    {
        $sacco = $this->makeSacco();
        $vehicleA = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicleB = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicleA->update(['till_number' => 'A-TILL']);
        $vehicleB->update(['till_number' => 'B-TILL']);

        Sanctum::actingAs($this->superAdmin());

        $rows = $this->getJson('/api/v1/super/tills/changes?vehicle_id='.$vehicleA->id)->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($vehicleA->id, $rows[0]['vehicle']['id']);
    }

    #[Test]
    public function tills_requires_the_permission(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/super/tills')->assertStatus(403);
    }
}
