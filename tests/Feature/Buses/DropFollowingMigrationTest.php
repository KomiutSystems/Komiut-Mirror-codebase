<?php

declare(strict_types=1);

namespace Tests\Feature\Buses;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The follower rows and follow codes are gone, and the migration that took
 * them can be rolled back and run again. Everything happens inside the test's
 * transaction (PostgreSQL DDL is transactional), so the schema is back as it
 * was afterwards.
 */
final class DropFollowingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_11_27_100000_drop_school_bus_following.php';

    #[Test]
    public function following_is_gone_and_the_migration_rolls_back_and_forward_cleanly(): void
    {
        $this->assertFalse(Schema::hasTable('vehicle_followers'));
        $this->assertFalse(Schema::hasColumn('vehicles', 'follow_code'));
        // What stays: schools, and the speed the app estimates arrival from.
        $this->assertTrue(Schema::hasColumn('saccos', 'kind'));
        $this->assertTrue(Schema::hasColumn('vehicle_locations', 'speed_kmh'));

        /** @var Migration $migration */
        $migration = require self::MIGRATION;

        $migration->down();
        $this->assertTrue(Schema::hasTable('vehicle_followers'));
        $this->assertTrue(Schema::hasColumns('vehicle_followers', [
            'id', 'user_id', 'vehicle_id', 'stop_place_id', 'stop_latitude', 'stop_longitude',
            'last_alerted_queue_id', 'last_alerted_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('vehicles', 'follow_code'));
        $this->assertContains('vehicles_follow_code_unique', array_column(Schema::getIndexes('vehicles'), 'name'));

        $migration->up();
        $this->assertFalse(Schema::hasTable('vehicle_followers'));
        $this->assertFalse(Schema::hasColumn('vehicles', 'follow_code'));
        $this->assertNotContains('vehicles_follow_code_unique', array_column(Schema::getIndexes('vehicles'), 'name'));

        // Running it again is harmless.
        $migration->up();
        $this->assertFalse(Schema::hasTable('vehicle_followers'));
    }
}
