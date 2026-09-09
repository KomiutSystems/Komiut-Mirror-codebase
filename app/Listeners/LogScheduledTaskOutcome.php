<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Log;

/**
 * A scheduled task that fails must say so where an operator is looking.
 *
 * THE GAP THIS CLOSES, MEASURED. On 2026-09-08 `payments:reconcile-legacy` found
 * a cross-system money deficit on essentially every 15-minute tick -- 634
 * audit_logs rows over nine days, and 28 exit-1 failures in laravel.log for that
 * command on that day alone. Over the same window `docker logs
 * komiut-scheduler-1` rendered all 16 of its runs as DONE and contained no FAIL
 * line at all. The operator-visible log showed a healthy scheduler while the runs
 * that found money missing were precisely the ones it did not mention.
 *
 * WHY `ScheduledTaskFailed` ALONE IS NOT ENOUGH, and this is the whole point of
 * the class: that event fires only when a task THROWS. A command that returns a
 * non-zero exit code -- which is what an Artisan command does when it reports
 * failure the ordinary way, `return self::FAILURE` -- does not throw, so
 * `ScheduledTaskFailed` never fires for it. It completes, and
 * `ScheduledTaskFinished` fires carrying the exit code on
 * `$event->task->exitCode` (Illuminate\Console\Scheduling\Event::$exitCode, set
 * in finish()). Listening only to the failure event would have logged none of the
 * 433 exit-1 runs, which is exactly the set we were blind to.
 *
 * So both are handled, and they are genuinely different facts:
 *   - finished() with a non-zero code = the task ran and REPORTED failure.
 *   - failed() = the task could not complete at all.
 *
 * Logged at error, with the command string, because a warning is what the
 * previous realtime attempt used and it hid its own cause through four red runs.
 * Nothing here throws: a listener that breaks the scheduler while trying to
 * report on it would be worse than the silence it replaces.
 */
final class LogScheduledTaskOutcome
{
    public function finished(ScheduledTaskFinished $event): void
    {
        $code = $event->task->exitCode;

        // null means the task never reached finish() -- a background task whose
        // result lands later, or one this process did not run. Not a failure.
        if ($code === null || (int) $code === 0) {
            return;
        }

        Log::error('scheduled task reported failure', [
            'command' => $this->name($event->task),
            'exit_code' => (int) $code,
            'runtime_seconds' => round($event->runtime, 3),
        ]);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        Log::error('scheduled task threw', [
            'command' => $this->name($event->task),
            // The exception CLASS, not just the message: an earlier catch in this
            // codebase logged the message alone and the cause stayed invisible.
            'exception' => $event->exception::class,
            'message' => $event->exception->getMessage(),
        ]);
    }

    /** The most identifiable name available — `command` is null for closures. */
    private function name(object $task): string
    {
        /** @var \Illuminate\Console\Scheduling\Event $task */
        return (string) ($task->command ?: $task->description ?: $task->getSummaryForDisplay());
    }
}
