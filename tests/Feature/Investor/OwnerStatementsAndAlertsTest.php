<?php

declare(strict_types=1);

namespace Tests\Feature\Investor;

use App\Auth\Roles;
use App\Models\OwnerNotificationSetting;
use App\Models\OwnerStatement;
use App\Models\Summary;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAlert;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleLocation;
use App\Models\VehicleUser;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * An owner's nightly statement and the alerts about their buses.
 *
 * The property that matters throughout: an owner sees THEIR buses, never the
 * SACCO's fleet. The Investor role carries View Summaries, and before
 * ScopesToOwnedVehicles that resolved to a SACCO-wide read.
 */
final class OwnerStatementsAndAlertsTest extends QueueTestCase
{
    /** @return array{world: array, owner: User, vehicle: Vehicle, other: Vehicle} */
    private function scene(): array
    {
        $world = $this->makeWorld();
        $vehicle = $world['vehicle'];

        // A second bus in the SAME sacco that the owner does NOT own.
        $other = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $owner = $this->makeUser([], $world['sacco']);
        $owner->assignRole(Role::firstOrCreate(['name' => Roles::INVESTOR]));

        VehicleUser::create([
            'user_id' => $owner->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $world['sacco']->id,
            'start_date' => Carbon::now()->subMonth(),
            'status' => true,
        ]);

        return compact('world', 'owner', 'vehicle', 'other');
    }

    private function takings(Vehicle $v, int $mpesa, int $cash, int $mpesaTxn = 0, int $cashTxn = 0): void
    {
        Summary::create([
            'vehicle_id' => $v->id,
            'mpesa_amount' => $mpesa,
            'cash_amount' => $cash,
            'mpesa_txn' => $mpesaTxn,
            'cash_txn' => $cashTxn,
            'trans_date' => Carbon::today()->toDateString(),
        ]);
    }

    // ------------------------------------------------------------- who may read

    #[Test]
    public function an_account_without_the_investor_role_gets_404_everywhere(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($this->makeUser([], $s['world']['sacco']));

        foreach (['owner/statements', 'owner/statements/2026-10-21', 'owner/alerts', 'owner/notification-settings'] as $path) {
            $this->getJson('/api/v1/auth/'.$path)->assertStatus(404);
        }

        $this->patchJson('/api/v1/auth/owner/notification-settings', ['noon_alert_enabled' => false])
            ->assertStatus(404);
    }

    #[Test]
    public function a_day_with_no_statement_yet_is_200_with_null_not_404(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['owner']);

        $this->getJson('/api/v1/auth/owner/statements/'.Carbon::today()->toDateString())
            ->assertOk()
            ->assertJsonPath('statement', null);
    }

    // ----------------------------------------------------------- the figures

    #[Test]
    public function the_statement_figures_reconcile(): void
    {
        $s = $this->scene();
        $this->takings($s['vehicle'], 7300, 1150, 96, 0);

        $fee = \App\Models\ExpenseFee::create(['name' => 'Fuel', 'sacco_id' => $s['world']['sacco']->id, 'type' => 'Expense', 'status' => true]);
        VehicleExpenseAndFee::create([
            'vehicle_id' => $s['vehicle']->id,
            'expense_fee_id' => $fee->id,
            'amount' => 1200,
            'trans_date' => Carbon::today()->setTime(9, 0),
            'status' => true,
        ]);

        $this->artisan('owners:send-statements', ['--owner' => $s['owner']->id, '--force' => true])->assertExitCode(0);

        Sanctum::actingAs($s['owner']);
        $body = $this->getJson('/api/v1/auth/owner/statements/'.Carbon::today()->toDateString())
            ->assertOk()->json('statement');

        $totals = $body['totals'];
        $bus = $body['vehicles'][0];

        $this->assertSame('KES', $body['currency']);
        // collections = mpesa + cash + points
        $this->assertSame($bus['mpesa'] + $bus['cash'] + $bus['points'], $bus['collections']);
        // net = collections - expenses
        $this->assertSame($bus['collections'] - $bus['expenses'], $bus['net']);
        $this->assertSame(1200, $bus['expenses']);
        $this->assertSame([['label' => 'Fuel', 'amount' => 1200]], $bus['expense_lines']);
        // the fleet line is the sum of the buses
        $this->assertSame($bus['collections'], $totals['collections']);
        $this->assertSame($bus['net'], $totals['net']);
        $this->assertSame(96, $totals['payments']);
    }

    #[Test]
    public function an_owner_sees_only_the_buses_they_own_not_the_saccos_fleet(): void
    {
        $s = $this->scene();
        $this->takings($s['vehicle'], 5000, 0, 10);
        $this->takings($s['other'], 9999, 0, 20);

        $this->artisan('owners:send-statements', ['--owner' => $s['owner']->id, '--force' => true])->assertExitCode(0);

        Sanctum::actingAs($s['owner']);
        $body = $this->getJson('/api/v1/auth/owner/statements/'.Carbon::today()->toDateString())
            ->assertOk()->json('statement');

        $this->assertCount(1, $body['vehicles'], 'Only the owned bus belongs on the statement.');
        $this->assertSame((int) $s['vehicle']->id, $body['vehicles'][0]['vehicle_id']);
        $this->assertSame(5000, $body['totals']['collections']);
    }

    // ---------------------------------------------------------------- the push

    #[Test]
    public function the_statement_is_stored_but_not_pushed_while_the_flag_is_off(): void
    {
        // Twelve real Investor accounts exist and their app build has no such
        // screen yet, so the push ships dark and the data does not.
        config(['owner.push_enabled' => false]);
        Notification::fake();

        $s = $this->scene();
        $this->takings($s['vehicle'], 5000, 0, 10);

        $this->artisan('owners:send-statements', ['--owner' => $s['owner']->id, '--force' => true])->assertExitCode(0);

        $this->assertDatabaseCount('owner_statements', 1);
        $this->assertNull(OwnerStatement::firstOrFail()->pushed_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function with_the_flag_on_the_owner_is_pushed_once(): void
    {
        config(['owner.push_enabled' => true]);
        Notification::fake();

        $s = $this->scene();
        $this->takings($s['vehicle'], 5000, 0, 10);

        $this->artisan('owners:send-statements', ['--owner' => $s['owner']->id, '--force' => true])->assertExitCode(0);

        Notification::assertSentTo($s['owner'], PlatformNotification::class, function (PlatformNotification $n): bool {
            return $n->type->value === 'owner_statement'
                && $n->referenceId === Carbon::today()->toDateString();
        });
        $this->assertNotNull(OwnerStatement::firstOrFail()->pushed_at);
    }

    #[Test]
    public function running_the_sweep_again_does_not_build_a_second_statement(): void
    {
        $s = $this->scene();
        $this->takings($s['vehicle'], 5000, 0, 10);

        foreach (range(1, 3) as $ignored) {
            $this->artisan('owners:send-statements', ['--owner' => $s['owner']->id, '--force' => true])->assertExitCode(0);
        }

        $this->assertDatabaseCount('owner_statements', 1);
    }

    #[Test]
    public function the_sweep_waits_for_the_owners_chosen_time(): void
    {
        $s = $this->scene();
        $this->takings($s['vehicle'], 5000, 0, 10);

        OwnerNotificationSetting::create([
            'owner_id' => $s['owner']->id,
            'statement_time' => '23:59',
        ] + OwnerNotificationSetting::DEFAULTS);

        // Without --force, 23:59 has not passed (unless the suite runs in that
        // one minute, which Carbon's test clock rules out below).
        Carbon::setTestNow(Carbon::today('Africa/Nairobi')->setTime(21, 0)->shiftTimezone('Africa/Nairobi'));
        $this->artisan('owners:send-statements', ['--owner' => $s['owner']->id])->assertExitCode(0);
        $this->assertDatabaseCount('owner_statements', 0);

        Carbon::setTestNow(Carbon::today('Africa/Nairobi')->setTime(23, 59)->shiftTimezone('Africa/Nairobi'));
        $this->artisan('owners:send-statements', ['--owner' => $s['owner']->id])->assertExitCode(0);
        $this->assertDatabaseCount('owner_statements', 1);

        Carbon::setTestNow();
    }

    // ------------------------------------------------------------- settings

    #[Test]
    public function an_owner_who_never_saved_gets_the_defaults(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['owner']);

        $this->getJson('/api/v1/auth/owner/notification-settings')
            ->assertOk()
            ->assertJsonPath('settings.statement_time', '21:00')
            ->assertJsonPath('settings.offline_after_minutes', 30)
            ->assertJsonPath('settings.statement_enabled', true);
    }

    #[Test]
    public function patching_one_setting_leaves_the_others_alone(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['owner']);

        $this->patchJson('/api/v1/auth/owner/notification-settings', ['statement_time' => '20:30'])->assertOk();
        $this->patchJson('/api/v1/auth/owner/notification-settings', ['noon_alert_enabled' => false])
            ->assertOk()
            // The time set in the first call must survive the second.
            ->assertJsonPath('settings.statement_time', '20:30')
            ->assertJsonPath('settings.noon_alert_enabled', false);
    }

    #[Test]
    public function only_the_three_offered_offline_thresholds_are_accepted(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['owner']);

        $this->patchJson('/api/v1/auth/owner/notification-settings', ['offline_after_minutes' => 45])
            ->assertStatus(400);

        $this->patchJson('/api/v1/auth/owner/notification-settings', ['offline_after_minutes' => 60])
            ->assertOk()
            ->assertJsonPath('settings.offline_after_minutes', 60);
    }

    // --------------------------------------------------------------- alerts

    #[Test]
    public function a_bus_that_stops_reporting_raises_one_alert_not_one_per_pass(): void
    {
        Carbon::setTestNow(Carbon::today('Africa/Nairobi')->setTime(14, 35)->shiftTimezone('Africa/Nairobi'));
        $s = $this->scene();

        // On shift today, last seen 30 minutes ago.
        VehicleUser::where('vehicle_id', $s['vehicle']->id)
            ->update(['start_date' => Carbon::now()->subHours(6)]);
        VehicleLocation::create([
            'vehicle_id' => $s['vehicle']->id,
            'latitude' => -1.28, 'longitude' => 36.82,
            'recorded_at' => Carbon::now()->subMinutes(35),
        ]);

        foreach (range(1, 3) as $ignored) {
            $this->artisan('vehicles:detect-offline')->assertExitCode(0);
        }

        $this->assertSame(1, VehicleAlert::where('type', VehicleAlert::OFFLINE)->count());

        $alert = VehicleAlert::firstOrFail();
        $this->assertSame(35, $alert->details['minutes_offline']);
        $this->assertNull($alert->resolved_at);

        // It reports again: the episode closes.
        VehicleLocation::where('vehicle_id', $s['vehicle']->id)->update(['recorded_at' => Carbon::now()]);
        $this->artisan('vehicles:detect-offline')->assertExitCode(0);

        $this->assertNotNull($alert->fresh()->resolved_at);
        Carbon::setTestNow();
    }

    #[Test]
    public function a_bus_parked_overnight_raises_nothing(): void
    {
        // 02:00 NAIROBI is outside 05:00-22:00; a parked bus is not a fault.
        Carbon::setTestNow(Carbon::today('Africa/Nairobi')->setTime(2, 0)->shiftTimezone('Africa/Nairobi'));
        $s = $this->scene();

        VehicleUser::where('vehicle_id', $s['vehicle']->id)->update(['start_date' => Carbon::now()->subHour()]);
        VehicleLocation::create([
            'vehicle_id' => $s['vehicle']->id,
            'latitude' => -1.28, 'longitude' => 36.82,
            'recorded_at' => Carbon::now()->subHours(4),
        ]);

        $this->artisan('vehicles:detect-offline')->assertExitCode(0);

        $this->assertSame(0, VehicleAlert::count());
        Carbon::setTestNow();
    }

    #[Test]
    public function the_noon_check_spares_a_bus_that_has_taken_money(): void
    {
        Carbon::setTestNow(Carbon::today('Africa/Nairobi')->setTime(12, 0)->shiftTimezone('Africa/Nairobi'));
        $s = $this->scene();

        VehicleUser::where('vehicle_id', $s['vehicle']->id)->update(['start_date' => Carbon::now()->subHours(5)]);

        $this->artisan('vehicles:noon-check')->assertExitCode(0);
        $this->assertSame(1, VehicleAlert::where('type', VehicleAlert::NO_COLLECTIONS)->count());

        VehicleAlert::query()->delete();
        \App\Models\Transaction::create([
            'vehicle_id' => $s['vehicle']->id,
            'amount' => 100,
            'status' => 1,
            'trans_date' => Carbon::now()->subHour(),
        ]);

        $this->artisan('vehicles:noon-check')->assertExitCode(0);
        $this->assertSame(0, VehicleAlert::count(), 'A bus that has taken money is not alerted on.');

        Carbon::setTestNow();
    }

    #[Test]
    public function the_alerts_list_is_scoped_to_the_owner_and_shaped_for_the_app(): void
    {
        $s = $this->scene();
        VehicleAlert::create([
            'owner_id' => $s['owner']->id,
            'vehicle_id' => $s['vehicle']->id,
            'type' => VehicleAlert::OFFLINE,
            'details' => ['last_seen_at' => Carbon::now()->toIso8601String(), 'minutes_offline' => 30],
            'raised_at' => Carbon::now(),
        ]);
        // Another owner's alert must not appear.
        $stranger = $this->makeUser([], $s['world']['sacco']);
        VehicleAlert::create([
            'owner_id' => $stranger->id,
            'vehicle_id' => $s['other']->id,
            'type' => VehicleAlert::OFFLINE,
            'details' => [],
            'raised_at' => Carbon::now(),
        ]);

        Sanctum::actingAs($s['owner']);
        $body = $this->getJson('/api/v1/auth/owner/alerts')->assertOk();

        $body->assertJsonPath('total', 1)
            ->assertJsonPath('alerts.0.type', VehicleAlert::OFFLINE)
            ->assertJsonPath('alerts.0.plate', $s['vehicle']->plate)
            ->assertJsonPath('alerts.0.resolved_at', null);

        $this->assertMatchesRegularExpression('/^al_\d+$/', $body->json('alerts.0.id'));
    }
}
