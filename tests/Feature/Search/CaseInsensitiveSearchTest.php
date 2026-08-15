<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Vehicle;
use App\Services\Sql\LikeSql;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Search boxes must not care about case.
 *
 * These queries were written against MySQL, whose LIKE is case-insensitive by
 * collation. Postgres's is not, so moving the platform across silently changed
 * every search in the dashboard: a SACCO typing a plate in lower case got an
 * empty result and no error, and concluded the vehicle had no transactions.
 *
 * The legacy MySQL stack still answers the same search correctly, which makes it
 * read as missing data rather than a broken query — the worst way for a bug to
 * present.
 */
final class CaseInsensitiveSearchTest extends QueueTestCase
{
    #[Test]
    public function the_operator_is_case_insensitive_on_this_connection(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->assertSame('ILIKE', LikeSql::op(), 'Postgres LIKE is case-sensitive; searches must use ILIKE');
        } else {
            $this->assertSame('LIKE', LikeSql::op());
        }
    }

    /**
     * The behaviour, not the operator string: whatever dialect we are on, a
     * lower-case needle must find an upper-case stored value.
     */
    #[Test]
    public function a_lowercase_search_finds_an_uppercase_plate(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['plate' => 'KCH 875N']);

        foreach (['kch', 'KCH', 'kCh 875n', '875N'] as $needle) {
            $found = Vehicle::where('plate', LikeSql::op(), '%'.$needle.'%')->first();

            $this->assertNotNull($found, "searching \"{$needle}\" should find the vehicle stored as \"KCH 875N\"");
            $this->assertSame($vehicle->id, $found->id);
        }
    }

    /**
     * Guards the regression directly: the case-sensitive operator misses, which
     * is why every one of these call sites had to change.
     */
    #[Test]
    public function the_case_sensitive_operator_would_have_missed_it(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Only Postgres has a case-sensitive LIKE.');
        }

        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['plate' => 'KCH 875N']);

        $this->assertNull(
            Vehicle::where('plate', 'LIKE', '%kch%')->first(),
            'if this finds the vehicle, Postgres LIKE has become case-insensitive and this guard is obsolete'
        );
        $this->assertNotNull(Vehicle::where('plate', LikeSql::op(), '%kch%')->first());
    }

    /**
     * No call site may reintroduce the bare operator. Cheaper to assert here
     * than to rediscover it from a SACCO phone call.
     */
    #[Test]
    public function no_controller_uses_the_bare_case_sensitive_operator(): void
    {
        $offenders = [];
        $base = dirname(__DIR__, 3).'/app';

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, 'app/Services/Sql/LikeSql.php')) {
                continue;
            }
            if (str_contains(file_get_contents($path), "'LIKE'")) {
                $offenders[] = substr($path, strpos($path, 'app/'));
            }
        }

        $this->assertSame([], $offenders, "use LikeSql::op() so searches stay case-insensitive on Postgres:\n".implode("\n", $offenders));
    }
}
