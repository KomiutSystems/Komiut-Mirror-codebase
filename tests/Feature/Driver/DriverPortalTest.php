<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Models\ExpenseFee;
use App\Models\Terminus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use Database\Seeders\ExpenseFeeSeeder;
use Database\Seeders\TerminusSeeder;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The driver app's own screens.
 *
 * The thing worth pinning down is WHOSE money a driver sees. Every endpoint
 * keys on the vehicle from the caller's current assignment, never on the driver
 * — crews rotate between matatus, and money is paid to the vehicle's till, so a
 * driver who changed buses this morning must see this bus and not yesterday's.
 */
final class DriverPortalTest extends QueueTestCase
{
    /** @return array{0:User, 1:\App\Models\Vehicle} */
    private function crewedDriver(?\App\Models\Sacco $sacco = null): array
    {
        $sacco ??= $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $vehicle = $this->makeVehicle($sacco, $owner, $this->makeSeat());

        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => \App\Enums\UserType::Driver])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return [$driver, $vehicle];
    }

    private function payment(\App\Models\Vehicle $vehicle, float $amount, ?string $at = null): Transaction
    {
        return Transaction::create([
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'trans_date' => $at ? \Carbon\Carbon::parse($at) : now(),
            'mpesa_id' => 0,
            'cash_id' => 0,
        ]);
    }

    #[Test]
    public function home_reports_todays_running_total_for_the_assigned_vehicle(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $this->payment($vehicle, 200);
        $this->payment($vehicle, 350);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/home')
            ->assertOk()
            ->assertJsonPath('vehicle.plate', $vehicle->plate)
            ->assertJsonPath('today.earnings', 550)
            ->assertJsonPath('today.payments', 2)
            ->assertJsonStructure([
                'vehicle' => ['id', 'plate'],
                'today' => ['earnings', 'mpesa', 'cash', 'payments', 'trips', 'expenses', 'net', 'first_at', 'last_at'],
                'capacity' => ['seats', 'occupied', 'available'],
                'recent_transactions',
            ]);
    }

    #[Test]
    public function a_driver_never_sees_another_vehicles_money(): void
    {
        // The defect this guards: the SACCO-wide `transactions` endpoint showed
        // a driver every other bus in the SACCO, including its daily totals.
        $sacco = $this->makeSacco();
        [$driver, $mine] = $this->crewedDriver($sacco);
        [, $theirs] = $this->crewedDriver($sacco);

        $this->payment($mine, 100);
        $this->payment($theirs, 999);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/home')
            ->assertOk()
            ->assertJsonPath('today.earnings', 100);

        $body = $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->json();
        $this->assertSame(1, $body['total'], 'Only the assigned vehicle\'s payments may appear.');
        $this->assertSame(100.0, (float) $body['data'][0]['amount']);
    }

    #[Test]
    public function net_is_takings_minus_expenses(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $this->payment($vehicle, 1000);

        $fuel = ExpenseFee::create(['name' => 'Fuel', 'status' => true]);
        VehicleExpenseAndFee::create([
            'vehicle_id' => $vehicle->id,
            'expense_fee_id' => $fuel->id,
            'amount' => 400,
            'trans_date' => now(),
            'status' => true,
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/home')
            ->assertOk()
            ->assertJsonPath('today.earnings', 1000)
            ->assertJsonPath('today.expenses', 400)
            // What the driver actually goes home with.
            ->assertJsonPath('today.net', 600);
    }

    #[Test]
    public function a_driver_records_an_expense_against_their_own_vehicle_only(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $fuel = ExpenseFee::create(['name' => 'Fuel', 'status' => true]);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/auth/driver/expenses', [
            'expense_fee_id' => $fuel->id,
            'amount' => 250,
        ])->assertCreated();

        $this->assertDatabaseHas('vehicle_expense_and_fees', [
            'vehicle_id' => $vehicle->id,
            'expense_fee_id' => $fuel->id,
            'amount' => 250,
        ]);

        // Recording an expense must move `net` immediately, not after the cache
        // expires — the driver is looking at the screen when they enter it.
        $this->getJson('/api/v1/auth/driver/home')->assertOk()->assertJsonPath('today.net', -250);
    }

    #[Test]
    public function transactions_are_paginated_at_twenty(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        for ($i = 0; $i < 25; $i++) {
            $this->payment($vehicle, 10);
        }

        Sanctum::actingAs($driver);

        $first = $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->json();
        $this->assertCount(20, $first['data']);
        $this->assertSame(25, $first['total']);
        $this->assertSame(2, $first['last_page']);

        $second = $this->getJson('/api/v1/auth/driver/transactions?page=2')->assertOk()->json();
        $this->assertCount(5, $second['data']);
    }

    #[Test]
    public function a_driver_with_no_assignment_is_refused(): void
    {
        // The state a conductor is in before they check in for a shift. It must
        // be a clear 403, not an empty dashboard that looks like a zero day.
        $driver = $this->makeUser();
        $driver->forceFill(['type' => \App\Enums\UserType::Driver])->save();

        Sanctum::actingAs($driver);

        foreach (['home', 'earnings', 'transactions', 'bookings', 'expenses'] as $screen) {
            $this->getJson('/api/v1/auth/driver/'.$screen)
                ->assertStatus(403)
                ->assertJsonStructure(['error']);
        }
    }

    #[Test]
    public function the_seeders_fill_what_the_write_paths_require(): void
    {
        // Every driver write path returned 400 on production because these two
        // tables were empty: no terminus means no queue, hence no trip and no
        // location broadcast; no expense type means the expense form rejects
        // every submission.
        $this->seed(TerminusSeeder::class);
        $this->seed(ExpenseFeeSeeder::class);

        $this->assertGreaterThan(0, Terminus::count(), 'queues/join needs a terminus to exist.');
        $this->assertGreaterThan(0, ExpenseFee::count(), 'driver/expenses needs an expense type to exist.');

        // Idempotent: a redeploy re-runs seeders and must not duplicate.
        $termini = Terminus::count();
        $fees = ExpenseFee::count();
        $this->seed(TerminusSeeder::class);
        $this->seed(ExpenseFeeSeeder::class);

        $this->assertSame($termini, Terminus::count());
        $this->assertSame($fees, ExpenseFee::count());
    }

    #[Test]
    public function the_expense_types_endpoint_feeds_the_apps_picker(): void
    {
        [$driver] = $this->crewedDriver();
        $this->seed(ExpenseFeeSeeder::class);

        Sanctum::actingAs($driver);

        $body = $this->getJson('/api/v1/auth/driver/expenses')->assertOk()->json();
        $this->assertNotEmpty($body['types'], 'The app cannot render an expense form without types.');
        $this->assertSame(0, $body['total']);
    }

    #[Test]
    public function the_picker_shows_shared_types_and_mine_but_not_another_saccos(): void
    {
        // ExpenseFee is SaccoScoped and the shared types carry sacco_id NULL,
        // so the scope filtered every one of them out and the picker came back
        // empty for everybody. Fixed by dropping scopes and re-stating the
        // boundary — which must still exclude another SACCO's categories.
        [$driver, $vehicle] = $this->crewedDriver();
        $other = $this->makeSacco();

        ExpenseFee::create(['name' => 'Shared Fuel', 'sacco_id' => null, 'status' => true]);
        ExpenseFee::create(['name' => 'My Levy', 'sacco_id' => $vehicle->sacco_id, 'status' => true]);
        ExpenseFee::create(['name' => 'Their Secret Levy', 'sacco_id' => $other->id, 'status' => true]);

        Sanctum::actingAs($driver);

        $names = collect($this->getJson('/api/v1/auth/driver/expenses')->assertOk()->json('types'))
            ->pluck('name')->all();

        $this->assertContains('Shared Fuel', $names);
        $this->assertContains('My Levy', $names);
        $this->assertNotContains('Their Secret Levy', $names);
    }
}
