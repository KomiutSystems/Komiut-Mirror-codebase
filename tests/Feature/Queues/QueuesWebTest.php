<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\Queue;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression coverage for the web (session-guard) queue controller,
 * App\Http\Controllers\Dashboard\Queues\QueuesController::addQueue.
 *
 * The web path differs from the API path: it sets/clears `end_time`
 * depending on the target status (QueuesController.php ~line 178).
 */
final class QueuesWebTest extends QueueTestCase
{
    #[Test]
    public function adding_a_queue_with_a_completed_status_stamps_the_end_time(): void
    {
        $world = $this->makeWorld();
        $completed = $this->makeQueueStatus('Completed', 'Completed');
        $user = $this->makeUser(['View Queues', 'Add Queues'], $world['sacco']);
        $this->actingAs($user);

        $this->post('/queues/add', [
            'id' => 0,
            'vehicle' => $world['vehicle']->id,
            'terminus' => $world['terminus']->id,
            'status' => $completed->id,
            'route' => $world['route']->id,
            'choice' => 0,
            'amount' => 200,
        ])->assertOk()->assertJson(['success' => 'Queue updated successfully!']);

        $queue = Queue::firstOrFail();
        $this->assertSame($completed->id, $queue->queue_status_id);
        $this->assertNotNull($queue->end_time, 'Completed status should stamp end_time on the web path');
    }

    #[Test]
    public function adding_a_queue_with_a_pending_status_leaves_the_end_time_null(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $user = $this->makeUser(['View Queues', 'Add Queues'], $world['sacco']);
        $this->actingAs($user);

        $this->post('/queues/add', [
            'id' => 0,
            'vehicle' => $world['vehicle']->id,
            'terminus' => $world['terminus']->id,
            'status' => $pending->id,
            'route' => $world['route']->id,
            'choice' => 0,
            'amount' => 200,
        ])->assertOk()->assertJson(['success' => 'Queue updated successfully!']);

        $queue = Queue::firstOrFail();
        $this->assertNull($queue->end_time, 'Non-terminal status should leave end_time null');
    }
}
