<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Enums\UserType;
use App\Models\Booking;
use App\Models\RouteFare;
use App\Models\SeatArrangement;
use App\Models\User;
use App\Services\Fares\FareResolver;
use App\Services\Seats\SeatMapGenerator;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The whole passenger journey, through the real endpoints, in the real order.
 *
 * WHY THIS EXISTS. Three separate things had to be true before a passenger could
 * board, and each was independently broken in production:
 *
 *   - `seat_arrangements` held zero rows, so every seat resolved to null
 *   - no terminus stood at any route origin, so no trip could be created
 *   - an unpriced leg silently charged the whole-route fare
 *
 * Each has its own unit test. This one exists because passing three tests
 * separately does not prove they COMPOSE, and the platform's own history is the
 * argument for checking: `bookings` has never held a single row, so every part
 * of this chain has been individually plausible and collectively untested.
 *
 * The steps mirror the mobile app exactly — stops, routes, queues, fare, seats,
 * booking — and use HTTP throughout rather than calling services directly, so a
 * route, a permission or a validator that refuses a passenger shows up here.
 */
final class PassengerJourneyTest extends QueueTestCase
{
    private function passenger(): User
    {
        $u = $this->makeUser([], null);
        $u->forceFill(['type' => UserType::Passenger])->save();

        return $u->fresh();
    }

    /**
     * A world that can actually carry someone: a mid-route stop, seats on the
     * bus, and a live trip at the terminus.
     *
     * @return array<string, mixed>
     */
    private function runningTrip(): array
    {
        $world = $this->makeWorld();

        // A stop in the middle, so there is a partial leg to price and to book.
        $mid = $this->makePlace('Ruiru '.$this->nextSequence());
        $this->makeRouteStage($world['route'], $mid, 22);

        // The seats the passenger will pick. This is the table that was empty.
        app(SeatMapGenerator::class)->generateFor($world['seat']);

        $queue = $this->makeQueue(
            $world['vehicle'],
            $world['terminus'],
            $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'),
            $world['owner'],
            'QN-'.$this->nextSequence()
        );

        app(FareResolver::class)->forget((int) $world['sacco']->id, (int) $world['route']->id);

        return $world + ['mid' => $mid, 'queue' => $queue];
    }

    #[Test]
    public function a_passenger_can_go_from_a_stop_to_a_confirmed_booking(): void
    {
        $w = $this->runningTrip();
        Sanctum::actingAs($this->passenger());

        // 1. Which stops exist. The passenger has no SACCO, so this is also a
        //    check that the tenant scope lets them see a catalogue at all.
        $stops = $this->getJson('/api/v1/auth/book_a_ride/stops')->assertOk()->json('stops');
        $this->assertNotEmpty($stops, 'a passenger must be able to find a stop to start from');
        $this->assertContains(
            $w['from']->id,
            array_column($stops, 'id'),
            'the route origin must be offered as a stop'
        );

        // 2. The trips running on it.
        $queues = $this->getJson('/api/v1/auth/book_a_ride/queues?'.http_build_query([
            'from' => $w['from']->id,
            'to' => $w['to']->id,
        ]))->assertOk()->json('queues');

        $this->assertContains(
            $w['queue']->id,
            array_column($queues, 'id'),
            'the trip must be visible — this is what a missing terminus used to prevent'
        );

        // 3. What the ride costs.
        $fare = $this->getJson('/api/v1/auth/book_a_ride/fare?'.http_build_query([
            'sacco_id' => $w['sacco']->id,
            'route_id' => $w['route']->id,
            'from_id' => $w['from']->id,
            'to_id' => $w['to']->id,
        ]))->assertOk()->json('fare');

        $this->assertSame(200.0, (float) $fare['amount']);

        // 4. Which seats are free.
        $seatMap = $this->getJson('/api/v1/auth/book_a_ride/seats?'.http_build_query([
            'bus_id' => $w['vehicle']->id,
            'id' => $w['queue']->id,
        ]))->assertOk()->json();

        $arrangements = $seatMap['seats']['seat']['seat_arrangements'];
        $this->assertNotEmpty($arrangements, 'an empty seat map is what made every booking impossible');

        $seat = $arrangements[0];

        // 5. Book it.
        $booked = $this->postJson('/api/v1/auth/book_a_ride/booking/add', [
            'id' => $w['queue']->id,
            'seats' => '['.$seat['id'].']',
            'name' => 'Wanjiku',
            'phone' => '0712345678',
            'fromId' => $w['from']->id,
            'toId' => $w['to']->id,
        ])->assertOk()->json();

        $this->assertArrayHasKey('booking_id', $booked);

        $booking = Booking::withoutGlobalScopes()->findOrFail($booked['booking_id']);
        $this->assertSame(200.0, (float) $booking->amount, 'the server sets the price, not the client');
        $this->assertSame(1, (int) $booking->passengers);
        $this->assertFalse((bool) $booking->paid, 'a fresh booking is a hold, not a payment');
    }

    #[Test]
    public function the_seat_a_passenger_took_is_no_longer_offered_to_the_next_one(): void
    {
        // The point of the seat map: it has to actually reserve. An empty table
        // could not, and neither can one whose ids nothing points at.
        $w = $this->runningTrip();

        Sanctum::actingAs($this->passenger());

        $seatId = SeatArrangement::where('seat_id', $w['seat']->id)->orderBy('id')->value('id');

        $this->postJson('/api/v1/auth/book_a_ride/booking/add', [
            'id' => $w['queue']->id,
            'seats' => '['.$seatId.']',
            'name' => 'First',
            'phone' => '0712345678',
            'fromId' => $w['from']->id,
            'toId' => $w['to']->id,
        ])->assertOk();

        Sanctum::actingAs($this->passenger());

        $taken = $this->getJson('/api/v1/auth/book_a_ride/seats?'.http_build_query([
            'bus_id' => $w['vehicle']->id,
            'id' => $w['queue']->id,
        ]))->assertOk()->json('booked');

        $this->assertContains($seatId, array_column($taken, 'seatId'), 'the seat must read as taken');

        $this->postJson('/api/v1/auth/book_a_ride/booking/add', [
            'id' => $w['queue']->id,
            'seats' => '['.$seatId.']',
            'name' => 'Second',
            'phone' => '0712345679',
            'fromId' => $w['from']->id,
            'toId' => $w['to']->id,
        ])->assertStatus(400);
    }

    #[Test]
    public function a_short_leg_is_charged_its_own_price_once_the_sacco_sets_one(): void
    {
        // The defect that started this, end to end: before, CBD to Ruiru and CBD
        // to Thika both cost the full route fare. Now the SACCO prices the leg
        // and the booking charges the leg.
        $w = $this->runningTrip();

        RouteFare::withoutGlobalScopes()->create([
            'sacco_id' => $w['sacco']->id,
            'route_id' => $w['route']->id,
            'from_place_id' => $w['from']->id,
            'to_place_id' => $w['mid']->id,
            'amount' => 60,
            'status' => true,
        ]);
        app(FareResolver::class)->forget((int) $w['sacco']->id, (int) $w['route']->id);

        Sanctum::actingAs($this->passenger());

        $seatId = SeatArrangement::where('seat_id', $w['seat']->id)->orderBy('id')->value('id');

        $booked = $this->postJson('/api/v1/auth/book_a_ride/booking/add', [
            'id' => $w['queue']->id,
            'seats' => '['.$seatId.']',
            'name' => 'Wanjiku',
            'phone' => '0712345678',
            'fromId' => $w['from']->id,
            'toId' => $w['mid']->id,
        ])->assertOk()->json();

        $booking = Booking::withoutGlobalScopes()->findOrFail($booked['booking_id']);

        $this->assertSame(60.0, (float) $booking->amount, 'the short leg must cost the short-leg price');
    }

    #[Test]
    public function the_fare_endpoint_warns_the_app_when_a_leg_is_unpriced(): void
    {
        // Same journey, no per-leg price set. The booking still goes through --
        // flat-fare SACCOs are legitimate -- but the quote says it is standing in.
        $w = $this->runningTrip();
        Sanctum::actingAs($this->passenger());

        $fare = $this->getJson('/api/v1/auth/book_a_ride/fare?'.http_build_query([
            'sacco_id' => $w['sacco']->id,
            'route_id' => $w['route']->id,
            'from_id' => $w['from']->id,
            'to_id' => $w['mid']->id,
        ]))->assertOk()->json('fare');

        $this->assertSame(200.0, (float) $fare['amount']);
        $this->assertTrue($fare['is_fallback'], 'the app must be told this is the whole-route price');
    }

    #[Test]
    public function a_passenger_cannot_talk_the_price_down(): void
    {
        // The fare is resolved server-side from the SACCO's own pricing. An
        // amount in the request body is ignored, whatever it says.
        $w = $this->runningTrip();
        Sanctum::actingAs($this->passenger());

        $seatId = SeatArrangement::where('seat_id', $w['seat']->id)->orderBy('id')->value('id');

        $booked = $this->postJson('/api/v1/auth/book_a_ride/booking/add', [
            'id' => $w['queue']->id,
            'seats' => '['.$seatId.']',
            'name' => 'Wanjiku',
            'phone' => '0712345678',
            'fromId' => $w['from']->id,
            'toId' => $w['to']->id,
            'amount' => 1,
        ])->assertOk()->json();

        $this->assertSame(
            200.0,
            (float) Booking::withoutGlobalScopes()->findOrFail($booked['booking_id'])->amount
        );
    }
}
