<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Enums\BookingCancellationReason;
use App\Enums\LoyaltyTransactionType;
use App\Enums\PaymentMethod;
use App\Events\BookingCancelled;
use App\Events\PassengerBalanceChanged;
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
 * Not boarded means refunded.
 *
 * THE POLICY, decided 2026-09-11: a passenger the crew marks as not boarded gets
 * back what they paid, always. Points if they paid points; a ride credit in
 * points if they paid money. Before this, `no_show` released the seat and that
 * was all -- a paid passenger lost the seat AND what they paid for it, from one
 * tap, and there was no path anywhere in the codebase to give it back.
 * LoyaltyTransactionType::Refunded existed and had never once been written.
 *
 * The refund is idempotent at the ledger: (booking_id, 'refunded') is unique,
 * so a conductor tapping twice or a retried request cannot pay a passenger back
 * twice. That property is the one worth the most here and has its own test.
 */
final class NoShowRefundsTest extends QueueTestCase
{
    /** @return array{driver: User, queue: Queue, world: array<string, mixed>} */
    private function trip(float $threshold = 5): array
    {
        $world = $this->makeWorld();
        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'is_active' => true,
            'redemption_threshold' => $threshold, 'divisor' => 100,
        ]);

        $status = $this->makeQueueStatus('ns-'.$this->nextSequence(), 'Active');
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $status, $world['owner'],
        );

        $driver = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return ['driver' => $driver, 'queue' => $queue, 'world' => $world];
    }

    private function booking(array $trip, User $passenger, int $seats = 1): Booking
    {
        $booking = $this->makeBooking($trip['queue'], $passenger, $trip['world']['from'], $trip['world']['to']);
        $booking->forceFill(['passengers' => $seats, 'amount' => 150 * $seats])->save();
        $this->makeSeatBooking($booking, $trip['world']['arrangements'][0]);

        return $booking;
    }

    private function paidWithPoints(array $trip, User $passenger, float $balance = 50): Booking
    {
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $passenger->id, 'sacco_id' => $trip['world']['sacco']->id, 'balance' => $balance,
        ]);
        $booking = $this->booking($trip, $passenger);

        Sanctum::actingAs($passenger);
        $this->postJson('/api/auth/book_a_ride/loyalty/redeem', ['booking_id' => $booking->id])->assertOk();

        return $booking->fresh();
    }

    private function balance(User $u, int $saccoId): float
    {
        return (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $u->id)->where('sacco_id', $saccoId)->value('balance');
    }

    private function noShow(array $trip, Booking $booking): void
    {
        Sanctum::actingAs($trip['driver']);
        $this->postJson('/api/auth/driver/bookings/'.$booking->id.'/mark', ['action' => 'no_show'])
            ->assertOk();
    }

    #[Test]
    public function a_points_paid_passenger_who_is_not_boarded_gets_their_points_back(): void
    {
        $trip = $this->trip(threshold: 5);
        $passenger = $this->makeUser();
        $booking = $this->paidWithPoints($trip, $passenger, balance: 50);
        $saccoId = (int) $trip['world']['sacco']->id;
        $this->assertEqualsWithDelta(45, $this->balance($passenger, $saccoId), 0.001, 'precondition: 5 spent');

        $this->noShow($trip, $booking);

        $this->assertEqualsWithDelta(50, $this->balance($passenger, $saccoId), 0.001,
            'the exact points spent come back');
        $refund = LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Refunded->value)->first();
        $this->assertNotNull($refund, 'a Refunded row is the audit trail, not just the balance');
        $this->assertEqualsWithDelta(5, (float) $refund->value, 0.001);

        // The seat still goes back on sale -- refunding must not undo that.
        $this->assertFalse((bool) $booking->fresh()->status);
        $this->assertFalse((bool) SeatBooking::where('booking_id', $booking->id)->value('status'));
    }

    #[Test]
    public function a_money_paid_passenger_who_is_not_boarded_gets_a_ride_credit_in_points(): void
    {
        // M-Pesa B2C refunds are a separate Daraja integration. Until it exists a
        // money-paid no-show gets one free ride per seat, at THIS SACCO's rate.
        $trip = $this->trip(threshold: 5);
        $passenger = $this->makeUser();
        $booking = $this->booking($trip, $passenger, seats: 2);
        $booking->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $saccoId = (int) $trip['world']['sacco']->id;
        $before = $this->balance($passenger, $saccoId);   // includes the earn from paying

        $this->noShow($trip, $booking);

        $this->assertEqualsWithDelta($before + 10, $this->balance($passenger, $saccoId), 0.001,
            'two seats bought and not used -> two free rides -> 2 x threshold');
        $refund = LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Refunded->value)->first();
        $this->assertEqualsWithDelta(10, (float) $refund->value, 0.001);
    }

    #[Test]
    public function marking_no_show_twice_refunds_exactly_once(): void
    {
        // THE PROPERTY WORTH THE MOST. A conductor double-taps, or the request is
        // retried on a bad connection. (booking_id, 'refunded') is unique, so the
        // second pass finds the row and moves nothing.
        $trip = $this->trip(threshold: 5);
        $passenger = $this->makeUser();
        $booking = $this->paidWithPoints($trip, $passenger, balance: 50);
        $saccoId = (int) $trip['world']['sacco']->id;

        $this->noShow($trip, $booking);
        $this->noShow($trip, $booking);

        $this->assertEqualsWithDelta(50, $this->balance($passenger, $saccoId), 0.001,
            'a second tap must not pay the passenger a second time');
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Refunded->value)->count());
    }

    #[Test]
    public function an_unpaid_no_show_refunds_nothing(): void
    {
        // Nothing went in, nothing comes out. The seat is released as before.
        $trip = $this->trip();
        $passenger = $this->makeUser();
        $booking = $this->booking($trip, $passenger);
        $saccoId = (int) $trip['world']['sacco']->id;

        $this->noShow($trip, $booking);

        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()->where('booking_id', $booking->id)->count());
        $this->assertNull(LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $passenger->id)->where('sacco_id', $saccoId)->first());
        $this->assertFalse((bool) $booking->fresh()->status);
    }

    #[Test]
    public function the_passenger_is_told_and_the_balance_change_is_broadcast(): void
    {
        // This used to be the ONLY cancellation path that fired no event at all --
        // a paid passenger found out by opening the app to an empty screen.
        Event::fake([BookingCancelled::class, PassengerBalanceChanged::class]);

        $trip = $this->trip(threshold: 5);
        $passenger = $this->makeUser();
        $booking = $this->paidWithPoints($trip, $passenger, balance: 50);

        $this->noShow($trip, $booking);

        Event::assertDispatched(BookingCancelled::class, fn (BookingCancelled $e) => $e->booking->id === $booking->id
            && $e->reason === BookingCancellationReason::NoShow);
        Event::assertDispatched(PassengerBalanceChanged::class, fn (PassengerBalanceChanged $e) => $e->reason === 'refunded'
            && (float) $e->broadcastWith()['delta'] === 5.0);
    }

    #[Test]
    public function boarding_a_passenger_refunds_nothing(): void
    {
        // The other arm of the same endpoint. Boarded means the ride was taken.
        $trip = $this->trip(threshold: 5);
        $passenger = $this->makeUser();
        $booking = $this->paidWithPoints($trip, $passenger, balance: 50);
        $saccoId = (int) $trip['world']['sacco']->id;

        Sanctum::actingAs($trip['driver']);
        $this->postJson('/api/auth/driver/bookings/'.$booking->id.'/mark', ['action' => 'board'])->assertOk();

        $this->assertEqualsWithDelta(45, $this->balance($passenger, $saccoId), 0.001);
        $this->assertTrue((bool) $booking->fresh()->boarded);
    }
}
