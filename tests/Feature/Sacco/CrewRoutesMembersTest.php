<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Models\Place;
use App\Models\Route;
use App\Models\SaccoRoute;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The three SACCO-admin gaps the frontend map reported: crew assignment had no
 * mutation at all, created routes never appeared in the authoritative list, and
 * the members screen had to read the whole-platform user list.
 */
final class CrewRoutesMembersTest extends QueueTestCase
{
    private function adminWith(string ...$permissions): User
    {
        $sacco = $this->makeSacco();
        $admin = $this->makeUser(['View Routes', 'View Sacco Members', 'Add Routes', 'Edit Routes'], $sacco);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $admin->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    #[Test]
    public function a_crew_member_can_be_assigned_to_a_vehicle(): void
    {
        $admin = $this->adminWith('Add Vehicle Users');
        $sacco = $admin->sacco;
        $vehicle = $this->makeVehicle($sacco, $admin, $this->makeSeat());
        $driver = $this->makeUser(['View Routes', 'View Sacco Members', 'Add Routes', 'Edit Routes'], $sacco);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/vehicles/users/add', [
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
        ])->assertStatus(201)->assertJsonPath('vehicle_user.user_id', $driver->id);

        $this->assertDatabaseHas('vehicle_users', [
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
        ]);
    }

    #[Test]
    public function reassigning_closes_the_previous_open_assignment(): void
    {
        // Two open assignments on one bus would make "who was driving" during a
        // collection unanswerable, so the old one must close.
        $admin = $this->adminWith('Add Vehicle Users');
        $sacco = $admin->sacco;
        $vehicle = $this->makeVehicle($sacco, $admin, $this->makeSeat());
        $first = $this->makeUser(['View Routes', 'View Sacco Members', 'Add Routes', 'Edit Routes'], $sacco);
        $second = $this->makeUser(['View Routes', 'View Sacco Members', 'Add Routes', 'Edit Routes'], $sacco);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/vehicles/users/add', ['user_id' => $first->id, 'vehicle_id' => $vehicle->id])
            ->assertStatus(201);
        $this->postJson('/api/v1/auth/vehicles/users/add', ['user_id' => $second->id, 'vehicle_id' => $vehicle->id])
            ->assertStatus(201);

        $open = VehicleUser::where('vehicle_id', $vehicle->id)->where('status', true)->get();
        $this->assertCount(1, $open, 'A vehicle carries exactly one open assignment.');
        $this->assertSame($second->id, $open->first()->user_id);

        $closed = VehicleUser::where('vehicle_id', $vehicle->id)->where('user_id', $first->id)->first();
        $this->assertFalse((bool) $closed->status);
        $this->assertNotNull($closed->end_date, 'The closed row keeps its history rather than being deleted.');
    }

    #[Test]
    public function assigning_the_same_crew_twice_is_rejected(): void
    {
        $admin = $this->adminWith('Add Vehicle Users');
        $sacco = $admin->sacco;
        $vehicle = $this->makeVehicle($sacco, $admin, $this->makeSeat());
        $driver = $this->makeUser(['View Routes', 'View Sacco Members', 'Add Routes', 'Edit Routes'], $sacco);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/vehicles/users/add', ['user_id' => $driver->id, 'vehicle_id' => $vehicle->id])
            ->assertStatus(201);
        $this->postJson('/api/v1/auth/vehicles/users/add', ['user_id' => $driver->id, 'vehicle_id' => $vehicle->id])
            ->assertStatus(409);
    }

    #[Test]
    public function another_saccos_vehicle_cannot_be_crewed(): void
    {
        $admin = $this->adminWith('Add Vehicle Users');
        $driver = $this->makeUser(['View Routes', 'View Sacco Members', 'Add Routes', 'Edit Routes'], $admin->sacco);

        $otherSacco = $this->makeSacco();
        $otherOwner = $this->makeUser(['View Routes', 'View Sacco Members', 'Add Routes', 'Edit Routes'], $otherSacco);
        $otherVehicle = $this->makeVehicle($otherSacco, $otherOwner, $this->makeSeat());

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/vehicles/users/add', [
            'user_id' => $driver->id,
            'vehicle_id' => $otherVehicle->id,
        ])->assertStatus(404);

        $this->assertDatabaseMissing('vehicle_users', ['vehicle_id' => $otherVehicle->id]);
    }

    #[Test]
    public function an_assignment_can_be_ended(): void
    {
        $admin = $this->adminWith('Add Vehicle Users', 'Edit Vehicle Users');
        $sacco = $admin->sacco;
        $vehicle = $this->makeVehicle($sacco, $admin, $this->makeSeat());
        $driver = $this->makeUser(['View Routes', 'View Sacco Members', 'Add Routes', 'Edit Routes'], $sacco);

        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/v1/auth/vehicles/users/add', ['user_id' => $driver->id, 'vehicle_id' => $vehicle->id])
            ->assertStatus(201)->json('vehicle_user.id');

        $this->postJson("/api/v1/auth/vehicles/users/{$id}/end")->assertOk();
        $this->postJson("/api/v1/auth/vehicles/users/{$id}/end")->assertStatus(409);

        $this->assertFalse((bool) VehicleUser::find($id)->status);
    }

    #[Test]
    public function assigning_without_permission_is_refused(): void
    {
        $admin = $this->adminWith();
        $sacco = $admin->sacco;
        $vehicle = $this->makeVehicle($sacco, $admin, $this->makeSeat());
        $driver = $this->makeUser(['View Routes', 'View Sacco Members', 'Add Routes', 'Edit Routes'], $sacco);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/vehicles/users/add', ['user_id' => $driver->id, 'vehicle_id' => $vehicle->id])
            ->assertStatus(401);
    }

    #[Test]
    public function a_created_route_appears_in_the_authoritative_route_list(): void
    {
        // The regression that pushed callers onto book_a_ride/routes: GET routes
        // lists via the sacco_routes pivot, and route creation never wrote it.
        $admin = $this->adminWith('Add Routes');
        $from = Place::create(['name' => 'Nairobi CBD', 'status' => 1]);
        $to = Place::create(['name' => 'Thika', 'status' => 1]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/routes/add', [
            'id' => 0, 'from' => $from->name, 'to' => $to->name, 'status' => 1,
        ])->assertOk();

        $route = Route::where('from_id', $from->id)->where('to_id', $to->id)->first();
        $this->assertNotNull($route);
        $this->assertDatabaseHas('sacco_routes', [
            'sacco_id' => $admin->sacco_id,
            'route_id' => $route->id,
        ]);

        $listed = collect($this->getJson('/api/v1/auth/routes')->assertOk()->json('routes'))->pluck('id');
        $this->assertTrue($listed->contains($route->id), 'A route must be visible on the screen that created it.');
    }

    #[Test]
    public function re_saving_a_route_does_not_duplicate_the_sacco_link(): void
    {
        $admin = $this->adminWith('Add Routes', 'Edit Routes');
        $from = Place::create(['name' => 'Ruiru', 'status' => 1]);
        $to = Place::create(['name' => 'Juja', 'status' => 1]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/routes/add', ['id' => 0, 'from' => $from->name, 'to' => $to->name, 'status' => 1])
            ->assertOk();
        $route = Route::where('from_id', $from->id)->where('to_id', $to->id)->first();

        $this->postJson('/api/v1/auth/routes/add', [
            'id' => $route->id, 'from' => $from->name, 'to' => $to->name, 'status' => 1, 'name' => 'Renamed',
        ])->assertOk();

        $this->assertSame(1, SaccoRoute::where('route_id', $route->id)->where('sacco_id', $admin->sacco_id)->count());
    }

    #[Test]
    public function members_returns_a_users_alias_alongside_sacco_users(): void
    {
        // So the members screen can standardise on this endpoint instead of the
        // whole-platform user list, without breaking existing sacco_users users.
        $admin = $this->adminWith('View Sacco Users');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/auth/saccos/members?sacco='.$admin->sacco_id)->assertOk();

        $response->assertJsonStructure(['sacco_users', 'users']);
        $this->assertSame(
            count($response->json('sacco_users')),
            count($response->json('users')),
            'The alias must describe the same membership, not a different set.',
        );
    }
}
