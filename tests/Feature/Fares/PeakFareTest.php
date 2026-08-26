<?php

declare(strict_types=1);

namespace Tests\Feature\Fares;

use App\Models\FarePeriod;
use App\Models\RouteFare;
use App\Services\Fares\FareResolver;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Peak fares: the same segment priced differently at different times.
 *
 * The resolution order these tests pin, most specific first:
 *   1. a stop-pair fare on a PEAK PERIOD covering this moment, highest priority
 *   2. the base stop-pair fare
 *   3. the SACCO's flat sacco_routes.amount
 *   4. null — refuse, never guess
 *
 * Times are Kenyan wall-clock (Africa/Nairobi, UTC+3) throughout. That is not
 * decoration: this system already has one EAT-vs-UTC trap in it, and a peak
 * window evaluated in UTC would start and end three hours late every day.
 */
final class PeakFareTest extends QueueTestCase
{
    private function period(array $world, array $attributes = []): FarePeriod
    {
        return FarePeriod::withoutGlobalScopes()->create(array_merge([
            'sacco_id' => $world['sacco']->id,
            'name' => 'Morning peak',
            'days' => [1, 2, 3, 4, 5],
            'start_time' => '06:00:00',
            'end_time' => '09:00:00',
            'priority' => 0,
            'status' => true,
        ], $attributes));
    }

    private function fare(array $world, float $amount, ?FarePeriod $period = null): RouteFare
    {
        return RouteFare::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id,
            'route_id' => $world['route']->id,
            'from_place_id' => $world['from']->id,
            'to_place_id' => $world['to']->id,
            'amount' => $amount,
            'fare_period_id' => $period?->id,
            'status' => true,
        ]);
    }

    /** 08:00 on a Wednesday, in Nairobi. */
    private function wednesdayMorning(string $time = '08:00'): Carbon
    {
        return Carbon::parse('2026-08-26 '.$time, FarePeriod::TIMEZONE);
    }

    private function resolveAt(array $world, Carbon $at): ?float
    {
        return app(FareResolver::class)->resolve(
            $world['sacco']->id,
            $world['route']->id,
            $world['from']->id,
            $world['to']->id,
            $at
        );
    }

    #[Test]
    public function inside_the_window_the_peak_price_wins(): void
    {
        $world = $this->makeWorld();
        $peak = $this->period($world);

        $this->fare($world, 150);          // base
        $this->fare($world, 200, $peak);   // peak

        $this->assertSame(200.0, $this->resolveAt($world, $this->wednesdayMorning('08:00')));
    }

    #[Test]
    public function outside_the_window_the_base_price_is_charged(): void
    {
        $world = $this->makeWorld();
        $peak = $this->period($world);

        $this->fare($world, 150);
        $this->fare($world, 200, $peak);

        $this->assertSame(150.0, $this->resolveAt($world, $this->wednesdayMorning('11:00')));
    }

    #[Test]
    public function the_window_end_is_exclusive_so_adjacent_windows_cannot_both_claim_it(): void
    {
        // 06:00-09:00 and 09:00-12:00 must not both own 09:00:00, or which one
        // applies depends on row order.
        $world = $this->makeWorld();
        $morning = $this->period($world);
        $midday = $this->period($world, [
            'name' => 'Midday', 'start_time' => '09:00:00', 'end_time' => '12:00:00',
        ]);

        $this->fare($world, 150);
        $this->fare($world, 200, $morning);
        $this->fare($world, 180, $midday);

        $this->assertSame(200.0, $this->resolveAt($world, $this->wednesdayMorning('08:59')));
        $this->assertSame(180.0, $this->resolveAt($world, $this->wednesdayMorning('09:00')));
    }

    #[Test]
    public function a_window_that_wraps_midnight_covers_the_small_hours_of_the_next_day(): void
    {
        // The late-night rate: 21:00 -> 05:00. The window belongs to the day it
        // STARTS on, so a Wednesday-only window must still cover Thursday 02:00.
        $world = $this->makeWorld();
        $night = $this->period($world, [
            'name' => 'Late night',
            'days' => [3],                 // Wednesday only
            'start_time' => '21:00:00',
            'end_time' => '05:00:00',
        ]);

        $this->fare($world, 150);
        $this->fare($world, 300, $night);

        // Wednesday 22:00 — after the start, on the listed day.
        $this->assertSame(300.0, $this->resolveAt($world, $this->wednesdayMorning('22:00')));

        // Thursday 02:00 — before the end, on the morning after the listed day.
        $thursday = Carbon::parse('2026-08-27 02:00', FarePeriod::TIMEZONE);
        $this->assertSame(300.0, $this->resolveAt($world, $thursday));

        // Thursday 06:00 — the window has closed.
        $this->assertSame(150.0, $this->resolveAt($world, Carbon::parse('2026-08-27 06:00', FarePeriod::TIMEZONE)));
    }

    #[Test]
    public function a_window_does_not_apply_on_a_day_it_does_not_list(): void
    {
        $world = $this->makeWorld();
        $weekday = $this->period($world, ['days' => [1, 2, 3, 4, 5]]);

        $this->fare($world, 150);
        $this->fare($world, 200, $weekday);

        // 2026-08-29 is a Saturday.
        $saturday = Carbon::parse('2026-08-29 08:00', FarePeriod::TIMEZONE);
        $this->assertSame(150.0, $this->resolveAt($world, $saturday));
    }

    #[Test]
    public function priority_settles_two_overlapping_windows(): void
    {
        // A holiday rate over a weekday peak. Without priority this is "whichever
        // row the planner returned first", which is not an answer a SACCO can
        // give a passenger who asks why they were charged what they were.
        $world = $this->makeWorld();
        $ordinary = $this->period($world, ['name' => 'Morning peak', 'priority' => 0]);
        $holiday = $this->period($world, ['name' => 'Holiday', 'priority' => 10]);

        $this->fare($world, 150);
        $this->fare($world, 200, $ordinary);
        $this->fare($world, 250, $holiday);

        $this->assertSame(250.0, $this->resolveAt($world, $this->wednesdayMorning('08:00')));
    }

    #[Test]
    public function a_live_window_with_no_price_for_this_segment_falls_through(): void
    {
        // A period is SACCO-wide but priced per segment. A window that is live
        // but silent about this journey must not block the base fare.
        $world = $this->makeWorld();
        $peak = $this->period($world);

        $this->fare($world, 150);   // base only; nothing priced against $peak

        $this->assertSame(150.0, $this->resolveAt($world, $this->wednesdayMorning('08:00')));
    }

    #[Test]
    public function a_deactivated_period_charges_the_base_fare(): void
    {
        $world = $this->makeWorld();
        $peak = $this->period($world, ['status' => false]);

        $this->fare($world, 150);
        $this->fare($world, 200, $peak);

        $this->assertSame(150.0, $this->resolveAt($world, $this->wednesdayMorning('08:00')));
    }

    #[Test]
    public function windows_are_kenyan_wall_clock_not_utc(): void
    {
        // EAT is UTC+3. 06:30 UTC is 09:30 in Nairobi — after the window closes.
        // Evaluated in UTC this would wrongly read as peak, and every window on
        // the platform would run three hours late.
        $world = $this->makeWorld();
        $peak = $this->period($world);

        $this->fare($world, 150);
        $this->fare($world, 200, $peak);

        $utcMorning = Carbon::parse('2026-08-26 06:30', 'UTC');

        $this->assertSame(
            150.0,
            $this->resolveAt($world, $utcMorning),
            '06:30 UTC is 09:30 in Nairobi — outside a 06:00-09:00 window'
        );

        // And 04:00 UTC is 07:00 in Nairobi, which IS inside it.
        $this->assertSame(200.0, $this->resolveAt($world, Carbon::parse('2026-08-26 04:00', 'UTC')));
    }

    #[Test]
    public function the_flat_fare_is_still_the_floor_when_no_pair_is_priced(): void
    {
        $world = $this->makeWorld();   // makeWorld sets a flat sacco_route of 200
        $this->period($world);

        $this->assertSame(200.0, $this->resolveAt($world, $this->wednesdayMorning('08:00')));
    }

    #[Test]
    public function an_unpriced_route_still_resolves_to_null_rather_than_guessing(): void
    {
        // The invariant peak pricing must not weaken: callers refuse on null,
        // and never fall back to a client-supplied amount.
        $world = $this->makeWorld();
        \App\Models\SaccoRoute::withoutGlobalScopes()
            ->where('route_id', $world['route']->id)->update(['status' => false]);

        app(FareResolver::class)->forget($world['sacco']->id, $world['route']->id);

        $this->assertNull($this->resolveAt($world, $this->wednesdayMorning('08:00')));
    }

    #[Test]
    public function the_quote_names_the_period_it_came_from(): void
    {
        // A passenger charged 200 at 07:00 and 150 at 11:00 will otherwise
        // conclude the app is broken or the SACCO is cheating.
        $world = $this->makeWorld();
        $peak = $this->period($world, ['name' => 'Morning peak']);
        $this->fare($world, 150);
        $this->fare($world, 200, $peak);

        $named = app(FareResolver::class)->activePeriodFor(
            $world['sacco']->id, $world['route']->id,
            $world['from']->id, $world['to']->id,
            $this->wednesdayMorning('08:00')
        );

        $this->assertSame('Morning peak', $named['name']);

        $this->assertNull(app(FareResolver::class)->activePeriodFor(
            $world['sacco']->id, $world['route']->id,
            $world['from']->id, $world['to']->id,
            $this->wednesdayMorning('11:00')
        ));
    }

    #[Test]
    public function the_cached_bundle_does_not_freeze_the_price_at_whatever_the_hour_started_as(): void
    {
        // THE most likely way to get this wrong. The bundle is cached for an
        // hour; if the ACTIVE price were baked into it, a 09:00 peak would end
        // somewhere between 09:00 and 10:00 depending on traffic.
        $world = $this->makeWorld();
        $peak = $this->period($world);
        $this->fare($world, 150);
        $this->fare($world, 200, $peak);

        // Warm the cache while the window is open...
        $this->assertSame(200.0, $this->resolveAt($world, $this->wednesdayMorning('08:00')));

        // ...then ask again, same cache entry, after it closes.
        $this->assertSame(
            150.0,
            $this->resolveAt($world, $this->wednesdayMorning('10:00')),
            'the cached bundle must hold DEFINITIONS, not the resolved price'
        );
    }
}
