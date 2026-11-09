<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Illuminate\Support\Carbon;
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
    /** Sign in and return when the session dies, as Nairobi wall-clock. */
    private function shiftEndsAt(string $phone = '254711000111', string $plate = 'KDA001A'): Carbon
    {
        $response = $this->postJson('/api/v1/auth/driver/login', ['phone' => $phone, 'plate' => $plate]);
        $response->assertOk();
        $expiresAt = $response->json('expires_at');
        $this->assertNotNull($expiresAt, 'A driver session must always expire.');

        return Carbon::parse($expiresAt)->setTimezone('Africa/Nairobi');
    }

    #[Test]
    public function a_shift_ends_at_the_next_three_am(): void
    {
        $sacco = $this->makeSacco();
        $this->assignDriver($sacco, '254711000111', 'KDA001A');

        // 09:00 Nairobi == 06:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-09 06:00:00', 'UTC'));

        $ends = $this->shiftEndsAt();

        // The business day boundary, not 24 hours from sign-in: a crew session
        // says "this person is on this bus today" and must not outlive the day.
        $this->assertSame(3, $ends->hour, 'A shift must end at 03:00 EAT.');
        $this->assertSame(0, $ends->minute);
        $this->assertSame('2026-11-10', $ends->toDateString());

        Carbon::setTestNow();
    }

    #[Test]
    public function a_late_night_sign_in_still_ends_at_the_same_three_am(): void
    {
        $sacco = $this->makeSacco();
        $this->assignDriver($sacco, '254711000111', 'KDA001A');

        // 23:50 Nairobi == 20:50 UTC. The old end-of-day rule gave this driver
        // ten minutes; the boundary gives them the rest of the business day.
        Carbon::setTestNow(Carbon::parse('2026-11-09 20:50:00', 'UTC'));

        $ends = $this->shiftEndsAt();

        $this->assertSame(3, $ends->hour);
        $this->assertSame('2026-11-10', $ends->toDateString());
        $this->assertTrue($ends->greaterThan(Carbon::now()->addHours(2)),
            'A 23:50 sign-in must still be worth more than a couple of hours.');

        Carbon::setTestNow();
    }

    #[Test]
    public function signing_in_just_before_the_boundary_runs_to_the_following_one(): void
    {
        $sacco = $this->makeSacco();
        $this->assignDriver($sacco, '254711000111', 'KDA001A');

        // 02:30 Nairobi == 23:30 UTC the day before: half an hour to go. Without
        // the floor this is a thirty-minute token, which is the 23:50 bug moved
        // three hours.
        Carbon::setTestNow(Carbon::parse('2026-11-08 23:30:00', 'UTC'));

        $ends = $this->shiftEndsAt();

        $this->assertSame(3, $ends->hour, 'Still an 03:00 boundary, just the next one.');
        $this->assertSame('2026-11-10', $ends->toDateString());

        Carbon::setTestNow();
    }

    #[Test]
    public function a_sign_in_comfortably_before_the_boundary_takes_the_nearer_one(): void
    {
        $sacco = $this->makeSacco();
        $this->assignDriver($sacco, '254711000111', 'KDA001A');

        // 01:00 Nairobi: two hours to go, over the floor, so the shift ends at
        // the boundary that is actually coming.
        Carbon::setTestNow(Carbon::parse('2026-11-08 22:00:00', 'UTC'));

        $ends = $this->shiftEndsAt();

        $this->assertSame(3, $ends->hour);
        $this->assertSame('2026-11-09', $ends->toDateString());

        Carbon::setTestNow();
    }

    #[Test]
    public function the_rotation_policy_no_longer_changes_the_session_length(): void
    {
        // rotates_drivers used to pick between end-of-day and never-expires.
        // Both are gone: every session is 24h, and a new sign-in revokes the
        // previous one, which is what rotation actually needed.
        $rotating = $this->makeSacco();
        $rotating->update(['rotates_drivers' => true]);
        $this->assignDriver($rotating, '254711000111', 'KDA001A');

        $fixed = $this->makeSacco();
        $this->assignDriver($fixed, '254722000222', 'KDB002B');

        $a = $this->postJson('/api/v1/auth/driver/login', ['phone' => '254711000111', 'plate' => 'KDA001A']);
        $b = $this->postJson('/api/v1/auth/driver/login', ['phone' => '254722000222', 'plate' => 'KDB002B']);

        $a->assertOk();
        $b->assertOk();
        $this->assertNotNull($a->json('expires_at'));
        $this->assertNotNull($b->json('expires_at'));
        $this->assertEqualsWithDelta(
            Carbon::parse($a->json('expires_at'))->timestamp,
            Carbon::parse($b->json('expires_at'))->timestamp,
            2,
            'Rotation policy must no longer affect session length.',
        );
    }

    #[Test]
    public function signing_in_revokes_the_drivers_previous_session(): void
    {
        $sacco = $this->makeSacco();
        [$driver] = $this->assignDriver($sacco, '254711000111', 'KDA001A');

        $first = $this->postJson('/api/v1/auth/driver/login', ['phone' => '254711000111', 'plate' => 'KDA001A']);
        $first->assertOk();
        $this->assertSame(1, $driver->tokens()->count());

        // A second sign-in — a new handset, or the phone left in the last
        // matatu — must leave exactly one live session, not two.
        $second = $this->postJson('/api/v1/auth/driver/login', ['phone' => '254711000111', 'plate' => 'KDA001A']);
        $second->assertOk();

        $this->assertSame(1, $driver->tokens()->count(), 'Only one driver session may be live.');
        $this->assertNotSame($first->json('access_token'), $second->json('access_token'));
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
