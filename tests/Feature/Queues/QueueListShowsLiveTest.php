<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * "Queues" means the buses on a stage now, not everything that happened today.
 *
 * The listing had no status filter, so a matatu that finished its run still sat
 * in the dispatcher's queue list while the driver's own app showed "Join Queue"
 * — currentQueue() has always filtered to Pending/Active. The two screens
 * disagreed about whether a bus was queued.
 */
final class QueueListShowsLiveTest extends QueueTestCase
{
    #[Test]
    public function a_finished_queue_drops_out_of_the_list(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $completed = $this->makeQueueStatus('Completed', 'Completed');

        $live = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-1');
        $done = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $completed, $world['owner'], 'QN-2');

        Sanctum::actingAs($this->makeUser(['View Queues'], $world['sacco']));

        $ids = $this->getJson('/api/auth/queues')->assertOk()->json('queues.*.id');

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($done->id, $ids, 'a completed run is not a queue');
    }

    #[Test]
    public function an_active_bus_is_still_a_queue(): void
    {
        $world = $this->makeWorld();
        $active = $this->makeQueueStatus('Active', 'Active');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner']);

        Sanctum::actingAs($this->makeUser(['View Queues'], $world['sacco']));

        // Active means departed and picking up — still on the road, still live.
        $this->assertContains($queue->id, $this->getJson('/api/auth/queues')->assertOk()->json('queues.*.id'));
    }

    #[Test]
    public function history_is_still_reachable_deliberately(): void
    {
        $world = $this->makeWorld();
        $completed = $this->makeQueueStatus('Completed', 'Completed');
        $done = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $completed, $world['owner']);

        Sanctum::actingAs($this->makeUser(['View Queues'], $world['sacco']));

        $this->assertContains($done->id, $this->getJson('/api/auth/queues?status=all')->assertOk()->json('queues.*.id'));
        $this->assertContains($done->id, $this->getJson('/api/auth/queues?status=Completed')->assertOk()->json('queues.*.id'));
    }
}
