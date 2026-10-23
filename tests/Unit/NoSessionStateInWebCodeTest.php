<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * With DB_PERSISTENT on, a php-fpm worker keeps its PostgreSQL connection
 * between requests (config/database.php), so anything a request changes about
 * the SESSION -- rather than its transaction -- carries into the next request
 * on that worker, which may be another SACCO's. This keeps the rule from
 * resting on a comment: use SET LOCAL, set_config(.., true) and
 * pg_advisory_xact_lock, never their session-wide forms.
 */
final class NoSessionStateInWebCodeTest extends TestCase
{
    private const PATTERNS = [
        'a session-wide SET' => '/["\']\s*SET\s+(?!LOCAL\b)(SESSION\s+)?(search_path|statement_timeout|lock_timeout|idle_in_transaction_session_timeout|(maintenance_)?work_mem|role|session\s+authorization|session\s+characteristics|time\s*zone|timezone|application_name|default_transaction_\w+|synchronous_commit|enable_\w+|jit\w*|random_page_cost|client_encoding|datestyle|transaction_isolation)\b/i',
        'set_config(.., false)' => '/set_config\s*\([^)]*,\s*false\s*\)/i',
        'a session advisory lock' => '/pg_(try_)?advisory_lock(_shared)?\s*\(/i',
        'a temporary table' => '/CREATE\s+(TEMP|TEMPORARY)\s+TABLE/i',
        'LISTEN' => '/["\']\s*LISTEN\s+\w+/i',
    ];

    #[Test]
    public function web_code_changes_no_database_session_state(): void
    {
        $root = dirname(__DIR__, 2);
        $offences = [];
        foreach (['app', 'routes', 'config', 'bootstrap/app.php'] as $path) {
            foreach ($this->phpFiles($root.'/'.$path) as $file) {
                foreach (file($file) as $n => $line) {
                    if (preg_match('#^\s*(//|\*|/\*|\#)#', $line) === 1) {
                        continue;   // comments may describe the rule
                    }
                    foreach (self::PATTERNS as $what => $re) {
                        if (preg_match($re, $line) === 1) {
                            $offences[] = sprintf('%s:%d %s: %s', substr($file, strlen($root) + 1), $n + 1, $what, trim($line));
                        }
                    }
                }
            }
        }

        $this->assertSame([], $offences, "Session state would leak between requests on a kept connection:\n".implode("\n", $offences));
    }

    /** @return list<string> */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }
}
