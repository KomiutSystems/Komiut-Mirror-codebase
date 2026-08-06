<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BrandBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TestCase registers only 'testing'. Plate mode is about moving vehicles
        // BETWEEN brands, so the catalogue needs a second one to move them to.
        config(['brands.other' => [
            'name' => 'Other',
            'connection' => config('database.default'),
            'hosts' => ['other.localhost'],
            'app_key' => 'other-app-key',
            'features' => [],
        ]]);
    }

    #[Test]
    public function it_assigns_the_brand_to_unbranded_saccos_and_their_vehicles(): void
    {
        // Unbranded legacy row (no brand context active, so no auto-stamp).
        $sacco = Sacco::create(['name' => 'Legacy', 'status' => 1]);
        $owner = User::factory()->create(['sacco_id' => $sacco->id]);
        $seat = Seat::create(['name' => 'S', 'seats' => 14, 'rows' => 4, 'columns' => 4, 'status' => true]);
        $vehicle = Vehicle::withoutGlobalScopes()->create([
            'plate' => 'KDA123X', 'fleet_no' => '1', 'sacco_id' => $sacco->id,
            'user_id' => $owner->id, 'seat_id' => $seat->id, 'status' => true,
        ]);

        $this->assertNull($sacco->fresh()->brand);

        $exit = Artisan::call('brand:backfill', ['brand' => 'testing']);

        $this->assertSame(0, $exit);
        $this->assertSame('testing', $sacco->fresh()->brand);
        $this->assertSame('testing', Vehicle::withoutGlobalScopes()->find($vehicle->id)->brand);
    }

    #[Test]
    public function it_rejects_an_unknown_brand(): void
    {
        $this->assertSame(1, Artisan::call('brand:backfill', ['brand' => 'nope']));
    }

    #[Test]
    public function plate_mode_rebrands_individual_vehicles_and_leaves_the_sacco_alone(): void
    {
        // The NICCO case: a bulk pass brands the whole SACCO, then a plate pass
        // corrects the minority that are financed by the other bank.
        [$sacco, $keep, $move] = $this->saccoWithTwoVehicles();

        Artisan::call('brand:backfill', ['brand' => 'testing']);
        $this->assertSame('testing', Vehicle::withoutGlobalScopes()->find($move->id)->brand);

        // Plates arrive spaced/suffixed from operators; matching must still land.
        $exit = Artisan::call('brand:backfill', [
            'brand' => 'other',
            '--plate' => ['kdr 027c'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('other', Vehicle::withoutGlobalScopes()->find($move->id)->brand);
        $this->assertSame('testing', Vehicle::withoutGlobalScopes()->find($keep->id)->brand,
            'Plate mode must not touch the rest of the fleet.');
        $this->assertSame('testing', $sacco->fresh()->brand,
            'Plate mode must leave the SACCO on its primary brand.');
    }

    #[Test]
    public function plate_mode_fails_without_writing_when_a_plate_matches_nothing(): void
    {
        // An unmatched plate is usually a typo. Writing the rest would leave a
        // bus silently in the wrong brand, so nothing is written at all.
        [, , $move] = $this->saccoWithTwoVehicles();
        Artisan::call('brand:backfill', ['brand' => 'testing']);

        $exit = Artisan::call('brand:backfill', [
            'brand' => 'other',
            '--plate' => ['KDR027C', 'KDZ999Z'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame('testing', Vehicle::withoutGlobalScopes()->find($move->id)->brand,
            'A typo in the list must abort the whole pass.');
    }

    #[Test]
    public function plate_mode_reads_a_plates_file(): void
    {
        [, , $move] = $this->saccoWithTwoVehicles();
        Artisan::call('brand:backfill', ['brand' => 'testing']);

        $path = tempnam(sys_get_temp_dir(), 'plates').'.txt';
        file_put_contents($path, "KDR 027C\n\nKDR-027C\n");

        $exit = Artisan::call('brand:backfill', ['brand' => 'other', '--plates-file' => $path]);
        unlink($path);

        $this->assertSame(0, $exit);
        $this->assertSame('other', Vehicle::withoutGlobalScopes()->find($move->id)->brand);
    }

    /** @return array{0: Sacco, 1: Vehicle, 2: Vehicle} sacco, vehicle to keep, vehicle to move */
    private function saccoWithTwoVehicles(): array
    {
        $sacco = Sacco::create(['name' => 'NICCO MOVERS LIMITED', 'status' => 1]);
        $owner = User::factory()->create(['sacco_id' => $sacco->id]);
        $seat = Seat::create(['name' => 'S', 'seats' => 14, 'rows' => 4, 'columns' => 4, 'status' => true]);

        $make = fn (string $plate): Vehicle => Vehicle::withoutGlobalScopes()->create([
            'plate' => $plate, 'fleet_no' => '1', 'sacco_id' => $sacco->id,
            'user_id' => $owner->id, 'seat_id' => $seat->id, 'status' => true,
        ]);

        return [$sacco, $make('KDX 447K'), $make('KDR 027C')];
    }

    #[Test]
    public function pretend_reports_without_writing(): void
    {
        $sacco = Sacco::create(['name' => 'Legacy', 'status' => 1]);

        Artisan::call('brand:backfill', ['brand' => 'testing', '--pretend' => true]);

        $this->assertNull($sacco->fresh()->brand, 'Pretend must not persist changes.');
    }
}
