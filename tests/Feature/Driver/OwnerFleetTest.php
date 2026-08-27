<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * An owner sees their whole fleet; a driver still sees one bus.
 *
 * WHAT WAS WRONG. Every driver endpoint resolved "your vehicle" as the most
 * recent open assignment — correct for someone on shift, who is on exactly one
 * matatu today. But investors are attached to their buses through that same
 * table: at NICCO ten of them hold open assignments, one across 40 vehicles and
 * another across 20. Each was shown a single arbitrary bus, with the rest of
 * their fleet invisible and nothing to say it existed.
 *
 * THE BOUNDARY MUST NOT MOVE. A caller may now name a vehicle, and that is
 * exactly the kind of change that turns an identity-scoped endpoint into an
 * IDOR. The id is matched against the caller's OWN assignments, so it can only
 * narrow — most of the tests below are about that, not about fleets.
 */
final class OwnerFleetTest extends QueueTestCase
{
    private function attach(User $user, Vehicle $vehicle): void
    {
        VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $vehicle->sacco_id,
            'status' => true,
            'start_date' => now()->subDay(),
        ]);
    }

    private function crew(array $world, string $role, UserType $type): User
    {
        $u = $this->makeUser([], $world['sacco']);
        $u->forceFill(['type' => $type, 'sacco_id' => $world['sacco']->id])->save();
        Role::findOrCreate($role, 'web');
        $u->assignRole($role);

        return $u->fresh();
    }

    #[Test]
    public function an_owner_sees_every_bus_they_hold_an_assignment_on(): void
    {
        $world = $this->makeWorld();
        $second = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $third = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $investor = $this->crew($world, Roles::INVESTOR, UserType::Passenger);
        foreach ([$world['vehicle'], $second, $third] as $v) {
            $this->attach($investor, $v);
        }

        Sanctum::actingAs($investor);

        $body = $this->getJson('/api/v1/auth/driver/vehicles')->assertOk()->json();

        $this->assertSame(3, $body['count']);
        $this->assertEqualsCanonicalizing(
            [$world['vehicle']->id, $second->id, $third->id],
            array_column($body['vehicles'], 'id')
        );
    }

    #[Test]
    public function a_driver_on_one_bus_still_sees_exactly_that_bus(): void
    {
        // The common case must not change shape. A client with one entry can
        // skip the picker entirely and behave as it does today.
        $world = $this->makeWorld();
        $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $driver = $this->crew($world, Roles::DRIVER, UserType::Driver);
        $this->attach($driver, $world['vehicle']);

        Sanctum::actingAs($driver);

        $body = $this->getJson('/api/v1/auth/driver/vehicles')->assertOk()->json();

        $this->assertSame(1, $body['count']);
        $this->assertSame($world['vehicle']->id, $body['vehicles'][0]['id']);
    }

    #[Test]
    public function a_caller_cannot_name_a_bus_that_is_not_theirs(): void
    {
        // THE IDOR THIS GUARDS. Same SACCO, so the tenant scope permits it —
        // only the assignment check stands in the way.
        $world = $this->makeWorld();
        $notMine = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $driver = $this->crew($world, Roles::DRIVER, UserType::Driver);
        $this->attach($driver, $world['vehicle']);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/transactions?vehicle_id='.$notMine->id)
            ->assertStatus(403);
    }

    #[Test]
    public function a_caller_cannot_reach_another_saccos_bus_by_naming_it(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $driver = $this->crew($mine, Roles::DRIVER, UserType::Driver);
        $this->attach($driver, $mine['vehicle']);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/transactions?vehicle_id='.$theirs['vehicle']->id)
            ->assertStatus(403);
    }

    #[Test]
    public function an_owner_can_ask_about_any_one_of_their_own_buses(): void
    {
        $world = $this->makeWorld();
        $second = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $investor = $this->crew($world, Roles::INVESTOR, UserType::Passenger);
        $this->attach($investor, $world['vehicle']);
        $this->attach($investor, $second);

        Sanctum::actingAs($investor);

        // Both resolve, and to DIFFERENT buses — the whole point. Without the
        // parameter only the latest assignment was ever reachable.
        $first = $this->getJson('/api/v1/auth/driver/home?vehicle_id='.$world['vehicle']->id)->assertOk()->json();
        $other = $this->getJson('/api/v1/auth/driver/home?vehicle_id='.$second->id)->assertOk()->json();

        $this->assertNotSame(
            json_encode($first['vehicle'] ?? $first),
            json_encode($other['vehicle'] ?? $other),
            'naming a different bus must return a different bus'
        );
    }

    #[Test]
    public function omitting_the_vehicle_keeps_the_old_behaviour(): void
    {
        // Backwards compatibility for every client already in the field.
        $world = $this->makeWorld();
        $driver = $this->crew($world, Roles::DRIVER, UserType::Driver);
        $this->attach($driver, $world['vehicle']);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/home')->assertOk();
    }

    #[Test]
    public function someone_with_no_assignment_at_all_gets_an_empty_fleet(): void
    {
        $world = $this->makeWorld();
        $nobody = $this->crew($world, Roles::INVESTOR, UserType::Passenger);

        Sanctum::actingAs($nobody);

        $body = $this->getJson('/api/v1/auth/driver/vehicles')->assertOk()->json();

        $this->assertSame(0, $body['count']);
        $this->assertSame([], $body['vehicles']);
    }

    #[Test]
    public function an_ended_assignment_does_not_keep_a_bus_on_the_list(): void
    {
        // Crews rotate. A driver who moved off a matatu last week must not still
        // be able to read its takings.
        $world = $this->makeWorld();
        $driver = $this->crew($world, Roles::DRIVER, UserType::Driver);

        VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => false,
            'start_date' => now()->subDays(9),
            'end_date' => now()->subDays(2),
        ]);

        Sanctum::actingAs($driver);

        $this->assertSame(0, $this->getJson('/api/v1/auth/driver/vehicles')->assertOk()->json('count'));
        $this->getJson('/api/v1/auth/driver/transactions?vehicle_id='.$world['vehicle']->id)->assertStatus(403);
    }

    #[Test]
    public function writing_an_expense_still_uses_the_crews_own_bus_only(): void
    {
        // Reads were widened; writes were not. Submitting an expense against a
        // bus you merely own, while someone else is crewing it, is a different
        // decision from reading its numbers — and not one being made here.
        $world = $this->makeWorld();
        $second = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $driver = $this->crew($world, Roles::DRIVER, UserType::Driver);
        $this->attach($driver, $world['vehicle']);
        $this->attach($driver, $second);

        Sanctum::actingAs($driver);

        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/auth/driver/expenses' && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route, 'the expense write must still exist');

        // It resolves without a vehicle_id, i.e. the parameter was not added.
        $source = file_get_contents(app_path('Http/Controllers/APIs/Driver/DriverPortalController.php'));
        $this->assertStringContainsString(
            "public function storeExpense(Request \$request): JsonResponse\n    {\n        \$vehicle = \$this->vehicle();",
            $source,
            'storeExpense must keep resolving the crew vehicle, unparameterised'
        );
    }
}
