<?php

declare(strict_types=1);

namespace Tests\Feature\Buses;

use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dropping the follower rows and follow codes is the CONTRACT half of
 * expand/contract: the previous release still reads them while the deploy
 * rolls, so this release removes the code and leaves the schema alone. The
 * drop is parked in database/contract/ for the next release; it is pinned
 * here so it is ready to move.
 *
 * Everything happens inside the test's transaction (PostgreSQL DDL is
 * transactional), so the schema is back as it was afterwards.
 */
final class DropFollowingContractTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = __DIR__.'/../../../database/contract/drop_school_bus_following.php';

    #[Test]
    public function this_release_leaves_the_schema_the_previous_one_reads(): void
    {
        $this->assertTrue(Schema::hasTable('vehicle_followers'));
        $this->assertTrue(Schema::hasColumn('vehicles', 'follow_code'));
        $this->assertSame([], glob(database_path('migrations/*drop_school_bus_following*')), 'the drop ships in a later release');

        // The codes already issued are still in the column: never serialized.
        $this->assertContains('follow_code', (new Vehicle)->getHidden());
    }

    #[Test]
    public function the_parked_drop_runs_rolls_back_and_runs_again_cleanly(): void
    {
        /** @var Migration $migration */
        $migration = require self::CONTRACT;

        $migration->up();
        $this->assertFalse(Schema::hasTable('vehicle_followers'));
        $this->assertFalse(Schema::hasColumn('vehicles', 'follow_code'));
        $this->assertNotContains('vehicles_follow_code_unique', array_column(Schema::getIndexes('vehicles'), 'name'));
        // What stays: schools, and the speed the app estimates arrival from.
        $this->assertTrue(Schema::hasColumn('saccos', 'kind'));
        $this->assertTrue(Schema::hasColumn('vehicle_locations', 'speed_kmh'));
        // A blocked drop fails fast instead of stalling every read of users.
        $this->assertSame('5s', DB::selectOne('SHOW lock_timeout')->lock_timeout);

        $migration->down();
        $this->assertTrue(Schema::hasColumns('vehicle_followers', [
            'id', 'user_id', 'vehicle_id', 'stop_place_id', 'stop_latitude', 'stop_longitude',
            'last_alerted_queue_id', 'last_alerted_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('vehicles', 'follow_code'));
        $this->assertContains('vehicles_follow_code_unique', array_column(Schema::getIndexes('vehicles'), 'name'));

        $migration->up();
        // Running it again is harmless.
        $migration->up();
        $this->assertFalse(Schema::hasTable('vehicle_followers'));
        $this->assertFalse(Schema::hasColumn('vehicles', 'follow_code'));
    }
}
