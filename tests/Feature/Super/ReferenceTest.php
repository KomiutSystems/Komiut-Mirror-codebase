<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Generic reference-data CRUD: unknown-set rejection + round-trips for
 * genders and seat_layouts.
 */
final class ReferenceTest extends QueueTestCase
{
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    #[Test]
    public function an_unknown_set_is_rejected_with_422(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/reference/not-a-real-set')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown reference set');

        $this->postJson('/api/v1/super/reference/not-a-real-set', ['name' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown reference set');
    }

    #[Test]
    public function genders_round_trip_through_create_and_update(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $create = $this->postJson('/api/v1/super/reference/genders', [
            'name' => 'Nonbinary',
            'status' => true,
        ])->assertStatus(201);

        $id = $create->json('id');
        $this->assertSame('Nonbinary', $create->json('name'));
        $this->assertTrue($create->json('status'));
        $this->assertSame(0, $create->json('in_use_count'));

        $this->assertDatabaseHas('genders', ['id' => $id, 'name' => 'Nonbinary', 'status' => true]);

        $update = $this->patchJson('/api/v1/super/reference/genders/'.$id, [
            'status' => false,
        ])->assertOk();

        $this->assertFalse($update->json('status'));
        $this->assertDatabaseHas('genders', ['id' => $id, 'status' => false]);

        $list = $this->getJson('/api/v1/super/reference/genders')->assertOk();
        $this->assertTrue(collect($list->json('data'))->pluck('id')->contains($id));
    }

    #[Test]
    public function seat_layouts_round_trip_with_meta_fields(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $create = $this->postJson('/api/v1/super/reference/seat_layouts', [
            'name' => 'Layout 33-seater',
            'status' => true,
            'meta' => ['seats' => 33, 'rows' => 11, 'columns' => 3],
        ])->assertStatus(201);

        $id = $create->json('id');
        $this->assertSame(['seats' => 33, 'rows' => 11, 'columns' => 3], $create->json('meta'));

        $this->assertDatabaseHas('seats', ['id' => $id, 'name' => 'Layout 33-seater', 'seats' => 33, 'rows' => 11, 'columns' => 3]);

        $update = $this->patchJson('/api/v1/super/reference/seat_layouts/'.$id, [
            'meta' => ['seats' => 14],
        ])->assertOk();

        $this->assertSame(14, $update->json('meta')['seats']);
        // Untouched meta fields survive a partial update.
        $this->assertSame(11, $update->json('meta')['rows']);
    }

    #[Test]
    public function seat_layout_in_use_count_reflects_assigned_vehicles(): void
    {
        $seat = $this->makeSeat();
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $this->makeVehicle($sacco, $owner, $seat);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/reference/seat_layouts')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $seat->id);

        $this->assertSame(1, $row['in_use_count']);
    }
}
