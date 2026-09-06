<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Models\Queue;
use App\Models\QueueStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Queues nobody ended must not strand the vehicle.
 *
 * A queue leaves Pending only when a driver taps start and Active only when a
 * driver taps end, so a dead phone or a force-closed app left it open forever.
 * That is not cosmetic: while any Pending or Active queue exists for a vehicle,
 * joining a queue answers 409 "This vehicle is already queued on another route"
 * and startTrip returns the stale one instead of starting a journey.
 *
 * KCE069C sat Active from 11 August to 6 September — twenty-six days unable to
 * queue on another route — and nothing was watching, because the one job that
 * sounds like it would (platform:check-queue-backlog) measures the Laravel jobs
 * table instead.
 */
final class CloseStaleQueuesTest extends QueueTestCase
{
    /** @return array{0: array, 1: QueueStatus, 2: QueueStatus, 3: QueueStatus} */
    private function world(): array
    {
        return [
            $this->makeWorld(),
            $this->makeQueueStatus('Pending', 'Pending'),
            $this->makeQueueStatus('Active', 'Active'),
            $this->makeQueueStatus('Cancelled', 'Cancelled'),
        ];
    }

    private function startedAt(Queue $queue, string $when): Queue
    {
        $queue->forceFill(['start_time' => $when])->save();

        return $queue->fresh();
    }

    private function statusOf(Queue $queue): string
    {
        return QueueStatus::find($queue->fresh()->queue_status_id)?->status ?? 'unknown';
    }

    #[Test]
    public function a_queue_left_active_overnight_is_closed(): void
    {
        [$w, , $active] = $this->world();

        $stale = $this->startedAt(
            $this->makeQueue($w['vehicle'], $w['terminus'], $w['route'], $active, $w['owner'], 'QN-STALE'),
            now()->subDays(26)->toDateTimeString(),
        );

        $this->artisan('queues:close-stale')->assertSuccessful();

        $this->assertSame('Cancelled', $this->statusOf($stale));
        $this->assertNotNull($stale->fresh()->end_time, 'a closed queue must carry an end time');
    }

    #[Test]
    public function a_queue_that_never_departed_is_closed_too(): void
    {
        // Pending strands the vehicle exactly as Active does — the 409 checks
        // for both.
        [$w, $pending] = $this->world();

        $stale = $this->startedAt(
            $this->makeQueue($w['vehicle'], $w['terminus'], $w['route'], $pending, $w['owner'], 'QN-PENDING'),
            now()->subDays(7)->toDateTimeString(),
        );

        $this->artisan('queues:close-stale')->assertSuccessful();

        $this->assertSame('Cancelled', $this->statusOf($stale));
    }

    #[Test]
    public function a_trip_running_right_now_is_left_alone(): void
    {
        // THE ONE THAT MATTERS MOST. Sweeping a live journey would end a trip
        // under a driver mid-route, which is far worse than the bug being fixed.
        [$w, , $active] = $this->world();

        $live = $this->startedAt(
            $this->makeQueue($w['vehicle'], $w['terminus'], $w['route'], $active, $w['owner'], 'QN-LIVE'),
            now()->subHours(2)->toDateTimeString(),
        );

        $this->artisan('queues:close-stale')->assertSuccessful();

        $this->assertSame('Active', $this->statusOf($live), 'a journey two hours old is a real trip, not a leak');
    }

    #[Test]
    public function the_window_is_the_boundary_and_is_configurable(): void
    {
        [$w, , $active] = $this->world();

        $queue = $this->startedAt(
            $this->makeQueue($w['vehicle'], $w['terminus'], $w['route'], $active, $w['owner'], 'QN-EDGE'),
            now()->subHours(6)->toDateTimeString(),
        );

        // Inside the default 12h window: untouched.
        $this->artisan('queues:close-stale')->assertSuccessful();
        $this->assertSame('Active', $this->statusOf($queue));

        // Outside a tighter one: closed.
        $this->artisan('queues:close-stale --hours=4')->assertSuccessful();
        $this->assertSame('Cancelled', $this->statusOf($queue));
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        [$w, , $active] = $this->world();

        $stale = $this->startedAt(
            $this->makeQueue($w['vehicle'], $w['terminus'], $w['route'], $active, $w['owner'], 'QN-DRY'),
            now()->subDays(3)->toDateTimeString(),
        );

        $this->artisan('queues:close-stale --dry-run')->assertSuccessful();

        $this->assertSame('Active', $this->statusOf($stale));
    }

    #[Test]
    public function an_already_closed_queue_is_not_touched_again(): void
    {
        // The sweep runs hourly; it must be a no-op once there is nothing stale,
        // and must never reopen or re-stamp a queue that is already terminal.
        [$w, , , $cancelled] = $this->world();

        $done = $this->startedAt(
            $this->makeQueue($w['vehicle'], $w['terminus'], $w['route'], $cancelled, $w['owner'], 'QN-DONE'),
            now()->subDays(30)->toDateTimeString(),
        );
        $before = $done->fresh()->end_time;

        $this->artisan('queues:close-stale')->assertSuccessful();

        $this->assertSame('Cancelled', $this->statusOf($done));
        $this->assertEquals($before, $done->fresh()->end_time);
    }

    #[Test]
    public function closing_is_recorded_so_a_driver_can_be_told_why(): void
    {
        [$w, , $active] = $this->world();

        $this->startedAt(
            $this->makeQueue($w['vehicle'], $w['terminus'], $w['route'], $active, $w['owner'], 'QN-AUDIT'),
            now()->subDays(26)->toDateTimeString(),
        );

        $this->artisan('queues:close-stale')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.stale.closed']);
    }
}
