<?php

declare(strict_types=1);

namespace Tests\Feature\Crew;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The crews screen, rebuilt around PEOPLE rather than assignment rows.
 *
 * The old screen listed `vehicle_users`. On NICCO MOVERS that is 261 rows for
 * 179 people, so one investor appeared 40 times, seven drivers with no
 * assignment did not appear at all, and every past rotation rendered as its own
 * "Ended" row with a dash for a vehicle. These tests pin the three properties
 * that were broken: one row per person, people with no bus still listed, and the
 * investor-who-drives included without being duplicated.
 */
final class CrewManagementTest extends QueueTestCase
{
    private function admin(array $world, array $permissions = ['View Vehicle Users', 'Edit Vehicle Users', 'Add Vehicle Users', 'Edit Sacco Members']): User
    {
        $u = $this->makeUser($permissions, $world['sacco']);
        $u->type = UserType::Admin;
        $u->sacco_id = $world['sacco']->id;
        $u->save();

        return $u->fresh();
    }

    private function person(array $world, UserType $type, ?string $role = null): User
    {
        $u = $this->makeUser([], $world['sacco']);
        $u->type = $type;
        $u->sacco_id = $world['sacco']->id;
        $u->save();
        if ($role !== null) {
            Role::findOrCreate($role, 'web');
            $u->assignRole($role);
        }

        return $u->fresh();
    }

    private function attach(User $u, $vehicle, ?string $endedAt = null): VehicleUser
    {
        return VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $u->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $vehicle->sacco_id,
            'status' => $endedAt === null,
            'start_date' => now()->subDay(),
            'end_date' => $endedAt,
        ]);
    }

    #[Test]
    public function a_person_with_many_assignments_appears_exactly_once(): void
    {
        // The 40-rows bug. Production had one investor rendered 40 times because
        // the page listed assignments.
        $world = $this->makeWorld();
        $driver = $this->person($world, UserType::Driver);

        foreach (range(1, 5) as $i) {
            $this->attach($driver, $world['vehicle'], now()->subDays($i)->toDateTimeString());
        }
        $this->attach($driver, $world['vehicle']);

        Sanctum::actingAs($this->admin($world));

        $ids = collect($this->getJson('/api/v1/auth/crew')->assertOk()->json('crew'))->pluck('id');

        $this->assertSame(1, $ids->filter(fn ($id) => $id === $driver->id)->count(), 'one row per person');
    }

    #[Test]
    public function a_driver_with_no_vehicle_is_still_listed(): void
    {
        // Seven NICCO drivers were invisible because they had no assignment row.
        $world = $this->makeWorld();
        $stranded = $this->person($world, UserType::Driver);

        Sanctum::actingAs($this->admin($world));

        $row = collect($this->getJson('/api/v1/auth/crew')->assertOk()->json('crew'))
            ->firstWhere('id', $stranded->id);

        $this->assertNotNull($row, 'a driver with no bus is still crew');
        $this->assertSame([], $row['vehicles']);
        $this->assertTrue($row['flags']['unassigned']);
    }

    #[Test]
    public function an_investor_who_drives_is_listed_but_a_purely_financial_one_is_not(): void
    {
        // All 12 NICCO investors are type=admin, so a filter on type hides the
        // ones actually working. Holding a bus is what makes them crew.
        $world = $this->makeWorld();

        $driving = $this->person($world, UserType::Admin, Roles::INVESTOR);
        $this->attach($driving, $world['vehicle']);

        $financialOnly = $this->person($world, UserType::Admin, Roles::INVESTOR);

        Sanctum::actingAs($this->admin($world));

        $ids = collect($this->getJson('/api/v1/auth/crew')->assertOk()->json('crew'))->pluck('id');

        $this->assertContains($driving->id, $ids->all());
        $this->assertNotContains($financialOnly->id, $ids->all());
    }

    #[Test]
    public function a_queue_supervisor_is_crew(): void
    {
        $world = $this->makeWorld();
        $supervisor = $this->person($world, UserType::Admin, Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($this->admin($world));

        $ids = collect($this->getJson('/api/v1/auth/crew')->assertOk()->json('crew'))->pluck('id');
        $this->assertContains($supervisor->id, $ids->all());
    }

    #[Test]
    public function another_saccos_crew_is_never_listed(): void
    {
        // User carries no SaccoScope, so the where() in the controller IS the
        // tenant boundary — not a convenience.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $outsider = $this->person($theirs, UserType::Driver);

        Sanctum::actingAs($this->admin($mine));

        $ids = collect($this->getJson('/api/v1/auth/crew')->assertOk()->json('crew'))->pluck('id');
        $this->assertNotContains($outsider->id, $ids->all());
    }

    #[Test]
    public function assigning_a_vehicle_goes_through_the_same_path_as_a_driver_login(): void
    {
        // The handover: the previous driver must be released and their open
        // queue closed, exactly as VehicleAssignment does at login. If the
        // dashboard wrote vehicle_users itself, the bus would end up with two
        // live drivers.
        $world = $this->makeWorld();
        $outgoing = $this->person($world, UserType::Driver);
        $incoming = $this->person($world, UserType::Driver);
        $this->attach($outgoing, $world['vehicle']);

        Sanctum::actingAs($this->admin($world));

        $this->postJson("/api/v1/auth/crew/{$incoming->id}/assign", ['vehicle_id' => $world['vehicle']->id])
            ->assertOk()
            ->assertJsonPath('assignment.vehicle.plate', $world['vehicle']->plate);

        $this->assertSame(0, VehicleUser::withoutGlobalScopes()
            ->where('user_id', $outgoing->id)->whereNull('end_date')->count(),
            'the outgoing driver must be released');
        $this->assertSame(1, VehicleUser::withoutGlobalScopes()
            ->where('user_id', $incoming->id)->whereNull('end_date')->count());
    }

    #[Test]
    public function a_crew_member_cannot_be_put_on_another_saccos_bus(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $driver = $this->person($mine, UserType::Driver);

        Sanctum::actingAs($this->admin($mine));

        $this->postJson("/api/v1/auth/crew/{$driver->id}/assign", ['vehicle_id' => $theirs['vehicle']->id])
            ->assertStatus(404);

        $this->assertSame(0, VehicleUser::withoutGlobalScopes()
            ->where('vehicle_id', $theirs['vehicle']->id)->count());
    }

    #[Test]
    public function reassigning_to_the_same_bus_is_reported_as_already_assigned(): void
    {
        $world = $this->makeWorld();
        $driver = $this->person($world, UserType::Driver);
        $this->attach($driver, $world['vehicle']);

        Sanctum::actingAs($this->admin($world));

        $this->postJson("/api/v1/auth/crew/{$driver->id}/assign", ['vehicle_id' => $world['vehicle']->id])
            ->assertOk()
            ->assertJsonPath('assignment.was_already_assigned', true);

        $this->assertSame(1, VehicleUser::withoutGlobalScopes()
            ->where('user_id', $driver->id)->whereNull('end_date')->count(), 'no duplicate assignment');
    }

    #[Test]
    public function unassigning_closes_the_row_rather_than_deleting_it(): void
    {
        // Who crewed which bus on which day is what a takings dispute is settled
        // with — the row must survive.
        $world = $this->makeWorld();
        $driver = $this->person($world, UserType::Driver);
        $assignment = $this->attach($driver, $world['vehicle']);

        Sanctum::actingAs($this->admin($world));

        $this->postJson("/api/v1/auth/crew/{$driver->id}/unassign")->assertOk();

        $row = VehicleUser::withoutGlobalScopes()->find($assignment->id);
        $this->assertNotNull($row, 'history must survive');
        $this->assertNotNull($row->end_date);
    }

    #[Test]
    public function details_can_be_corrected_and_a_plate_name_is_flagged(): void
    {
        // 171 NICCO drivers are named after their bus. This is where they become
        // people, and the flag is how the UI knows to ask.
        $world = $this->makeWorld();
        $driver = $this->person($world, UserType::Driver);
        $driver->forceFill(['firstname' => 'KDY', 'lastname' => '759D'])->save();

        Sanctum::actingAs($this->admin($world));

        $row = collect($this->getJson('/api/v1/auth/crew')->assertOk()->json('crew'))
            ->firstWhere('id', $driver->id);
        $this->assertTrue($row['flags']['name_looks_like_a_plate']);

        $this->postJson("/api/v1/auth/crew/{$driver->id}", [
            'firstname' => 'Joseph', 'lastname' => 'Mwangi', 'phone' => '254711000111',
        ])->assertOk()->assertJsonPath('crew.flags.name_looks_like_a_plate', false);

        $this->assertSame('Joseph', $driver->fresh()->firstname);
    }

    #[Test]
    public function another_saccos_member_cannot_be_edited(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $outsider = $this->person($theirs, UserType::Driver);
        $before = $outsider->firstname;

        Sanctum::actingAs($this->admin($mine));

        $this->postJson("/api/v1/auth/crew/{$outsider->id}", [
            'firstname' => 'Hijacked', 'phone' => '254799000111',
        ])->assertStatus(404);

        $this->assertSame($before, $outsider->fresh()->firstname);
    }

    #[Test]
    public function a_duplicate_phone_is_refused(): void
    {
        // Phone IS the driver's credential on the mobile app — phone plus plate,
        // no password. Two people sharing one would be two people sharing a login.
        $world = $this->makeWorld();
        $a = $this->person($world, UserType::Driver);
        $b = $this->person($world, UserType::Driver);

        Sanctum::actingAs($this->admin($world));

        $this->postJson("/api/v1/auth/crew/{$a->id}", [
            'firstname' => 'Test', 'phone' => $b->phone,
        ])->assertStatus(422);
    }

    #[Test]
    public function history_returns_the_rows_the_directory_no_longer_shows(): void
    {
        $world = $this->makeWorld();
        $driver = $this->person($world, UserType::Driver);
        $this->attach($driver, $world['vehicle'], now()->subDays(3)->toDateTimeString());
        $this->attach($driver, $world['vehicle']);

        Sanctum::actingAs($this->admin($world));

        $history = $this->getJson("/api/v1/auth/crew/{$driver->id}/history")->assertOk()->json('history');
        $this->assertCount(2, $history);
    }
}
