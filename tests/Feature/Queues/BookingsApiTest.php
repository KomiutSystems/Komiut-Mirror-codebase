<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\Booking;
use App\Models\SeatBooking;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression coverage for passenger bookings made against a queue:
 * - App\Http\Controllers\APIs\Dashboard\BookARide\BookARideQueuesAPIController::addBooking
 * - App\Http\Controllers\APIs\Dashboard\Bookings\BookingsAPIController::getPassengerBookings
 */
final class BookingsApiTest extends QueueTestCase
{
    #[Test]
    public function a_passenger_can_book_seats_on_a_queue(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $user = $this->makeUser([], $world['sacco']);
        Sanctum::actingAs($user);

        $seats = $world['arrangements'];

        $response = $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id,
            'seats' => $seats[0]->id . ',' . $seats[1]->id,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
            'amount' => 400,
        ]);

        $response->assertOk()->assertJson(['success' => 'Booking successful!']);

        $booking = Booking::firstOrFail();
        $this->assertSame($response->json('booking_id'), $booking->id);
        $this->assertSame($queue->id, $booking->queue_id);
        $this->assertSame(2, $booking->passengers);
        // 10-digit local numbers are normalised to the 254 international form.
        $this->assertSame('254722123456', $booking->phone);
        // Missing fromId/toId fall back to the route's origin and destination.
        $this->assertSame($world['from']->id, $booking->from_id);
        $this->assertSame($world['to']->id, $booking->to_id);
        $this->assertSame($user->id, $booking->user_id);
        $this->assertSame($user->id, $booking->created_by);
        $this->assertFalse((bool) $booking->paid);
        $this->assertFalse((bool) $booking->boarded);

        $this->assertSame(2, SeatBooking::where('booking_id', $booking->id)->count());
        $this->assertEqualsCanonicalizing(
            [$seats[0]->id, $seats[1]->id],
            SeatBooking::where('booking_id', $booking->id)->pluck('seat_id')->all()
        );
    }

    #[Test]
    public function a_seat_already_booked_on_the_same_queue_cannot_be_double_booked(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $user = $this->makeUser([], $world['sacco']);
        $seats = $world['arrangements'];

        $existing = $this->makeBooking($queue, $user, $world['from'], $world['to'], 'Otieno');
        $this->makeSeatBooking($existing, $seats[0]);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id,
            'seats' => (string) $seats[0]->id,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
            'amount' => 200,
        ])->assertStatus(400)
            ->assertJson(['error' => 'Seat ' . $seats[0]->name . ' already booked. Try a different seat!']);

        $this->assertSame(1, Booking::count());
    }

    #[Test]
    public function the_same_seat_can_be_booked_on_a_different_queue(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queueA = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-1');
        $queueB = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-2');
        $user = $this->makeUser([], $world['sacco']);
        $seats = $world['arrangements'];

        $existing = $this->makeBooking($queueA, $user, $world['from'], $world['to'], 'Otieno');
        $this->makeSeatBooking($existing, $seats[0]);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queueB->id,
            'seats' => (string) $seats[0]->id,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
            'amount' => 200,
        ])->assertOk()->assertJson(['success' => 'Booking successful!']);

        $this->assertSame(2, Booking::count());
    }

    #[Test]
    public function an_existing_booking_can_be_amended_and_its_seats_replaced(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $user = $this->makeUser([], $world['sacco']);
        $seats = $world['arrangements'];

        $booking = $this->makeBooking($queue, $user, $world['from'], $world['to'], 'Otieno');
        $this->makeSeatBooking($booking, $seats[0]);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id,
            'booking_id' => $booking->id,
            'seats' => $seats[1]->id . ',' . $seats[2]->id,
            'name' => 'Otieno',
            'phone' => '254722123456',
            'amount' => 400,
        ])->assertOk()->assertJson(['success' => 'Booking successful!', 'booking_id' => $booking->id]);

        $this->assertSame(1, Booking::count());
        $booking->refresh();
        $this->assertSame(2, $booking->passengers);
        $this->assertSame('254722123456', $booking->phone);
        $this->assertEqualsCanonicalizing(
            [$seats[1]->id, $seats[2]->id],
            SeatBooking::where('booking_id', $booking->id)->pluck('seat_id')->all()
        );
    }

    #[Test]
    public function booking_honours_explicit_pickup_and_dropoff_places(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $midway = $this->makePlace('Ruiru');
        $this->makeRouteStage($world['route'], $midway, 20);
        $user = $this->makeUser([], $world['sacco']);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id,
            'seats' => (string) $world['arrangements'][0]->id,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
            'amount' => 150,
            'fromId' => $midway->id,
            'toId' => $world['to']->id,
        ])->assertOk();

        $booking = Booking::firstOrFail();
        $this->assertSame($midway->id, $booking->from_id);
        $this->assertSame($world['to']->id, $booking->to_id);
    }

    #[Test]
    public function booking_validates_its_input(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => 999999,
            'phone' => '123',
        ])->assertStatus(400)
            // `amount` is no longer client-supplied — the server sets the fare.
            ->assertJsonStructure(['errors' => ['id', 'seats', 'name', 'phone']]);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function a_passenger_only_sees_their_own_bookings(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $other = $this->makeUser([], $world['sacco']);

        $mine = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $this->makeBooking($queue, $other, $world['from'], $world['to'], 'Otieno');

        Sanctum::actingAs($passenger);

        $this->getJson('/api/auth/bookings/passengers')
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.id', $mine->id);
    }

    #[Test]
    public function a_user_with_the_view_passengers_permission_sees_every_booking(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Otieno');

        Sanctum::actingAs($this->makeUser(['View Passengers'], $world['sacco']));

        $this->getJson('/api/auth/bookings/passengers')
            ->assertOk()
            ->assertJsonCount(2, 'bookings');
    }

    #[Test]
    public function passenger_bookings_can_be_searched_by_name(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Otieno');

        Sanctum::actingAs($this->makeUser(['View Passengers'], $world['sacco']));

        $this->getJson('/api/auth/bookings/passengers?search=Otieno')
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.name', 'Otieno');
    }

    #[Test]
    public function passenger_bookings_are_scoped_to_the_requested_day(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $old = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $old->forceFill(['created_at' => now()->subDays(2)])->save();

        Sanctum::actingAs($this->makeUser(['View Passengers'], $world['sacco']));

        $this->getJson('/api/auth/bookings/passengers')
            ->assertOk()
            ->assertJsonCount(0, 'bookings');

        $this->getJson('/api/auth/bookings/passengers?date=' . now()->subDays(2)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'bookings');
    }

    #[Test]
    public function a_single_passenger_booking_can_be_viewed(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');

        Sanctum::actingAs($passenger);

        $this->getJson('/api/auth/bookings/passengers/view/' . $booking->id)
            ->assertOk()
            ->assertJsonPath('booking.id', $booking->id)
            ->assertJsonPath('booking.queue.queue_number', 'QN-1');
    }

    #[Test]
    public function picking_up_a_passenger_requires_edit_passengers_permission(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');

        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/bookings/passenger/pick/' . $booking->id)
            ->assertStatus(403);

        $this->assertFalse((bool) $booking->fresh()->boarded);
    }

    #[Test]
    public function a_conductor_with_edit_passengers_can_pick_up_a_passenger(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');

        Sanctum::actingAs($this->makeUser(['Edit Passengers'], $world['sacco']));

        $this->getJson('/api/auth/bookings/passenger/pick/' . $booking->id)
            ->assertOk()
            ->assertJson(['success' => 'Passenger Picked Successfully!']);

        $this->assertTrue((bool) $booking->fresh()->boarded);
    }

    #[Test]
    public function bulk_picking_passengers_requires_edit_passengers_permission(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/bookings/passengers/pick', [
            'queueId' => 999999,
            'pickupId' => 999999,
        ])->assertStatus(403);
    }
}
