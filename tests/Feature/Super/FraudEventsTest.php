<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\BankPartner;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\DriverBankLead;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The fraud / abuse / data-protection domain of the super-admin console.
 *
 * Two properties matter most here: a run of failed driver logins on one plate is
 * surfaced (the phone is the only secret, so guessing it is the attack), and every
 * bulk export of driver leads to a partner bank is audited FIRST and PII-free —
 * counts and refs leave, never a driver's name, phone or ID number.
 */
final class FraudEventsTest extends QueueTestCase
{
    private const LOGIN = '/api/v1/auth/driver/login';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bank_portal.partners' => [
                'ncba' => ['password' => 'ncba-secret', 'brand' => 'komiut', 'label' => 'NCBA Bank'],
            ],
        ]);
    }

    private function makeDriver(Sacco $sacco, string $phone): User
    {
        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver, 'phone' => $phone])->save();

        return $driver;
    }

    private function makeVehicleWithPlate(Sacco $sacco, string $plate): Vehicle
    {
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['plate' => $plate]);

        return $vehicle;
    }

    #[Test]
    public function a_failed_driver_login_burst_raises_an_alert(): void
    {
        $sacco = $this->makeSacco();
        $this->makeVehicleWithPlate($sacco, 'KDA123X');

        // Default threshold is 5 failed logins in 15 minutes. Five unknown phones
        // guessing against the one plate — each a 401 — must cross it.
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::LOGIN, [
                'phone' => '25479000000'.$i, // unknown phone → failed login
                'plate' => 'KDA123X',
            ])->assertStatus(401);
        }

        $alert = PlatformNotification::where('event', 'driver.login.failed_burst')->first();

        $this->assertNotNull($alert, 'A failed-login burst must raise driver.login.failed_burst.');
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('alert', $alert->delivery_class);
        $this->assertSame('KDA123X', $alert->data['plate']);
        $this->assertGreaterThanOrEqual(5, $alert->data['attemptCount']);
        $this->assertSame(5, $alert->data['distinctPhones']);

        // PII-free: the guessed phone numbers must never appear in the payload.
        $this->assertStringNotContainsString('254790000001', json_encode($alert->data));
        $this->assertStringNotContainsString('254790000005', json_encode($alert->data));
    }

    #[Test]
    public function a_partner_leads_export_is_audited_and_emits_pii_free(): void
    {
        // A driver lead carrying real PII the export must never leak into the trail.
        $sacco = Sacco::create(['name' => 'Nicco', 'status' => 1, 'brand' => 'komiut']);
        $driver = User::create([
            'firstname' => 'Jane', 'lastname' => 'Wanjiru',
            'phone' => '0712345678', 'id_number' => '24567890',
            'password' => 'password', 'sacco_id' => $sacco->id, 'status' => true,
        ]);
        DriverBankLead::create([
            'user_id' => $driver->id,
            'brand' => 'komiut',
            'bank' => BankPartner::Ncba,
            'preferred_branch' => 'Westlands',
            'vehicle_capacity' => 14,
            'opted_in_at' => now(),
            'status' => 'new',
        ]);

        $this->get('/api/v1/partner/bank/leads/export', ['X-Partner-Key' => 'ncba-secret'])
            ->assertOk();

        // The audit row is written FIRST and is PII-free.
        $audit = AuditLog::where('action', 'partner.leads.exported')->first();
        $this->assertNotNull($audit, 'An export must write an audit row.');
        $this->assertSame('komiut', $audit->brand);
        $this->assertSame(1, $audit->data['recordCount']);

        $auditJson = json_encode($audit->data).json_encode($audit->only(['actor_label', 'subject_id']));
        foreach (['Jane', 'Wanjiru', '0712345678', '24567890'] as $pii) {
            $this->assertStringNotContainsString($pii, $auditJson, 'The audit trail must be PII-free.');
        }

        // The critical alert references that audit row.
        $alert = PlatformNotification::where('event', 'partner.leads.exported')->first();
        $this->assertNotNull($alert, 'An export must emit partner.leads.exported.');
        $this->assertSame('critical', $alert->severity);
        $this->assertSame((int) $audit->id, (int) $alert->audit_id);
        $this->assertSame(1, $alert->data['recordCount']);

        $alertJson = json_encode($alert->data);
        foreach (['Jane', 'Wanjiru', '0712345678', '24567890'] as $pii) {
            $this->assertStringNotContainsString($pii, $alertJson, 'The alert payload must be PII-free.');
        }
    }
}
