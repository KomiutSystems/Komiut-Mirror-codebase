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
            'seats' => $seats[0]->id.','.$seats[1]->id,
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
            ->assertJson(['error' => 'Seat '.$seats[0]->name.' already booked. Try a different seat!']);

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
            'seats' => $seats[1]->id.','.$seats[2]->id,
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

        $this->getJson('/api/auth/bookings/passengers?date='.now()->subDays(2)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'bookings');
    }

    #[Test]
    public function with_no_params_only_todays_bookings_are_returned(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);

        $today = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $old = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Otieno');
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        Sanctum::actingAs($this->makeUser(['View Passengers'], $world['sacco']));

        $this->getJson('/api/auth/bookings/passengers')
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.id', $today->id);
    }

    #[Test]
    public function range_all_returns_bookings_from_prior_days_too(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);

        $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Today');
        $old = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'LastWeek');
        $old->forceFill(['created_at' => now()->subDays(7)])->save();
        $older = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'LastMonth');
        $older->forceFill(['created_at' => now()->subDays(40)])->save();

        Sanctum::actingAs($this->makeUser(['View Passengers'], $world['sacco']));

        // Default is still today-only...
        $this->getJson('/api/auth/bookings/passengers')
            ->assertOk()
            ->assertJsonCount(1, 'bookings');

        // ...but range=all reaches the whole history.
        $this->getJson('/api/auth/bookings/passengers?range=all')
            ->assertOk()
            ->assertJsonCount(3, 'bookings');
    }

    #[Test]
    public function an_inclusive_from_to_window_returns_exactly_that_range(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);

        // Three bookings on three distinct days.
        $day10 = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Day10');
        $day10->forceFill(['created_at' => now()->subDays(10)->setTime(9, 0)])->save();
        $day5 = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Day5');
        $day5->forceFill(['created_at' => now()->subDays(5)->setTime(9, 0)])->save();
        $day1 = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Day1');
        $day1->forceFill(['created_at' => now()->subDays(1)->setTime(9, 0)])->save();

        Sanctum::actingAs($this->makeUser(['View Passengers'], $world['sacco']));

        $from = now()->subDays(6)->toDateString();
        $to = now()->subDays(4)->toDateString();

        // Only the day-5 booking falls inside the inclusive [from, to] window.
        $this->getJson('/api/auth/bookings/passengers?from='.$from.'&to='.$to)
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.id', $day5->id);

        // The `to` day is inclusive: widening to cover day-1 pulls it in too.
        $wideTo = now()->toDateString();
        $this->getJson('/api/auth/bookings/passengers?from='.$from.'&to='.$wideTo)
            ->assertOk()
            ->assertJsonCount(2, 'bookings');
    }

    #[Test]
    public function range_all_still_scopes_to_the_callers_own_bookings(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $other = $this->makeUser([], $world['sacco']);

        $mine = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Mine');
        $mine->forceFill(['created_at' => now()->subDays(9)])->save();
        $theirs = $this->makeBooking($queue, $other, $world['from'], $world['to'], 'Theirs');
        $theirs->forceFill(['created_at' => now()->subDays(9)])->save();

        // No View Passengers permission: full history must still be my own only.
        Sanctum::actingAs($passenger);

        $this->getJson('/api/auth/bookings/passengers?range=all')
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.id', $mine->id);
    }

    #[Test]
    public function an_invalid_date_window_is_rejected(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['View Passengers'], $world['sacco']));

        $this->getJson('/api/auth/bookings/passengers?from=not-a-date&to=also-bad')
            ->assertStatus(400)
            ->assertJsonStructure(['errors']);
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

        $this->getJson('/api/auth/bookings/passengers/view/'.$booking->id)
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

        $this->getJson('/api/auth/bookings/passenger/pick/'.$booking->id)
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

        $this->getJson('/api/auth/bookings/passenger/pick/'.$booking->id)
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

    // --------------------------------------------------------------- cancel

    #[Test]
    public function a_passenger_can_cancel_their_own_unpaid_booking(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $booking->forceFill(['status' => true, 'paid' => false])->save();
        $this->makeSeatBooking($booking, $world['arrangements'][0]);

        Sanctum::actingAs($passenger);

        $this->postJson('/api/auth/bookings/passengers/cancel/'.$booking->id)
            ->assertOk()
            ->assertJson(['success' => 'Booking cancelled and seat released.', 'booking_id' => $booking->id]);

        // Seat release == the hold flipped inactive; the occupancy query frees it.
        $this->assertFalse((bool) $booking->fresh()->status);
    }

    #[Test]
    public function a_passenger_cannot_cancel_someone_elses_booking(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $owner = $this->makeUser([], $world['sacco']);
        $stranger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $owner, $world['from'], $world['to'], 'Wanjiku');
        $booking->forceFill(['status' => true, 'paid' => false])->save();

        Sanctum::actingAs($stranger);

        $this->postJson('/api/auth/bookings/passengers/cancel/'.$booking->id)
            ->assertStatus(403);

        $this->assertTrue((bool) $booking->fresh()->status);
    }

    #[Test]
    public function a_paid_booking_cannot_be_self_cancelled(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $booking->forceFill(['status' => true, 'paid' => true])->save();

        Sanctum::actingAs($passenger);

        $this->postJson('/api/auth/bookings/passengers/cancel/'.$booking->id)
            ->assertStatus(422);

        // Still active — a paid seat is not released by self-service cancel.
        $this->assertTrue((bool) $booking->fresh()->status);
    }

    #[Test]
    public function a_boarded_booking_cannot_be_cancelled(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $booking->forceFill(['status' => true, 'paid' => false, 'boarded' => true])->save();

        Sanctum::actingAs($passenger);

        $this->postJson('/api/auth/bookings/passengers/cancel/'.$booking->id)
            ->assertStatus(422);
    }

    #[Test]
    public function cancelling_an_unknown_booking_returns_404(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/bookings/passengers/cancel/999999')
            ->assertStatus(404);
    }

    #[Test]
    public function staff_with_edit_passengers_can_cancel_any_booking(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $booking->forceFill(['status' => true, 'paid' => false])->save();

        Sanctum::actingAs($this->makeUser(['Edit Passengers'], $world['sacco']));

        $this->postJson('/api/auth/bookings/passengers/cancel/'.$booking->id)
            ->assertOk();

        $this->assertFalse((bool) $booking->fresh()->status);
    }

    #[Test]
    public function cancelling_an_already_cancelled_booking_is_idempotent(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $booking->forceFill(['status' => false, 'paid' => false])->save();

        Sanctum::actingAs($passenger);

        $this->postJson('/api/auth/bookings/passengers/cancel/'.$booking->id)
            ->assertOk()
            ->assertJson(['success' => 'Booking already cancelled.']);
    }

    #[Test]
    public function viewing_an_unknown_booking_returns_404_not_a_200_null(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/bookings/passengers/view/999999')
            ->assertStatus(404);
    }
}
