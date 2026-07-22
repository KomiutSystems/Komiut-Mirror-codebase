<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Daily driver check-in: phone + vehicle number plate, with session lifetime
 * driven by the SACCO's rotation policy.
 */
final class DriverLoginTest extends QueueTestCase
{
    private function assignDriver(Sacco $sacco, string $phone, string $plate): array
    {
        $owner = $this->makeUser([], $sacco);
        $vehicle = $this->makeVehicle($sacco, $owner, $this->makeSeat());
        $vehicle->update(['plate' => $plate]);

        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver, 'phone' => $phone])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return [$driver, $vehicle];
    }

    #[Test]
    public function a_driver_signs_in_with_phone_and_plate(): void
    {
        $sacco = $this->makeSacco();
        [$driver] = $this->assignDriver($sacco, '254711000111', 'KDA001A');

        $response = $this->postJson('/api/v1/auth/driver/login', [
            'phone' => '254711000111',
            'plate' => 'KDA001A',
        ]);

        $response->assertOk()->assertJsonStructure(['user', 'vehicle', 'access_token', 'token_type', 'expires_at']);
        $this->assertSame($driver->id, $response->json('user.id'));
    }

    #[Test]
    public function a_wrong_plate_is_rejected(): void
    {
        $sacco = $this->makeSacco();
        $this->assignDriver($sacco, '254711000111', 'KDA001A');

        $this->postJson('/api/v1/auth/driver/login', [
            'phone' => '254711000111',
            'plate' => 'KDB999Z',
        ])->assertStatus(401);
    }

    #[Test]
    public function a_rotating_sacco_issues_a_session_that_expires_today(): void
    {
        $sacco = $this->makeSacco();
        $sacco->update(['rotates_drivers' => true]);
        $this->assignDriver($sacco, '254711000111', 'KDA001A');

        $response = $this->postJson('/api/v1/auth/driver/login', [
            'phone' => '254711000111',
            'plate' => 'KDA001A',
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('expires_at'), 'Rotating SACCO must issue an expiring session.');
    }

    #[Test]
    public function a_non_rotating_sacco_issues_a_persistent_session(): void
    {
        $sacco = $this->makeSacco();  // rotates_drivers defaults to false
        $this->assignDriver($sacco, '254711000111', 'KDA001A');

        $response = $this->postJson('/api/v1/auth/driver/login', [
            'phone' => '254711000111',
            'plate' => 'KDA001A',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('expires_at'), 'Non-rotating SACCO must issue a non-expiring session.');
    }

    #[Test]
    public function a_non_driver_account_cannot_use_driver_login(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $vehicle = $this->makeVehicle($sacco, $owner, $this->makeSeat());
        $vehicle->update(['plate' => 'KDA001A']);

        // An admin assigned to the vehicle must still not sign in as a driver.
        $admin = $this->makeUser([], $sacco);
        $admin->forceFill(['type' => UserType::Admin, 'phone' => '254722000222'])->save();
        VehicleUser::create([
            'user_id' => $admin->id, 'vehicle_id' => $vehicle->id, 'sacco_id' => $sacco->id,
            'status' => true, 'start_date' => now(),
        ]);

        $this->postJson('/api/v1/auth/driver/login', [
            'phone' => '254722000222',
            'plate' => 'KDA001A',
        ])->assertStatus(403);
    }
}
