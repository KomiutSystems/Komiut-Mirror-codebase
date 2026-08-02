<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Logging in IS the rotation record: a driver who swapped matatus this morning
 * signs in against the new plate and the assignment follows them.
 *
 * The complementary session-lifetime rules live in Tests\Feature\Auth\DriverLoginTest.
 */
final class DriverLoginAssignmentTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/driver/login';

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
    public function signing_in_creates_the_assignment_when_none_exists(): void
    {
        $sacco = $this->makeSacco();
        $driver = $this->makeDriver($sacco, '254711000111');
        $vehicle = $this->makeVehicleWithPlate($sacco, 'KDA001A');

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDA001A'])
            ->assertOk();

        $this->assertDatabaseHas('vehicle_users', [
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'end_date' => null,
        ]);
    }

    #[Test]
    public function switching_vehicle_closes_the_previous_assignment(): void
    {
        $sacco = $this->makeSacco();
        $driver = $this->makeDriver($sacco, '254711000111');
        $yesterday = $this->makeVehicleWithPlate($sacco, 'KDA001A');
        $today = $this->makeVehicleWithPlate($sacco, 'KDB002B');

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDA001A'])->assertOk();
        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDB002B'])->assertOk();

        $closed = VehicleUser::where('user_id', $driver->id)->where('vehicle_id', $yesterday->id)->firstOrFail();
        $this->assertNotNull($closed->end_date, 'The superseded assignment must be closed, not deleted.');
        $this->assertFalse((bool) $closed->status);

        $open = VehicleUser::where('user_id', $driver->id)->where('vehicle_id', $today->id)->firstOrFail();
        $this->assertNull($open->end_date);
        $this->assertTrue((bool) $open->status);

        $this->assertSame(
            1,
            VehicleUser::where('user_id', $driver->id)->where('status', true)->whereNull('end_date')->count(),
            'A driver may only ever have one open assignment.'
        );
    }

    #[Test]
    public function a_new_driver_taking_a_vehicle_releases_the_previous_driver(): void
    {
        // The morning handover: yesterday's driver had this matatu; today another
        // driver signs in against the same plate. The vehicle must end up attached
        // to exactly the new driver, and the previous attachment closed.
        $sacco = $this->makeSacco();
        $yesterdayDriver = $this->makeDriver($sacco, '254711000111');
        $todayDriver = $this->makeDriver($sacco, '254711000222');
        $vehicle = $this->makeVehicleWithPlate($sacco, 'KDA001A');

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDA001A'])->assertOk();
        $this->postJson(self::ENDPOINT, ['phone' => '254711000222', 'plate' => 'KDA001A'])->assertOk();

        $previous = VehicleUser::where('user_id', $yesterdayDriver->id)->where('vehicle_id', $vehicle->id)->firstOrFail();
        $this->assertNotNull($previous->end_date, "The previous driver's attachment must be closed.");
        $this->assertFalse((bool) $previous->status);

        $current = VehicleUser::where('user_id', $todayDriver->id)->where('vehicle_id', $vehicle->id)->firstOrFail();
        $this->assertNull($current->end_date);
        $this->assertTrue((bool) $current->status);

        $this->assertSame(
            1,
            VehicleUser::where('vehicle_id', $vehicle->id)->where('status', true)->whereNull('end_date')->count(),
            'A vehicle may only ever have one open driver assignment.'
        );
    }

    #[Test]
    public function the_handover_cancels_the_previous_drivers_open_queue(): void
    {
        // A vehicle carries at most one live queue. When a new driver takes it,
        // the outgoing driver's open queue is cancelled so the new driver starts
        // clean rather than silently inheriting the previous trip + its bookings.
        $sacco = $this->makeSacco();
        $yesterdayDriver = $this->makeDriver($sacco, '254711000111');
        $todayDriver = $this->makeDriver($sacco, '254711000222');
        $vehicle = $this->makeVehicleWithPlate($sacco, 'KDA001A');

        $from = $this->makePlace('CBD');
        $to = $this->makePlace('Thika');
        $route = $this->makeRoute($from, $to);
        $terminus = $this->makeTerminus($from);
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $this->makeQueueStatus('Cancelled', 'Cancelled');
        $queue = $this->makeQueue($vehicle, $terminus, $route, $pending, $yesterdayDriver);

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDA001A'])->assertOk();
        $this->postJson(self::ENDPOINT, ['phone' => '254711000222', 'plate' => 'KDA001A'])->assertOk();

        $this->assertSame(
            'Cancelled',
            $queue->refresh()->queue_status->status,
            "The previous driver's queue must be cancelled on handover."
        );
        $this->assertSame(
            0,
            Queue::where('vehicle_id', $vehicle->id)
                ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Pending', 'Active']))
                ->count(),
            'No live queue may survive the handover for the new driver to inherit.'
        );
    }

    #[Test]
    public function a_non_driver_assignment_on_the_vehicle_survives_a_rotation(): void
    {
        // The handover releases the vehicle's previous *driver* only. A non-driver
        // VehicleUser row (an owner/admin, or a legacy crew row) must be left alone.
        $sacco = $this->makeSacco();
        $this->makeDriver($sacco, '254711000111');
        $vehicle = $this->makeVehicleWithPlate($sacco, 'KDA001A');

        $admin = $this->makeUser([], $sacco);
        $admin->forceFill(['type' => UserType::Admin])->save();
        $adminRow = VehicleUser::create([
            'user_id' => $admin->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'start_date' => now(),
        ]);

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDA001A'])->assertOk();

        $adminRow->refresh();
        $this->assertNull($adminRow->end_date, 'A non-driver assignment must not be closed by a driver rotation.');
        $this->assertTrue((bool) $adminRow->status);
    }

    #[Test]
    public function signing_in_twice_on_the_same_vehicle_does_not_stack_assignments(): void
    {
        $sacco = $this->makeSacco();
        $driver = $this->makeDriver($sacco, '254711000111');
        $this->makeVehicleWithPlate($sacco, 'KDA001A');

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDA001A'])->assertOk();
        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDA001A'])->assertOk();

        $this->assertSame(1, VehicleUser::where('user_id', $driver->id)->count());
    }

    #[Test]
    public function a_plate_from_another_sacco_is_rejected(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $driver = $this->makeDriver($mine, '254711000111');
        $this->makeVehicleWithPlate($theirs, 'KDZ999Z');

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDZ999Z'])
            ->assertStatus(403);

        $this->assertSame(
            0,
            VehicleUser::where('user_id', $driver->id)->count(),
            'A rejected login must not leave an assignment behind.'
        );
    }

    #[Test]
    public function a_plate_is_matched_however_it_was_typed(): void
    {
        $sacco = $this->makeSacco();
        $this->makeDriver($sacco, '254711000111');
        $this->makeVehicleWithPlate($sacco, 'KDQ446R');

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'kdq 446r'])
            ->assertOk();
    }

    #[Test]
    public function an_unknown_phone_is_rejected(): void
    {
        $sacco = $this->makeSacco();
        $this->makeVehicleWithPlate($sacco, 'KDA001A');

        $this->postJson(self::ENDPOINT, ['phone' => '254799999999', 'plate' => 'KDA001A'])
            ->assertStatus(401);
    }

    #[Test]
    public function a_deactivated_driver_cannot_sign_in(): void
    {
        $sacco = $this->makeSacco();
        $driver = $this->makeDriver($sacco, '254711000111');
        $driver->forceFill(['status' => false])->save();
        $this->makeVehicleWithPlate($sacco, 'KDA001A');

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => 'KDA001A'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_failed_login_by_a_non_driver_leaves_no_assignment(): void
    {
        $sacco = $this->makeSacco();
        $admin = $this->makeUser([], $sacco);
        $admin->forceFill(['type' => UserType::Admin, 'phone' => '254722000222'])->save();
        $this->makeVehicleWithPlate($sacco, 'KDA001A');

        $this->postJson(self::ENDPOINT, ['phone' => '254722000222', 'plate' => 'KDA001A'])
            ->assertStatus(403);

        $this->assertSame(0, VehicleUser::where('user_id', $admin->id)->count());
    }
}
