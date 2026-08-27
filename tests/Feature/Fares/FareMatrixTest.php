<?php

declare(strict_types=1);

namespace Tests\Feature\Fares;

use App\Enums\UserType;
use App\Models\FarePeriod;
use App\Models\Place;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\User;
use App\Services\Fares\FareResolver;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The fare grid: every leg of a route, read and written as one thing.
 *
 * WHY. Fares are stored per stop-pair and the only writer set ONE pair per call
 * — 6 calls for a 4-stop route, 45 for a 10-stop one, times another full set per
 * peak window — and nothing could read the grid back. So nobody priced anything:
 * every route on the platform had zero stop-pair fares, and every leg fell
 * through to the whole-route amount. On NICCO's route 1973 that meant Nairobi
 * CBD to Ruiru and Nairobi CBD to Thika both quoted 150/=, for 21.96 km and
 * 40.96 km respectively.
 */
final class FareMatrixTest extends QueueTestCase
{
    /** @return array{0: array<string,mixed>, 1: User, 2: list<Place>} */
    private function pricedWorld(array $perms = ['View Fares', 'Add Fares', 'Edit Fares', 'Edit Routes']): array
    {
        $w = $this->makeWorld();

        // A four-stop route, like the real one: CBD → Ruiru → Juja → Thika.
        $ruiru = $this->makePlace('Ruiru '.$this->nextSequence());
        $juja = $this->makePlace('Juja '.$this->nextSequence());
        $this->makeRouteStage($w['route'], $ruiru, 22);
        $this->makeRouteStage($w['route'], $juja, 32);

        $admin = $this->makeUser($perms, $w['sacco']);
        $admin->forceFill(['type' => UserType::Admin, 'sacco_id' => $w['sacco']->id])->save();
        Sanctum::actingAs($admin->fresh());

        app(FareResolver::class)->forget((int) $w['sacco']->id, (int) $w['route']->id);

        return [$w, $admin->fresh(), [$w['from'], $ruiru, $juja, $w['to']]];
    }

    private function uri(array $w): string
    {
        return '/api/v1/auth/saccos/routes/'.$w['route']->id.'/fares';
    }

    #[Test]
    public function the_grid_lists_every_forward_leg_and_flags_the_unpriced_ones(): void
    {
        // "Which legs of this route still fall back?" is the question nothing
        // could answer. Today, for every route on the platform, the answer is
        // all of them — so an empty cell has to be part of the response.
        [$w] = $this->pricedWorld();

        $body = $this->getJson($this->uri($w))->assertOk()->json();

        // 4 stops in order = 6 forward pairs.
        $this->assertSame(6, $body['total_legs']);
        $this->assertSame(6, $body['unpriced_legs'], 'a fresh route is entirely unpriced');
        $this->assertSame(200.0, (float) $body['flat'], 'the whole-route fare every leg falls back to');

        foreach ($body['legs'] as $leg) {
            $this->assertFalse($leg['is_priced']);
            $this->assertNull($leg['base']);
        }
    }

    #[Test]
    public function reverse_legs_are_not_offered_because_nobody_can_ride_them(): void
    {
        // A CBD→Thika trip cannot carry someone from Thika to the CBD, so a
        // reverse cell would sit in the grid looking editable and never be read.
        [$w, , $stops] = $this->pricedWorld();

        $legs = collect($this->getJson($this->uri($w))->assertOk()->json('legs'));

        $this->assertTrue(
            $legs->contains(fn ($l) => $l['from_id'] === $stops[0]->id && $l['to_id'] === $stops[3]->id),
            'the full run must be offered'
        );
        $this->assertFalse(
            $legs->contains(fn ($l) => $l['from_id'] === $stops[3]->id && $l['to_id'] === $stops[0]->id),
            'the reverse of it must not be'
        );
    }

    #[Test]
    public function the_whole_grid_saves_in_one_call_and_the_resolver_sees_it(): void
    {
        // The point of the endpoint: one call instead of six, and the price a
        // passenger is quoted changes immediately.
        [$w, , $stops] = $this->pricedWorld();

        $this->postJson($this->uri($w), ['legs' => [
            ['from_id' => $stops[0]->id, 'to_id' => $stops[1]->id, 'amount' => 60],
            ['from_id' => $stops[0]->id, 'to_id' => $stops[2]->id, 'amount' => 100],
            ['from_id' => $stops[0]->id, 'to_id' => $stops[3]->id, 'amount' => 150],
        ]])->assertOk()->assertJsonPath('priced', 3);

        $fares = app(FareResolver::class);

        $this->assertSame(60.0, $fares->resolve((int) $w['sacco']->id, (int) $w['route']->id, $stops[0]->id, $stops[1]->id));
        $this->assertSame(150.0, $fares->resolve((int) $w['sacco']->id, (int) $w['route']->id, $stops[0]->id, $stops[3]->id));

        // And the short leg no longer announces itself as a guess.
        $quote = $fares->quote((int) $w['sacco']->id, (int) $w['route']->id, $stops[0]->id, $stops[1]->id);
        $this->assertSame('pair', $quote['source']);
        $this->assertFalse($quote['is_fallback']);
    }

    #[Test]
    public function a_reversed_leg_is_refused_rather_than_stored_where_nothing_can_read_it(): void
    {
        // The old single-pair writer never checked travel order, so a reversed
        // pair could be saved and would show in the listing as though the route
        // were priced — while being unreachable by any booking. A fare that can
        // never be charged is worse than a missing one: it hides the gap.
        [$w, , $stops] = $this->pricedWorld();

        $this->postJson($this->uri($w), ['legs' => [
            ['from_id' => $stops[3]->id, 'to_id' => $stops[0]->id, 'amount' => 150],
        ]])->assertStatus(422)->assertJsonStructure(['errors' => ['legs.0.to_id']]);

        $this->assertSame(0, RouteFare::withoutGlobalScopes()->where('route_id', $w['route']->id)->count());
    }

    #[Test]
    public function one_bad_leg_takes_the_whole_submission_with_it(): void
    {
        // A half-priced route is more dangerous than an unpriced one: it looks
        // configured, and the gaps quietly overcharge.
        [$w, , $stops] = $this->pricedWorld();
        $stranger = $this->makePlace('Not On This Route '.$this->nextSequence());

        $this->postJson($this->uri($w), ['legs' => [
            ['from_id' => $stops[0]->id, 'to_id' => $stops[1]->id, 'amount' => 60],
            ['from_id' => $stops[0]->id, 'to_id' => $stranger->id, 'amount' => 90],
        ]])->assertStatus(422);

        $this->assertSame(
            0,
            RouteFare::withoutGlobalScopes()->where('route_id', $w['route']->id)->count(),
            'the valid leg must not have been saved either'
        );
    }

    #[Test]
    public function a_null_amount_clears_a_leg_rather_than_pricing_it_at_zero(): void
    {
        // Zero is a free ride, which a SACCO might genuinely mean. "No price
        // set" is a different thing, and the two must not collapse together.
        [$w, , $stops] = $this->pricedWorld();

        $this->postJson($this->uri($w), ['legs' => [
            ['from_id' => $stops[0]->id, 'to_id' => $stops[1]->id, 'amount' => 60],
        ]])->assertOk();

        $this->postJson($this->uri($w), ['legs' => [
            ['from_id' => $stops[0]->id, 'to_id' => $stops[1]->id, 'amount' => null],
        ]])->assertOk()->assertJsonPath('cleared', 1);

        $quote = app(FareResolver::class)->quote(
            (int) $w['sacco']->id, (int) $w['route']->id, $stops[0]->id, $stops[1]->id
        );

        $this->assertSame('flat', $quote['source'], 'the leg must fall back again, not cost nothing');
        $this->assertTrue($quote['is_fallback']);
    }

    #[Test]
    public function a_peak_price_sits_beside_the_base_one_rather_than_replacing_it(): void
    {
        [$w, , $stops] = $this->pricedWorld();

        $period = FarePeriod::withoutGlobalScopes()->create([
            'sacco_id' => $w['sacco']->id,
            'name' => 'Morning peak',
            'days' => [1, 2, 3, 4, 5],
            'start_time' => '06:00',
            'end_time' => '09:00',
            'priority' => 10,
            'status' => true,
        ]);

        $this->postJson($this->uri($w), ['legs' => [
            ['from_id' => $stops[0]->id, 'to_id' => $stops[1]->id, 'amount' => 60],
            ['from_id' => $stops[0]->id, 'to_id' => $stops[1]->id, 'amount' => 80, 'fare_period_id' => $period->id],
        ]])->assertOk();

        $leg = collect($this->getJson($this->uri($w))->assertOk()->json('legs'))
            ->first(fn ($l) => $l['from_id'] === $stops[0]->id && $l['to_id'] === $stops[1]->id);

        $this->assertSame(60.0, (float) $leg['base']);
        $this->assertSame(80.0, (float) ((array) $leg['peak'])[$period->id]);
    }

    #[Test]
    public function another_saccos_route_is_not_readable_or_writable(): void
    {
        [$mine] = $this->pricedWorld();
        $theirs = $this->makeWorld();

        $this->getJson('/api/v1/auth/saccos/routes/'.$theirs['route']->id.'/fares')->assertStatus(404);

        $this->postJson('/api/v1/auth/saccos/routes/'.$theirs['route']->id.'/fares', ['legs' => [
            ['from_id' => $theirs['from']->id, 'to_id' => $theirs['to']->id, 'amount' => 1],
        ]])->assertStatus(404);

        $this->assertSame(0, RouteFare::withoutGlobalScopes()->where('route_id', $theirs['route']->id)->count());
    }

    #[Test]
    public function pricing_against_another_saccos_peak_window_is_refused(): void
    {
        // Ownership, not existence. Pricing against someone else's period would
        // make this SACCO's fares move whenever that SACCO edited their rush hour.
        [$w, , $stops] = $this->pricedWorld();
        $other = $this->makeWorld();

        $theirPeriod = FarePeriod::withoutGlobalScopes()->create([
            'sacco_id' => $other['sacco']->id,
            'name' => 'Their peak',
            'days' => [1], 'start_time' => '06:00', 'end_time' => '09:00',
            'priority' => 5, 'status' => true,
        ]);

        $this->postJson($this->uri($w), ['legs' => [
            ['from_id' => $stops[0]->id, 'to_id' => $stops[1]->id, 'amount' => 80, 'fare_period_id' => $theirPeriod->id],
        ]])->assertStatus(403);
    }

    #[Test]
    public function reading_the_grid_needs_the_fares_permission(): void
    {
        [$w] = $this->pricedWorld(['Edit Routes']);

        $this->getJson($this->uri($w))->assertStatus(403);
    }
}
