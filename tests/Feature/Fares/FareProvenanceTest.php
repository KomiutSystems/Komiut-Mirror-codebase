<?php

declare(strict_types=1);

namespace Tests\Feature\Fares;

use App\Models\RouteFare;
use App\Services\Fares\FareResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Where a fare came from, not just what it is.
 *
 * THE DEFECT THIS PINS. The resolver's third tier returns the whole-route fare
 * whenever a stop pair is unpriced, and it did so silently. Measured on the live
 * database against NICCO's route 1973:
 *
 *     Nairobi CBD → Ruiru   150/=
 *     Nairobi CBD → Juja    150/=
 *     Nairobi CBD → Thika   150/=
 *
 * Ruiru is 21.96 km along a 40.96 km route. A passenger riding 54% of it paid
 * 100% of the fare, and every layer above — the fare endpoint, the booking, the
 * STK push — saw an ordinary float and had no way to know.
 *
 * The fallback is kept, because plenty of SACCOs genuinely do charge one fare to
 * anywhere on the route and refusing would break them. What changes is that it
 * now says so.
 */
final class FareProvenanceTest extends QueueTestCase
{
    private function fares(): FareResolver
    {
        return app(FareResolver::class);
    }

    /** A world whose route has a flat 150/= and no per-leg fares — route 1973's shape. */
    private function flatOnlyWorld(): array
    {
        $world = $this->makeWorld();
        $mid = $this->makePlace('Ruiru '.$this->nextSequence());
        $this->makeRouteStage($world['route'], $mid, 22);

        $this->fares()->forget((int) $world['sacco']->id, (int) $world['route']->id);

        return $world + ['mid' => $mid];
    }

    #[Test]
    public function an_unpriced_leg_is_reported_as_a_fallback(): void
    {
        $w = $this->flatOnlyWorld();

        $quote = $this->fares()->quote(
            (int) $w['sacco']->id,
            (int) $w['route']->id,
            (int) $w['from']->id,
            (int) $w['mid']->id
        );

        $this->assertSame(200.0, (float) $quote['amount'], 'it still quotes, so flat-fare SACCOs keep working');
        $this->assertSame('flat', $quote['source']);
        $this->assertTrue($quote['is_fallback'], 'a partial leg on the whole-route fare must announce itself');
    }

    #[Test]
    public function the_whole_route_on_a_flat_fare_is_not_a_fallback(): void
    {
        // sacco_routes.amount IS the price of the whole run. Quoting it for the
        // route's own endpoints is the correct answer, not a stand-in.
        $w = $this->flatOnlyWorld();

        $quote = $this->fares()->quote(
            (int) $w['sacco']->id,
            (int) $w['route']->id,
            (int) $w['from']->id,
            (int) $w['to']->id
        );

        $this->assertSame('flat', $quote['source']);
        $this->assertFalse($quote['is_fallback']);
    }

    #[Test]
    public function naming_no_stops_at_all_is_not_a_fallback(): void
    {
        $w = $this->flatOnlyWorld();

        $quote = $this->fares()->quote((int) $w['sacco']->id, (int) $w['route']->id, null, null);

        $this->assertSame('flat', $quote['source']);
        $this->assertFalse($quote['is_fallback']);
    }

    #[Test]
    public function a_priced_leg_is_not_a_fallback_and_costs_what_it_says(): void
    {
        // The fix the whole thing exists to make possible: CBD→Ruiru at 60/=
        // while the full run stays 200/=.
        $w = $this->flatOnlyWorld();

        RouteFare::withoutGlobalScopes()->create([
            'sacco_id' => $w['sacco']->id,
            'route_id' => $w['route']->id,
            'from_place_id' => $w['from']->id,
            'to_place_id' => $w['mid']->id,
            'amount' => 60,
            'status' => true,
        ]);
        $this->fares()->forget((int) $w['sacco']->id, (int) $w['route']->id);

        $leg = $this->fares()->quote(
            (int) $w['sacco']->id, (int) $w['route']->id,
            (int) $w['from']->id, (int) $w['mid']->id
        );

        $this->assertSame(60.0, (float) $leg['amount']);
        $this->assertSame('pair', $leg['source']);
        $this->assertFalse($leg['is_fallback']);

        // ...and the full run is untouched by that.
        $full = $this->fares()->quote(
            (int) $w['sacco']->id, (int) $w['route']->id,
            (int) $w['from']->id, (int) $w['to']->id
        );
        $this->assertSame(200.0, (float) $full['amount']);
    }

    #[Test]
    public function an_unpriced_route_still_refuses_rather_than_guessing(): void
    {
        // Tier 4 is unchanged: no price at all means the caller must refuse, not
        // trust whatever amount the client sent.
        $world = $this->makeWorld();

        \App\Models\SaccoRoute::withoutGlobalScopes()
            ->where('route_id', $world['route']->id)
            ->update(['status' => false]);
        $this->fares()->forget((int) $world['sacco']->id, (int) $world['route']->id);

        $quote = $this->fares()->quote(
            (int) $world['sacco']->id, (int) $world['route']->id,
            (int) $world['from']->id, (int) $world['to']->id
        );

        $this->assertNull($quote['amount']);
        $this->assertNull($quote['source']);
        $this->assertFalse($quote['is_fallback'], 'nothing to fall back to is not a fallback');
    }

    #[Test]
    public function resolve_still_returns_the_same_number_it_always_did(): void
    {
        // resolve() now delegates to quote(). Every existing caller — the
        // booking, the STK push — must be unaffected.
        $w = $this->flatOnlyWorld();

        foreach ([[$w['from']->id, $w['mid']->id], [$w['from']->id, $w['to']->id], [null, null]] as [$from, $to]) {
            $this->assertSame(
                $this->fares()->quote((int) $w['sacco']->id, (int) $w['route']->id, $from, $to)['amount'],
                $this->fares()->resolve((int) $w['sacco']->id, (int) $w['route']->id, $from, $to)
            );
        }
    }

    #[Test]
    public function the_fare_endpoint_tells_the_app_the_price_is_a_stand_in(): void
    {
        $w = $this->flatOnlyWorld();
        $passenger = $this->makeUser([], null);

        \Laravel\Sanctum\Sanctum::actingAs($passenger);

        $body = $this->getJson('/api/v1/auth/book_a_ride/fare?'.http_build_query([
            'sacco_id' => $w['sacco']->id,
            'route_id' => $w['route']->id,
            'from_id' => $w['from']->id,
            'to_id' => $w['mid']->id,
        ]))->assertOk()->json('fare');

        $this->assertSame('flat', $body['source']);
        $this->assertTrue($body['is_fallback']);
    }
}
