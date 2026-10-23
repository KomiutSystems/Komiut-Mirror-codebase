<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * platform:check-queue-backlog reads the queue the worker consumes. It used to
 * count the database `jobs` table whatever the driver, and production queues
 * on Redis: the table is always empty there, so it could never raise the alert.
 */
final class QueueBacklogCheckTest extends QueueTestCase
{
    private function pending(int $ageSeconds): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Example', 'createdAt' => time() - $ageSeconds]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - $ageSeconds,
            'created_at' => time() - $ageSeconds,
        ]);
    }

    #[Test]
    public function a_job_waiting_too_long_on_the_configured_queue_raises_the_alert(): void
    {
        config(['queue.default' => 'database']);
        $this->pending(600);   // over the 300s default

        $this->artisan('platform:check-queue-backlog')->expectsOutputToContain('Queue backlog flagged')->assertSuccessful();

        $this->assertDatabaseHas('platform_notifications', ['dedupe_key' => 'platform.queue.backlog']);
    }

    #[Test]
    public function the_check_asks_the_configured_connection_not_the_jobs_table(): void
    {
        // A stale row in `jobs` on a box whose queue is elsewhere is not a backlog.
        $this->pending(600);
        config(['queue.default' => 'sync']);

        $this->artisan('platform:check-queue-backlog')->expectsOutputToContain('Queue healthy')->assertSuccessful();

        $this->assertDatabaseMissing('platform_notifications', ['dedupe_key' => 'platform.queue.backlog']);
    }
}
