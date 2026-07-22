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
    public function pretend_reports_without_writing(): void
    {
        $sacco = Sacco::create(['name' => 'Legacy', 'status' => 1]);

        Artisan::call('brand:backfill', ['brand' => 'testing', '--pretend' => true]);

        $this->assertNull($sacco->fresh()->brand, 'Pretend must not persist changes.');
    }
}
