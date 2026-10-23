<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VehicleUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * 2026-10-23: fourteen crew phones polling driver/transactions, home and
 * earnings tied up every php-fpm worker -- each request counted (and, on
 * earnings, summed) the bus's whole payment history from the heap -- and the
 * ALB replaced both servers. The totals must stay right; how they are read
 * must stay cheap.
 */
final class DriverTakingsCostTest extends QueueTestCase
{
    /** @return array{0: User, 1: array<string, mixed>} */
    private function driverOnABusWithHistory(): array
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver, 'sacco_id' => $world['sacco']->id])->save();
        Role::findOrCreate(Roles::DRIVER, 'web');
        $driver->assignRole(Roles::DRIVER);
        VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id, 'sacco_id' => $world['sacco']->id,
            'status' => true, 'start_date' => now()->subDay(),
        ]);
        foreach (['2026-08-01 09:00', '2026-09-15 09:00', '2026-10-23 07:00'] as $i => $at) {
            $m = Mpesa::withoutGlobalScopes()->create(['TransID' => 'DRV'.$i, 'MSISDN' => '254700000000', 'TransAmount' => '100',
                'TransTime' => Carbon::parse($at), 'FirstName' => 'Ivy']);
            Transaction::withoutGlobalScopes()->create(['vehicle_id' => $world['vehicle']->id, 'mpesa_id' => $m->id, 'amount' => 100, 'trans_date' => Carbon::parse($at)]);
        }

        return [$driver->fresh(), $world];
    }

    #[Test]
    public function the_takings_total_counts_every_payment_without_joining_mpesas(): void
    {
        [$driver] = $this->driverOnABusWithHistory();
        Sanctum::actingAs($driver);
        $counts = [];
        DB::listen(function ($q) use (&$counts) {
            if (str_contains($q->sql, 'count(*)') && str_contains($q->sql, '"transactions"')) {
                $counts[] = $q->sql;
            }
        });

        $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->assertJsonPath('total', 3);

        $this->assertNotEmpty($counts);
        $this->assertStringNotContainsString('join "mpesas"', $counts[0], 'the join kept the count off an index-only scan');
    }

    #[Test]
    public function a_search_still_counts_what_it_matches(): void
    {
        [$driver] = $this->driverOnABusWithHistory();
        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/transactions?search=DRV1')->assertOk()->assertJsonPath('total', 1);
    }

    #[Test]
    public function all_time_earnings_are_reused_rather_than_re_summed_on_every_poll(): void
    {
        [$driver] = $this->driverOnABusWithHistory();
        Sanctum::actingAs($driver);

        $first = $this->getJson('/api/v1/auth/driver/earnings')->assertOk()->json('all_time');
        $sums = 0;
        DB::listen(function ($q) use (&$sums) {
            if (str_contains($q->sql, 'SUM(amount)') && ! str_contains($q->sql, '"trans_date" >=')) {
                $sums++;
            }
        });
        $second = $this->getJson('/api/v1/auth/driver/earnings')->assertOk()->json('all_time');

        $this->assertSame($first, $second);
        $this->assertSame(300.0, (float) $first['mpesa']);
        $this->assertSame(0, $sums, 'the unbounded all-time sum ran again');
    }

    #[Test]
    public function a_one_or_two_letter_word_no_longer_scans_every_payers_name(): void
    {
        // "Iv" has no trigrams: it probed every payment the bus ever took.
        [$driver] = $this->driverOnABusWithHistory();
        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/transactions?search=Iv')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/auth/driver/transactions?search=Ivy')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/v1/auth/driver/transactions?search=100')->assertOk()->assertJsonPath('total', 3);   // an amount
    }

    #[Test]
    public function a_search_that_runs_out_of_time_answers_instead_of_holding_the_worker(): void
    {
        // Hold a lock the search must wait for, from another connection, so it
        // cannot finish within its budget -- as a 12s whole-history name
        // search could not. It must answer, flagged, within the two budgets.
        [$driver] = $this->driverOnABusWithHistory();
        Sanctum::actingAs($driver);
        config(['database.connections.pgsql_blocker' => config('database.connections.pgsql')]);
        $blocker = DB::connection('pgsql_blocker');
        $blocker->beginTransaction();
        $blocker->statement('LOCK TABLE qrcode_payments IN ACCESS EXCLUSIVE MODE');

        try {
            $started = microtime(true);
            $response = $this->getJson('/api/v1/auth/driver/transactions?search=Ivy')->assertOk();
            $took = microtime(true) - $started;
        } finally {
            $blocker->rollBack();
            DB::purge('pgsql_blocker');
        }

        $response->assertJsonPath('search_window_days', 14);
        $this->assertLessThan(6.0, $took, 'bounded by the 1.5s budget plus the 3s fallback budget');
    }

    #[Test]
    public function an_unhurried_search_still_covers_every_payment(): void
    {
        [$driver] = $this->driverOnABusWithHistory();
        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/transactions?search=DRV0')   // paid 2026-08-01, months ago
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('search_window_days', null);
    }
}
