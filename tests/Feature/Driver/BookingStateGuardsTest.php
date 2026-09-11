<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\BookingCancellationReason;
use App\Enums\LoyaltyTransactionType;
use App\Events\BookingCancelled;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\Queue;
use App\Models\SeatBooking;
use App\Models\User;
use App\Models\VehicleUser;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Board and no-show are terminal, mutually exclusive marks -- and a no-show
 * REFUNDS, which is what makes the missing guards money bugs rather than
 * untidiness.
 *
 * Neither arm of driver/bookings/{id}/mark checked where the booking already
 * was. So:
 *
 *   board -> no_show   the passenger pays, is boarded, rides, and one tap at the
 *                      terminus cancels the booking and puts the fare back on
 *                      their balance. Rode AND refunded.
 *   no_show -> board   the refund is already on the balance; the board seats
 *                      them on a cancelled booking (status=0, boarded=1) whose
 *                      seat is back on sale. Rides on the refund, seat sold twice.
 *   board (unpaid)     the dashboard's stop sweep boards "PAID and ACTIVE only",
 *                      and ReleaseExpiredBookings would later cancel the seated
 *                      passenger's booking as an unpaid hold.
 *
 * And the queue side: DriverQueueController::exit() took an Active queue to
 * Cancelled with its paid, unmarked passengers untouched. Cancelled is terminal
 * -- currentQueue() resolves Pending/Active only -- so no driver path could ever
 * reach those bookings again. The 409 driver/trip/end already had was decoration
 * while exit() stood open next to it.
 */
final class BookingStateGuardsTest extends QueueTestCase
{
    private const MARK = '/api/auth/driver/bookings/%d/mark';

    private const EXIT = '/api/auth/queues/exit';

    /**
     * A departed trip with a loyalty program so a no-show has something to refund.
     *
     * @param  array<int, string>  $permissions
     * @return array{driver: User, queue: Queue, world: array<string, mixed>, sacco_id: int}
     */
    private function departedTrip(array $permissions = []): array
    {
        $world = $this->makeWorld();
        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'is_active' => true,
            'redemption_threshold' => 5, 'divisor' => 100,
        ]);
        $this->makeQueueStatus('Completed '.$this->nextSequence(), 'Completed');
        $this->makeQueueStatus('Cancelled '.$this->nextSequence(), 'Cancelled');

        $active = $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active');
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner'],
        );

        $driver = $this->makeUser($permissions, $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return ['driver' => $driver, 'queue' => $queue, 'world' => $world, 'sacco_id' => (int) $world['sacco']->id];
    }

    /** @return array{0: User, 1: Booking} */
    private function paidWithPoints(array $trip, float $balance = 50): array
    {
        $passenger = $this->makeUser();
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $passenger->id, 'sacco_id' => $trip['sacco_id'], 'balance' => $balance,
        ]);
        $booking = $this->makeBooking($trip['queue'], $passenger, $trip['world']['from'], $trip['world']['to']);
        $this->makeSeatBooking($booking, $trip['world']['arrangements'][0]);

        Sanctum::actingAs($passenger);
        $this->postJson('/api/auth/book_a_ride/loyalty/redeem', ['booking_id' => $booking->id])->assertOk();

        return [$passenger, $booking->fresh()];
    }

    private function balance(User $u, int $saccoId): float
    {
        return (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $u->id)->where('sacco_id', $saccoId)->value('balance');
    }

    private function refunds(Booking $booking): int
    {
        return LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)
            ->where('type', LoyaltyTransactionType::Refunded->value)
            ->count();
    }

    private function mark(Booking $booking, string $action)
    {
        return $this->postJson(sprintf(self::MARK, $booking->id), ['action' => $action]);
    }

    #[Test]
    public function a_boarded_passenger_cannot_be_no_showed_and_is_not_refunded(): void
    {
        // The rode-AND-refunded case. 5 points were spent on the seat; after
        // board -> no_show the balance must still read 45, with no Refunded row,
        // the booking still live and still boarded, and nobody told anything.
        Event::fake([BookingCancelled::class]);
        $trip = $this->departedTrip();
        [$passenger, $booking] = $this->paidWithPoints($trip);
        $this->assertEqualsWithDelta(45, $this->balance($passenger, $trip['sacco_id']), 0.001, 'precondition: 5 spent');

        Sanctum::actingAs($trip['driver']);
        $this->mark($booking, 'board')->assertOk()->assertJsonPath('booking.status', 'boarded');

        $this->mark($booking, 'no_show')
            ->assertStatus(409)
            ->assertJsonPath('error', 'This passenger is already boarded.');

        $fresh = $booking->fresh();
        $this->assertTrue((bool) $fresh->status, 'the booking is still live');
        $this->assertTrue((bool) $fresh->boarded, 'and still boarded');
        $this->assertTrue((bool) SeatBooking::where('booking_id', $booking->id)->value('status'), 'seat still held');
        $this->assertEqualsWithDelta(45, $this->balance($passenger, $trip['sacco_id']), 0.001,
            'a passenger who rode is not refunded the fare for the ride');
        $this->assertSame(0, $this->refunds($booking));
        Event::assertNotDispatched(BookingCancelled::class);
    }

    #[Test]
    public function a_no_showed_passenger_cannot_be_boarded(): void
    {
        // The refund is already on the balance and the seat is back on sale; a
        // board now would seat them on a cancelled booking. They book again.
        $trip = $this->departedTrip();
        [$passenger, $booking] = $this->paidWithPoints($trip);

        Sanctum::actingAs($trip['driver']);
        $this->mark($booking, 'no_show')->assertOk()->assertJsonPath('booking.status', 'failed');
        $this->assertEqualsWithDelta(50, $this->balance($passenger, $trip['sacco_id']), 0.001, 'refunded');

        $this->mark($booking, 'board')
            ->assertStatus(409)
            ->assertJsonPath('error', 'This booking was cancelled; the passenger must book again.');

        $fresh = $booking->fresh();
        $this->assertFalse((bool) $fresh->status);
        $this->assertFalse((bool) $fresh->boarded, 'no status=0, boarded=1 row');
    }

    #[Test]
    public function an_unpaid_passenger_cannot_be_boarded(): void
    {
        // Same rule as the dashboard's stop sweep. An unpaid-but-boarded booking
        // is still an unpaid hold to ReleaseExpiredBookings, which would cancel
        // it and release the seat from under a seated passenger.
        $trip = $this->departedTrip();
        $passenger = $this->makeUser();
        $booking = $this->makeBooking($trip['queue'], $passenger, $trip['world']['from'], $trip['world']['to']);

        Sanctum::actingAs($trip['driver']);
        $this->mark($booking, 'board')
            ->assertStatus(409)
            ->assertJsonPath('error', 'Take the fare first.');

        $this->assertFalse((bool) $booking->fresh()->boarded);
        $this->assertTrue((bool) $booking->fresh()->status, 'refusing to board cancels nothing');
    }

    #[Test]
    public function boarding_twice_is_a_retry_not_an_error(): void
    {
        $trip = $this->departedTrip();
        [, $booking] = $this->paidWithPoints($trip);

        Sanctum::actingAs($trip['driver']);
        $this->mark($booking, 'board')->assertOk()->assertJsonPath('success', 'Passenger boarded.');
        $this->mark($booking, 'board')
            ->assertOk()
            ->assertJsonPath('success', 'Already boarded.')
            ->assertJsonPath('booking.status', 'boarded');

        $this->assertTrue((bool) $booking->fresh()->boarded);
    }

    #[Test]
    public function no_showing_twice_announces_once(): void
    {
        // The ledger was already idempotent (one Refunded row); the EVENT was not.
        // A second tap re-cancelled by query and re-dispatched BookingCancelled,
        // so the passenger was told "not boarded" twice. The second call is a 200
        // that touches nothing, and exactly one event crosses both calls.
        Event::fake([BookingCancelled::class]);
        $trip = $this->departedTrip();
        [$passenger, $booking] = $this->paidWithPoints($trip);

        Sanctum::actingAs($trip['driver']);
        $this->mark($booking, 'no_show')->assertOk()->assertJsonPath('success', 'Marked as a no-show.');
        $this->mark($booking, 'no_show')
            ->assertOk()
            ->assertJsonPath('success', 'Already marked.')
            ->assertJsonPath('booking.status', 'failed');

        $this->assertEqualsWithDelta(50, $this->balance($passenger, $trip['sacco_id']), 0.001, 'refunded once');
        $this->assertSame(1, $this->refunds($booking));
        Event::assertDispatchedTimes(BookingCancelled::class, 1);
        Event::assertDispatched(BookingCancelled::class, fn (BookingCancelled $e) => $e->booking->id === $booking->id
            && $e->reason === BookingCancellationReason::NoShow
            && $e->refunded !== null && abs($e->refunded - 5.0) < 0.001);
    }

    #[Test]
    public function a_no_show_that_refunded_nothing_says_so_on_the_event(): void
    {
        // Unpaid: the seat is released but nothing comes back, and the event
        // must carry null rather than a promise. The listener words off this.
        Event::fake([BookingCancelled::class]);
        $trip = $this->departedTrip();
        $passenger = $this->makeUser();
        $booking = $this->makeBooking($trip['queue'], $passenger, $trip['world']['from'], $trip['world']['to']);

        Sanctum::actingAs($trip['driver']);
        $this->mark($booking, 'no_show')->assertOk();

        Event::assertDispatched(BookingCancelled::class, fn (BookingCancelled $e) => $e->booking->id === $booking->id
            && $e->reason === BookingCancellationReason::NoShow
            && $e->refunded === null);
    }

    #[Test]
    public function an_active_queue_cannot_be_exited_with_a_paid_unmarked_passenger(): void
    {
        // Cancelled is terminal: once the queue is Cancelled no driver path can
        // reach its bookings again, so exiting here would strand the passenger
        // with their money kept. Same 409 as ending the trip.
        $trip = $this->departedTrip(['Edit Queues']);
        [$passenger, $booking] = $this->paidWithPoints($trip);

        Sanctum::actingAs($trip['driver']);
        $this->postJson(self::EXIT)
            ->assertStatus(409)
            ->assertJsonPath('error', '1 paid passenger has not been marked. Board or no-show them, or end the trip.');

        $this->assertSame('Active', $trip['queue']->fresh()->queue_status->status, 'the queue is untouched');
        $this->assertTrue((bool) $booking->fresh()->status);
        $this->assertEqualsWithDelta(45, $this->balance($passenger, $trip['sacco_id']), 0.001,
            'refusing to exit refunds nothing itself');

        // Once every paid passenger is marked, the exit goes through.
        $this->mark($booking, 'board')->assertOk();
        $this->postJson(self::EXIT)->assertOk()->assertJson(['success' => 'Left the queue.']);
        $this->assertSame('Cancelled', $trip['queue']->fresh()->queue_status->status);
    }
}
