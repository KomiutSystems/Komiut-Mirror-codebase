<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Listeners\LogScheduledTaskOutcome;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * A scheduled command that fails has to reach the log an operator reads.
 *
 * MEASURED, 2026-09-08: `payments:reconcile-legacy` found a cross-system money
 * deficit on essentially every 15-minute tick — 634 audit rows over nine days,
 * 28 exit-1 failures in laravel.log on that day alone — while `docker logs
 * komiut-scheduler-1` rendered all 16 of its runs as DONE and contained no FAIL
 * line at all. The scheduler looked healthy precisely on the runs that found
 * money missing.
 *
 * The load-bearing case is the FIRST test. A command that reports failure the
 * ordinary Artisan way, `return self::FAILURE`, does NOT throw — so
 * ScheduledTaskFailed never fires for it, and a listener bound only to that event
 * would have logged none of those 433 exit-1 runs. The exit code arrives on
 * ScheduledTaskFinished instead, via Event::$exitCode.
 *
 * These go through `event()` rather than calling the listener directly, so they
 * also pin the REGISTRATION. EventServiceProvider::shouldDiscoverEvents() is
 * false in this app, and its own comment says a listener missing from `$listen`
 * "is simply never called" — a working listener nobody wired up would reproduce
 * the exact silence this is meant to end.
 */
final class ScheduledTaskFailureIsLoggedTest extends TestCase
{
    private function task(string $command, ?int $exitCode): \Illuminate\Console\Scheduling\Event
    {
        $task = app(Schedule::class)->command($command);
        $task->exitCode = $exitCode;

        return $task;
    }

    #[Test]
    public function a_task_that_exits_non_zero_is_logged_even_though_it_did_not_throw(): void
    {
        Log::spy();

        event(new ScheduledTaskFinished($this->task('payments:reconcile-legacy', 1), 2.5));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'reported failure')
                    && str_contains((string) $context['command'], 'payments:reconcile-legacy')
                    && $context['exit_code'] === 1;
            });
    }

    #[Test]
    public function a_task_that_succeeds_is_not_logged_as_an_error(): void
    {
        // Or the log fills with 1,440 lines a day and the real failures drown.
        Log::spy();

        event(new ScheduledTaskFinished($this->task('bookings:release-expired', 0), 0.1));

        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function a_task_with_no_exit_code_yet_is_not_called_a_failure(): void
    {
        // null means the task never reached finish() — a background task whose
        // result lands later. Reporting that as a failure would be a false alarm
        // every time one is scheduled.
        Log::spy();

        event(new ScheduledTaskFinished($this->task('app:generate-vehicle-summaries', null), 0.1));

        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function a_task_that_throws_is_logged_with_the_exception_class(): void
    {
        // The class, not just the message: an earlier catch in this codebase logged
        // the message alone and hid its own cause through four red runs.
        Log::spy();

        event(new ScheduledTaskFailed(
            $this->task('payments:reconcile', null),
            new RuntimeException('Daraja timed out'),
        ));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'threw')
                    && $context['exception'] === RuntimeException::class
                    && $context['message'] === 'Daraja timed out';
            });
    }

    #[Test]
    public function the_listener_is_actually_registered_for_both_events(): void
    {
        // Pins the wiring itself. Both events are needed and for different
        // reasons, so losing either registration silently restores half the blind
        // spot — and nothing else in the suite would notice.
        foreach ([ScheduledTaskFinished::class, ScheduledTaskFailed::class] as $event) {
            $handlers = array_map(
                fn ($listener) => is_string($listener) ? $listener : get_class($listener),
                \Illuminate\Support\Facades\Event::getRawListeners()[$event] ?? [],
            );

            $this->assertNotEmpty($handlers, $event.' has no listener at all');
            $this->assertTrue(
                collect($handlers)->contains(fn ($h) => str_contains((string) $h, class_basename(LogScheduledTaskOutcome::class))),
                $event.' is not wired to LogScheduledTaskOutcome',
            );
        }
    }
}
