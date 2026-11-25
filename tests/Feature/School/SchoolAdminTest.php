<?php

declare(strict_types=1);

namespace Tests\Feature\School;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\Place;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFollower;
use App\Services\School\FollowCode;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SchoolDemoSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The school's side of school bus tracking: its buses and their follow codes,
 * rotating a code, and the parents following each bus.
 *
 * The walls under test: a SACCO that is not a school gets 403 before any bus
 * is looked at; a bus outside the caller's school is 404, whatever it is; a
 * follow code leaves the API only through GET school/buses; a parent's full
 * phone never leaves it at all.
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

    private function follow(Vehicle $bus, ?User $parent = null, ?Place $stop = null): VehicleFollower
    {
        return VehicleFollower::create([
            'user_id' => ($parent ?? $this->makeUser())->id,
            'vehicle_id' => $bus->id,
            'stop_place_id' => $stop?->id,
        ]);
    }

    private function codeOf(Vehicle $bus): ?string
    {
        return Vehicle::withoutGlobalScopes()->whereKey($bus->id)->value('follow_code');
    }

    // ---------------------------------------------------------------- list

    #[Test]
    public function the_list_issues_missing_codes_once_and_counts_followers_and_live_buses(): void
    {
        $this->assertNull($this->codeOf($this->bus1));
        $this->follow($this->bus1);
        $this->follow($this->bus1);
        $this->pingFrom($this->bus1);
        $this->pingFrom($this->bus2, ageSeconds: 600); // stale: not live

        $otherSchool = $this->makeSchool();
        $foreign = $this->makeVehicle($otherSchool, $this->makeUser([], $otherSchool), $this->seat);

        Sanctum::actingAs($this->admin);
        $first = $this->getJson(self::BUSES)->assertOk();

        $buses = collect($first->json('buses'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$this->bus1->id, $this->bus2->id], $buses->keys()->all());
        $this->assertArrayNotHasKey($foreign->id, $buses->all());
        $this->assertSame(['id', 'plate', 'fleet_no', 'school', 'follow_code', 'followers', 'live'], array_keys($buses[$this->bus1->id]));

        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{8}$/', $buses[$this->bus1->id]['follow_code']);
        $this->assertSame($this->codeOf($this->bus1), $buses[$this->bus1->id]['follow_code']);
        $this->assertSame(2, $buses[$this->bus1->id]['followers']);
        $this->assertSame(0, $buses[$this->bus2->id]['followers']);
        $this->assertTrue($buses[$this->bus1->id]['live']);
        $this->assertFalse($buses[$this->bus2->id]['live']);
        // Another school's bus is not touched either.
        $this->assertNull($this->codeOf($foreign));

        // Idempotent: a second look shows the same codes.
        $second = collect($this->getJson(self::BUSES)->assertOk()->json('buses'))->keyBy('id');
        $this->assertSame($buses[$this->bus1->id]['follow_code'], $second[$this->bus1->id]['follow_code']);
        $this->assertSame($buses[$this->bus2->id]['follow_code'], $second[$this->bus2->id]['follow_code']);
    }

    #[Test]
    public function the_list_costs_the_same_queries_for_two_buses_as_for_six(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson(self::BUSES)->assertOk(); // issue codes first
        $small = $this->queriesFor(self::BUSES);

        foreach (range(1, 4) as $_) {
            $bus = $this->makeVehicle($this->school, $this->admin, $this->seat);
            FollowCode::issue($bus);
            $this->follow($bus);
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

    // -------------------------------------------------------------- rotate

    #[Test]
    public function rotating_kills_the_old_code_and_revoking_removes_every_follower(): void
    {
        $old = FollowCode::issue($this->bus1);
        $this->follow($this->bus1);
        $this->follow($this->bus1);
        $keep = $this->follow($this->bus2);

        Sanctum::actingAs($this->admin);
        $response = $this->postJson(self::BUSES."/{$this->bus1->id}/follow-code", ['revoke_followers' => true])
            ->assertOk()
            ->assertJsonPath('bus.id', $this->bus1->id)
            ->assertJsonPath('bus.plate', $this->bus1->plate)
            ->assertJsonPath('revoked', 2);

        $new = $response->json('bus.follow_code');
        $this->assertNotSame($old, $new);
        $fresh = Vehicle::withoutGlobalScopes()->find($this->bus1->id);
        $this->assertFalse(FollowCode::matches($fresh, $old));
        $this->assertTrue(FollowCode::matches($fresh, $new));

        $this->assertSame(0, VehicleFollower::where('vehicle_id', $this->bus1->id)->count());
        $this->assertNotNull($keep->fresh(), 'Another bus\'s followers are untouched.');

        $audit = AuditLog::where('action', 'school.follow_code.rotated')->sole();
        $this->assertSame((string) $this->bus1->id, $audit->subject_id);
        $this->assertSame($this->school->id, (int) $audit->sacco_id);
        $this->assertSame((string) $this->admin->id, $audit->actor_id);
        $this->assertSame(2, $audit->data['revoked']);
        $this->assertStringNotContainsString($new, json_encode($audit->data));
    }

    #[Test]
    public function rotating_without_revoking_keeps_the_followers(): void
    {
        FollowCode::issue($this->bus1);
        $this->follow($this->bus1);

        Sanctum::actingAs($this->admin);
        $this->postJson(self::BUSES."/{$this->bus1->id}/follow-code")
            ->assertOk()
            ->assertJsonPath('revoked', 0);

        $this->assertSame(1, VehicleFollower::where('vehicle_id', $this->bus1->id)->count());

        $this->postJson(self::BUSES."/{$this->bus1->id}/follow-code", ['revoke_followers' => 'maybe'])
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['revoke_followers']]);
    }

    // ----------------------------------------------------------- followers

    #[Test]
    public function the_followers_list_masks_phones_and_shows_only_this_bus(): void
    {
        $parent = $this->makeUser();
        $parent->forceFill(['phone' => '254712345678', 'firstname' => 'Jane', 'lastname' => 'Wanjiru'])->save();
        $stop = $this->makePlace('Kahawa West');
        $row = $this->follow($this->bus1, $parent, $stop);

        $pinned = $this->makeUser();
        $pinRow = VehicleFollower::create([
            'user_id' => $pinned->id, 'vehicle_id' => $this->bus1->id,
            'stop_latitude' => -1.18512, 'stop_longitude' => 36.91234,
        ]);

        $elsewhere = $this->makeUser();
        $elsewhere->forceFill(['firstname' => 'Other', 'lastname' => 'Parent'])->save();
        $this->follow($this->bus2, $elsewhere);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson(self::BUSES."/{$this->bus1->id}/followers")->assertOk();

        $followers = collect($response->json('followers'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$row->id, $pinRow->id], $followers->keys()->all());

        $jane = $followers[$row->id];
        $this->assertSame(['id', 'name', 'phone_masked', 'stop', 'followed_at'], array_keys($jane));
        $this->assertSame('Jane Wanjiru', $jane['name']);
        $this->assertSame('0712***678', $jane['phone_masked']);
        $this->assertSame($stop->id, $jane['stop']['place_id']);
        $this->assertSame('Kahawa West', $jane['stop']['name']);
        $this->assertFalse($jane['stop']['own_pin']);

        // A parent's own pin is usually their home: the school is told one
        // exists, not where it is.
        $this->assertTrue($followers[$pinRow->id]['stop']['own_pin']);
        $this->assertNull($followers[$pinRow->id]['stop']['latitude']);

        $body = $response->getContent();
        $this->assertStringNotContainsString('254712345678', $body);
        $this->assertStringNotContainsString('0712345678', $body);
        $this->assertStringNotContainsString('-1.18512', $body);
        $this->assertStringNotContainsString('Other', $body);
        $this->assertStringNotContainsString($parent->email, $body);
        $this->assertSame(2, $response->json('total'));
    }

    #[Test]
    public function removing_a_follower_removes_only_that_one(): void
    {
        $gone = $this->follow($this->bus1);
        $stays = $this->follow($this->bus1);
        $otherBus = $this->follow($this->bus2);

        Sanctum::actingAs($this->admin);
        $this->deleteJson(self::BUSES."/{$this->bus1->id}/followers/{$gone->id}")->assertNoContent();

        $this->assertNull($gone->fresh());
        $this->assertNotNull($stays->fresh());
        $this->assertNotNull($otherBus->fresh());

        $audit = AuditLog::where('action', 'school.follower.removed')->sole();
        $this->assertSame((string) $this->bus1->id, $audit->subject_id);
        $this->assertSame($this->school->id, (int) $audit->sacco_id);
        $this->assertSame($gone->id, $audit->data['follower_id']);

        // A follower of ANOTHER bus, even of this same school, is not found
        // through this one.
        $this->deleteJson(self::BUSES."/{$this->bus1->id}/followers/{$otherBus->id}")
            ->assertNotFound()
            ->assertExactJson(['error' => 'No such follower on this bus.']);
        $this->assertNotNull($otherBus->fresh());
    }

    // --------------------------------------------------------------- walls

    #[Test]
    public function a_sacco_that_is_not_a_school_is_refused_on_every_endpoint(): void
    {
        $sacco = $this->makeSacco();
        $saccoAdmin = $this->makeUser(self::ADMIN_PERMISSIONS, $sacco);
        $own = $this->makeVehicle($sacco, $saccoAdmin, $this->seat);
        $follower = $this->follow($this->bus1);

        Sanctum::actingAs($saccoAdmin);
        $refusal = ['error' => 'This is for school accounts.'];

        $this->getJson(self::BUSES)->assertForbidden()->assertExactJson($refusal);
        // The same 403 for its own bus and for a real school bus: nothing about
        // which ids are school buses.
        foreach ([$own->id, $this->bus1->id] as $id) {
            $this->postJson(self::BUSES."/{$id}/follow-code")->assertForbidden()->assertExactJson($refusal);
            $this->getJson(self::BUSES."/{$id}/followers")->assertForbidden()->assertExactJson($refusal);
            $this->deleteJson(self::BUSES."/{$id}/followers/{$follower->id}")->assertForbidden()->assertExactJson($refusal);
        }

        $this->assertNotNull($follower->fresh());
        $this->assertNull($this->codeOf($this->bus1));
    }

    #[Test]
    public function another_schools_bus_is_not_found(): void
    {
        $otherSchool = $this->makeSchool();
        $foreign = $this->makeVehicle($otherSchool, $this->makeUser([], $otherSchool), $this->seat);
        $code = FollowCode::issue($foreign);
        $follower = $this->follow($foreign);
        $missing = ['error' => 'No such bus in your school.'];

        Sanctum::actingAs($this->admin);

        foreach ([$foreign->id, 999999] as $id) {
            $this->postJson(self::BUSES."/{$id}/follow-code", ['revoke_followers' => true])->assertNotFound()->assertExactJson($missing);
            $this->getJson(self::BUSES."/{$id}/followers")->assertNotFound()->assertExactJson($missing);
            $this->deleteJson(self::BUSES."/{$id}/followers/{$follower->id}")->assertNotFound()->assertExactJson($missing);
        }

        $this->assertSame($code, $this->codeOf($foreign));
        $this->assertNotNull($follower->fresh());
    }

    #[Test]
    public function the_permission_gates_still_apply(): void
    {
        $viewer = $this->makeUser(['View Vehicles'], $this->school);

        Sanctum::actingAs($viewer);
        $this->getJson(self::BUSES)->assertOk();
        $this->postJson(self::BUSES."/{$this->bus1->id}/follow-code")->assertForbidden();

        Sanctum::actingAs($this->makeUser([], $this->school));
        $this->getJson(self::BUSES)->assertForbidden();
    }

    #[Test]
    public function a_superadmin_acts_for_the_school_a_bus_is_in(): void
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
        $this->assertNotNull($all->json('buses.0.follow_code'), 'codes are issued for every school, lazily');
        $this->assertNotContains($matatu->id, array_column($all->json('buses'), 'id'), 'a matatu is never listed');

        $listed = $this->getJson(self::BUSES.'?school='.$this->school->id)->assertOk()->json('buses');
        $this->assertCount(2, $listed);
        $this->getJson(self::BUSES.'?school='.$plainSacco->id)->assertForbidden();

        $this->postJson(self::BUSES."/{$this->bus1->id}/follow-code")->assertOk();
        $this->assertSame($this->school->id, (int) AuditLog::where('action', 'school.follow_code.rotated')->sole()->sacco_id);

        // A matatu is not a school bus, for a superadmin either.
        $this->postJson(self::BUSES."/{$matatu->id}/follow-code")->assertNotFound();
        $this->assertNull($this->codeOf($matatu));
    }

    #[Test]
    public function the_real_school_admin_role_reaches_all_four_endpoints(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser([], $this->school);
        $admin->assignRole(Role::findByName(Roles::SCHOOL_ADMIN, 'web'));
        $follower = $this->follow($this->bus1);

        Sanctum::actingAs($admin);
        $this->getJson(self::BUSES)->assertOk();
        $this->getJson(self::BUSES."/{$this->bus1->id}/followers")->assertOk();
        $this->postJson(self::BUSES."/{$this->bus1->id}/follow-code")->assertOk();
        $this->deleteJson(self::BUSES."/{$this->bus1->id}/followers/{$follower->id}")->assertNoContent();
    }

    // ---------------------------------------------- codes stay inside the school

    #[Test]
    public function the_fleet_list_never_carries_a_follow_code(): void
    {
        $code = FollowCode::issue($this->bus1);

        // Neither the school's own admin nor a plain SACCO admin sees it on
        // the ordinary fleet screen: only GET school/buses hands it out.
        $sacco = $this->makeSacco();
        $saccoAdmin = $this->makeUser(['View Vehicles'], $sacco);
        $this->makeVehicle($sacco, $saccoAdmin, $this->seat);

        foreach ([$this->admin, $saccoAdmin] as $caller) {
            Sanctum::actingAs($caller);
            $body = $this->getJson('/api/v1/auth/vehicles')->assertOk()->getContent();
            $this->assertStringNotContainsString('follow_code', $body);
            $this->assertStringNotContainsString($code, $body);
        }
    }

    #[Test]
    public function a_bus_added_to_a_school_is_issued_a_code_and_left_out_of_till_registration(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0,
            'plate' => 'KSB 777Z',
            'seat' => $this->seat->name,
            'status' => 1,
        ])->assertOk()->assertExactJson(['success' => 'Vehicle saved successfully']);

        $bus = Vehicle::withoutGlobalScopes()->where('plate', 'KSB 777Z')->firstOrFail();
        $this->assertSame($this->school->id, (int) $bus->sacco_id);
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{8}$/', (string) $bus->follow_code);
        $this->assertNull($bus->till_registration_status);
        $this->assertStringNotContainsString((string) $bus->follow_code, $response->getContent());
    }

    #[Test]
    public function a_bus_added_to_a_sacco_gets_no_code(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->makeUser(['Add Vehicles'], $sacco));

        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0,
            'plate' => 'KDM 123M',
            'seat' => $this->seat->name,
            'status' => 1,
        ])->assertOk();

        $this->assertNull(Vehicle::withoutGlobalScopes()->where('plate', 'KDM 123M')->value('follow_code'));
    }

    // ------------------------------------------------------------- demo seed

    #[Test]
    public function the_demo_school_seeds_once_however_often_it_runs(): void
    {
        $this->seed(SchoolDemoSeeder::class);
        $codes = Vehicle::withoutGlobalScopes()->whereIn('plate', ['KSB 101A', 'KSB 102A'])->orderBy('plate')->pluck('follow_code')->all();
        $this->seed(SchoolDemoSeeder::class);

        $school = Sacco::withoutGlobalScopes()->where('name', 'Greenfield Demo Academy')->sole();
        $this->assertTrue($school->isSchool());
        $this->assertSame('komiut', $school->brand);
        $this->assertCount(2, array_filter($codes));
        $this->assertSame($codes, Vehicle::withoutGlobalScopes()->whereIn('plate', ['KSB 101A', 'KSB 102A'])->orderBy('plate')->pluck('follow_code')->all());
        $this->assertSame(2, Vehicle::withoutGlobalScopes()->where('sacco_id', $school->id)->count());

        $bus1 = Vehicle::withoutGlobalScopes()->where('plate', 'KSB 101A')->sole();
        $this->assertSame(1, VehicleFollower::where('vehicle_id', $bus1->id)->count());
        $this->assertNotNull(VehicleFollower::where('vehicle_id', $bus1->id)->sole()->stopPoint());
        $this->assertSame(1, DB::table('vehicle_users')->where('vehicle_id', $bus1->id)->count());
        $route = DB::table('routes')->where('sacco_id', $school->id)->sole();
        $this->assertSame(3, DB::table('route_stages')->where('route_id', $route->id)->count());
        $this->assertSame(1, DB::table('sacco_routes')->where('sacco_id', $school->id)->count());
        $this->assertTrue(User::where('email', 'admin@greenfield.school.test')->sole()->hasRole(Roles::SCHOOL_ADMIN));
    }
}
