<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\CashSubmission;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Support\BusinessDay;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The end-of-shift cash declaration.
 *
 * A driver states the cash they physically hold for the bus. It is a manual
 * reconciliation record — not the M-Pesa callback — keyed on the VEHICLE from
 * the caller's assignment (crews rotate; the money is the till's) and on the
 * business day, with exactly one declaration per vehicle per day: resubmitting
 * corrects it, never duplicates.
 */
final class DriverCashSubmissionTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/driver/cash';

    /** @return array{0:User, 1:Vehicle} */
    private function crewedDriver(?Sacco $sacco = null): array
    {
        $sacco ??= $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $vehicle = $this->makeVehicle($sacco, $owner, $this->makeSeat());

        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver])->save();

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
    public function a_driver_declares_todays_cash_for_the_assigned_vehicle(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();

        Sanctum::actingAs($driver);

        $this->putJson(self::ENDPOINT, ['declared_amount' => 4500, 'note' => 'Counted at the stage'])
            ->assertOk()
            ->assertJsonPath('submission.declared_amount', 4500)
            ->assertJsonPath('submission.vehicle_id', $vehicle->id)
            ->assertJsonPath('submission.user_id', $driver->id)
            ->assertJsonPath('submission.business_date', BusinessDay::current()->toDateString());

        $this->assertDatabaseHas('cash_submissions', [
            'vehicle_id' => $vehicle->id,
            'user_id' => $driver->id,
            'business_date' => BusinessDay::current()->toDateString(),
            'declared_amount' => 4500,
            'note' => 'Counted at the stage',
        ]);
    }

    #[Test]
    public function resubmitting_the_same_day_updates_the_single_declaration(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();

        Sanctum::actingAs($driver);

        $this->putJson(self::ENDPOINT, ['declared_amount' => 4500])->assertOk();
        // The driver recounts and corrects — the same shift, not a second count.
        $this->putJson(self::ENDPOINT, ['declared_amount' => 5200])->assertOk();

        $this->assertSame(
            1,
            CashSubmission::where('vehicle_id', $vehicle->id)
                ->where('business_date', BusinessDay::current()->toDateString())
                ->count(),
            'A resubmission on the same day must update the one row, not stack a second.'
        );

        $this->assertDatabaseHas('cash_submissions', [
            'vehicle_id' => $vehicle->id,
            'business_date' => BusinessDay::current()->toDateString(),
            'declared_amount' => 5200,
        ]);
    }

    #[Test]
    public function the_declaration_is_filed_against_the_callers_vehicle_only(): void
    {
        // Two drivers, two buses in the same SACCO. Each declaration must land on
        // the declarer's own assigned vehicle — nothing takes a vehicle from the
        // request, so a body-supplied vehicle_id is ignored.
        $sacco = $this->makeSacco();
        [$mine, $myVehicle] = $this->crewedDriver($sacco);
        [, $theirVehicle] = $this->crewedDriver($sacco);

        Sanctum::actingAs($mine);

        $this->putJson(self::ENDPOINT, [
            'declared_amount' => 3000,
            // A malicious/confused client trying to declare against another bus.
            'vehicle_id' => $theirVehicle->id,
        ])->assertOk()->assertJsonPath('submission.vehicle_id', $myVehicle->id);

        $this->assertDatabaseHas('cash_submissions', [
            'vehicle_id' => $myVehicle->id,
            'declared_amount' => 3000,
        ]);
        $this->assertSame(
            0,
            CashSubmission::where('vehicle_id', $theirVehicle->id)->count(),
            'The declaration must never be attributed to a vehicle the caller was not assigned to.'
        );
    }

    #[Test]
    public function the_response_reports_recorded_cash_and_the_variance(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();

        // Recorded cash for today: two cash fares. M-Pesa (mpesa_id) must not count.
        Transaction::create(['vehicle_id' => $vehicle->id, 'amount' => 200, 'trans_date' => now(), 'cash_id' => 1, 'mpesa_id' => 0]);
        Transaction::create(['vehicle_id' => $vehicle->id, 'amount' => 300, 'trans_date' => now(), 'cash_id' => 2, 'mpesa_id' => 0]);
        Transaction::create(['vehicle_id' => $vehicle->id, 'amount' => 999, 'trans_date' => now(), 'cash_id' => 0, 'mpesa_id' => 5]);

        Sanctum::actingAs($driver);

        $this->putJson(self::ENDPOINT, ['declared_amount' => 450])
            ->assertOk()
            ->assertJsonPath('expected', 500)
            // Declared 450 against 500 recorded -> a 50 shortfall.
            ->assertJsonPath('variance', -50);
    }

    #[Test]
    public function a_missing_or_negative_amount_is_rejected(): void
    {
        [$driver] = $this->crewedDriver();

        Sanctum::actingAs($driver);

        $this->putJson(self::ENDPOINT, [])->assertStatus(400);
        $this->putJson(self::ENDPOINT, ['declared_amount' => -100])->assertStatus(400);
        $this->putJson(self::ENDPOINT, ['declared_amount' => 'not-a-number'])->assertStatus(400);

        $this->assertSame(0, CashSubmission::count(), 'A rejected declaration must not persist anything.');
    }

    #[Test]
    public function a_driver_with_no_assignment_is_refused(): void
    {
        $driver = $this->makeUser();
        $driver->forceFill(['type' => UserType::Driver])->save();

        Sanctum::actingAs($driver);

        $this->putJson(self::ENDPOINT, ['declared_amount' => 1000])
            ->assertStatus(403)
            ->assertJsonStructure(['error']);
    }

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        $this->putJson(self::ENDPOINT, ['declared_amount' => 1000])->assertStatus(401);

        $this->assertSame(0, CashSubmission::count());
    }
}
