<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The driver's "end trip" refuses to land moments after the trip started.
 *
 * KDN 024V, 2026-11-22: departed its stage at 16:22:44, "end trip" at 16:22:45
 * -- the app's one button had turned from Start trip into End trip under the
 * driver's finger. The end took the bus's live run with it, and passengers
 * searching the route were shown nothing for the next hour.
 */
final class DriverTripEndGuardTest extends QueueTestCase
{
    private const END = '/api/v1/auth/driver/trip/end';

    protected function setUp(): void
    {
        parent::setUp();
        // The real window: phpunit.xml switches it off for every other trip test.
        config(['booking.end_trip_grace_seconds' => 60]);
    }

    /** @return array{0: User, 1: Queue, 2: array<string, mixed>} */
    private function liveRunStarted(string $ago): array
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);
        $this->makeQueueStatus('Completed '.$this->nextSequence(), 'Completed');

        $run = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active'), $world['owner'], 'LIVE', Queue::KIND_LIVE);
        $run->forceFill(['departed_at' => now()->sub($ago), 'start_time' => now()->sub($ago)])->save();

        return [$driver, $run->fresh(), $world];
    }

    #[Test]
    public function an_end_seconds_after_the_start_is_refused_and_touches_nothing(): void
    {
        [$driver, $run] = $this->liveRunStarted('35 seconds');
        Sanctum::actingAs($driver);

        $this->postJson(self::END)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'just_started')
            ->assertJsonPath('retry_after', 25)
            ->assertHeader('Retry-After', '25');

        $this->assertSame('Active', $run->fresh()->queue_status->status);
        $this->assertNull($run->fresh()->end_time);
    }

    #[Test]
    public function the_stage_queue_departed_a_second_ago_counts_as_the_start(): void
    {
        // The run had been live for minutes; the stage departure is what the
        // second tap followed -- and ending the run ends that stage queue too.
        [$driver, $run, $world] = $this->liveRunStarted('10 minutes');
        $stage = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active'), $world['owner'], 'QN-1', Queue::KIND_STAGE);
        $stage->forceFill(['departed_at' => now()->subSecond()])->save();
        Sanctum::actingAs($driver);

        $this->postJson(self::END)->assertStatus(409)->assertJsonPath('reason', 'just_started');

        $this->assertNull($run->fresh()->end_time);
        $this->assertNull($stage->fresh()->end_time);
    }

    #[Test]
    public function joining_the_next_line_does_not_make_an_old_run_look_new(): void
    {
        // At the terminus the driver joins the line for the next trip, then
        // ends the run. The new place in line is Pending -- TripEnder leaves
        // it alone -- so it is not the trip that "just started".
        [$driver, $run, $world] = $this->liveRunStarted('40 minutes');
        $line = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'), $world['owner'], 'QN-2', Queue::KIND_STAGE);
        $line->forceFill(['start_time' => now(), 'departed_at' => null])->save();
        Sanctum::actingAs($driver);

        $this->postJson(self::END)->assertOk();

        $this->assertSame('Completed', $run->fresh()->queue_status->status);
        $this->assertSame('Pending', $line->fresh()->queue_status->status);
    }

    #[Test]
    public function after_the_window_the_trip_ends_as_before(): void
    {
        [$driver, $run] = $this->liveRunStarted('61 seconds');
        Sanctum::actingAs($driver);

        $this->postJson(self::END)->assertOk();

        $this->assertSame('Completed', $run->fresh()->queue_status->status);
    }

    #[Test]
    public function a_client_that_asks_for_it_can_end_at_once(): void
    {
        [$driver, $run] = $this->liveRunStarted('5 seconds');
        Sanctum::actingAs($driver);

        $this->postJson(self::END, ['confirm' => true])->assertOk();

        $this->assertNotNull($run->fresh()->end_time);
    }

    #[Test]
    public function a_queue_that_never_departed_still_gets_its_own_answer(): void
    {
        [$driver, $run] = $this->liveRunStarted('5 seconds');
        $run->forceFill(['queue_status_id' => $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending')->id])->save();
        Sanctum::actingAs($driver);

        $this->postJson(self::END)->assertStatus(409)->assertJsonPath('reason', 'not_departed');
    }
}
