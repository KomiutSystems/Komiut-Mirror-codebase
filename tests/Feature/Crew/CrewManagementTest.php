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

    /**
     * Releasing a crew member — the dashboard half of the street-onboarding
     * gate.
     *
     * driver/onboard is public and matches on a phone number, so it no longer
     * moves a driver who already belongs to a SACCO; anyone could otherwise type
     * a stranger's number into their own SACCO. Drivers do change SACCO though,
     * so the move became two same-tenant writes — release here, then a normal
     * onboard — instead of one cross-tenant write on a public endpoint.
     */
    #[Test]
    public function releasing_a_driver_clears_their_sacco_and_closes_their_bus(): void
    {
        $world = $this->makeWorld();
        $driver = $this->person($world, UserType::Driver, Roles::DRIVER);
        $row = $this->attach($driver, $world['vehicle']);

        Sanctum::actingAs($this->admin($world));

        $this->postJson("/api/v1/auth/crew/{$driver->id}/release")
            ->assertOk()
            ->assertJsonPath('released', true)
            ->assertJsonPath('closed_assignments', 1);

        $this->assertNull($driver->fresh()->sacco_id);

        // Closed, not deleted — the rota history is the record of who drove what.
        $row->refresh();
        $this->assertNotNull($row->end_date);
        $this->assertFalse((bool) $row->status);
    }

    #[Test]
    public function a_released_driver_can_then_be_onboarded_by_the_new_sacco(): void
    {
        // The whole point: the two halves have to actually compose. Without this
        // the gate on driver/onboard is a dead end and every SACCO change turns
        // into a support ticket.
        $old = $this->makeWorld();
        $new = $this->makeSacco();

        $driver = $this->person($old, UserType::Driver, Roles::DRIVER);
        $driver->forceFill(['phone' => '0722000111'])->save();

        Sanctum::actingAs($this->admin($old));
        $this->postJson("/api/v1/auth/crew/{$driver->id}/release")->assertOk();

        $this->assertNull($driver->fresh()->sacco_id, 'release must have taken effect first');

        // Street onboarding runs unauthenticated, exactly as an agent does it.
        // Dropping the guard matters: Sacco is SACCO-scoped now, so an onboard
        // made while still signed in as the OLD SACCO's admin would not even
        // resolve the new SACCO.
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/driver/onboard', [
            'firstname' => 'Peter',
            'lastname' => 'Kamau',
            'phone' => '0722000111',
            'id_number' => '24567890',
            'plate' => 'KDQ446R',
            'sacco_id' => $new->id,
            'preferred_branch' => 'Thika Road',
        ])->assertCreated();

        $this->assertSame($new->id, $driver->fresh()->sacco_id, 'the driver must land in the new SACCO');
        $this->assertSame(1, User::where('phone', '0722000111')->count(), 'one account, one history');
    }

    #[Test]
    public function another_saccos_member_cannot_be_released(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $victim = $this->person($theirs, UserType::Driver, Roles::DRIVER);

        Sanctum::actingAs($this->admin($mine));

        $this->postJson("/api/v1/auth/crew/{$victim->id}/release")->assertStatus(404);

        $this->assertSame($theirs['sacco']->id, $victim->fresh()->sacco_id);
    }

    #[Test]
    public function releasing_requires_the_member_editing_permission(): void
    {
        $world = $this->makeWorld();
        $driver = $this->person($world, UserType::Driver, Roles::DRIVER);

        // Everything except Edit Sacco Members.
        Sanctum::actingAs($this->admin($world, ['View Vehicle Users', 'Edit Vehicle Users']));

        $this->postJson("/api/v1/auth/crew/{$driver->id}/release")->assertStatus(403);

        $this->assertSame($world['sacco']->id, $driver->fresh()->sacco_id);
    }

    #[Test]
    public function an_admin_cannot_be_released_and_neither_can_yourself(): void
    {
        // Releasing an admin would drop a colleague out of the SACCO they
        // administer, and releasing yourself would do it to you.
        $world = $this->makeWorld();
        $me = $this->admin($world);
        $colleague = $this->person($world, UserType::Admin);

        Sanctum::actingAs($me);

        $this->postJson("/api/v1/auth/crew/{$colleague->id}/release")->assertStatus(422);
        $this->postJson("/api/v1/auth/crew/{$me->id}/release")->assertStatus(422);

        $this->assertSame($world['sacco']->id, $colleague->fresh()->sacco_id);
        $this->assertSame($world['sacco']->id, $me->fresh()->sacco_id);
    }

    #[Test]
    public function an_investor_is_found_by_an_open_assignment_not_by_who_last_saved_the_bus(): void
    {
        // The bug this replaces: the query also matched vehicles.user_id, with a
        // comment calling it "the ownership column". It is not — it records
        // whoever last SAVED the row, and 168 of NICCO's 180 vehicles point at
        // the migration account. So a real investor whose buses all carry that
        // account was invisible here, while any heavy last-saver holding the
        // Investor role would have been listed for the whole SACCO.
        $world = $this->makeWorld();

        $realOwner = $this->person($world, UserType::Admin, Roles::INVESTOR);
        $this->attach($realOwner, $world['vehicle']);

        // Somebody else's name is on vehicles.user_id — the migration account's
        // situation, reproduced.
        $lastSaver = $this->person($world, UserType::Admin, Roles::INVESTOR);
        $world['vehicle']->forceFill(['user_id' => $lastSaver->id])->save();

        Sanctum::actingAs($this->admin($world));

        $ids = collect($this->getJson('/api/v1/auth/crew')->assertOk()->json('crew'))->pluck('id')->all();

        $this->assertContains($realOwner->id, $ids, 'the open assignment is what makes them crew');
        $this->assertNotContains(
            $lastSaver->id,
            $ids,
            'saving a vehicle row must never put someone on the crew page'
        );
    }

    #[Test]
    public function a_suspended_assignment_does_not_count_as_holding_a_bus(): void
    {
        // The old branch checked only end_date, so a suspended assignment still
        // read as current. status = true AND end_date IS NULL is the house
        // definition of open, used by VehicleAssignment and
        // ResolvesDriverVehicle alike.
        $world = $this->makeWorld();
        $investor = $this->person($world, UserType::Admin, Roles::INVESTOR);

        VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $investor->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => false,
            'start_date' => now()->subDay(),
            'end_date' => null,
        ]);

        Sanctum::actingAs($this->admin($world));

        $ids = collect($this->getJson('/api/v1/auth/crew')->assertOk()->json('crew'))->pluck('id')->all();

        $this->assertNotContains($investor->id, $ids);
    }
}
