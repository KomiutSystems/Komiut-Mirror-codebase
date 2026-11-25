<?php

declare(strict_types=1);

namespace Tests\Feature\School;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SchoolDemoSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The school's side of school bus tracking: its buses and which are live.
 *
 * The walls under test: a SACCO that is not a school gets 403; a school sees
 * its own buses only; a superadmin sees every school's. Following, follow
 * codes and the followers screens are gone (saved buses replaced them), and
 * stay gone.
 */
final class SchoolAdminTest extends QueueTestCase
{
    private const BUSES = '/api/v1/auth/school/buses';

    private const ADMIN_PERMISSIONS = ['View Vehicles', 'Edit Vehicles', 'Add Vehicles'];

    private Sacco $school;

    private User $admin;

    private Seat $seat;

    private Vehicle $bus1;

    private Vehicle $bus2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->makeSchool();
        $this->admin = $this->makeUser(self::ADMIN_PERMISSIONS, $this->school);
        $this->seat = $this->makeSeat();
        $this->bus1 = $this->makeVehicle($this->school, $this->admin, $this->seat);
        $this->bus2 = $this->makeVehicle($this->school, $this->admin, $this->seat);
    }

    private function makeSchool(): Sacco
    {
        $school = $this->makeSacco();
        $school->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();

        return $school;
    }

    // ---------------------------------------------------------------- list

    #[Test]
    public function the_list_shows_the_schools_own_buses_and_which_are_live(): void
    {
        $this->pingFrom($this->bus1);
        $this->pingFrom($this->bus2, ageSeconds: 600); // stale: not live

        $otherSchool = $this->makeSchool();
        $foreign = $this->makeVehicle($otherSchool, $this->makeUser([], $otherSchool), $this->seat);

        Sanctum::actingAs($this->admin);
        $first = $this->getJson(self::BUSES)->assertOk();

        $buses = collect($first->json('buses'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$this->bus1->id, $this->bus2->id], $buses->keys()->all());
        $this->assertArrayNotHasKey($foreign->id, $buses->all());
        $this->assertSame(['id', 'plate', 'fleet_no', 'school', 'live'], array_keys($buses[$this->bus1->id]));
        $this->assertSame(['id' => $this->school->id, 'name' => $this->school->name], $buses[$this->bus1->id]['school']);
        $this->assertSame(['id' => $this->school->id, 'name' => $this->school->name], $first->json('school'));
        $this->assertTrue($buses[$this->bus1->id]['live']);
        $this->assertFalse($buses[$this->bus2->id]['live']);
    }

    #[Test]
    public function the_list_costs_the_same_queries_for_two_buses_as_for_six(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson(self::BUSES)->assertOk();
        $small = $this->queriesFor(self::BUSES);

        foreach (range(1, 4) as $_) {
            $bus = $this->makeVehicle($this->school, $this->admin, $this->seat);
            $this->pingFrom($bus);
        }
        // Warm again: makeUser() above flushed the permission cache, which
        // would otherwise be counted against the larger list.
        $this->getJson(self::BUSES)->assertOk();
        $large = $this->queriesFor(self::BUSES);

        $this->assertSame($small, $large, 'GET school/buses must not query per bus.');
    }

    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function a_sacco_that_is_not_a_school_is_refused(): void
    {
        $sacco = $this->makeSacco();
        $saccoAdmin = $this->makeUser(self::ADMIN_PERMISSIONS, $sacco);
        $this->makeVehicle($sacco, $saccoAdmin, $this->seat);

        Sanctum::actingAs($saccoAdmin);

        $this->getJson(self::BUSES)->assertForbidden()->assertExactJson(['error' => 'This is for school accounts.']);
    }

    #[Test]
    public function the_permission_gates_still_apply(): void
    {
        $viewer = $this->makeUser(['View Vehicles'], $this->school);

        Sanctum::actingAs($viewer);
        $this->getJson(self::BUSES)->assertOk();

        Sanctum::actingAs($this->makeUser([], $this->school));
        $this->getJson(self::BUSES)->assertForbidden();
    }

    #[Test]
    public function a_superadmin_sees_every_school_or_one(): void
    {
        $super = $this->makeUser(self::ADMIN_PERMISSIONS);
        $super->forceFill(['type' => UserType::Superadmin])->save();
        $plainSacco = $this->makeSacco();
        $matatu = $this->makeVehicle($plainSacco, $super, $this->seat);

        Sanctum::actingAs($super);

        // Without ?school: every school, grouped, with a summary per school.
        $otherSchool = $this->makeSacco();
        $otherSchool->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();
        $otherBus = $this->makeVehicle($otherSchool, $super, $this->seat);
        $all = $this->getJson(self::BUSES)->assertOk();
        $this->assertSame(3, $all->json('total'));
        $this->assertEqualsCanonicalizing([$this->school->id, $otherSchool->id], array_column($all->json('schools'), 'id'));
        $this->assertSame([$otherSchool->id], array_values(array_unique(array_column(array_column(
            array_filter($all->json('buses'), fn ($b) => $b['id'] === $otherBus->id), 'school'), 'id'))));
        $this->assertSame(['id', 'name', 'buses', 'live'], array_keys($all->json('schools.0')));
        $this->assertNotContains($matatu->id, array_column($all->json('buses'), 'id'), 'a matatu is never listed');

        $listed = $this->getJson(self::BUSES.'?school='.$this->school->id)->assertOk()->json('buses');
        $this->assertCount(2, $listed);
        $this->getJson(self::BUSES.'?school='.$plainSacco->id)->assertForbidden();
    }

    #[Test]
    public function the_real_school_admin_role_reaches_the_list(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser([], $this->school);
        $admin->assignRole(Role::findByName(Roles::SCHOOL_ADMIN, 'web'));

        Sanctum::actingAs($admin);
        $this->getJson(self::BUSES)->assertOk()->assertJsonCount(2, 'buses');
    }

    #[Test]
    public function the_follow_code_and_followers_endpoints_are_gone(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson(self::BUSES."/{$this->bus1->id}/follow-code")->assertNotFound();
        $this->getJson(self::BUSES."/{$this->bus1->id}/followers")->assertNotFound();
        $this->deleteJson(self::BUSES."/{$this->bus1->id}/followers/1")->assertNotFound();
    }

    // ------------------------------------------------------------ fleet

    #[Test]
    public function a_bus_added_to_a_school_is_left_out_of_till_registration(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0,
            'plate' => 'KSB 777Z',
            'seat' => $this->seat->name,
            'status' => 1,
        ])->assertOk()->assertExactJson(['success' => 'Vehicle saved successfully']);

        $bus = Vehicle::withoutGlobalScopes()->where('plate', 'KSB 777Z')->firstOrFail();
        $this->assertSame($this->school->id, (int) $bus->sacco_id);
        $this->assertNull($bus->till_registration_status);
    }

    // ------------------------------------------------------------- demo seed

    #[Test]
    public function the_demo_school_seeds_once_however_often_it_runs(): void
    {
        $this->seed(SchoolDemoSeeder::class);
        $this->seed(SchoolDemoSeeder::class);

        $school = Sacco::withoutGlobalScopes()->where('name', 'Greenfield Demo Academy')->sole();
        $this->assertTrue($school->isSchool());
        $this->assertSame('komiut', $school->brand);
        $this->assertSame(2, Vehicle::withoutGlobalScopes()->where('sacco_id', $school->id)->count());

        $bus1 = Vehicle::withoutGlobalScopes()->where('plate', 'KSB 101A')->sole();
        $this->assertSame(1, DB::table('vehicle_users')->where('vehicle_id', $bus1->id)->count());
        $route = DB::table('routes')->where('sacco_id', $school->id)->sole();
        $this->assertSame(3, DB::table('route_stages')->where('route_id', $route->id)->count());
        $this->assertSame(1, DB::table('sacco_routes')->where('sacco_id', $school->id)->count());
        $this->assertTrue(User::where('email', 'admin@greenfield.school.test')->sole()->hasRole(Roles::SCHOOL_ADMIN));
        $this->assertNull(User::where('email', 'parent@greenfield.school.test')->sole()->sacco_id);
    }
}
