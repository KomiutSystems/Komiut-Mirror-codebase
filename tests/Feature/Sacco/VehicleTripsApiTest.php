<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\Summary;
use App\Models\Vehicle;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * GET saccos/vehicles/trips — "which of my buses did the most trips today?"
 *
 * The screen this replaces answered that question with `total_txn`, which counts
 * PAYMENTS: 13,313 of them across 143 NICCO buses in one production day, read by
 * owners as ~93 journeys per bus. These tests pin the three things that make the
 * new number trustworthy — WHAT is counted (a queue, and only the statuses that
 * mean the bus moved), WHOSE buses are counted (the caller's SACCO, from the
 * model scope), and that the money beside it is the day's takings rather than
 * the takings multiplied by the trip count.
 */
final class VehicleTripsApiTest extends QueueTestCase
{
    private const URL = '/api/v1/auth/saccos/vehicles/trips';

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed "now" so `date`/`from`/`to` maths is deterministic and a run
        // that straddles midnight cannot flap.
        Carbon::setTestNow('2026-08-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The five canonical statuses, created once and shared: queue_statuses is a
     * platform table, not a per-SACCO one, so two SACCOs in one test must key
     * off the same rows.
     *
     * @return array<string, QueueStatus>
     */
    private function statuses(): array
    {
        $out = [];
        foreach (['Pending', 'Active', 'Completed', 'Cancelled', 'Suspended'] as $status) {
            $out[$status] = $this->makeQueueStatus($status, $status);
        }

        return $out;
    }

    /** $count queues for $vehicle at $at, all in the given status. */
    private function queues(array $world, Vehicle $vehicle, QueueStatus $status, int $count, ?string $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $queue = $this->makeQueue(
                $vehicle, $world['terminus'], $world['route'], $status, $world['owner'],
                'QN-'.$vehicle->id.'-'.$status->id.'-'.$i
            );

            if ($at !== null) {
                $queue->forceFill(['created_at' => Carbon::parse($at)])->save();
            }
        }
    }

    private function summary(Vehicle $vehicle, string $date, float $mpesa, float $cash): Summary
    {
        return Summary::create([
            'vehicle_id' => $vehicle->id,
            'trans_date' => $date,
            'mpesa_amount' => $mpesa,
            'cash_amount' => $cash,
            'mpesa_txn' => 1,
            'cash_txn' => 1,
        ]);
    }

    /** @return array<int, string> plates in the order the endpoint returned them */
    private function plates(array $body): array
    {
        return array_column($body['vehicles'], 'plate');
    }

    #[Test]
    public function it_counts_trips_per_vehicle_and_puts_the_busiest_bus_first(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $busiest = $world['vehicle'];
        $quiet = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $this->queues($world, $busiest, $s['Completed'], 3);
        $this->queues($world, $quiet, $s['Completed'], 1);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $body = $this->getJson(self::URL)->assertOk()->json();

        $this->assertSame([$busiest->plate, $quiet->plate], $this->plates($body));
        $this->assertSame(3, $body['vehicles'][0]['trips']);
        $this->assertSame(1, $body['vehicles'][1]['trips']);

        // The footer describes the fleet, not the page.
        $this->assertSame(4, $body['totals']['trips']);
        $this->assertSame(2, $body['totals']['vehicles']);
        $this->assertSame(2, $body['totals']['vehicles_with_trips']);
    }

    /**
     * THE DEFINITION, pinned. Completed and Active are trips; Pending, Cancelled
     * and Suspended are not.
     *
     * A cancelled queue is a driver who joined the stage and pulled out — no
     * passenger carried, no fare earned. A pending one is a bus parked in the
     * line. Counting either turns "queued" into "travelled", which is the same
     * inflation the old total_txn column produced by a different route.
     */
    #[Test]
    public function only_completed_and_active_queues_count_as_trips(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($world, $world['vehicle'], $s['Completed'], 1);
        $this->queues($world, $world['vehicle'], $s['Active'], 1);
        $this->queues($world, $world['vehicle'], $s['Pending'], 4);
        $this->queues($world, $world['vehicle'], $s['Cancelled'], 3);
        $this->queues($world, $world['vehicle'], $s['Suspended'], 2);

        $this->assertSame(11, Queue::count(), 'guard: all eleven queues were written');

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $body = $this->getJson(self::URL)->assertOk()->json();

        $this->assertSame(2, $body['vehicles'][0]['trips']);
        $this->assertSame(2, $body['totals']['trips']);

        // Published in the payload so a dashboard can print WHAT it counted
        // under the column heading — the caption total_txn never had.
        $this->assertSame(['Completed', 'Active'], $body['trip_statuses']);
    }

    #[Test]
    public function another_saccos_vehicles_never_appear(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($mine, $mine['vehicle'], $s['Completed'], 1);
        // The other SACCO is BUSIER, so if the boundary leaked its bus would be
        // the first row, not a row buried at the bottom.
        $this->queues($theirs, $theirs['vehicle'], $s['Completed'], 9);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $mine['sacco']));

        $body = $this->getJson(self::URL)->assertOk()->json();

        $this->assertSame([$mine['vehicle']->plate], $this->plates($body));
        $this->assertSame(1, $body['totals']['trips']);
        $this->assertSame(1, $body['total']);
    }

    /**
     * The other half of the tenancy story: the trip COUNT must not be totalled
     * across SACCOs either. A shared vehicle_id space plus an unscoped subquery
     * would show the right buses with the wrong numbers.
     */
    #[Test]
    public function trip_counts_are_not_inflated_by_another_saccos_queues(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($mine, $mine['vehicle'], $s['Completed'], 2);
        $this->queues($theirs, $theirs['vehicle'], $s['Completed'], 40);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $mine['sacco']));

        $body = $this->getJson(self::URL)->assertOk()->json();

        $this->assertSame(2, $body['vehicles'][0]['trips']);
    }

    #[Test]
    public function a_caller_with_no_relevant_permission_is_refused(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();
        $this->queues($world, $world['vehicle'], $s['Completed'], 1);

        // A passenger: authenticated, no permissions, no SACCO. Vehicle opts
        // into cross-tenant browsing so SaccoScope does NOT narrow them — the
        // route's permission gate is the only thing standing between this
        // account and every SACCO's fleet.
        // Bring both permissions into existence first, so the refusal below is
        // "you do not hold this" rather than the weaker "no such permission".
        $this->makeUser(['View Sacco Vehicles', 'View Summaries'], $world['sacco']);

        Sanctum::actingAs($this->makeUser([], null));

        $this->getJson(self::URL)->assertStatus(403);
    }

    #[Test]
    public function the_date_parameter_selects_a_single_day(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($world, $world['vehicle'], $s['Completed'], 2, '2026-08-18 09:00:00');
        $this->queues($world, $world['vehicle'], $s['Completed'], 5, '2026-08-20 09:00:00');

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $body = $this->getJson(self::URL.'?date=2026-08-18')->assertOk()->json();

        $this->assertSame(2, $body['vehicles'][0]['trips']);
        $this->assertSame(['from' => '2026-08-18', 'to' => '2026-08-18'], $body['range']);

        // No parameter at all means today, exactly as the money screens behave.
        $today = $this->getJson(self::URL)->assertOk()->json();
        $this->assertSame(5, $today['vehicles'][0]['trips']);
    }

    #[Test]
    public function the_from_and_to_parameters_select_an_inclusive_range(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($world, $world['vehicle'], $s['Completed'], 1, '2026-08-17 23:59:00');
        $this->queues($world, $world['vehicle'], $s['Completed'], 2, '2026-08-18 09:00:00');
        $this->queues($world, $world['vehicle'], $s['Completed'], 3, '2026-08-20 09:00:00');

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $body = $this->getJson(self::URL.'?from=2026-08-18&to=2026-08-20')->assertOk()->json();

        // 2+3. The 17th is outside; `to` is INCLUSIVE of the whole 20th, which
        // is what the exclusive upper bound in range() buys.
        $this->assertSame(5, $body['vehicles'][0]['trips']);
        $this->assertSame(['from' => '2026-08-18', 'to' => '2026-08-20'], $body['range']);
    }

    /**
     * A range longer than a year is refused rather than served slowly. The
     * derived tables scan queues and summaries over the whole window before the
     * join narrows them, so one mistyped `from` is an unbounded scan.
     */
    #[Test]
    public function an_absurd_range_is_refused_out_loud(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $this->getJson(self::URL.'?from=2019-01-01&to=2026-01-01')
            ->assertStatus(400)
            ->assertJsonStructure(['error']);

        // A typo is a 400, not a 500 — "the server is broken" is the wrong thing
        // to tell someone who mistyped a date.
        $this->getJson(self::URL.'?date=yesterday-ish')->assertStatus(400);
    }

    /**
     * THE FAN-OUT REGRESSION. Joining queues and summaries straight onto
     * vehicles and aggregating once would multiply the day's takings by the
     * number of queues: this bus would report 3,000 M-Pesa instead of 1,000, and
     * the trip count would be wrong in the other direction.
     */
    #[Test]
    public function the_money_is_the_days_takings_not_the_takings_times_the_trips(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($world, $world['vehicle'], $s['Completed'], 3);
        $this->summary($world['vehicle'], '2026-08-20', 1000, 500);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles', 'View Summaries'], $world['sacco']));

        $body = $this->getJson(self::URL)->assertOk()->json();

        $row = $body['vehicles'][0];
        $this->assertSame(3, $row['trips']);
        $this->assertSame(1000.0, (float) $row['mpesa_amount']);
        $this->assertSame(500.0, (float) $row['cash_amount']);
        $this->assertSame(1500.0, (float) $row['collections']);

        $this->assertTrue($body['includes_money']);
        $this->assertSame(1500.0, (float) $body['totals']['collections']);
    }

    /** summaries is one row per bus per day, so a range has to SUM them. */
    #[Test]
    public function money_is_summed_across_the_days_in_the_range(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($world, $world['vehicle'], $s['Completed'], 1, '2026-08-18 09:00:00');
        $this->summary($world['vehicle'], '2026-08-18', 1000, 100);
        $this->summary($world['vehicle'], '2026-08-19', 2000, 200);
        // Outside the window on purpose.
        $this->summary($world['vehicle'], '2026-08-20', 9000, 900);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles', 'View Summaries'], $world['sacco']));

        $body = $this->getJson(self::URL.'?from=2026-08-18&to=2026-08-19')->assertOk()->json();

        $this->assertSame(3000.0, (float) $body['vehicles'][0]['mpesa_amount']);
        $this->assertSame(300.0, (float) $body['vehicles'][0]['cash_amount']);
    }

    /**
     * A depot manager holds 'View Sacco Vehicles' but not 'View Summaries'. They
     * get the trip counts and NO money keys at all — absent rather than zeroed,
     * because a 0 in a money column says "this bus earned nothing today", which
     * is a very different and much more alarming claim than "you may not see
     * this".
     */
    #[Test]
    public function money_is_withheld_from_a_caller_who_cannot_see_summaries(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($world, $world['vehicle'], $s['Completed'], 2);
        $this->summary($world['vehicle'], '2026-08-20', 1000, 500);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $body = $this->getJson(self::URL)->assertOk()->json();

        $this->assertSame(2, $body['vehicles'][0]['trips']);
        $this->assertFalse($body['includes_money']);
        $this->assertArrayNotHasKey('mpesa_amount', $body['vehicles'][0]);
        $this->assertArrayNotHasKey('cash_amount', $body['vehicles'][0]);
        $this->assertArrayNotHasKey('collections', $body['vehicles'][0]);
        $this->assertArrayNotHasKey('collections', $body['totals']);

        // And they cannot sort by it either — that would leak the ordering,
        // naming the top earners a page at a time.
        $sorted = $this->getJson(self::URL.'?sort=collections')->assertOk()->json();
        $this->assertSame('trips', $sorted['sort']);
    }

    /**
     * THE NULLS-FIRST TRAP. A bus with no queues in the window gets NULL from
     * the LEFT JOIN, and PostgreSQL orders NULLS FIRST on DESC — so without the
     * COALESCE in the ORDER BY, "busiest first" opens on the buses that never
     * left the yard. On the day measured that was 37 of NICCO's 180, which is
     * two whole pages before the busiest bus.
     */
    #[Test]
    public function buses_that_did_nothing_are_listed_last_not_first(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $idleOne = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $idleTwo = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $this->queues($world, $world['vehicle'], $s['Completed'], 1);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $body = $this->getJson(self::URL)->assertOk()->json();

        $this->assertSame($world['vehicle']->plate, $body['vehicles'][0]['plate']);
        $this->assertSame(1, $body['vehicles'][0]['trips']);

        // Idle buses are still LISTED — "which of my buses did nothing today?"
        // is the same owner's next question, and a missing bus reads as a fine
        // one.
        $this->assertCount(3, $body['vehicles']);
        $this->assertSame(0, $body['vehicles'][1]['trips']);
        $this->assertSame(0, $body['vehicles'][2]['trips']);
        $this->assertEqualsCanonicalizing(
            [$idleOne->plate, $idleTwo->plate],
            [$body['vehicles'][1]['plate'], $body['vehicles'][2]['plate']],
        );
    }

    #[Test]
    public function only_with_trips_hides_the_idle_buses(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $this->queues($world, $world['vehicle'], $s['Completed'], 1);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $body = $this->getJson(self::URL.'?only_with_trips=1')->assertOk()->json();

        $this->assertSame([$world['vehicle']->plate], $this->plates($body));
        // The page count follows the filter, or the pager offers a page that
        // does not exist.
        $this->assertSame(1, $body['total']);
    }

    #[Test]
    public function the_sort_is_whitelisted_and_the_direction_is_honoured(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $busiest = $world['vehicle'];
        $quiet = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $this->queues($world, $busiest, $s['Completed'], 3);
        $this->queues($world, $quiet, $s['Completed'], 1);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $asc = $this->getJson(self::URL.'?direction=asc')->assertOk()->json();
        $this->assertSame([$quiet->plate, $busiest->plate], $this->plates($asc));

        // Anything not on the whitelist falls back to the question the endpoint
        // exists to answer, rather than reaching orderByRaw.
        $junk = $this->getJson(self::URL.'?sort=vehicles.id;drop%20table%20queues')->assertOk()->json();
        $this->assertSame('trips', $junk['sort']);
        $this->assertSame([$busiest->plate, $quiet->plate], $this->plates($junk));

        $byPlate = $this->getJson(self::URL.'?sort=plate&direction=asc')->assertOk()->json();
        $plates = $this->plates($byPlate);
        $sorted = $plates;
        sort($sorted);
        $this->assertSame($sorted, $plates);
    }

    #[Test]
    public function the_page_is_paginated_and_the_footer_covers_the_whole_fleet(): void
    {
        $world = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($world, $world['vehicle'], $s['Completed'], 3);
        $second = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $this->queues($world, $second, $s['Completed'], 2);
        $third = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $this->queues($world, $third, $s['Completed'], 1);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $world['sacco']));

        $page1 = $this->getJson(self::URL.'?per_page=2')->assertOk()->json();

        $this->assertCount(2, $page1['vehicles']);
        $this->assertSame(3, $page1['total']);
        $this->assertSame(2, $page1['per_page']);
        $this->assertSame(1, $page1['current_page']);
        $this->assertSame(2, $page1['last_page']);
        // Six trips over three buses, whichever two are on screen.
        $this->assertSame(6, $page1['totals']['trips']);

        $page2 = $this->getJson(self::URL.'?per_page=2&page=2')->assertOk()->json();

        $this->assertCount(1, $page2['vehicles']);
        $this->assertSame(6, $page2['totals']['trips']);
        $this->assertSame(3, $page2['total']);

        // No bus appears on both pages. Without the id tie-breaker in the ORDER
        // BY, tied rows can be ordered differently per statement and a reader
        // sees one bus twice and another never.
        $this->assertEmpty(array_intersect($this->plates($page1), $this->plates($page2)));
    }

    /**
     * A superadmin is above the tenant boundary and may narrow to one SACCO with
     * `sacco`. That parameter is a display filter, never the boundary — for
     * everyone else SaccoScope has already decided, and naming a foreign SACCO
     * simply returns nothing.
     */
    #[Test]
    public function the_sacco_parameter_narrows_but_does_not_widen(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $s = $this->statuses();

        $this->queues($mine, $mine['vehicle'], $s['Completed'], 1);
        $this->queues($theirs, $theirs['vehicle'], $s['Completed'], 7);

        Sanctum::actingAs($this->makeUser(['View Sacco Vehicles'], $mine['sacco']));

        $body = $this->getJson(self::URL.'?sacco='.$theirs['sacco']->id)->assertOk()->json();

        $this->assertSame([], $body['vehicles']);
        $this->assertSame(0, $body['totals']['trips']);
    }
}
