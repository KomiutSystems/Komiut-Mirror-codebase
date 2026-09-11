<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\BookingCancellationReason;
use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\SeatBooking;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The booking lifecycle used to be silent everywhere except the PAID transition.
 * These tests pin the three things that had to change:
 *
 *  1. creating a booking raises BookingCreated (from the model, so no write path
 *     can forget it);
 *  2. flipping status false raises BookingCancelled;
 *  3. the two SCHEDULED SWEEPS raise it too — and they are the interesting case,
 *     because both cancel with a mass update() that fires NO Eloquent model
 *     events at all. Test 5 below asserts that trap directly, so anyone who
 *     "tidies up" the explicit dispatch sees a red test instead of a fleet of
 *     silently released seats.
 *
 * Event::fake is always PARTIAL. A bare Event::fake() also swaps the model event
 * dispatcher, which would stop Booking::booted()'s hooks running at all and make
 * every one of these pass for the wrong reason.
 */
final class BookingLifecycleEventsTest extends QueueTestCase
{
    /** @return array{queue: Queue, booking: Booking, passenger: User} */
    private function bookingOnPendingQueue(): array
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Rider');

        return ['queue' => $queue, 'booking' => $booking, 'passenger' => $passenger];
    }

    #[Test]
    public function creating_a_booking_fires_booking_created(): void
    {
        Event::fake([BookingCreated::class]);
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);

        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Rider');

        Event::assertDispatched(
            BookingCreated::class,
            fn (BookingCreated $e) => $e->booking->id === $booking->id
        );
    }

    #[Test]
    public function updating_a_booking_does_not_re_fire_booking_created(): void
    {
        // addBooking() reuses one method for create AND edit
        // (Booking::find($id) when booking_id > 0), so an edit must not look
        // like a new booking to the passenger.
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Rider');

        Event::fake([BookingCreated::class]);
        $booking->passengers = 2;
        $booking->save();

        Event::assertNotDispatched(BookingCreated::class);
    }

    #[Test]
    public function the_passenger_is_told_the_seat_is_held_in_app_only(): void
    {
        // IN-APP ONLY. This used to text as well, and the argument for it was
        // real: the hold is booking.hold_minutes long, bookings:release-expired
        // sweeps every minute, and push reaches almost nobody. That is now a
        // product decision rather than a technical one — the platform notifies
        // in the app, so the stored row, the socket and the push carry it.
        Notification::fake();
        $context = $this->bookingOnPendingQueue();

        Notification::assertSentTo(
            $context['passenger'],
            PlatformNotification::class,
            function (PlatformNotification $n) use ($context) {
                return $n->title === 'Booking created'
                    && $n->referenceId === (string) $context['booking']->id
                    && $n->channels === ['database', 'broadcast', 'fcm']
                    && ! in_array(SmsChannel::class, $n->via($context['passenger']), true);
            }
        );
    }

    #[Test]
    public function deactivating_a_booking_fires_booking_cancelled(): void
    {
        $context = $this->bookingOnPendingQueue();

        Event::fake([BookingCancelled::class]);
        $context['booking']->status = false;
        $context['booking']->save();

        Event::assertDispatched(
            BookingCancelled::class,
            fn (BookingCancelled $e) => $e->booking->id === $context['booking']->id
                && $e->reason === BookingCancellationReason::Cancelled
        );
    }

    #[Test]
    public function saving_an_unchanged_status_does_not_re_announce_a_cancellation(): void
    {
        $context = $this->bookingOnPendingQueue();
        $context['booking']->status = false;
        $context['booking']->save();

        Event::fake([BookingCancelled::class]);
        // Already false; a later save that touches something else must not read
        // as a second cancellation (wasChanged, not isDirty).
        $context['booking']->name = 'Renamed';
        $context['booking']->save();

        Event::assertNotDispatched(BookingCancelled::class);
    }

    #[Test]
    public function a_mass_update_fires_no_model_event_at_all(): void
    {
        // THE TRAP, asserted. Booking::booted() cannot see this write, which is
        // exactly why both scheduled sweeps have to dispatch by hand. If Eloquent
        // ever starts firing events for mass updates this test goes red and the
        // explicit dispatches can be revisited — until then, do not remove them.
        $context = $this->bookingOnPendingQueue();

        Event::fake([BookingCancelled::class]);
        Booking::whereKey($context['booking']->id)->update(['status' => false]);

        Event::assertNotDispatched(BookingCancelled::class);
        $this->assertFalse((bool) $context['booking']->fresh()->status);
    }

    #[Test]
    public function the_release_sweep_announces_every_booking_it_expires(): void
    {
        $context = $this->bookingOnPendingQueue();
        $this->backdate($context['booking'], (int) config('booking.hold_minutes', 10) + 5);

        Event::fake([BookingCancelled::class]);
        $this->artisan('bookings:release-expired')->assertExitCode(0);

        Event::assertDispatched(
            BookingCancelled::class,
            fn (BookingCancelled $e) => $e->booking->id === $context['booking']->id
                && $e->reason === BookingCancellationReason::Expired
        );
        $this->assertFalse((bool) $context['booking']->fresh()->status);
    }

    #[Test]
    public function the_release_sweep_stays_quiet_for_a_booking_inside_its_hold(): void
    {
        $this->bookingOnPendingQueue();

        Event::fake([BookingCancelled::class]);
        $this->artisan('bookings:release-expired')->assertExitCode(0);

        Event::assertNotDispatched(BookingCancelled::class);
    }

    #[Test]
    public function the_unpaid_payment_sweep_announces_every_booking_it_cancels(): void
    {
        $context = $this->bookingOnPendingQueue();
        // app:check-passenger-payments only looks at bookings whose queue is
        // Pending or Active; bookingOnPendingQueue() gives it one.
        $this->backdate($context['booking'], $this->pastTheHold());

        Event::fake([BookingCancelled::class]);
        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        Event::assertDispatched(
            BookingCancelled::class,
            fn (BookingCancelled $e) => $e->booking->id === $context['booking']->id
                && $e->reason === BookingCancellationReason::Expired
        );
        $this->assertFalse((bool) $context['booking']->fresh()->status);
    }

    #[Test]
    public function the_unpaid_payment_sweep_does_not_re_cancel_what_it_already_cancelled(): void
    {
        // It runs every 2 minutes. Before the status filter it re-matched rows it
        // had already flipped, so the same passenger would have been told their
        // booking expired on every single run, forever.
        $context = $this->bookingOnPendingQueue();
        $this->backdate($context['booking'], $this->pastTheHold());
        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        Event::fake([BookingCancelled::class]);
        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        Event::assertNotDispatched(BookingCancelled::class);
    }

    #[Test]
    public function the_unpaid_payment_sweep_leaves_a_paid_booking_alone(): void
    {
        $context = $this->bookingOnPendingQueue();
        Booking::whereKey($context['booking']->id)->update(['paid' => true]);
        $this->backdate($context['booking'], $this->pastTheHold());

        Event::fake([BookingCancelled::class]);
        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        Event::assertNotDispatched(BookingCancelled::class);
        $this->assertTrue((bool) $context['booking']->fresh()->status);
    }

    #[Test]
    public function neither_an_expired_hold_nor_a_cancelled_paid_booking_is_texted(): void
    {
        // A cancelled PAID booking was the one case that still justified an SMS:
        // rare, and the passenger has actually lost money. It is in-app now like
        // everything else, so this pins that BOTH paths stay off SMS — the
        // cancellation branch was conditional, and a condition that can turn a
        // channel back on is worth a test rather than a reading of the code.
        Notification::fake();
        $context = $this->bookingOnPendingQueue();
        $booking = $context['booking'];

        event(new BookingCancelled($booking, BookingCancellationReason::Expired));
        Notification::assertSentTo(
            $context['passenger'],
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title !== 'Booking expired'
                || ! in_array('sms', $n->channels, true)
        );

        Booking::whereKey($booking->id)->update(['paid' => true]);
        event(new BookingCancelled($booking->fresh(), BookingCancellationReason::Cancelled));
        Notification::assertSentTo(
            $context['passenger'],
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title !== 'Booking cancelled'
                || ! in_array('sms', $n->channels, true)
        );
    }

    #[Test]
    public function a_no_show_that_refunded_names_the_points_that_came_back(): void
    {
        Notification::fake();
        $context = $this->bookingOnPendingQueue();
        Booking::whereKey($context['booking']->id)->update(['paid' => true]);

        event(new BookingCancelled($context['booking']->fresh(), BookingCancellationReason::NoShow, 5.0));

        Notification::assertSentTo(
            $context['passenger'],
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title === 'Not boarded'
                && $n->referenceId === (string) $context['booking']->id
                && str_contains($n->message, '5 points')
        );
    }

    #[Test]
    public function a_no_show_that_refunded_nothing_does_not_claim_it_did(): void
    {
        // The listener used to say "what you paid has been returned to your
        // points balance" on EVERY no-show, keyed on the reason alone. The refund
        // has real null exits -- a money-paid seat on a SACCO with no loyalty
        // program, for one -- and a passenger whose balance did not move was
        // being told it had. The wording is keyed on the event's refunded
        // figure, and null means the sentence about money is not said at all.
        Notification::fake();
        $context = $this->bookingOnPendingQueue();
        Booking::whereKey($context['booking']->id)->update(['paid' => true]);

        event(new BookingCancelled($context['booking']->fresh(), BookingCancellationReason::NoShow, null));

        Notification::assertSentTo(
            $context['passenger'],
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title === 'Not boarded'
                && $n->referenceId === (string) $context['booking']->id
                && str_contains($n->message, 'seat has been released')
                && ! str_contains($n->message, 'returned')
        );
        Notification::assertNotSentTo(
            $context['passenger'],
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title === 'Not boarded'
                && str_contains($n->message, 'returned')
        );
    }

    #[Test]
    public function the_payment_sweep_frees_the_seat_rows_of_what_it_announces(): void
    {
        // Guards the pairing: the announcement and the seat release must describe
        // the same set, or a passenger is told their seat went back on sale while
        // it is still held.
        $context = $this->bookingOnPendingQueue();
        $seat = $this->makeSeatBooking($context['booking'], $this->makeSeatArrangements($this->makeSeat(), 1)[0]);
        $this->backdate($context['booking'], $this->pastTheHold());

        Event::fake([BookingCancelled::class]);
        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        Event::assertDispatched(BookingCancelled::class);
        $this->assertFalse((bool) $context['booking']->fresh()->status);
        $this->assertFalse((bool) SeatBooking::whereKey($seat->id)->first()->status);
    }

    /** Age a booking without firing model events (mass update, by design). */
    /**
     * Old enough for app:check-passenger-payments to take it, whatever the hold is.
     *
     * These were a hardcoded 5, which only counted as "past the window" while that
     * command used its own hardcoded subMinutes(2) -- against the ten minutes
     * config('booking.hold_minutes') advertises and every other caller honours.
     * The command reads the config now, so these must too; line 163 above already
     * did it this way for the other sweep.
     */
    private function pastTheHold(): int
    {
        return (int) config('booking.hold_minutes', 10) + 5;
    }

    private function backdate(Booking $booking, int $minutes): void
    {
        Booking::whereKey($booking->id)->update(['created_at' => now()->subMinutes($minutes)]);
    }
}
