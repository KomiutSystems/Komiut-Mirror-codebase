<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
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
 * Super-admin console — cross-brand payment read aggregates (super/payments,
 * super/payments/summary). Live/aggregate data, not the money audit trail.
 */
final class PaymentsTest extends QueueTestCase
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

    /** A settled C2B payment: Mpesa row + linked Transaction, as MpesaAPIController's own flow builds them. */
    private function settledPayment(Vehicle $vehicle, string $transId, float $amount, string $msisdn): Mpesa
    {
        $mpesa = Mpesa::create([
            'TransID' => $transId,
            'MSISDN' => $msisdn,
            'TransAmount' => $amount,
            'TransTime' => now(),
            'FirstName' => 'Jane',
            'LastName' => 'Doe',
            'BusinessShortCode' => '5557936',
            'BillRefNumber' => $vehicle->till_number,
            'TransactionType' => 'Pay Bill',
        ]);
        Transaction::create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'trans_date' => now(),
        ]);

        return $mpesa;
    }

    #[Test]
    public function the_payments_list_masks_the_passenger_phone(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['till_number' => '111222']);

        $rawMsisdn = '254799887766';
        $this->settledPayment($vehicle, 'MASK-TEST-1', 150, $rawMsisdn);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/payments?status=settled')->assertOk();

        // The raw MSISDN must never appear anywhere in the response body.
        $response->assertDontSee($rawMsisdn);

        $row = collect($response->json('data'))->firstWhere('mpesa_receipt', 'MASK-TEST-1');
        $this->assertNotNull($row, 'The settled payment should be in the list.');
        $this->assertSame('settled', $row['status']);
        $this->assertEquals(150.0, $row['amount']);
        $this->assertStringStartsWith('2547', $row['passenger']['phone']);
        $this->assertStringEndsWith('7766', $row['passenger']['phone']);
        $this->assertStringContainsString('*', $row['passenger']['phone']);
        $this->assertSame('Jane Doe', $row['passenger']['name']);
        $this->assertSame($vehicle->id, $row['vehicle']['id']);
        $this->assertSame($sacco->id, $row['sacco']['id']);
    }

    #[Test]
    public function the_payments_list_requires_the_permission(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/super/payments')->assertStatus(403);
    }

    #[Test]
    public function summary_returns_the_expected_aggregate_shape(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());

        $this->settledPayment($vehicle, 'SUM-TEST-1', 100, '254711111111');
        $this->settledPayment($vehicle, 'SUM-TEST-2', 200, '254722222222');

        // An unattributed C2B payment (no Transaction) — the unreconciled case.
        Mpesa::create([
            'TransID' => 'ORPHAN-1',
            'MSISDN' => '254733333333',
            'TransAmount' => 50,
            'TransTime' => now(),
            'BusinessShortCode' => '5557936',
        ]);

        Sanctum::actingAs($this->superAdmin());

        $summary = $this->getJson('/api/v1/super/payments/summary')->assertOk()->json();

        foreach (['gross_volume', 'currency', 'settled', 'failed', 'unreconciled', 'unreconciled_value', 'success_rate', 'series'] as $key) {
            $this->assertArrayHasKey($key, $summary, "summary must include `{$key}`.");
        }

        $this->assertSame('KES', $summary['currency']);
        $this->assertEquals(300.0, $summary['gross_volume'], 'gross_volume sums settled Transaction.amount.');
        $this->assertSame(2, $summary['settled']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(1, $summary['unreconciled']);
        $this->assertEquals(50.0, $summary['unreconciled_value']);
        $this->assertEquals(100.0, $summary['success_rate'], 'No failures recorded → 100% success.');
        $this->assertIsArray($summary['series']);
        $this->assertNotEmpty($summary['series']);
        $this->assertCount(2, $summary['series'][0], 'Each series point is a [date, total] pair.');
    }

    #[Test]
    public function summary_scopes_gross_volume_to_the_requested_sacco(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $myVehicle = $this->makeVehicle($mine, $this->makeUser([], $mine), $this->makeSeat());
        $theirVehicle = $this->makeVehicle($theirs, $this->makeUser([], $theirs), $this->makeSeat());

        $this->settledPayment($myVehicle, 'SCOPE-MINE', 80, '254744444444');
        $this->settledPayment($theirVehicle, 'SCOPE-THEIRS', 500, '254755555555');

        Sanctum::actingAs($this->superAdmin());

        $summary = $this->getJson('/api/v1/super/payments/summary?sacco_id='.$mine->id)->assertOk()->json();

        $this->assertEquals(80.0, $summary['gross_volume'], 'Only the requested SACCO\'s volume should count.');
        $this->assertSame(1, $summary['settled']);
    }
}
