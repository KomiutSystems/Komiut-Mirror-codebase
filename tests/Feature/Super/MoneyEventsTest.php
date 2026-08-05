<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\LoyaltyTransactionType;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\Super\Money\PaymentReconciliationAlerter;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The super-admin MONEY-INTEGRITY domain: money-redirect and loyalty-config alerts
 * emitted from model observers, plus the money audit-trail read endpoint.
 */
final class MoneyEventsTest extends QueueTestCase
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
    public function changing_a_vehicle_till_emits_an_audited_before_and_after(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());

        $vehicle->update(['till_number' => '558844']);

        $note = PlatformNotification::where('event', 'vehicle.payment_details.changed')->first();
        $this->assertNotNull($note, 'A till change must raise a money-redirect alert.');
        $this->assertSame('critical', $note->severity);
        $this->assertSame('alert', $note->delivery_class);
        $this->assertSame('till_number', $note->data['field']);
        $this->assertNull($note->data['from'], 'Before value was null (unset till).');
        $this->assertSame('558844', $note->data['to']);
        $this->assertSame($vehicle->id, $note->data['vehicleId']);

        $audit = AuditLog::where('action', 'vehicle.payment_details.changed')->first();
        $this->assertNotNull($audit, 'The change must be audited FIRST.');
        $this->assertSame($audit->id, $note->audit_id, 'The alert must link its audit row.');
        $this->assertNull($audit->data['from']);
        $this->assertSame('558844', $audit->data['to']);
    }

    #[Test]
    public function only_the_changed_payment_field_fires(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());

        // A non-payment edit must stay silent.
        $vehicle->update(['fleet_no' => 'FN-CHANGED']);

        $this->assertSame(0, PlatformNotification::where('event', 'vehicle.payment_details.changed')->count());
    }

    #[Test]
    public function a_shared_till_across_vehicles_is_flagged_as_duplicate(): void
    {
        $sacco = $this->makeSacco();
        $first = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $second = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());

        $first->update(['till_number' => '700700']);
        $second->update(['till_number' => '700700']); // collides with $first

        $dup = PlatformNotification::where('event', 'vehicle.till.duplicate')->first();
        $this->assertNotNull($dup, 'A till used by >1 vehicle must be flagged.');
        $this->assertSame('700700', $dup->data['tillNumber']);
        $this->assertContains($first->id, $dup->data['vehicleIds']);
        $this->assertContains($second->id, $dup->data['vehicleIds']);
    }

    #[Test]
    public function an_extreme_loyalty_divisor_emits(): void
    {
        $sacco = $this->makeSacco();

        // divisor 2 is far below the default floor of 10 → mints points too fast.
        LoyaltyProgram::create([
            'sacco_id' => $sacco->id,
            'divisor' => 2,
            'redemption_threshold' => 100,
            'is_active' => true,
        ]);

        $note = PlatformNotification::where('event', 'loyalty.program.extreme_config')->first();
        $this->assertNotNull($note, 'A below-floor divisor must raise a config alert.');
        $this->assertSame('high', $note->severity);
        $this->assertEquals(2, $note->data['divisor']); // JSON round-trips 2.0 -> 2
        $this->assertSame($sacco->id, $note->data['saccoId']);

        $this->assertSame(
            1,
            AuditLog::where('action', 'loyalty.program.extreme_config')->count(),
            'The extreme config must be audited.',
        );
    }

    #[Test]
    public function a_healthy_loyalty_config_stays_silent(): void
    {
        $sacco = $this->makeSacco();

        LoyaltyProgram::create([
            'sacco_id' => $sacco->id,
            'divisor' => 50,           // >= floor
            'redemption_threshold' => 500, // != 0
            'is_active' => true,
        ]);

        $this->assertSame(0, PlatformNotification::where('event', 'loyalty.program.extreme_config')->count());
    }

    #[Test]
    public function money_logs_returns_the_audit_trail_newest_first(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['merchant_short_code' => 'MSC-42']);

        Sanctum::actingAs($this->superAdmin());

        $body = $this->getJson('/api/v1/super/money/logs')
            ->assertOk()
            ->json('message');

        $this->assertGreaterThanOrEqual(1, $body['totalCount']);
        $this->assertSame('vehicle.payment_details.changed', $body['items'][0]['action']);
        $this->assertSame('merchant_short_code', $body['items'][0]['data']['field']);
        $this->assertSame('MSC-42', $body['items'][0]['data']['to']);
    }

    #[Test]
    public function unmatched_payments_aggregate_into_one_throttled_alert(): void
    {
        $alerter = app(PaymentReconciliationAlerter::class);

        $alerter->record('testing', 'BOOK-1', 120.0);
        $alerter->record('testing', 'BOOK-2', 80.0);

        $rows = PlatformNotification::where('event', 'payment.reconciliation.failed')->get();
        $this->assertCount(1, $rows, 'Repeats collapse onto one open row within the window.');

        $note = $rows->first();
        $this->assertSame(2, $note->count, 'The notifier counted both occurrences.');
        $this->assertSame(2, $note->data['failureCount']);
        $this->assertEquals(200, $note->data['totalAmount']);
        $this->assertSame(['BOOK-1', 'BOOK-2'], $note->data['sampleRefs']);
    }

    #[Test]
    public function a_burst_of_redemptions_for_one_sacco_flags_a_spike(): void
    {
        $sacco = $this->makeSacco();
        $user = $this->makeUser([], $sacco);

        // Five redemptions in the trailing window against a quiet baseline.
        foreach (range(1, 5) as $i) {
            LoyaltyTransaction::create([
                'user_id' => $user->id,
                'sacco_id' => $sacco->id,
                'value' => -100,
                'type' => LoyaltyTransactionType::Redeemed,
            ]);
        }

        $note = PlatformNotification::where('event', 'loyalty.redemption.spike')->first();
        $this->assertNotNull($note, 'A redemption burst must be flagged.');
        $this->assertSame('high', $note->severity);
        $this->assertSame($sacco->id, $note->data['saccoId']);
        $this->assertGreaterThanOrEqual(5, $note->data['redemptionCount']);
        $this->assertEquals(500, $note->data['pointsBurned']);
    }

    #[Test]
    public function money_logs_is_gated_to_super_admins(): void
    {
        Sanctum::actingAs($this->makeUser()); // ordinary user, no permission

        $this->getJson('/api/v1/super/money/logs')->assertStatus(403);
    }
}
