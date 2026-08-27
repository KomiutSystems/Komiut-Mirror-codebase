<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Seat;
use App\Models\SeatArrangement;
use App\Services\Seats\SeatMapGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Seat maps: the thing whose absence made every booking on the platform fail.
 *
 * `seat_arrangements` held zero rows against five layouts and 895 vehicles, so
 * SeatArrangement::find returned null for every seat a passenger could pick and
 * addBooking 400'd before writing anything. `bookings` has never had a row in
 * it, and this is why.
 *
 * The layouts these tests use are the real five from production, with their real
 * numbers, because the geometry rule has to hold for those specifically — not
 * for a tidy invented example.
 */
final class SeatMapGeneratorTest extends QueueTestCase
{
    /** The production layouts: name, seats, rows, columns. */
    private const PRODUCTION_LAYOUTS = [
        ['9-seater', 9, 5, 2],
        ['33-seater', 33, 9, 5],
        ['45-Seater', 45, 10, 6],
        ['51-seater', 51, 11, 6],
        ['14-Seater', 14, 5, 3],
    ];

    private function layout(string $name, int $seats, int $rows, int $columns): Seat
    {
        return Seat::create([
            'name' => $name.' '.$this->nextSequence(),
            'seats' => $seats,
            'rows' => $rows,
            'columns' => $columns,
            'status' => true,
        ]);
    }

    private function generator(): SeatMapGenerator
    {
        return app(SeatMapGenerator::class);
    }

    #[Test]
    public function every_production_layout_gets_exactly_the_seats_it_claims(): void
    {
        foreach (self::PRODUCTION_LAYOUTS as [$name, $seats, $rows, $columns]) {
            $layout = $this->layout($name, $seats, $rows, $columns);

            $created = $this->generator()->generateFor($layout);

            $this->assertSame($seats, $created, $name.' must produce its full seat count');
            $this->assertSame(
                $seats,
                SeatArrangement::where('seat_id', $layout->id)->count(),
                $name.' seats in the database'
            );
        }
    }

    #[Test]
    public function seats_are_numbered_the_way_a_conductor_counts_them(): void
    {
        // "Seat 7", not "R2C3". A grid coordinate means nothing to someone
        // standing at a stage being told where to sit.
        $layout = $this->layout('14-Seater', 14, 5, 3);
        $this->generator()->generateFor($layout);

        $names = SeatArrangement::where('seat_id', $layout->id)
            ->orderBy('id')->pluck('name')->all();

        $this->assertSame(array_map('strval', range(1, 14)), $names);
    }

    #[Test]
    public function a_five_across_bus_gets_a_centre_aisle(): void
    {
        // The 33-seater is 85 of NICCO's 180 buses, and a 5-across bus is built
        // as 2 + gangway + 2. Nine rows of four seats is 36 places for 33 seats.
        $layout = $this->layout('33-seater', 33, 9, 5);
        $this->generator()->generateFor($layout);

        $columns = SeatArrangement::where('seat_id', $layout->id)
            ->pluck('column')->unique()->sort()->values()->all();

        $this->assertSame([1, 2, 4, 5], $columns, 'column 3 is the gangway and must hold no seats');
    }

    #[Test]
    public function a_layout_too_narrow_for_an_aisle_uses_the_whole_grid(): void
    {
        // A 14-seater is 3 across and you climb over. Drop a column and only 10
        // seats fit, so forcing an aisle here would build the wrong bus.
        $layout = $this->layout('14-Seater', 14, 5, 3);
        $this->generator()->generateFor($layout);

        $columns = SeatArrangement::where('seat_id', $layout->id)
            ->pluck('column')->unique()->sort()->values()->all();

        $this->assertSame([1, 2, 3], $columns);
    }

    #[Test]
    public function a_layout_that_already_has_seats_is_left_completely_alone(): void
    {
        // Arrangement ids live inside seat_bookings. Regenerating a map under a
        // live booking moves a passenger to a different seat, or points their
        // booking at one that no longer exists.
        $layout = $this->layout('33-seater', 33, 9, 5);
        $this->generator()->generateFor($layout);

        $before = SeatArrangement::where('seat_id', $layout->id)->pluck('id')->all();

        $this->assertSame(0, $this->generator()->generateFor($layout), 'a second run must create nothing');
        $this->assertSame($before, SeatArrangement::where('seat_id', $layout->id)->pluck('id')->all());
    }

    #[Test]
    public function a_layout_whose_grid_cannot_hold_its_seats_builds_nothing(): void
    {
        // 2 rows of 2 cannot seat 33. Better visibly missing than a bus that is
        // quietly 29 seats short of the one on the road.
        $layout = $this->layout('Impossible', 33, 2, 2);

        $this->assertSame(0, $this->generator()->generateFor($layout));
        $this->assertSame(0, SeatArrangement::where('seat_id', $layout->id)->count());
    }

    #[Test]
    public function nonsense_dimensions_are_refused_rather_than_guessed(): void
    {
        foreach ([[0, 5, 3], [14, 0, 3], [14, 5, 0]] as [$seats, $rows, $columns]) {
            $layout = $this->layout('Broken', $seats, $rows, $columns);
            $this->assertSame(0, $this->generator()->generateFor($layout));
        }
    }

    #[Test]
    public function generate_missing_reports_what_it_built_and_skips_what_it_did_not(): void
    {
        $fresh = $this->layout('45-Seater', 45, 10, 6);
        $done = $this->layout('51-seater', 51, 11, 6);
        $this->generator()->generateFor($done);

        $report = $this->generator()->generateMissing();

        $this->assertArrayHasKey($fresh->name, $report);
        $this->assertSame(45, $report[$fresh->name]);
        $this->assertArrayNotHasKey($done->name, $report, 'an already-mapped layout is not reported');
    }

    #[Test]
    public function seats_run_front_to_back_and_left_to_right(): void
    {
        // The order the map is drawn in, and the order a conductor walks.
        $layout = $this->layout('9-seater', 9, 5, 2);
        $this->generator()->generateFor($layout);

        $seats = SeatArrangement::where('seat_id', $layout->id)->orderBy('id')->get();

        $this->assertSame(1, (int) $seats[0]->row);
        $this->assertSame(1, (int) $seats[0]->column);
        $this->assertSame(1, (int) $seats[1]->row);
        $this->assertSame(2, (int) $seats[1]->column);
        $this->assertSame(2, (int) $seats[2]->row, 'the third seat starts the second row');
        $this->assertSame(1, (int) $seats[2]->column);
    }
}
