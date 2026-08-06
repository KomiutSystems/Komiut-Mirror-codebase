<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\VehicleLocation;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The live-map read. The point of interest is not that it returns positions —
 * it is that a SACCO admin cannot reach another SACCO's fleet, including by
 * naming their vehicle ids directly.
 */
final class VehicleLocationsReadTest extends QueueTestCase
{
    /** @return array{0: \App\Models\User, 1: \App\Models\Vehicle, 2: \App\Models\Vehicle} */
    private function twoSaccosWithPositions(): array
    {
        $seat = $this->makeSeat();

        $mine = $this->makeSacco();
        $myAdmin = $this->makeUser([], $mine);
        $myVehicle = $this->makeVehicle($mine, $myAdmin, $seat);

        $theirs = $this->makeSacco();
        $theirOwner = $this->makeUser([], $theirs);
        $theirVehicle = $this->makeVehicle($theirs, $theirOwner, $seat);

        foreach ([$myVehicle, $theirVehicle] as $vehicle) {
            VehicleLocation::withoutGlobalScopes()->create([
                'vehicle_id' => $vehicle->id,
                'latitude' => -1.2921,
                'longitude' => 36.8219,
                'heading' => 90,
                'broadcasting' => true,
                'recorded_at' => now(),
            ]);
        }

        return [$myAdmin, $myVehicle, $theirVehicle];
    }

    #[Test]
    public function it_returns_only_the_callers_sacco_fleet(): void
    {
        [$admin, $mine, $theirs] = $this->twoSaccosWithPositions();
        Sanctum::actingAs($admin);

        $ids = collect($this->getJson('/api/v1/auth/vehicle_locations')->assertOk()->json('data'))
            ->pluck('vehicle_id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id), 'Another SACCO\'s vehicle must never appear.');
    }

    #[Test]
    public function naming_another_saccos_vehicle_id_does_not_widen_the_scope(): void
    {
        // The filter narrows an already-scoped set. If it were applied before
        // scoping, this request would leak a competitor's live positions.
        [$admin, , $theirs] = $this->twoSaccosWithPositions();
        Sanctum::actingAs($admin);

        $data = $this->getJson('/api/v1/auth/vehicle_locations?vehicle_ids='.$theirs->id)
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data, 'Asking for another SACCO\'s vehicle must return nothing, not their position.');
    }

    #[Test]
    public function it_reports_position_fields_and_a_poll_cursor(): void
    {
        [$admin, $mine] = $this->twoSaccosWithPositions();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/auth/vehicle_locations')->assertOk();
        $row = collect($response->json('data'))->firstWhere('vehicle_id', $mine->id);

        $this->assertSame($mine->plate, $row['plate']);
        $this->assertEqualsWithDelta(-1.2921, $row['latitude'], 0.0001);
        $this->assertEqualsWithDelta(36.8219, $row['longitude'], 0.0001);
        $this->assertSame(90, $row['heading']);
        $this->assertTrue($row['broadcasting']);
        $this->assertNotNull($row['recorded_at']);
        $this->assertNotNull($response->json('polled_at'), 'The map needs a cursor for its next poll.');
    }

    #[Test]
    public function stale_positions_are_excluded_by_default(): void
    {
        [$admin, $mine] = $this->twoSaccosWithPositions();
        VehicleLocation::withoutGlobalScopes()
            ->where('vehicle_id', $mine->id)
            ->update(['recorded_at' => now()->subHours(3)]);

        Sanctum::actingAs($admin);

        $fresh = $this->getJson('/api/v1/auth/vehicle_locations')->assertOk()->json('data');
        $this->assertSame([], $fresh, 'A 3-hour-old position is misleading on a live map.');

        $widened = $this->getJson('/api/v1/auth/vehicle_locations?max_age_minutes=240')->assertOk()->json('data');
        $this->assertCount(1, $widened, 'A widened window should surface it again.');
    }

    #[Test]
    public function since_returns_only_what_moved_after_the_cursor(): void
    {
        [$admin, $mine] = $this->twoSaccosWithPositions();
        Sanctum::actingAs($admin);

        $future = now()->addMinute()->toIso8601String();
        $this->assertSame([], $this->getJson('/api/v1/auth/vehicle_locations?since='.urlencode($future))
            ->assertOk()->json('data'));

        $past = now()->subMinutes(5)->toIso8601String();
        $this->assertCount(1, $this->getJson('/api/v1/auth/vehicle_locations?since='.urlencode($past))
            ->assertOk()->json('data'));
    }

    #[Test]
    public function an_unparseable_since_is_rejected(): void
    {
        [$admin] = $this->twoSaccosWithPositions();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/auth/vehicle_locations?since=not-a-date')->assertStatus(422);
    }
}
