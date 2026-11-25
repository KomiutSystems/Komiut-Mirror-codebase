<?php

declare(strict_types=1);

namespace Tests\Feature\Buses;

use App\Brands\BrandRegistry;
use App\Enums\UserType;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Buses\SavedBuses;
use App\Services\Location\VehicleLocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Saved buses: any passenger finds a bus by plate and reads where it is by the
 * key search gave (docs/school-bus-tracking.md, "Saved buses").
 *
 * Open by plate -- a product decision -- so what is pinned here is the rest:
 * the plate is the only way in (a bare or guessed id reads nothing); only
 * buses in service; a matatu only from its own brand's app, as on the nearby
 * map; a school bus from either; a position only while it is live; the exact
 * fields and nothing else; and a cost that does not grow with the list.
 */
final class SavedBusesTest extends QueueTestCase
{
    private const SEARCH = '/api/v1/auth/buses/search';

    private const LIVE = '/api/v1/auth/buses/live';

    /** The second app: same database, its own brand (see TestCase::setUp). */
    private const OTHER_APP_KEY = 'other-app-key';

    private const BUS_KEYS = ['id', 'key', 'plate', 'fleet_no', 'operator'];

    private const LIVE_BUS_KEYS = ['id', 'key', 'plate', 'fleet_no', 'operator', 'live', 'trip', 'channel'];

    private const OPERATOR_KEYS = ['id', 'name', 'kind'];

    private const POSITION_KEYS = ['latitude', 'longitude', 'heading', 'speed_kmh', 'recorded_at', 'age_seconds'];

    private ?User $owner = null;

    private ?Seat $seat = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['brands.other' => [
            'name' => 'Other',
            'connection' => config('database.default'),
            'hosts' => [],
            'app_key' => self::OTHER_APP_KEY,
            'features' => ['parcels' => true],
        ]]);
        $this->app->forgetInstance(BrandRegistry::class);
    }

    private function operator(string $kind = Sacco::KIND_SACCO, string $brand = 'testing', bool $active = true): Sacco
    {
        $sacco = $this->makeSacco($active);
        $sacco->forceFill(['kind' => $kind, 'brand' => $brand])->save();

        return $sacco;
    }

    private function bus(Sacco $operator, string $plate, bool $on = true): Vehicle
    {
        $this->owner ??= $this->makeUser();
        $this->seat ??= $this->makeSeat();

        $bus = $this->makeVehicle($operator, $this->owner, $this->seat);
        $bus->forceFill(['plate' => $plate, 'status' => $on, 'brand' => $operator->brand])->save();

        return $bus;
    }

    /** A bus's key, as search hands it out; an int names a bus that may not exist. */
    private function k(Vehicle|int $bus): string
    {
        return SavedBuses::key($bus instanceof Vehicle ? $bus->id : $bus);
    }

    /**
     * @param  list<Vehicle|int|string>  $buses  a bus or an id (its key is sent), or a raw key
     */
    private function live(array $buses, bool $otherApp = false): TestResponse
    {
        $keys = array_map(fn ($b) => is_string($b) ? $b : $this->k($b), $buses);

        // Headers per call: withHeaders() would stick to every later request.
        return $this->getJson(self::LIVE.'?keys='.implode(',', $keys), $otherApp ? ['X-App-Key' => self::OTHER_APP_KEY] : []);
    }

    private function search(string $plate, bool $otherApp = false): TestResponse
    {
        return $this->getJson(self::SEARCH.'?plate='.urlencode($plate), $otherApp ? ['X-App-Key' => self::OTHER_APP_KEY] : []);
    }

    private function liveRun(Vehicle $bus, string $statusName = 'Active'): Queue
    {
        $route = $this->makeRoute($this->makePlace('From '.$this->nextSequence()), $this->makePlace('To '.$this->nextSequence()));
        $route->forceFill(['name' => 'Morning run '.$this->nextSequence()])->save();

        return $this->makeQueue($bus, $this->makeTerminus($this->makePlace('Stage '.$this->nextSequence())), $route,
            $this->makeQueueStatus($statusName.' '.$this->nextSequence(), $statusName), $this->owner ?? $this->makeUser());
    }

    // ---------------------------------------------------------------- search

    #[Test]
    public function search_finds_a_bus_by_plate_however_it_is_typed_and_says_nothing_else(): void
    {
        $operator = $this->operator();
        $bus = $this->bus($operator, 'KSB 101A');
        $bus->forceFill(['fleet_no' => 'GB1'])->save();
        $this->pingFrom($bus);
        Sanctum::actingAs($this->makeUser());

        foreach (['ksb101a', 'KSB-101A', ' ksb 101 a '] as $typed) {
            $res = $this->search($typed)->assertOk();
            $this->assertSame(['buses'], array_keys($res->json()));
            $this->assertSame([[
                'id' => $bus->id, 'key' => $this->k($bus), 'plate' => 'KSB 101A', 'fleet_no' => 'GB1',
                'operator' => ['id' => $operator->id, 'name' => $operator->name, 'kind' => 'sacco'],
            ]], $res->json('buses'), "typed as '{$typed}'");
            $this->assertStringNotContainsString('latitude', (string) $res->getContent(), 'search never says where');
        }

        // Exact, not contains: a fragment finds nothing.
        $this->search('KSB')->assertOk()->assertExactJson(['buses' => []]);
        $this->search('KZZ 999Z')->assertOk()->assertExactJson(['buses' => []]);
    }

    #[Test]
    public function search_validates_the_plate(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson(self::SEARCH)->assertStatus(422)->assertJsonValidationErrors('plate');
        $this->search('KS')->assertStatus(422)->assertJsonValidationErrors('plate');
        $this->search(str_repeat('K', 16))->assertStatus(422)->assertJsonValidationErrors('plate');
        $this->getJson(self::SEARCH.'?plate[]=KSB101A')->assertStatus(422)->assertJsonValidationErrors('plate');
        // Punctuation only: a valid length, normalises to nothing, matches nothing.
        $this->search('---')->assertOk()->assertExactJson(['buses' => []]);
    }

    // ---------------------------------------------------------------- brands

    #[Test]
    public function a_school_bus_is_found_and_read_from_either_app(): void
    {
        $here = $this->bus($this->operator(Sacco::KIND_SCHOOL), 'KSA 111A');
        $there = $this->bus($this->operator(Sacco::KIND_SCHOOL, 'other'), 'KSB 222B');

        // A SACCO's conductor is also a parent: their own SACCO must not
        // confine what they can save.
        foreach ([$this->makeUser(), $this->makeUser([], $this->operator())] as $user) {
            Sanctum::actingAs($user);
            foreach ([false, true] as $otherApp) {
                $this->search('KSA111A', $otherApp)->assertOk()->assertJsonPath('buses.0.id', $here->id)->assertJsonPath('buses.0.operator.kind', 'school');
                $this->search('KSB222B', $otherApp)->assertOk()->assertJsonPath('buses.0.id', $there->id);

                $res = $this->live([$here, $there], $otherApp)->assertOk();
                $this->assertSame([$here->id, $there->id], array_column($res->json('buses'), 'id'));
                $this->assertSame([], $res->json('missing'));
            }
        }
    }

    #[Test]
    public function a_matatu_is_seen_only_from_its_own_brands_app(): void
    {
        $ours = $this->bus($this->operator(), 'KDA 100A');
        $otherSacco = $this->operator(brand: 'other');
        $theirs = $this->bus($otherSacco, 'KDB 200B');
        $this->pingFrom($theirs);

        Sanctum::actingAs($this->makeUser());
        $this->search('KDB200B')->assertOk()->assertExactJson(['buses' => []]);
        $res = $this->live([$ours, $theirs])->assertOk();
        $this->assertSame([$ours->id], array_column($res->json('buses'), 'id'));
        $this->assertSame([$this->k($theirs)], $res->json('missing'));
        $this->assertStringNotContainsString('KDB 200B', (string) $res->getContent());

        // ...and the other way round.
        $this->search('KDB200B', otherApp: true)->assertOk()->assertJsonPath('buses.0.id', $theirs->id);
        $res = $this->live([$ours, $theirs], otherApp: true)->assertOk();
        $this->assertSame([$theirs->id], array_column($res->json('buses'), 'id'));
        $this->assertSame([$this->k($ours)], $res->json('missing'));

        // As on the nearby map: a member of the bus's own SACCO sees it from
        // either app, and a superadmin sees everything.
        $member = $this->makeUser([], $otherSacco);
        $super = $this->makeUser();
        $super->forceFill(['type' => UserType::Superadmin])->save();
        foreach ([$member, $super] as $user) {
            Sanctum::actingAs($user);
            $this->search('KDB200B')->assertOk()->assertJsonPath('buses.0.id', $theirs->id);
            $this->live([$theirs])->assertOk()->assertJsonPath('buses.0.id', $theirs->id);
        }
        // The member's own SACCO is the only exception: another brand's
        // matatu in some other SACCO is still not theirs to see.
        $stranger = $this->bus($this->operator(brand: 'other'), 'KDC 300C');
        Sanctum::actingAs($member);
        $this->search('KDC300C')->assertOk()->assertExactJson(['buses' => []]);
        $this->live([$stranger])->assertOk()->assertExactJson(['buses' => [], 'missing' => [$this->k($stranger)]]);
    }

    #[Test]
    public function a_bank_sees_across_brands_only_the_buses_it_financed_as_on_nearby(): void
    {
        $financed = $this->bus($this->operator(brand: 'other'), 'KDK 100K');
        $notOurs = $this->bus($this->operator(brand: 'other'), 'KDK 200K');
        $financed->forceFill(['financier' => 'coop-bank'])->save();
        $notOurs->forceFill(['financier' => 'NCBA'])->save();
        $bank = $this->makeUser();
        $bank->forceFill(['financier' => 'coop-bank'])->save();
        Sanctum::actingAs($bank);

        // Nearby lets a bank past the brand for the buses FinancierScope
        // leaves it: its own. So does this.
        $this->search('KDK100K')->assertOk()->assertJsonPath('buses.0.id', $financed->id);
        $this->live([$financed])->assertOk()->assertJsonPath('buses.0.id', $financed->id);

        // Another bank's bus in the other brand stays behind the wall.
        $this->search('KDK200K')->assertOk()->assertExactJson(['buses' => []]);
        $this->live([$notOurs])->assertOk()->assertExactJson(['buses' => [], 'missing' => [$this->k($notOurs)]]);

    }

    #[Test]
    public function only_buses_in_service_are_found_or_read(): void
    {
        $offBus = $this->bus($this->operator(), 'KDE 100E', on: false);
        $offSacco = $this->bus($this->operator(active: false), 'KDE 200E');
        $offSchool = $this->bus($this->operator(Sacco::KIND_SCHOOL, active: false), 'KDE 300E');
        $noOperator = $this->bus($this->operator(), 'KDE 400E');
        $noOperator->forceFill(['sacco_id' => null])->save();
        foreach ([$offBus, $offSacco, $offSchool, $noOperator] as $bus) {
            $this->pingFrom($bus);
        }
        Sanctum::actingAs($this->makeUser());

        foreach (['KDE100E', 'KDE200E', 'KDE300E', 'KDE400E'] as $plate) {
            $this->search($plate)->assertOk()->assertExactJson(['buses' => []]);
        }
        $buses = [$offBus, $offSacco, $offSchool, $noOperator, 99999999];
        $this->live($buses)->assertOk()->assertExactJson(['buses' => [], 'missing' => array_map(fn ($b) => $this->k($b), $buses)]);
    }

    // ---------------------------------------------------------------- live

    #[Test]
    public function live_is_the_position_only_while_the_bus_is_broadcasting_fresh(): void
    {
        $bus = $this->bus($this->operator(Sacco::KIND_SCHOOL), 'KSC 101C');
        Sanctum::actingAs($this->makeUser());

        // Never pinged.
        $this->live([$bus])->assertOk()->assertJsonPath('buses.0.live', null)->assertJsonPath('buses.0.trip', null);

        // Fresh.
        $location = $this->pingFrom($bus, ageSeconds: 30);
        $location->forceFill(['speed_kmh' => 28.5, 'heading' => 74])->save();
        $res = $this->live([$bus])->assertOk();
        $live = $res->json('buses.0.live');
        $this->assertSame(self::POSITION_KEYS, array_keys($live));
        $this->assertSame(-1.28, $live['latitude']);
        $this->assertSame(36.82, $live['longitude']);
        $this->assertSame(74, $live['heading']);
        $this->assertSame(28.5, $live['speed_kmh']);
        $this->assertSame($location->fresh()->recorded_at->toIso8601String(), $live['recorded_at']);
        $this->assertEqualsWithDelta(30, $live['age_seconds'], 2);
        $this->assertSame($this->k($bus), $res->json('buses.0.key'));
        $this->assertSame('private-bus.'.$this->k($bus), $res->json('buses.0.channel'));

        // Speed unknown yet: null, not zero.
        $location->forceFill(['speed_kmh' => null])->save();
        $this->live([$bus])->assertOk()->assertJsonPath('buses.0.live.speed_kmh', null);

        // Stale: no position at all, not an old one.
        $this->pingFrom($bus, ageSeconds: VehicleLocationService::FRESH_SECONDS + 5);
        $stale = $this->live([$bus])->assertOk();
        $stale->assertJsonPath('buses.0.live', null);
        $this->assertStringNotContainsString('latitude', (string) $stale->getContent());

        // Fresh, but the driver stopped broadcasting.
        $this->pingFrom($bus)->forceFill(['broadcasting' => false])->save();
        $this->live([$bus])->assertOk()->assertJsonPath('buses.0.live', null);
    }

    #[Test]
    public function the_trip_is_the_buses_own_open_live_run(): void
    {
        $bus = $this->bus($this->operator(), 'KDF 100F');
        $run = $this->liveRun($bus);
        $this->pingFrom($bus, $run);
        Sanctum::actingAs($this->makeUser());

        $this->live([$bus])->assertOk()->assertJsonPath('buses.0.trip', [
            'queue_id' => $run->id,
            'route' => ['id' => $run->route_id, 'name' => $run->route->name],
        ]);

        // A run that has ended is no trip.
        $ended = $this->liveRun($bus, 'Completed');
        $this->pingFrom($bus, $ended);
        $this->live([$bus])->assertOk()->assertJsonPath('buses.0.trip', null)->assertJsonPath('buses.0.live.latitude', -1.28);

        // Another bus's run is never shown on this one.
        $other = $this->bus($this->operator(), 'KDF 200F');
        $this->pingFrom($bus, $this->liveRun($other));
        $this->live([$bus])->assertOk()->assertJsonPath('buses.0.trip', null);

        // A stale fix carries no trip either.
        $this->pingFrom($bus, $run, ageSeconds: VehicleLocationService::FRESH_SECONDS + 5);
        $this->live([$bus])->assertOk()->assertJsonPath('buses.0.trip', null);
    }

    #[Test]
    public function live_answers_in_the_order_asked_and_collapses_repeats(): void
    {
        $a = $this->bus($this->operator(), 'KDG 100G');
        $b = $this->bus($this->operator(), 'KDG 200G');
        Sanctum::actingAs($this->makeUser());

        $res = $this->live([$b, $a, $b, 99999998, 99999998])->assertOk();
        $this->assertSame([$b->id, $a->id], array_column($res->json('buses'), 'id'));
        $this->assertSame([$this->k(99999998)], $res->json('missing'));

        // keys[] works too, a trailing comma is not an error, and a key in
        // capitals is the same key.
        $this->getJson(self::LIVE.'?keys[]='.$this->k($a).'&keys[]='.$this->k($b))->assertOk()->assertJsonCount(2, 'buses');
        $this->getJson(self::LIVE.'?keys='.$this->k($a).',')->assertOk()->assertJsonCount(1, 'buses');
        $this->getJson(self::LIVE.'?keys='.strtoupper($this->k($a)))->assertOk()->assertJsonPath('buses.0.key', $this->k($a));

        // 25 entries, 20 distinct: fine.
        $twenty = range(1000, 1019);
        $this->live(array_merge($twenty, array_slice($twenty, 0, 5)))->assertOk()->assertJsonCount(20, 'missing');
    }

    #[Test]
    public function only_the_plate_leads_to_a_bus_never_a_guessed_id(): void
    {
        $school = $this->bus($this->operator(Sacco::KIND_SCHOOL), 'KSE 100E');
        $this->pingFrom($school);
        Sanctum::actingAs($this->makeUser());

        // The bare id -- the old shape -- reads nothing.
        $this->getJson(self::LIVE.'?ids='.$school->id)->assertStatus(422);
        $this->getJson(self::LIVE.'?keys='.$school->id)->assertStatus(422)->assertJsonValidationErrors('keys');

        // A key-shaped guess, or a real key with its id swapped, is just missing.
        $real = $this->k($school);
        $guess = $school->id.'-'.str_repeat('0', 20);
        $swapped = ($school->id + 1).substr($real, strlen((string) $school->id));
        $this->live([$guess, $swapped])->assertOk()->assertExactJson(['buses' => [], 'missing' => [$guess, $swapped]]);

        // Walking the ids with made-up signatures finds no school bus.
        $walk = array_map(fn (int $id) => $id.'-'.substr(sha1((string) $id), 0, 20), range($school->id - 10, $school->id + 9));
        $this->assertSame([], $this->live($walk)->assertOk()->json('buses'));

        // The plate is the way in, and its key is stable.
        $this->search('KSE100E')->assertOk()->assertJsonPath('buses.0.key', $real);
        $this->live([$real])->assertOk()->assertJsonPath('buses.0.live.latitude', -1.28);
        $this->assertSame($real, SavedBuses::key($school->id));
        $this->assertSame($school->id, SavedBuses::idFromKey($real));
        $this->assertNull(SavedBuses::idFromKey($guess));
    }

    #[Test]
    public function live_validates_the_keys(): void
    {
        Sanctum::actingAs($this->makeUser());
        $key = $this->k(1);

        foreach ([
            self::LIVE,
            self::LIVE.'?keys=',
            self::LIVE.'?keys=,',
            self::LIVE.'?keys='.implode(',', array_map(fn (int $i) => $this->k($i), range(1, 21))),
            self::LIVE.'?keys='.$key.',abc',
            self::LIVE.'?keys=1',
            self::LIVE.'?keys=0-'.str_repeat('a', 20),
            self::LIVE.'?keys=-1-'.str_repeat('a', 20),
            self::LIVE.'?keys=1-'.str_repeat('a', 19),
            self::LIVE.'?keys=1-'.str_repeat('g', 20),
            self::LIVE.'?keys='.str_repeat('9', 19).'-'.str_repeat('a', 20),
            self::LIVE.'?keys[0][]='.$key,
            self::LIVE.'?keys='.implode(',', array_fill(0, 101, $key)),
        ] as $url) {
            $res = $this->getJson($url)->assertStatus(422);
            $this->assertSame(['errors'], array_keys($res->json()), $url);
            $this->assertSame(['keys'], array_keys($res->json('errors')), $url);
        }
    }

    #[Test]
    public function polling_live_never_locks_the_passenger_out_of_search(): void
    {
        $bus = $this->bus($this->operator(), 'KDL 100L');
        Sanctum::actingAs($this->makeUser());

        // A saved-buses screen polling every 2 s: 30 a minute, well inside
        // live's own 120.
        for ($i = 0; $i < 30; $i++) {
            $this->live([$bus])->assertOk();
        }
        $this->search('KDL100L')->assertOk()->assertJsonPath('buses.0.id', $bus->id);

        // Search still has all 30 of its own: the lives counted elsewhere.
        // (60 calls in all, which is also the whole API's per-user minute,
        // the `api` limiter, so this is as far as one minute goes.)
        for ($i = 1; $i < 30; $i++) {
            $this->search('KDL100L')->assertOk();
        }
    }

    #[Test]
    public function both_need_a_signed_in_user(): void
    {
        $this->getJson(self::SEARCH.'?plate=KSB101A')->assertUnauthorized();
        $this->getJson(self::LIVE.'?keys='.$this->k(1))->assertUnauthorized();
    }

    // ---------------------------------------------------------------- fields

    #[Test]
    public function nothing_leaves_but_the_listed_fields(): void
    {
        $operator = $this->operator();
        $operator->forceFill(['phone' => '0722333444'])->save();
        $bus = $this->bus($operator, 'KDH 100H');
        $bus->forceFill([
            'till_number' => '5550001', 'merchant_short_code' => '7770001', 'financier' => 'NCBA',
            'ncba_till' => '01100000000001', 'coop_till' => '01100000000002',
        ])->save();
        $run = $this->liveRun($bus);
        $this->pingFrom($bus, $run);
        Sanctum::actingAs($this->makeUser());

        $found = $this->search('KDH100H')->assertOk();
        $this->assertSame(self::BUS_KEYS, array_keys($found->json('buses.0')));
        $this->assertSame(self::OPERATOR_KEYS, array_keys($found->json('buses.0.operator')));

        $live = $this->live([$bus])->assertOk();
        $this->assertSame(['buses', 'missing'], array_keys($live->json()));
        $this->assertSame(self::LIVE_BUS_KEYS, array_keys($live->json('buses.0')));
        $this->assertSame(self::OPERATOR_KEYS, array_keys($live->json('buses.0.operator')));
        $this->assertSame(self::POSITION_KEYS, array_keys($live->json('buses.0.live')));
        $this->assertSame(['queue_id', 'route'], array_keys($live->json('buses.0.trip')));
        $this->assertSame(['id', 'name'], array_keys($live->json('buses.0.trip.route')));

        foreach ([$found, $live] as $res) {
            $body = (string) $res->getContent();
            foreach (['5550001', '7770001', 'NCBA', '01100000000001', '01100000000002', '0722333444', $this->owner->email, 'financier', 'till', 'user_id', 'owner'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
    }

    // ---------------------------------------------------------------- cost

    #[Test]
    public function live_costs_the_same_queries_for_one_bus_as_for_twenty(): void
    {
        $user = $this->makeUser();
        $one = $this->bus($this->operator(), 'KDJ 100J');
        $this->pingFrom($one, $this->liveRun($one));

        $many = [$one];
        foreach (range(1, 19) as $i) {
            $bus = $this->bus($this->operator($i % 2 === 0 ? Sacco::KIND_SCHOOL : Sacco::KIND_SACCO), 'KDJ '.(200 + $i).'J');
            $this->pingFrom($bus, $i % 3 === 0 ? $this->liveRun($bus) : null);
            $many[] = $bus;
        }

        Sanctum::actingAs($user);
        $this->live([$one])->assertOk(); // warm the permission and role caches

        $small = $this->queriesFor([$one]);
        $large = $this->queriesFor($many);

        $this->assertSame($small, $large, 'buses/live must not query per bus');
        $this->assertCount(20, $this->live($many)->assertOk()->json('buses'));
    }

    /** @param  list<Vehicle>  $buses */
    private function queriesFor(array $buses): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->live($buses)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // ---------------------------------------------------------------- the old feature

    #[Test]
    public function the_follow_endpoints_are_gone(): void
    {
        $bus = $this->bus($this->operator(Sacco::KIND_SCHOOL), 'KSD 100D');
        Sanctum::actingAs($this->makeUser());

        foreach (['/api/v1/auth', '/api/auth'] as $prefix) {
            $this->getJson($prefix.'/school-buses/search?plate=KSD100D')->assertNotFound();
            $this->getJson($prefix.'/school-buses/following')->assertNotFound();
            $this->postJson($prefix."/school-buses/{$bus->id}/follow", ['code' => 'AB3K7PQX'])->assertNotFound();
            $this->patchJson($prefix."/school-buses/{$bus->id}/follow")->assertNotFound();
            $this->deleteJson($prefix."/school-buses/{$bus->id}/follow")->assertNotFound();
            $this->getJson($prefix."/school-buses/{$bus->id}/stops")->assertNotFound();
        }

        // The new endpoints answer on both prefixes.
        $this->getJson('/api/auth/buses/search?plate=KSD100D')->assertOk()->assertJsonPath('buses.0.id', $bus->id);
        $this->getJson('/api/auth/buses/live?keys='.$this->k($bus))->assertOk()->assertJsonPath('buses.0.id', $bus->id);
    }
}
