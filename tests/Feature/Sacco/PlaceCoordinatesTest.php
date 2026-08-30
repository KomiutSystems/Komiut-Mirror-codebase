<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Models\Place;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A place with no coordinates cannot be drawn on a map — no route line, no
 * stage marker, no terminus pin. The columns were always accepted but never
 * sent, so every existing row has NULL lat/lng.
 */
final class PlaceCoordinatesTest extends QueueTestCase
{
    private function placeAdmin(): User
    {
        $sacco = $this->makeSacco();
        $admin = $this->makeUser([], $sacco);
        foreach (['Add Places', 'Edit Places'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $admin->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    /**
     * Creating without coordinates was rejected for a while, to stop the NULL
     * lat/lng gap growing. It cost more than it saved: naming a route is naming
     * two stages, and the dashboard had to make an admin paste a Google Maps
     * pin before "Odeon to Kikuyu" could be saved at all. A place with no
     * position is worth strictly more than a route nobody created.
     */
    #[Test]
    public function creating_a_place_without_coordinates_is_allowed(): void
    {
        Sanctum::actingAs($this->placeAdmin());

        $this->postJson('/api/v1/auth/routes/place/add', [
            'id' => 0, 'name' => 'Nowhere', 'status' => 1,
        ])->assertOk();

        $place = Place::where('name', 'Nowhere')->first();
        $this->assertNotNull($place);
        $this->assertNull($place->latitude, 'No pin was sent, so none is stored.');
        $this->assertNull($place->longitude);
    }

    #[Test]
    public function creating_a_place_with_coordinates_succeeds(): void
    {
        Sanctum::actingAs($this->placeAdmin());

        $this->postJson('/api/v1/auth/routes/place/add', [
            'id' => 0, 'name' => 'Kimbo Yard', 'status' => 1,
            'latitude' => -1.1489, 'longitude' => 37.0125,
        ])->assertOk();

        $place = Place::where('name', 'Kimbo Yard')->first();
        $this->assertNotNull($place);
        $this->assertEqualsWithDelta(-1.1489, (float) $place->latitude, 0.0001);
        $this->assertEqualsWithDelta(37.0125, (float) $place->longitude, 0.0001);
    }

    #[Test]
    public function an_impossible_coordinate_is_rejected(): void
    {
        Sanctum::actingAs($this->placeAdmin());

        $this->postJson('/api/v1/auth/routes/place/add', [
            'id' => 0, 'name' => 'Off World', 'status' => 1,
            'latitude' => 91, 'longitude' => 37.0,
        ])->assertStatus(400);
    }

    #[Test]
    public function editing_without_coordinates_does_not_wipe_them(): void
    {
        // The trap: the controller assigned lat/lng unconditionally, so a
        // rename or a status toggle blanked the position of a mapped place.
        Sanctum::actingAs($this->placeAdmin());

        $place = Place::create([
            'name' => 'Thika Stage', 'status' => 1, 'latitude' => -1.0333, 'longitude' => 37.0693,
        ]);

        $this->postJson('/api/v1/auth/routes/place/add', [
            'id' => $place->id, 'name' => 'Thika Stage Renamed', 'status' => 1,
        ])->assertOk();

        $place->refresh();
        $this->assertSame('Thika Stage Renamed', $place->name);
        $this->assertEqualsWithDelta(-1.0333, (float) $place->latitude, 0.0001, 'An edit must not blank the position.');
        $this->assertEqualsWithDelta(37.0693, (float) $place->longitude, 0.0001);
    }
}
