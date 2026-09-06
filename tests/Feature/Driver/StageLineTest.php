<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Models\Queue;
use App\Services\Queues\StageLine;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The line at the stage is live: when the front bus departs, everyone moves up.
 *
 * Positions used to be handed out as max(position) + 1 over every row created
 * that day, whatever its status, and nothing ever gave a position back. A bus
 * that departed at 06:30 still held slot 1 at midnight, so the /queues screen
 * showed arrival order rather than a queue, and the second bus in the line was
 * still called number 2 with nobody in front of it.
 *
 * The unique index on (terminus_id, route_id, created_at::date, position) makes
 * releasing mandatory rather than tidy: a departed bus holding slot 1 makes that
 * slot unusable for the rest of the day, so the vehicle behind cannot be moved
 * into it even deliberately.
 */
final class StageLineTest extends QueueTestCase
{
    private function line(): StageLine
    {
        return app(StageLine::class);
    }

    /** Three buses waiting on one route at one terminus, in order. */
    private function threeWaiting(): array
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');

        $buses = [];
        foreach ([1, 2, 3] as $slot) {
            $vehicle = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
            $queue = $this->makeQueue($vehicle, $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-'.$slot);
            $queue->forceFill(['position' => $slot])->save();
            $buses[$slot] = $queue->fresh();
        }

        return [$world, $pending, $buses];
    }

    private function positionsInLine(array $buses): array
    {
        return array_map(fn (Queue $q) => $q->fresh()->position === null ? null : (int) $q->fresh()->position, $buses);
    }

    #[Test]
    public function when_the_front_bus_leaves_everyone_moves_up(): void
    {
        // THE BEHAVIOUR THE STAGE ACTUALLY HAS. Position 2 becomes position 1.
        [, , $buses] = $this->threeWaiting();

        $this->line()->release($buses[1]);

        $this->assertNull($buses[1]->fresh()->position, 'a departed bus holds no place in the line');
        $this->assertSame([2 => 1, 3 => 2], array_filter($this->positionsInLine($buses)));
    }

    #[Test]
    public function the_label_follows_the_position(): void
    {
        // The screen renders queue_number. Leaving it at QN-3 while the bus is
        // second in line is the same bug wearing a different column.
        [, , $buses] = $this->threeWaiting();

        $this->line()->release($buses[1]);

        $this->assertSame('QN-1', $buses[2]->fresh()->queue_number);
        $this->assertSame('QN-2', $buses[3]->fresh()->queue_number);
    }

    #[Test]
    public function a_bus_leaving_from_the_middle_closes_its_own_gap(): void
    {
        [, , $buses] = $this->threeWaiting();

        $this->line()->release($buses[2]);

        $this->assertSame(1, (int) $buses[1]->fresh()->position, 'the bus in front does not move');
        $this->assertSame(2, (int) $buses[3]->fresh()->position);
    }

    #[Test]
    public function the_released_slot_is_handed_to_the_next_joiner(): void
    {
        // Without reuse, a stage that has seen forty buses hands the next one
        // slot 41 while two are actually waiting.
        [$world, , $buses] = $this->threeWaiting();

        $this->line()->release($buses[1]);
        $this->line()->release($buses[2]);

        $next = $this->line()->takeSlot((int) $world['terminus']->id, (int) $world['route']->id);

        $this->assertSame(2, $next, 'one bus is waiting, so the next joins as number two');
    }

    #[Test]
    public function departing_takes_the_bus_out_of_the_line(): void
    {
        // Departing is leaving the stage. It must free the slot at that moment,
        // not at trip end — the bus is gone from the terminus the instant it
        // pulls out.
        [, , $buses] = $this->threeWaiting();
        $active = $this->makeQueueStatus('Active', 'Active');

        $buses[1]->forceFill(['queue_status_id' => $active->id])->save();
        $this->line()->release($buses[1]->fresh());

        $this->assertNull($buses[1]->fresh()->position);
        $this->assertSame(1, (int) $buses[2]->fresh()->position);
    }

    #[Test]
    public function a_bus_on_the_road_never_occupies_a_slot(): void
    {
        // Active means departed. Only Pending is "at the stage", so an in-flight
        // trip must not be counted when handing out the next number.
        [$world, , $buses] = $this->threeWaiting();
        $active = $this->makeQueueStatus('Active', 'Active');

        foreach ($buses as $bus) {
            $bus->forceFill(['queue_status_id' => $active->id, 'position' => null])->save();
        }

        $this->assertSame(
            1,
            $this->line()->takeSlot((int) $world['terminus']->id, (int) $world['route']->id),
            'an empty stage starts again at one however many buses are out on the route',
        );
    }

    #[Test]
    public function releasing_a_bus_that_holds_no_slot_is_harmless(): void
    {
        // Called from the stale sweep and from exit, which cannot know whether a
        // position was ever assigned.
        [, , $buses] = $this->threeWaiting();
        $buses[3]->forceFill(['position' => null])->save();

        $this->line()->release($buses[3]->fresh());

        $this->assertSame(1, (int) $buses[1]->fresh()->position);
        $this->assertSame(2, (int) $buses[2]->fresh()->position);
    }

    #[Test]
    public function another_route_at_the_same_terminus_is_a_different_line(): void
    {
        // One stage serves many routes and each is its own queue. Compacting the
        // Thika line must not renumber the one beside it.
        [$world, $pending, $buses] = $this->threeWaiting();

        $otherRoute = $this->makeRoute($world['from'], $world['to'], $world['sacco']);
        $otherVehicle = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $other = $this->makeQueue($otherVehicle, $world['terminus'], $otherRoute, $pending, $world['owner'], 'QN-1');
        $other->forceFill(['position' => 1])->save();

        $this->line()->release($buses[1]);

        $this->assertSame(1, (int) $other->fresh()->position, "the other route's line is untouched");
        $this->assertSame('QN-1', $other->fresh()->queue_number);
    }
}
