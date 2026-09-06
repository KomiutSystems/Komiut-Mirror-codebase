<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The numbers behind the earnings sparklines.
 *
 * A total says how much; it does not say whether the day is building or already
 * over, which is most of what a driver checking their phone at 14:00 wants.
 * `series` adds the shape.
 *
 * THE ASSERTION THAT MATTERS MOST is that the series sums to the headline. A
 * sparkline disagreeing with the number printed beside it is worse than no
 * sparkline, because it makes the reader distrust both — so that is pinned
 * first and for every window.
 */
final class EarningsSeriesTest extends QueueTestCase
{
    private const URL = '/api/v1/auth/driver/earnings';

    /** @return array{0: User, 1: Vehicle} */
    private function crewedBus(): array
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return [$driver, $world['vehicle']];
    }

    /** An M-Pesa fare on this bus at a given Nairobi wall-clock moment. */
    private function fare(Vehicle $vehicle, float $amount, string $at): void
    {
        Transaction::create([
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'trans_date' => $at,
            'mpesa_id' => 1,
            'cash_id' => 0,
        ]);
    }

    private function earnings(User $driver): array
    {
        Sanctum::actingAs($driver);

        return $this->getJson(self::URL)->assertOk()->json();
    }

    #[Test]
    public function every_window_carries_a_series(): void
    {
        [$driver] = $this->crewedBus();

        $body = $this->earnings($driver);

        foreach (['today', 'week', 'month', 'all_time'] as $window) {
            $this->assertArrayHasKey('series', $body[$window], "{$window} must carry a series");
            $this->assertIsArray($body[$window]['series']);
        }
    }

    #[Test]
    public function the_series_sums_to_the_headline(): void
    {
        // THE ONE THAT MATTERS. The card prints cash + mpesa above the sparkline
        // drawn from these points; if they disagree the screen contradicts
        // itself.
        [$driver, $bus] = $this->crewedBus();
        $today = BusinessDay::forLocalColumn(Carbon::now());

        $this->fare($bus, 120, $today->copy()->setTime(7, 15)->toDateTimeString());
        $this->fare($bus, 80, $today->copy()->setTime(7, 45)->toDateTimeString());
        $this->fare($bus, 50, $today->copy()->setTime(13, 5)->toDateTimeString());

        $body = $this->earnings($driver);

        foreach (['today', 'week', 'month'] as $window) {
            $headline = (float) $body[$window]['cash'] + (float) $body[$window]['mpesa'];
            $charted = array_sum(array_column($body[$window]['series'], 'amount'));

            $this->assertEqualsWithDelta($headline, $charted, 0.01, "{$window}: the chart must equal the number above it");
        }
    }

    #[Test]
    public function quiet_hours_are_drawn_as_zero_not_skipped(): void
    {
        // Dropping empty buckets compresses the quiet hours and draws a busy
        // afternoon as though it ran all day. The spacing carries meaning.
        [$driver, $bus] = $this->crewedBus();
        $today = BusinessDay::forLocalColumn(Carbon::now());

        $this->fare($bus, 100, $today->copy()->setTime(6, 30)->toDateTimeString());
        $this->fare($bus, 100, $today->copy()->setTime(9, 30)->toDateTimeString());

        $series = $this->earnings($driver)['today']['series'];
        // Cast: json_encode drops the fraction from a whole float, so an empty
        // bucket arrives as int 0. JSON has one number type; the assertion
        // should not pretend otherwise.
        $amounts = array_map('floatval', array_column($series, 'amount'));

        $this->assertGreaterThan(2, count($series), 'the gap between 06:00 and 09:00 has to be drawn');
        $this->assertContains(0.0, $amounts, 'an hour with no fares is a zero, not a missing point');
    }

    #[Test]
    public function the_points_run_oldest_first(): void
    {
        [$driver, $bus] = $this->crewedBus();
        $today = BusinessDay::forLocalColumn(Carbon::now());
        $this->fare($bus, 60, $today->copy()->setTime(8, 0)->toDateTimeString());

        $series = $this->earnings($driver)['today']['series'];
        $stamps = array_column($series, 'at');
        $sorted = $stamps;
        sort($sorted);

        $this->assertSame($sorted, $stamps, 'a chart drawn backwards is a wrong chart');
    }

    #[Test]
    public function the_week_is_bucketed_by_business_day_not_by_midnight(): void
    {
        // A fare at 01:30 belongs to the night shift that began the previous
        // afternoon. Bucketing it at midnight would split one shift across two
        // bars and move money into a day the driver did not work.
        [$driver, $bus] = $this->crewedBus();

        $lateFare = BusinessDay::forLocalColumn(Carbon::now())->copy()->setTime(1, 30);
        $this->fare($bus, 90, $lateFare->toDateTimeString());

        $body = $this->earnings($driver);
        $nonZero = array_values(array_filter($body['week']['series'], fn ($p) => $p['amount'] > 0));

        $this->assertCount(1, $nonZero, 'the fare lands in exactly one business day');

        $bucketStart = Carbon::parse($nonZero[0]['at']);
        $this->assertSame(BusinessDay::START_HOUR, $bucketStart->hour, 'daily buckets start at 03:00, not midnight');
        $this->assertTrue($bucketStart->lessThan($lateFare), 'a 01:30 fare belongs to the shift that began the day before');
    }

    #[Test]
    public function a_bus_that_has_never_earned_gets_a_flat_line_not_an_error(): void
    {
        // A new vehicle is the first thing a SACCO opens after onboarding.
        [$driver] = $this->crewedBus();

        $body = $this->earnings($driver);

        $this->assertSame([], $body['all_time']['series'], 'no payments means no history to chart');
        $this->assertNotEmpty($body['today']['series'], 'today still has hours, they are simply empty');
        $this->assertEqualsWithDelta(0.0, array_sum(array_column($body['today']['series'], 'amount')), 0.01);
    }

    #[Test]
    public function the_existing_totals_are_untouched(): void
    {
        // Additive by contract: the shipped app reads these four and must keep
        // working through this deploy.
        [$driver, $bus] = $this->crewedBus();
        $this->fare($bus, 200, BusinessDay::forLocalColumn(Carbon::now())->copy()->setTime(10, 0)->toDateTimeString());

        $body = $this->earnings($driver);

        foreach (['today', 'week', 'month', 'all_time'] as $window) {
            foreach (['cash', 'mpesa', 'net', 'trips'] as $key) {
                $this->assertArrayHasKey($key, $body[$window], "{$window}.{$key} must survive");
            }
        }

        $this->assertSame(200.0, (float) $body['today']['mpesa']);
        $this->assertArrayHasKey('takings', $body, 'the legacy shape stays too');
    }
}
