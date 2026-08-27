<?php

declare(strict_types=1);

namespace App\Services\Seats;

use App\Models\Seat;
use App\Models\SeatArrangement;
use Illuminate\Support\Facades\DB;

/**
 * Turns a seat LAYOUT into the individual seats a passenger can actually pick.
 *
 * `seats` holds five layouts — 9, 14, 33, 45 and 51-seater — and each one is
 * just a name and a grid size. `seat_arrangements` holds the seats themselves,
 * and it is what a booking points at: addBooking resolves every id the client
 * sends through SeatArrangement::find, and seat_bookings.seat_id stores an
 * arrangement id (despite being declared foreignIdFor(Seat::class), which is a
 * trap worth knowing about).
 *
 * THE TABLE WAS EMPTY. Zero rows, platform-wide, against five layouts and 895
 * vehicles. So every seat lookup returned null and every booking failed with a
 * 400 before writing anything — which is the whole reason `bookings` has never
 * had a single row in it. Nothing else about the booking flow could be tested
 * until this existed.
 *
 * There is no way to create a layout through the API (SeatsAPIController only
 * reads), so these five are fixed reference data and generating their maps once
 * is the complete fix rather than a stopgap.
 *
 * WHERE THE AISLE GOES. A layout carries `rows` and `columns`, and in every
 * case rows × columns exceeds the seat count — the slack is the aisle and the
 * door. But the slack is not the same shape in each:
 *
 *     9-seater    5×2 = 10 cells,  9 seats
 *     14-seater   5×3 = 15 cells, 14 seats
 *     33-seater   9×5 = 45 cells, 33 seats
 *     45-seater  10×6 = 60 cells, 45 seats
 *     51-seater  11×6 = 66 cells, 51 seats
 *
 * A centre aisle only works where the geometry can spare a whole column: the
 * 33-seater has 9 rows of 4 seats either side of one, which is exactly how a
 * 5-across bus is built. The 14-seater cannot — drop a column from 5×3 and only
 * 10 seats fit, so a matatu that seats 14 is 3 across and you climb over. So the
 * aisle is given where it fits and skipped where it does not, rather than forced
 * on every layout to look tidy. 85 of NICCO's 180 buses are 33-seaters, so the
 * common case is the one that gets a real aisle.
 *
 * IDEMPOTENT, AND ONE-WAY ON PURPOSE. A layout that already has seats is left
 * completely alone. Arrangement ids end up inside seat_bookings, so regenerating
 * them under a live booking would silently move a passenger to another seat, or
 * point their booking at a seat that no longer exists.
 */
final class SeatMapGenerator
{
    /**
     * Build the missing maps and report what was created, keyed by layout name.
     *
     * @return array<string, int> layout name => seats created
     */
    public function generateMissing(): array
    {
        $created = [];

        foreach (Seat::query()->orderBy('id')->get() as $layout) {
            $n = $this->generateFor($layout);

            if ($n > 0) {
                $created[$layout->name] = $n;
            }
        }

        return $created;
    }

    /**
     * Seats for one layout. Returns 0 — and touches nothing — when the layout
     * already has a map, or when its numbers do not describe a real bus.
     */
    public function generateFor(Seat $layout): int
    {
        if (SeatArrangement::query()->where('seat_id', $layout->id)->exists()) {
            return 0;
        }

        $rows = $this->plan($layout);

        if ($rows === []) {
            return 0;
        }

        // One statement, so a layout is either fully mapped or not mapped at
        // all. A half-built map is worse than none: the seats that exist look
        // bookable and the rest read as a bus with holes in it.
        DB::transaction(static function () use ($rows): void {
            SeatArrangement::query()->insert($rows);
        });

        return count($rows);
    }

    /**
     * The rows to insert, laid out left to right and front to back.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(Seat $layout): array
    {
        $total = (int) $layout->seats;
        $rows = (int) $layout->rows;
        $columns = (int) $layout->columns;

        if ($total < 1 || $rows < 1 || $columns < 1) {
            return [];
        }

        $aisle = $this->aisleColumn($total, $rows, $columns);

        $plan = [];
        $number = 1;
        $now = now();

        for ($row = 1; $row <= $rows && $number <= $total; $row++) {
            for ($column = 1; $column <= $columns && $number <= $total; $column++) {
                if ($column === $aisle) {
                    continue;
                }

                $plan[] = [
                    // What the conductor shouts and the passenger repeats back.
                    // Seat 7 is seat 7 — not "R2C3", which is a grid coordinate
                    // and means nothing to anyone standing at a stage.
                    'name' => (string) $number,
                    'seat_id' => $layout->id,
                    'row' => $row,
                    'column' => $column,
                    'status' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $number++;
            }
        }

        // The grid was too small to hold the seats the layout claims. Better to
        // build nothing and be visibly missing than to build a bus that is
        // quietly three seats short of the one on the road.
        return count($plan) === $total ? $plan : [];
    }

    /**
     * The column to leave empty as a gangway, or null when the layout cannot
     * spare one.
     */
    private function aisleColumn(int $total, int $rows, int $columns): ?int
    {
        if ($columns % 2 === 0) {
            return null;   // no middle column to give up
        }

        return $rows * ($columns - 1) >= $total
            ? intdiv($columns, 2) + 1
            : null;
    }
}
