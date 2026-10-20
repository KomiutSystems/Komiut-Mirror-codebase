<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\Crew;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VehicleUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The mobile app's one sign-in (phone + plate for crew, phone + password for
 * staff and investors) and the stage manager's screens.
 */
final class MobileStaffTest extends QueueTestCase
{
    private const LOGIN = '/api/v1/auth/mobile/login';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function role(User $user, string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A crew member assigned to a bus, who is also allowed the money screens. */
    private function crew(Sacco $sacco, string $phone, string $plate): User
    {
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['plate' => $plate]);
        $driver = $this->makeUser(['View Summaries'], $sacco);
        $driver->forceFill(['type' => UserType::Driver, 'phone' => $phone])->save();
        VehicleUser::create(['user_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'sacco_id' => $sacco->id, 'status' => true, 'start_date' => now()]);

        return $driver->fresh();
    }

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    #[Test]
    public function a_plate_opens_a_crew_session_that_never_reaches_money(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->role($this->crew($sacco, '254711000111', 'KDA001A'), Roles::INVESTOR);

        $res = $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'kda 001a'])->assertOk()
            ->assertJsonPath('session', 'crew');

        $this->as($res->json('access_token'))->getJson('/api/v1/auth/summaries/today')
            ->assertForbidden()->assertJsonPath('reason', 'crew_session');
        $this->as($res->json('access_token'))->getJson('/api/v1/auth/driver/trip')->assertStatus(200);

        // The same owner-driver with their password: their investor view.
        $staff = $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'password'])->assertOk()
            ->assertJsonPath('session', 'staff');
        $this->assertContains('investor', $staff->json('staff_roles'));
        $this->as($staff->json('access_token'))->getJson('/api/v1/auth/summaries/today')->assertOk();
        $this->assertSame($owner->id, $staff->json('user.id'));
    }

    #[Test]
    public function a_wrong_secret_never_says_which_half_was_wrong(): void
    {
        $sacco = $this->makeSacco();
        $this->crew($sacco, '254711000111', 'KDA001A');
        $manager = $this->role($this->makeUser([], $sacco), Roles::QUEUE_SUPERVISOR);

        $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'KDB999Z'])->assertStatus(401);
        $this->postJson(self::LOGIN, ['phone' => $manager->phone, 'secret' => 'not-it'])->assertStatus(401);
        $this->postJson(self::LOGIN, ['phone' => '0799999999', 'secret' => 'password'])->assertStatus(401);

        $passenger = $this->makeUser();
        $passenger->forceFill(['type' => UserType::Passenger])->save();
        $this->postJson(self::LOGIN, ['phone' => $passenger->phone, 'secret' => 'password'])
            ->assertForbidden()->assertJsonPath('reason', 'no_mobile_role');
    }

    #[Test]
    public function a_stage_manager_sees_each_bus_and_its_trip_money_at_their_stage_and_no_sacco_total(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00', 'UTC'));   // 12:00 in Nairobi
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['plate' => 'KDN 458N'])->save();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);

        $token = $this->postJson(self::LOGIN, ['phone' => $manager->phone, 'secret' => 'password'])->assertOk()
            ->assertJsonPath('staff_roles', ['stage_manager'])->json('access_token');

        $this->as($token)->getJson('/api/v1/auth/mobile/stage/buses')->assertStatus(409)->assertJsonPath('reason', 'no_stage');
        $stages = $this->as($token)->getJson('/api/v1/auth/mobile/stage')->assertOk()->json('stages');
        $this->assertContains($world['terminus']->id, array_column($stages, 'id'));

        $elsewhere = $this->makeWorld();
        $this->as($token)->putJson('/api/v1/auth/mobile/stage', ['terminus_id' => $elsewhere['terminus']->id])->assertStatus(422);
        $this->as($token)->putJson('/api/v1/auth/mobile/stage', ['terminus_id' => $world['terminus']->id])->assertOk();

        // The crew joined the line at 11:00 and departed; paid 200 at 11:30.
        // A 100 fare at 09:00 was this bus's, but before this trip.
        $trip = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active'), $world['owner'], 'QN-1', Queue::KIND_STAGE);
        $trip->forceFill(['start_time' => '2026-10-19 08:00:00', 'departed_at' => '2026-10-19 08:20:00'])->save();
        Transaction::withoutGlobalScopes()->create(['vehicle_id' => $world['vehicle']->id, 'amount' => 200, 'trans_date' => '2026-10-19 11:30:00']);
        Transaction::withoutGlobalScopes()->create(['vehicle_id' => $world['vehicle']->id, 'amount' => 100, 'trans_date' => '2026-10-19 09:00:00']);

        // Another SACCO's bus in the same stage's line is not theirs to see.
        $this->makeQueue($elsewhere['vehicle'], $world['terminus'], $elsewhere['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'), $elsewhere['owner'], 'QN-2', Queue::KIND_STAGE);

        $res = $this->as($token)->getJson('/api/v1/auth/mobile/stage/buses')->assertOk();

        $this->assertSame(['KDN 458N'], array_column($res->json('buses'), 'plate'));
        $this->assertSame('on_trip', $res->json('buses.0.state'));
        $this->assertEquals(200, $res->json('buses.0.trip.amount'));
        $this->assertSame(1, $res->json('buses.0.trip.payments'));
        $this->assertEquals(300, $res->json('buses.0.today_amount'));
        $this->assertArrayNotHasKey('totals', $res->json());
    }

    #[Test]
    public function a_bus_that_leaves_the_line_and_goes_live_is_one_trip_counted_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00', 'UTC'));
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);
        $manager->forceFill(['stage_terminus_id' => $world['terminus']->id])->save();
        $token = $this->postJson(self::LOGIN, ['phone' => $manager->phone, 'secret' => 'password'])->assertOk()->json('access_token');

        $active = $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active');
        $line = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner'], 'QN-1', Queue::KIND_STAGE);
        $line->forceFill(['start_time' => '2026-10-19 08:00:00', 'departed_at' => '2026-10-19 08:20:00'])->save();
        $run = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner'], 'LIVE', Queue::KIND_LIVE);
        $run->forceFill(['start_time' => '2026-10-19 08:20:00'])->save();
        Transaction::withoutGlobalScopes()->create(['vehicle_id' => $world['vehicle']->id, 'amount' => 70, 'trans_date' => '2026-10-19 11:10:00']);  // loading
        Transaction::withoutGlobalScopes()->create(['vehicle_id' => $world['vehicle']->id, 'amount' => 130, 'trans_date' => '2026-10-19 11:40:00']); // on the road

        $res = $this->as($token)->getJson('/api/v1/auth/mobile/stage/buses')->assertOk();

        $this->assertCount(1, $res->json('buses'));
        $this->assertEquals(200, $res->json('buses.0.trip.amount'), 'loading and the road, once');
        $this->assertSame(2, $res->json('buses.0.trip.payments'));
        $this->assertSame('live', $res->json('buses.0.trip.kind'));
        $this->assertNull($res->json('buses.0.previous_trip'));
    }

    #[Test]
    public function a_plate_session_sees_only_its_own_bus_and_only_the_crew_routes(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->role($this->crew($sacco, '254711000111', 'KDA001A'), Roles::INVESTOR);
        $owner->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('View Transactions', 'web'));
        // The same owner's second bus.
        $second = $this->makeVehicle($sacco, $owner, $this->makeSeat());
        $second->update(['plate' => 'KDB002B']);
        VehicleUser::create(['user_id' => $owner->id, 'vehicle_id' => $second->id, 'sacco_id' => $sacco->id, 'status' => true, 'start_date' => now()]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The old crew sign-in is a plate session too.
        $token = $this->postJson('/api/v1/auth/driver/login', ['phone' => '254711000111', 'plate' => 'KDA001A'])->assertOk()->json('access_token');

        $this->as($token)->getJson('/api/v1/auth/summaries/overview')->assertForbidden()->assertJsonPath('reason', 'crew_session');
        $this->as($token)->getJson('/api/v1/auth/transactions/export')->assertForbidden()->assertJsonPath('reason', 'crew_session');
        $this->as($token)->getJson('/api/v1/auth/dashboard')->assertForbidden();

        // The crew app's own screens still work -- for the bus signed in with.
        $this->as($token)->getJson('/api/v1/auth/driver/trip')->assertOk();
        $this->as($token)->getJson('/api/v1/auth/summaries')->assertOk();
    }

    #[Test]
    public function crew_can_type_their_password_and_get_the_crew_session_for_their_bus(): void
    {
        $sacco = $this->makeSacco();
        $driver = $this->crew($sacco, '254711000111', 'KDA001A');

        $res = $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'password'])->assertOk()
            ->assertJsonPath('session', 'crew')
            ->assertJsonPath('signed_in_with', 'password')
            ->assertJsonPath('vehicle.plate', 'KDA001A')
            ->assertJsonPath('user.id', $driver->id);

        // The same crew session as the plate: crew screens only.
        $this->as($res->json('access_token'))->getJson('/api/v1/auth/summaries/overview')->assertForbidden()->assertJsonPath('reason', 'crew_session');
        $this->as($res->json('access_token'))->getJson('/api/v1/auth/driver/trip')->assertOk();
    }

    #[Test]
    public function a_crew_password_from_the_older_app_works_too(): void
    {
        // Crew passwords set for the older app live on their Crew row.
        $sacco = $this->makeSacco();
        $driver = $this->crew($sacco, '254722000222', 'KDC003C');
        Crew::create([
            'firstname' => 'Jane', 'lastname' => 'Crew', 'id_number' => '12345678', 'badge_number' => 'B-1',
            'phone' => '254722000222', 'password' => Hash::make('crew-pass'), 'user_id' => $driver->id,
            'created_by' => $driver->id, 'status' => true,
        ]);

        $this->postJson(self::LOGIN, ['phone' => '0722000222', 'secret' => 'crew-pass'])->assertOk()
            ->assertJsonPath('session', 'crew')
            ->assertJsonPath('vehicle.plate', 'KDC003C');
    }

    #[Test]
    public function crew_with_no_bus_are_told_to_use_the_plate_and_office_staff_the_web(): void
    {
        $sacco = $this->makeSacco();
        $idle = $this->makeUser([], $sacco);
        $idle->forceFill(['type' => UserType::Driver, 'phone' => '254733000333'])->save();
        $this->postJson(self::LOGIN, ['phone' => '0733000333', 'secret' => 'password'])
            ->assertForbidden()->assertJsonPath('reason', 'no_assignment');

        $admin = $this->role($this->makeUser([], $sacco), Roles::SACCO_ADMIN);
        $this->postJson(self::LOGIN, ['phone' => $admin->phone, 'secret' => 'password'])
            ->assertForbidden()->assertJsonPath('reason', 'no_mobile_role');
        $this->assertSame(0, $admin->tokens()->count(), 'no session is issued for a role the app has no screens for');
    }

    #[Test]
    public function the_stage_screens_are_for_stage_managers(): void
    {
        $sacco = $this->makeSacco();
        $investor = $this->role($this->makeUser([], $sacco), Roles::INVESTOR);
        $token = $this->postJson(self::LOGIN, ['phone' => $investor->phone, 'secret' => 'password'])->assertOk()->json('access_token');

        $this->as($token)->getJson('/api/v1/auth/mobile/stage')->assertForbidden();
    }
}
