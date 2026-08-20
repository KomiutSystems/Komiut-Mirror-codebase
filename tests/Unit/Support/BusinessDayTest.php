<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BusinessDay;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BusinessDay — the 03:00 EAT day boundary, proven directly against
 * Africa/Nairobi so it holds no matter what config('app.timezone') is set to.
 *
 * A pure unit test (no Laravel container): the class must compute the window in
 * Nairobi and only re-express the result in the app timezone, defaulting to UTC
 * when the container is not booted. Under that UTC default, 03:00 EAT == 00:00
 * UTC, which is what keeps the window byte-identical to the old calendar-midnight
 * code while making the boundary explicit.
 */
final class BusinessDayTest extends TestCase
{
    #[Test]
    public function the_window_starts_at_03_00_eat_and_spans_24_hours(): void
    {
        // Mid-afternoon in Nairobi — squarely inside one business day.
        $at = Carbon::parse('2026-08-20 14:00:00', 'Africa/Nairobi');

        [$start, $end] = BusinessDay::windowFor($at);

        // The returned instants default to UTC (container not booted), where the
        // 03:00-EAT boundary is exactly 00:00 UTC.
        $this->assertSame('2026-08-20 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-21 00:00:00', $end->format('Y-m-d H:i:s'));

        // The wall-clock boundary in Nairobi is 03:00, not midnight.
        $this->assertSame('03:00', $start->copy()->setTimezone('Africa/Nairobi')->format('H:i'));
        $this->assertSame('03:00', $end->copy()->setTimezone('Africa/Nairobi')->format('H:i'));

        // Exactly 24 hours, half-open.
        $this->assertSame(24 * 60, (int) $start->diffInMinutes($end));
    }

    #[Test]
    public function two_fifty_nine_eat_falls_into_the_previous_business_day(): void
    {
        // 02:59 EAT on the 20th is still the night of the 19th's business day.
        $at = Carbon::parse('2026-08-20 02:59:00', 'Africa/Nairobi');

        [$start, $end] = BusinessDay::windowFor($at);

        $this->assertSame(
            '2026-08-19 03:00:00',
            $start->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-08-20 03:00:00',
            $end->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i:s'),
        );

        // At 02:59 EAT the business date is still the 19th.
        $this->assertSame('2026-08-19', BusinessDay::current($at)->toDateString());
    }

    #[Test]
    public function three_oh_one_eat_starts_the_current_business_day(): void
    {
        // One minute past the boundary rolls into the new business day.
        $at = Carbon::parse('2026-08-20 03:01:00', 'Africa/Nairobi');

        [$start, $end] = BusinessDay::windowFor($at);

        $this->assertSame(
            '2026-08-20 03:00:00',
            $start->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-08-21 03:00:00',
            $end->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i:s'),
        );

        $this->assertSame('2026-08-20', BusinessDay::current($at)->toDateString());
    }

    #[Test]
    public function exactly_03_00_eat_is_the_inclusive_start_of_the_new_day(): void
    {
        // The boundary instant itself belongs to the day it opens (half-open).
        $at = Carbon::parse('2026-08-20 03:00:00', 'Africa/Nairobi');

        [$start] = BusinessDay::windowFor($at);

        $this->assertTrue(
            $start->equalTo($at),
            'The 03:00 EAT boundary is the inclusive start of its own business day.',
        );
        $this->assertSame('2026-08-20', BusinessDay::current($at)->toDateString());
    }

    #[Test]
    public function the_window_is_byte_identical_to_calendar_midnight_under_utc(): void
    {
        // The invariant that keeps the current UTC-configured numbers unchanged:
        // windowFor(date) == [date->startOfDay(), +1 day] when the app tz is UTC,
        // because 03:00 EAT == 00:00 UTC.
        $date = Carbon::parse('2026-08-20 00:00:00', 'UTC');

        [$start, $end] = BusinessDay::windowFor($date);

        $this->assertTrue($start->equalTo($date->copy()->startOfDay()));
        $this->assertTrue($end->equalTo($date->copy()->startOfDay()->addDay()));
    }

    #[Test]
    public function current_returns_an_immutable_nairobi_midnight(): void
    {
        $current = BusinessDay::current(Carbon::parse('2026-08-20 14:00:00', 'Africa/Nairobi'));

        $this->assertInstanceOf(CarbonImmutable::class, $current);
        $this->assertSame('00:00', $current->format('H:i'));
        $this->assertSame('Africa/Nairobi', $current->getTimezone()->getName());
    }
}
