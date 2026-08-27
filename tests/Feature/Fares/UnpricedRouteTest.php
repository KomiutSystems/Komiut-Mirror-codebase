<?php

declare(strict_types=1);

namespace Tests\Feature\Fares;

use App\Models\Booking;
use App\Models\RouteFare;
use App\Models\SaccoRoute;
use App\Services\Fares\FareResolver;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * An unpriced route must REFUSE, never quote free.
 *
 * `sacco_routes.amount` is NOT NULL with no database default, so
 * RouteAPIController@addRoute writes 0 into it purely to make the insert
 * succeed — the fare is meant to be set later. For a long time nothing could
 * set it, and that seeded zero flowed out of FareResolver as `0.0`.
 *
 * `0.0` is not `null`, so every guard downstream — FareAPIController's 404,
 * addBooking's 422, the broadcast reserve refusal — accepted it as a real
 * price. A passenger asking for any pair the SACCO had not explicitly priced
 * was quoted a FREE RIDE and could book at zero. Confirmed live on Frankfurt:
 * sacco 4 / route 1972 stored 0 and resolved to 0.0, not null.
 *
 * The distinction this pins: a SEEDED zero means "not priced". An EXPLICIT zero
 * typed into `route_fares` for one segment means zero, and still does —
 * `addFare` validates `min:0` on purpose.
 */
final class UnpricedRouteTest extends QueueTestCase
{
    #[Test]
    public function a_flat_fare_of_zero_resolves_to_null_not_to_free(): void
    {
        $world = $this->makeWorld(); // seeds a flat SaccoRoute fare of 200

        // Exactly what addRoute leaves behind.
        SaccoRoute::withoutGlobalScopes()
            ->where('sacco_id', $world['sacco']->id)
            ->update(['amount' => 0]);

        $amount = app(FareResolver::class)->resolve(
            $world['sacco']->id,
            $world['route']->id,
            $world['from']->id,
            $world['to']->id,
        );

        $this->assertNull($amount, 'a seeded zero is not a price');
    }

    #[Test]
    public function a_positive_flat_fare_still_resolves(): void
    {
        $world = $this->makeWorld();

        $this->assertSame(200.0, app(FareResolver::class)->resolve(
            $world['sacco']->id, $world['route']->id, $world['from']->id, $world['to']->id,
        ));
    }

    #[Test]
    public function an_explicit_zero_for_one_stop_pair_is_still_a_price(): void
    {
        $world = $this->makeWorld();

        // A SACCO that deliberately prices this segment at zero said something.
        // Only the tier-3 flat fallback treats zero as "unset".
        RouteFare::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id,
            'route_id' => $world['route']->id,
            'from_place_id' => $world['from']->id,
            'to_place_id' => $world['to']->id,
            'amount' => 0,
            'status' => true,
        ]);

        $this->assertSame(0.0, app(FareResolver::class)->resolve(
            $world['sacco']->id, $world['route']->id, $world['from']->id, $world['to']->id,
        ));
    }

    #[Test]
    public function the_fare_endpoint_404s_on_a_zero_flat_fare(): void
    {
        $world = $this->makeWorld();
        SaccoRoute::withoutGlobalScopes()
            ->where('sacco_id', $world['sacco']->id)->update(['amount' => 0]);

        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/book_a_ride/fare?sacco_id='.$world['sacco']->id.'&route_id='.$world['route']->id)
            ->assertStatus(404);
    }

    #[Test]
    public function a_booking_on_an_unpriced_route_is_refused_rather_than_sold_for_nothing(): void
    {
        $world = $this->makeWorld();
        SaccoRoute::withoutGlobalScopes()
            ->where('sacco_id', $world['sacco']->id)->update(['amount' => 0]);

        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id,
            'seats' => (string) $world['arrangements'][0]->id,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
        ])->assertStatus(422);

        $this->assertSame(0, Booking::count(), 'no free ride was written');
    }
}
