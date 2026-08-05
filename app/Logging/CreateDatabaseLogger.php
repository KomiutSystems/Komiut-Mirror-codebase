<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;
use Monolog\Logger;

/**
 * Factory for the 'database' log channel (config/logging.php `driver => custom`).
 * Laravel calls __invoke() with the channel config and expects a configured
 * Monolog Logger back.
 */
final class CreateDatabaseLogger
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $level = Level::fromName((string) ($config['level'] ?? 'debug'));

        return new Logger('database', [
            new DatabaseLogHandler($level),
        ]);
    }
}
