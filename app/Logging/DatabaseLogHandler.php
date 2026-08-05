<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/**
 * A Monolog handler that persists log records into application_logs so the
 * framework/application log is readable from the super-admin console.
 *
 * Wired as the 'database' log channel (config/logging.php). It is an ADDITIONAL
 * channel, never the default, so a database problem can never take down normal
 * file logging. The write itself is wrapped in a Throwable swallow: a handler
 * that threw during logging would recurse or 500 a served request.
 */
final class DatabaseLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        try {
            DB::table('application_logs')->insert([
                'level' => strtolower($record->level->getName()),
                'channel' => $record->channel,
                'message' => $record->message,
                'context' => $record->context !== [] ? json_encode($record->context) : null,
                'created_at' => $record->datetime->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Logging must never throw. Drop the record rather than recurse.
        }
    }
}
