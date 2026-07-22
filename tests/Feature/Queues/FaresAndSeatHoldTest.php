<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\SaccoRoute;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * The fare subsystem + seat-hold behaviour:
 * - App\Services\Fares\FareResolver (via the /book_a_ride/fare endpoint)
 * - server-authoritative fare in BookARideQueuesAPIController::addBooking
 * - the shared seat-occupancy rule + hold expiry (ReleaseExpiredBookings)
 */
final class FaresAndSeatHoldTest extends QueueTestCase
{
    #[Test]
    public function the_fare_endpoint_returns_the_flat_route_fare(): void
    {
        $world = $this->makeWorld(); // seeds a flat SaccoRoute fare of 200
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/book_a_ride/fare?sacco_id=' . $world['sacco']->id . '&route_id=' . $world['route']->id)
            ->assertOk()
            ->assertJsonPath('fare.amount', 200);
    }

    #[Test]
    public function a_stop_pair_fare_overrides_the_flat_fare(): void
    {
        $world = $this->makeWorld();
        $this->makeRouteFare($world['sacco'], $world['route'], $world['from'], $world['to'], 350);
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $base = '/api/auth/book_a_ride/fare?sacco_id=' . $world['sacco']->id . '&route_id=' . $world['route']->id;

        // Exact pair → the pair price.
        $this->getJson($base . '&from_id=' . $world['from']->id . '&to_id=' . $world['to']->id)
            ->assertOk()->assertJsonPath('fare.amount', 350);

        // No pair given → the flat fare.
        $this->getJson($base)->assertOk()->assertJsonPath('fare.amount', 200);
    }

    #[Test]
    public function the_fare_endpoint_404s_when_the_route_is_unpriced(): void
    {
        $world = $this->makeWorld();
        SaccoRoute::where('sacco_id', $world['sacco']->id)->delete();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/book_a_ride/fare?sacco_id=' . $world['sacco']->id . '&route_id=' . $world['route']->id)
            ->assertStatus(404);
    }

    #[Test]
    public function the_booking_amount_is_the_server_fare_not_the_clients_number(): void
    {
        $world = $this->makeWorld(); // flat fare 200
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id,
            'seats' => (string) $world['arrangements'][0]->id,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
            'amount' => 9999, // must be ignored
            'payment_method' => 'mpesa',
        ])->assertOk()->assertJsonPath('amount', 200);

        $booking = Booking::firstOrFail();
        $this->assertEquals(200.0, (float) $booking->amount);
        $this->assertSame(PaymentMethod::Mpesa, $booking->payment_method);
    }

    #[Test]
    public function a_booking_is_refused_when_the_route_has_no_fare(): void
    {
        $world = $this->makeWorld();
        SaccoRoute::where('sacco_id', $world['sacco']->id)->delete(); // no fare anywhere
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id,
            'seats' => (string) $world['arrangements'][0]->id,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
        ])->assertStatus(422);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function an_expired_unpaid_hold_frees_its_seat(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $user = $this->makeUser([], $world['sacco']);
        $seat = $world['arrangements'][0];

        $hold = $this->makeBooking($queue, $user, $world['from'], $world['to'], 'Otieno');
        $this->makeSeatBooking($hold, $seat);

        Sanctum::actingAs($user);
        $seatUrl = '/api/auth/book_a_ride/seats?bus_id=' . $world['vehicle']->id . '&id=' . $queue->id;

        // Fresh unpaid hold → seat reads as taken.
        $this->getJson($seatUrl)->assertOk()->assertJsonCount(1, 'booked');

        // Age it past the hold window → the same query now reads it as free.
        $hold->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $this->getJson($seatUrl)->assertOk()->assertJsonCount(0, 'booked');

        // And the seat can be booked again.
        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id,
            'seats' => (string) $seat->id,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
        ])->assertOk();

        // The release sweep flips the stale hold inactive.
        Artisan::call('bookings:release-expired');
        $this->assertFalse((bool) $hold->fresh()->status);
    }
}
