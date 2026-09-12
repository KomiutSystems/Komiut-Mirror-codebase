<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Enums\BookingCancellationReason;
use App\Enums\LoyaltyTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
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
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
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
            // KES 30 a point: the KES 150 single-seat fare costs 5 points, so
            // the single-seat figures below read as they always did.
            'point_value' => 30,
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
        $this->assertFalse(LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Reversed->value)->exists(),
            'nothing was earned on a free ride, so there is nothing to reverse');

        // The seat still goes back on sale -- refunding must not undo that.
        $this->assertFalse((bool) $booking->fresh()->status);
        $this->assertFalse((bool) SeatBooking::where('booking_id', $booking->id)->value('status'));
    }

    #[Test]
    public function a_money_paid_passenger_who_is_not_boarded_gets_back_the_fares_worth_in_points(): void
    {
        // M-Pesa B2C refunds are a separate Daraja integration. Until it exists a
        // money-paid no-show gets the FARE'S WORTH in points at THIS SACCO's
        // point value -- exactly what redeem would have charged for the booking,
        // so what went in is what comes out. Four seats at KES 150 is KES 600;
        // at KES 30 a point that is 20 points, and 20 points buys exactly KES
        // 600 of travel. Not four rides of any length, not one ride of any
        // length: six hundred shillings' worth.
        $trip = $this->trip(threshold: 5);
        $passenger = $this->makeUser();
        $booking = $this->booking($trip, $passenger, seats: 4);
        $booking->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);

        $this->noShow($trip, $booking);

        $refund = LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Refunded->value)->first();
        $this->assertNotNull($refund);
        $this->assertEqualsWithDelta(20, (float) $refund->value, 0.001,
            'KES 600 paid, KES 600 of travel back: 600 / 30');
    }

    #[Test]
    public function a_money_refund_takes_back_the_earn_the_payment_minted(): void
    {
        // Paying KES 150 earned 1.5 points. The ride those points were earned ON
        // never happened, so they go too: without this the passenger ended with
        // Earned +1.5 and Refunded +5 -- 6.5 points for a ride never taken, and
        // a scheme farmable by booking and not boarding.
        Event::fake([PassengerBalanceChanged::class]);

        $trip = $this->trip(threshold: 5);
        $passenger = $this->makeUser();
        $booking = $this->booking($trip, $passenger, seats: 1);
        $booking->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $saccoId = (int) $trip['world']['sacco']->id;

        $earn = LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Earned->value)->first();
        $this->assertNotNull($earn, 'precondition: paying by M-Pesa earns');
        $earned = (float) $earn->value;
        $this->assertEqualsWithDelta(1.5, $earned, 0.001, 'precondition: 150 / divisor 100');
        $before = $this->balance($passenger, $saccoId);

        $this->noShow($trip, $booking);

        $this->assertEqualsWithDelta($before - $earned + 5, $this->balance($passenger, $saccoId), 0.001,
            'the earn is taken back and the ride credit is given: before - earned + threshold');

        $reversal = LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Reversed->value)->first();
        $this->assertNotNull($reversal, 'the reversal is on the ledger, keyed (booking_id, reversed)');
        $this->assertEqualsWithDelta(-$earned, (float) $reversal->value, 0.001, 'Reversed is a debit');

        // ONE frame for the net move, not one per ledger row. Each frame carries
        // the balance AFTER the move, and two frames sharing one final balance
        // would each contradict the other's delta.
        $frames = Event::dispatched(PassengerBalanceChanged::class, fn (PassengerBalanceChanged $e) => $e->reason !== 'earned');
        $this->assertCount(1, $frames, 'the no-show is announced once');
        $frame = $frames->first()[0]->broadcastWith();
        $this->assertSame('refunded', $frame['reason']);
        $this->assertEqualsWithDelta(5 - $earned, (float) $frame['delta'], 0.001, 'the delta is the net move');
        $this->assertEqualsWithDelta($before - $earned + 5, (float) $frame['balance'], 0.001);
    }

    #[Test]
    public function marking_no_show_twice_reverses_the_earn_exactly_once(): void
    {
        // (booking_id, 'reversed') is unique too. A second tap finds the Refunded
        // row and returns before it ever gets near the reversal, but the index
        // is the guard, not that ordering.
        $trip = $this->trip(threshold: 5);
        $passenger = $this->makeUser();
        $booking = $this->booking($trip, $passenger, seats: 1);
        $booking->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $saccoId = (int) $trip['world']['sacco']->id;
        $before = $this->balance($passenger, $saccoId);

        $this->noShow($trip, $booking);
        $this->noShow($trip, $booking);

        $this->assertEqualsWithDelta($before - 1.5 + 5, $this->balance($passenger, $saccoId), 0.001);
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Reversed->value)->count());
    }

    #[Test]
    public function a_booking_whose_user_is_not_a_passenger_refunds_nothing(): void
    {
        // Both booking-creation paths set user_id to the authenticated caller, so
        // a booking a CONDUCTOR keys in for a walk-in carries the conductor's own
        // id. A cash-paid walk-in who never boarded used to refund points to the
        // conductor's account.
        $trip = $this->trip(threshold: 5);
        $conductor = $this->makeUser([], $trip['world']['sacco']);
        $conductor->forceFill(['type' => UserType::Driver])->save();
        $booking = $this->booking($trip, $conductor);
        // By query, not save(): an Eloquent save fires BookingPaid and would earn
        // for the conductor too, which is a different question from this one.
        Booking::withoutGlobalScopes()->whereKey($booking->id)
            ->update(['paid' => true, 'payment_method' => PaymentMethod::Cash->value]);
        $saccoId = (int) $trip['world']['sacco']->id;

        $refund = app(LoyaltyService::class)->refundForBooking(Booking::withoutGlobalScopes()->find($booking->id));

        $this->assertNull($refund, 'a non-passenger is not refunded');
        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()->where('booking_id', $booking->id)->count(),
            'and nothing is written to the ledger');
        $this->assertNull(LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $conductor->id)->where('sacco_id', $saccoId)->first(),
            'the conductor gets no account, let alone a balance');
    }

    #[Test]
    public function losing_a_refund_race_returns_the_winners_row_without_failing_or_announcing(): void
    {
        // Two no-show requests for one booking at once -- a double-tap, or a retry
        // racing the request it retries. Both pass the pre-transaction "already
        // refunded?" check; one insert wins, the other hits the unique index.
        //
        // The loser used to FAIL. It had already incremented the balance, and on
        // Postgres a failed statement aborts the transaction until a rollback
        // (SQLSTATE 25P02) -- so the "undo the increment" in the catch threw, the
        // QueryException escaped, and the caller got an error instead of the
        // winner's row. Rolled back, so no money was lost; but the API call
        // failed for a refund that had in fact happened. And even where the
        // catch worked, the loser announced a +delta frame for a refund it had
        // not made, so the passenger's socket saw the refund twice.
        //
        // PHPUnit cannot run two requests at once, so the interleaving is staged:
        // the moment this call opens its transaction -- after its own pre-check
        // has passed -- "the other tap" lands its row and its increment, which is
        // exactly what the loser of a real race finds when its insert runs.
        $trip = $this->trip(threshold: 5);
        $passenger = $this->makeUser();
        $booking = $this->paidWithPoints($trip, $passenger, balance: 50);
        $saccoId = (int) $trip['world']['sacco']->id;
        $this->assertEqualsWithDelta(45, $this->balance($passenger, $saccoId), 0.001, 'precondition: 5 spent');

        $outer = DB::transactionLevel();
        $staged = false;
        $winner = null;
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $e) use (&$staged, &$winner, $outer, $passenger, $saccoId, $booking) {
            if ($staged || $e->connection->transactionLevel() !== $outer + 1) {
                return; // already staged, or a savepoint inside the refund rather than the refund itself
            }
            $staged = true;
            $winner = LoyaltyTransaction::withoutGlobalScopes()->create([
                'user_id' => $passenger->id, 'sacco_id' => $saccoId, 'value' => 5,
                'type' => LoyaltyTransactionType::Refunded, 'booking_id' => $booking->id,
            ]);
            LoyaltyAccount::withoutGlobalScopes()
                ->where('user_id', $passenger->id)->where('sacco_id', $saccoId)->increment('balance', 5);
        });
        Event::fake([PassengerBalanceChanged::class]);

        $row = app(LoyaltyService::class)->refundForBooking($booking);

        $this->assertNotNull($winner, 'the staged race did not fire: the refund never opened a transaction');
        $this->assertNotNull($row, 'the loser must not fail -- the refund happened');
        $this->assertSame($winner->id, $row->id, 'and what it returns is the row that won');
        $this->assertEqualsWithDelta(50, $this->balance($passenger, $saccoId), 0.001,
            'the winner moved the balance once; the loser must not move it again');
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Refunded->value)->count());
        Event::assertNotDispatched(PassengerBalanceChanged::class);
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
