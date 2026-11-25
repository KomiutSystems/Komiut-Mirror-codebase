<?php

declare(strict_types=1);

namespace Tests\Feature\School;

use App\Models\Place;
use App\Models\Route;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFollower;
use App\Services\Location\VehicleLocationService;
use App\Services\School\FollowCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A parent finds their child's school bus by plate, follows it with the code
 * the school gave them, and sees it live -- and nobody else learns anything.
 *
 * The data is about children, so most of what is pinned here is what does NOT
 * happen: search never says where a bus is; a wrong code, an unknown bus and a
 * matatu are indistinguishable; the follow code never comes back out; a
 * position appears only for a bus the caller follows and only while it is
 * broadcasting. See docs/school-bus-tracking.md.
 */
final class ParentFollowsSchoolBusTest extends QueueTestCase
{
    private const BASE = '/api/v1/auth/school-buses';

    private const CODE = 'AB3K7PQX';

    private const NO_MATCH = ['error' => 'No school bus matches that plate and code.'];

    private ?User $owner = null;

    private ?Seat $seat = null;

    private function school(bool $active = true, string $brand = 'testing'): Sacco
    {
        $school = $this->makeSacco($active);
        $school->forceFill(['kind' => Sacco::KIND_SCHOOL, 'brand' => $brand])->save();

        return $school;
    }

    private function bus(Sacco $sacco, string $plate, ?string $code = self::CODE, bool $on = true): Vehicle
    {
        $this->owner ??= $this->makeUser();
        $this->seat ??= $this->makeSeat();

        $bus = $this->makeVehicle($sacco, $this->owner, $this->seat);
        // forceFill: follow_code is not fillable, and whatever the app issues
        // on create is replaced by a code this test knows.
        $bus->forceFill(['plate' => $plate, 'follow_code' => $code, 'status' => $on, 'brand' => $sacco->brand])->save();

        return $bus;
    }

    /** A route of the school's with a stop at $place. */
    private function schoolRoute(Sacco $school, Place $stop): Route
    {
        $route = $this->makeRoute($this->makePlace('Gate '.$this->nextSequence()), $this->makePlace('Campus '.$this->nextSequence()), $school);
        $this->makeRouteStage($route, $stop, 5);

        return $route;
    }

    private function follow(Vehicle|int|string $bus, array $body = []): TestResponse
    {
        $id = $bus instanceof Vehicle ? $bus->id : $bus;

        return $this->postJson(self::BASE."/{$id}/follow", $body + ['code' => self::CODE]);
    }

    private function following(): TestResponse
    {
        return $this->getJson(self::BASE.'/following')->assertOk();
    }

    private function followRow(User $parent, Vehicle $bus, array $extra = []): VehicleFollower
    {
        return VehicleFollower::create(['user_id' => $parent->id, 'vehicle_id' => $bus->id] + $extra);
    }

    private function assertNoCode(TestResponse $response, string $code = self::CODE): void
    {
        $this->assertStringNotContainsString($code, (string) $response->getContent(), 'the follow code must never leave through a parent endpoint');
    }

    // ---------------------------------------------------------------- search

    #[Test]
    public function search_finds_a_school_bus_by_plate_however_it_is_typed_and_says_nothing_else(): void
    {
        $school = $this->school();
        $bus = $this->bus($school, 'KCW 563E');
        $this->pingFrom($bus);
        $parent = $this->makeUser();
        Sanctum::actingAs($parent);

        foreach (['kcw563e', 'KCW-563E', ' kcw 563 e '] as $typed) {
            $res = $this->getJson(self::BASE.'/search?plate='.urlencode($typed))->assertOk();
            $this->assertSame([[
                'id' => $bus->id, 'plate' => 'KCW 563E',
                'school' => ['id' => $school->id, 'name' => $school->name], 'following' => false,
            ]], $res->json('buses'), "typed as '{$typed}'");
            $this->assertNoCode($res);
            $this->assertStringNotContainsString('latitude', (string) $res->getContent(), 'the bus is broadcasting, and search still says nothing about where');
        }

        // Exact, not contains: a fragment finds nothing.
        $this->getJson(self::BASE.'/search?plate=KCW')->assertOk()->assertExactJson(['buses' => []]);

        $this->followRow($parent, $bus);
        $this->getJson(self::BASE.'/search?plate=KCW563E')->assertOk()->assertJsonPath('buses.0.following', true);
    }

    #[Test]
    public function search_offers_only_buses_that_are_on_in_schools_that_are_on(): void
    {
        $matatuSacco = $this->makeSacco();
        $this->bus($matatuSacco, 'KDA 100A', null);
        $this->bus($this->school(active: false), 'KDA 200B');
        $this->bus($this->school(), 'KDA 300C', 'CODE3003', on: false);
        Sanctum::actingAs($this->makeUser());

        foreach (['KDA100A', 'KDA200B', 'KDA300C'] as $plate) {
            $this->getJson(self::BASE.'/search?plate='.$plate)->assertOk()->assertExactJson(['buses' => []]);
        }
    }

    #[Test]
    public function search_validates_the_plate(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson(self::BASE.'/search')->assertStatus(422)->assertJsonValidationErrors('plate');
        $this->getJson(self::BASE.'/search?plate=KC')->assertStatus(422)->assertJsonValidationErrors('plate');
        $this->getJson(self::BASE.'/search?plate='.str_repeat('K', 16))->assertStatus(422)->assertJsonValidationErrors('plate');
        // Punctuation only: valid length, normalises to nothing, matches nothing.
        $this->getJson(self::BASE.'/search?plate=---')->assertOk()->assertExactJson(['buses' => []]);
    }

    #[Test]
    public function a_parent_in_either_app_or_with_a_sacco_of_their_own_finds_and_follows_any_brands_school_bus(): void
    {
        $komiut = $this->bus($this->school(brand: 'komiut'), 'KBK 111K');
        $safiri = $this->bus($this->school(brand: 'safiri'), 'KBS 222S', 'SAFR2345');

        // A conductor at some matatu SACCO is also a parent: SaccoScope would
        // confine them to their own SACCO, and must not here.
        $staff = $this->makeUser([], $this->makeSacco());
        foreach ([$this->makeUser(), $staff] as $parent) {
            Sanctum::actingAs($parent);
            $this->getJson(self::BASE.'/search?plate=KBK111K')->assertOk()->assertJsonPath('buses.0.id', $komiut->id);
            $this->getJson(self::BASE.'/search?plate=KBS222S')->assertOk()->assertJsonPath('buses.0.id', $safiri->id);

            $this->follow($komiut)->assertCreated();
            $this->follow($safiri, ['code' => 'safr-2345'])->assertCreated();
            $this->assertEqualsCanonicalizing([$komiut->id, $safiri->id], array_column($this->following()->json('buses'), 'id'));
        }
    }

    // ---------------------------------------------------------------- follow

    #[Test]
    public function the_right_code_follows_the_bus_and_the_audit_log_records_the_bus_not_the_code(): void
    {
        $school = $this->school();
        $bus = $this->bus($school, 'KCW 563E');
        $parent = $this->makeUser();
        Sanctum::actingAs($parent);

        $res = $this->follow($bus, ['code' => 'ab3k-7pqx'])->assertCreated();
        $res->assertExactJson(['bus' => [
            'id' => $bus->id, 'plate' => 'KCW 563E',
            'school' => ['id' => $school->id, 'name' => $school->name],
            'stop' => null, 'live' => null, 'trip' => null,
            'channel' => 'private-school-bus.'.$bus->id,
            'followed_at' => $res->json('bus.followed_at'),
        ]]);
        $this->assertNotNull($res->json('bus.followed_at'));
        $this->assertNoCode($res);
        $this->assertSame(1, VehicleFollower::where('user_id', $parent->id)->where('vehicle_id', $bus->id)->count());

        $audit = DB::table('audit_logs')->where('action', 'school.bus.followed')->sole();
        $this->assertSame((string) $parent->id, $audit->actor_id);
        $this->assertSame((string) $bus->id, $audit->subject_id);
        $this->assertSame($school->id, (int) $audit->sacco_id);
        $this->assertStringNotContainsString(self::CODE, (string) $audit->data);
    }

    #[Test]
    public function a_wrong_code_an_unknown_bus_a_matatu_and_a_switched_off_school_all_get_the_same_404(): void
    {
        $bus = $this->bus($this->school(), 'KCW 563E');
        // A matatu that somehow carries a code is still not a school bus.
        $matatu = $this->bus($this->makeSacco(), 'KDA 100A', 'MTTU2345');
        $offSchool = $this->bus($this->school(active: false), 'KDA 200B', 'OFFS2345');
        Sanctum::actingAs($this->makeUser());

        $bodies = [
            $this->follow($bus, ['code' => 'WRONG234'])->assertNotFound()->getContent(),
            $this->follow(99999999)->assertNotFound()->getContent(),
            $this->follow($matatu, ['code' => 'MTTU2345'])->assertNotFound()->getContent(),
            $this->follow($offSchool, ['code' => 'OFFS2345'])->assertNotFound()->getContent(),
            // Longer than a bigint: still the same 404, not a database error.
            $this->follow('999999999999999999999999')->assertNotFound()->getContent(),
        ];

        foreach ($bodies as $body) {
            $this->assertSame(json_encode(self::NO_MATCH), $body);
        }
        $this->assertSame(0, VehicleFollower::count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'school.bus.followed')->count());
    }

    #[Test]
    public function following_again_is_200_keeps_one_row_and_updates_the_stop(): void
    {
        $bus = $this->bus($this->school(), 'KCW 563E');
        $parent = $this->makeUser();
        Sanctum::actingAs($parent);

        $this->follow($bus)->assertCreated();
        $this->follow($bus, ['stop_latitude' => -1.18, 'stop_longitude' => 36.92])->assertOk()
            ->assertJsonPath('bus.stop', ['place_id' => null, 'name' => null, 'latitude' => -1.18, 'longitude' => 36.92]);

        $this->assertSame(1, VehicleFollower::where('user_id', $parent->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'school.bus.followed')->count(), 'a re-follow is not a new follow');
    }

    #[Test]
    public function a_family_not_a_fleet_the_follow_after_the_maximum_is_refused(): void
    {
        $school = $this->school();
        $parent = $this->makeUser();
        for ($i = 0; $i < VehicleFollower::MAX_PER_USER; $i++) {
            $this->followRow($parent, $this->bus($school, 'KSB '.(100 + $i).'S', 'CODE'.(2000 + $i)));
        }
        $one = $this->bus($school, 'KCW 563E');
        Sanctum::actingAs($parent);

        $this->follow($one)->assertStatus(422)
            ->assertExactJson(['error' => 'You can follow at most '.VehicleFollower::MAX_PER_USER.' buses. Unfollow one first.']);
        $this->assertSame(VehicleFollower::MAX_PER_USER, VehicleFollower::where('user_id', $parent->id)->count());
    }

    #[Test]
    public function a_stop_must_be_on_the_schools_own_route_and_only_one_kind_of_stop(): void
    {
        $school = $this->school();
        $bus = $this->bus($school, 'KCW 563E');
        $theirs = $this->makePlace('Other school stop');
        $this->schoolRoute($this->school(), $theirs);
        Sanctum::actingAs($this->makeUser());

        // A wrong code says nothing about the stop, even an invalid one.
        $this->follow($bus, ['code' => 'WRONG234', 'stop_place_id' => $theirs->id])->assertNotFound()->assertExactJson(self::NO_MATCH);

        $this->follow($bus, ['stop_place_id' => $theirs->id])->assertStatus(422)
            ->assertExactJson(['errors' => ['stop_place_id' => ["That stop is not on this school's routes."]]]);
        $this->follow($bus, ['stop_place_id' => $theirs->id, 'stop_latitude' => -1.2, 'stop_longitude' => 36.9])
            ->assertStatus(422)->assertJsonValidationErrors('stop');
        $this->follow($bus, ['stop_latitude' => -1.2])->assertStatus(422)->assertJsonValidationErrors('stop_longitude');
        $this->follow($bus, ['stop_latitude' => 51.5, 'stop_longitude' => -0.12])->assertStatus(422)->assertJsonValidationErrors(['stop_latitude', 'stop_longitude']);

        $this->assertSame(0, VehicleFollower::count());
    }

    #[Test]
    public function a_stop_can_be_a_pin_or_a_stop_on_the_schools_route(): void
    {
        $school = $this->school();
        $bus = $this->bus($school, 'KCW 563E');
        $stop = $this->makePlace('Kahawa West');
        $this->schoolRoute($school, $stop);

        Sanctum::actingAs($this->makeUser());
        $this->follow($bus, ['stop_latitude' => '-1.18', 'stop_longitude' => '36.92'])->assertCreated()
            ->assertJsonPath('bus.stop', ['place_id' => null, 'name' => null, 'latitude' => -1.18, 'longitude' => 36.92]);

        Sanctum::actingAs($this->makeUser());
        $this->follow($bus, ['stop_place_id' => $stop->id])->assertCreated()
            ->assertJsonPath('bus.stop', ['place_id' => $stop->id, 'name' => 'Kahawa West', 'latitude' => -1.28, 'longitude' => 36.8]);

        // A route linked through the older sacco_routes pivot counts too.
        $linkedStop = $this->makePlace('Githurai');
        $linked = $this->schoolRoute($this->makeSacco(), $linkedStop);
        $this->makeSaccoRoute($school, $linked, $this->makeUser());
        Sanctum::actingAs($this->makeUser());
        $this->follow($bus, ['stop_place_id' => $linkedStop->id])->assertCreated()->assertJsonPath('bus.stop.place_id', $linkedStop->id);
    }

    #[Test]
    public function the_sixth_follow_attempt_in_a_minute_is_throttled(): void
    {
        $bus = $this->bus($this->school(), 'KCW 563E');
        Sanctum::actingAs($this->makeUser());

        for ($i = 0; $i < 5; $i++) {
            $this->follow($bus, ['code' => 'GUESS'.$i])->assertNotFound();
        }
        $this->follow($bus)->assertStatus(429);
        $this->assertSame(0, VehicleFollower::count(), 'not even the right code gets through the throttle');
    }

    // ------------------------------------------------------ stop and unfollow

    #[Test]
    public function a_parent_changes_and_clears_their_stop(): void
    {
        $school = $this->school();
        $bus = $this->bus($school, 'KCW 563E');
        $stop = $this->makePlace('Kahawa West');
        $this->schoolRoute($school, $stop);
        $parent = $this->makeUser();
        $this->followRow($parent, $bus, ['last_alerted_queue_id' => 55, 'last_alerted_at' => now()]);
        Sanctum::actingAs($parent);
        $url = self::BASE."/{$bus->id}/follow";

        $this->patchJson($url, ['stop_place_id' => $stop->id])->assertOk()->assertJsonPath('bus.stop.place_id', $stop->id);
        $this->assertNull(VehicleFollower::sole()->last_alerted_queue_id, 'a new stop gets its own alert');

        $this->patchJson($url, ['stop_latitude' => -1.18, 'stop_longitude' => 36.92])->assertOk()
            ->assertJsonPath('bus.stop', ['place_id' => null, 'name' => null, 'latitude' => -1.18, 'longitude' => 36.92]);
        $this->assertNull(VehicleFollower::sole()->stop_place_id);

        $res = $this->patchJson($url, ['stop' => null])->assertOk()->assertJsonPath('bus.stop', null);
        $this->assertNoCode($res);
        $row = VehicleFollower::sole();
        $this->assertNull($row->stop_latitude);
        $this->assertNull($row->stop_place_id);

        $this->patchJson($url, [])->assertStatus(422)->assertJsonValidationErrors('stop');
        $this->patchJson($url, ['stop' => 'home'])->assertStatus(422)->assertJsonValidationErrors('stop');
        $this->patchJson($url, ['stop_place_id' => $this->makePlace('Elsewhere')->id])->assertStatus(422)->assertJsonValidationErrors('stop_place_id');
    }

    #[Test]
    public function a_stop_cannot_be_set_on_a_bus_the_parent_does_not_follow(): void
    {
        $bus = $this->bus($this->school(), 'KCW 563E');
        $this->followRow($this->makeUser(), $bus);
        Sanctum::actingAs($this->makeUser());

        $this->patchJson(self::BASE."/{$bus->id}/follow", ['stop' => null])->assertNotFound()
            ->assertExactJson(['error' => 'You are not following this bus.']);
        $this->patchJson(self::BASE.'/99999999/follow', ['stop' => null])->assertNotFound();
    }

    #[Test]
    public function unfollowing_removes_only_the_callers_own_follow_and_is_audited(): void
    {
        $bus = $this->bus($this->school(), 'KCW 563E');
        $parent = $this->makeUser();
        $other = $this->makeUser();
        $this->followRow($parent, $bus);
        $this->followRow($other, $bus);
        Sanctum::actingAs($parent);

        $this->deleteJson(self::BASE."/{$bus->id}/follow")->assertNoContent();
        $this->deleteJson(self::BASE."/{$bus->id}/follow")->assertNotFound()->assertExactJson(['error' => 'You are not following this bus.']);

        $this->assertSame([$other->id], VehicleFollower::pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $audit = DB::table('audit_logs')->where('action', 'school.bus.unfollowed')->sole();
        $this->assertSame((string) $parent->id, $audit->actor_id);
        $this->assertSame((string) $bus->id, $audit->subject_id);
    }

    // ----------------------------------------------------------------- index

    #[Test]
    public function the_followed_list_shows_a_bus_live_only_while_it_broadcasts_and_its_trip_only_while_open(): void
    {
        $school = $this->school();
        $active = $this->makeQueueStatus('Active', 'Active');
        $completed = $this->makeQueueStatus('Completed', 'Completed');
        $stopPlace = $this->makePlace('Kahawa West');
        $route = $this->schoolRoute($school, $stopPlace);
        $terminus = $this->makeTerminus($stopPlace);

        $live = $this->bus($school, 'KSB 001A');
        $stale = $this->bus($school, 'KSB 002B', 'CODE2002');
        $ended = $this->bus($school, 'KSB 003C', 'CODE2003');
        $otherFollowed = $this->bus($school, 'KSB 004D', 'CODE2004');

        $run = $this->makeQueue($live, $terminus, $route, $active, $this->owner);
        $this->pingFrom($live, $run, ageSeconds: 10);
        $this->pingFrom($stale, $this->makeQueue($stale, $terminus, $route, $active, $this->owner), ageSeconds: VehicleLocationService::FRESH_SECONDS + 5);
        $this->pingFrom($ended, $this->makeQueue($ended, $terminus, $route, $completed, $this->owner), ageSeconds: 5);

        $parent = $this->makeUser();
        $this->followRow($parent, $live, ['stop_place_id' => $stopPlace->id]);
        $this->followRow($parent, $stale);
        $this->followRow($parent, $ended);
        $this->followRow($this->makeUser(), $otherFollowed);
        Sanctum::actingAs($parent);

        $res = $this->following();
        $this->assertNoCode($res);
        $buses = collect($res->json('buses'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$live->id, $stale->id, $ended->id], $buses->keys()->all(), 'only my own follows');

        $first = $buses[$live->id];
        $this->assertSame(['id', 'plate', 'school', 'stop', 'live', 'trip', 'channel', 'followed_at'], array_keys($first));
        $this->assertSame(['id' => $school->id, 'name' => $school->name], $first['school']);
        $this->assertSame(['place_id' => $stopPlace->id, 'name' => 'Kahawa West', 'latitude' => -1.28, 'longitude' => 36.8], $first['stop']);
        $this->assertSame(['latitude', 'longitude', 'heading', 'speed_kmh', 'recorded_at', 'age_seconds', 'distance_to_stop_m', 'eta_to_stop_seconds'], array_keys($first['live']));
        $this->assertEqualsWithDelta(-1.28, $first['live']['latitude'], 0.0001);
        $this->assertGreaterThanOrEqual(9, $first['live']['age_seconds']);
        $this->assertSame(['queue_id' => $run->id, 'route' => ['id' => $route->id, 'name' => $route->name]], $first['trip']);
        $this->assertSame('private-school-bus.'.$live->id, $first['channel']);

        $this->assertNull($buses[$stale->id]['live'], 'quiet past the live window: no position');
        $this->assertNull($buses[$stale->id]['trip']);
        $this->assertNotNull($buses[$ended->id]['live']);
        $this->assertNull($buses[$ended->id]['trip'], 'its run is over');
    }

    #[Test]
    public function a_bus_whose_school_is_switched_off_or_that_is_off_drops_out_of_the_list_but_the_follow_is_kept(): void
    {
        $school = $this->school();
        $bus = $this->bus($school, 'KSB 001A');
        $off = $this->bus($this->school(), 'KSB 002B', 'CODE2002');
        $parent = $this->makeUser();
        $this->followRow($parent, $bus);
        $this->followRow($parent, $off);
        Sanctum::actingAs($parent);
        $this->assertCount(2, $this->following()->json('buses'));

        $school->forceFill(['status' => 0])->save();
        $off->forceFill(['status' => false])->save();

        $this->following()->assertExactJson(['buses' => []]);
        $this->assertSame(2, VehicleFollower::count());

        // Turned back into a matatu SACCO: not a school bus, not shown.
        $school->forceFill(['status' => 1, 'kind' => Sacco::KIND_SACCO])->save();
        $this->following()->assertExactJson(['buses' => []]);
    }

    #[Test]
    public function the_followed_list_costs_the_same_queries_for_one_bus_as_for_four(): void
    {
        $school = $this->school();
        $active = $this->makeQueueStatus('Active', 'Active');
        $stop = $this->makePlace('Kahawa West');
        $route = $this->schoolRoute($school, $stop);
        $terminus = $this->makeTerminus($stop);

        $makeFollowed = function (User $parent, int $n) use ($school, $active, $route, $terminus, $stop): void {
            for ($i = 0; $i < $n; $i++) {
                $bus = $this->bus($school, 'KQC '.$this->nextSequence().'Q', 'CODE'.(3000 + $this->nextSequence()));
                $this->pingFrom($bus, $this->makeQueue($bus, $terminus, $route, $active, $this->owner));
                // Half by stop, half by pin, so both paths are in the count.
                $this->followRow($parent, $bus, $i % 2 === 0 ? ['stop_place_id' => $stop->id] : ['stop_latitude' => -1.2, 'stop_longitude' => 36.9]);
            }
        };

        $one = $this->makeUser();
        $four = $this->makeUser();
        $makeFollowed($one, 1);
        $makeFollowed($four, 4);

        $count = function (User $parent, int $expected): int {
            Sanctum::actingAs($parent);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $buses = $this->following()->json('buses');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertCount($expected, $buses);
            $this->assertNotNull($buses[0]['trip']);

            return $queries;
        };

        $this->assertSame($count($one, 1), $count($four, 4), 'the list is batch-loaded, not one round trip per bus');
    }

    #[Test]
    public function the_follow_code_never_comes_back_from_any_parent_endpoint(): void
    {
        $school = $this->school();
        $bus = $this->bus($school, 'KCW 563E');
        $this->pingFrom($bus);
        Sanctum::actingAs($this->makeUser());

        foreach ([
            $this->getJson(self::BASE.'/search?plate=KCW563E'),
            $this->follow($bus),
            $this->follow($bus),
            $this->following(),
            $this->patchJson(self::BASE."/{$bus->id}/follow", ['stop_latitude' => -1.2, 'stop_longitude' => 36.9]),
            $this->deleteJson(self::BASE."/{$bus->id}/follow"),
        ] as $response) {
            $this->assertLessThan(300, $response->getStatusCode());
            $this->assertNoCode($response);
        }
    }

    #[Test]
    public function a_follower_gets_the_schools_stops_and_nobody_else_does(): void
    {
        $world = $this->makeWorld();
        $world['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();
        FollowCode::issue($world['vehicle']);
        $world['route']->forceFill(['sacco_id' => $world['sacco']->id])->save();
        $parent = $this->makeUser();
        VehicleFollower::create(['user_id' => $parent->id, 'vehicle_id' => $world['vehicle']->id]);

        Sanctum::actingAs($parent);
        $stops = $this->getJson('/api/v1/auth/school-buses/'.$world['vehicle']->id.'/stops')->assertOk()->json('stops');
        $this->assertContains((int) $world['from']->id, array_column($stops, 'place_id'));
        $this->assertSame(['place_id', 'name', 'latitude', 'longitude'], array_keys($stops[0]));

        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/auth/school-buses/'.$world['vehicle']->id.'/stops')
            ->assertNotFound()->assertJsonPath('error', 'You are not following this bus.');
    }
}
