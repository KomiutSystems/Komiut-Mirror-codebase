<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Enums\BookingCancellationReason;
use App\Enums\LoyaltyTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Events\BookingCancelled;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\MpesaStkCallback;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\User;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A passenger who paid and never rode gets their money back -- whichever way
 * the trip ended on them.
 *
 * THE AUDIT, 2026-09-12. Seven paths end a trip. The crew's trip/end refused
 * to finish with paid, unmarked passengers and refunded on `unmarked:
 * no_show`; the other six flipped the queue and walked away: the bus pulling
 * out of a Pending queue, a new driver signing into the vehicle, the
 * stale-queue sweep, the dashboard completing or replacing a queue, the
 * crew's last-stop pick-up. Every one of them left a `confirmed` booking on a
 * dead trip -- money kept, seat consumed, nobody told, and a map with nothing
 * on it. And a passenger could still PAY a dead trip: points redeem, an STK
 * push, or a callback landing after the end all accepted money for a ride
 * nobody would take and nobody would ever no-show.
 *
 * Now Queue::booted() settles whoever is still waiting whenever a queue moves
 * onto Completed or Cancelled, the payment rails refuse a dead trip, a
 * callback that lands late refunds on the spot, and an hourly sweep finishes
 * any refund that did not.
 */
final class EveryWayATripEndsSettlesItsPassengersTest extends QueueTestCase
{
    /** @return array{world: array<string, mixed>, queue: Queue, sacco_id: int} */
    private function trip(string $status = 'Pending'): array
    {
        $world = $this->makeWorld();
        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'is_active' => true,
            'divisor' => 100, 'redemption_threshold' => 5, 'point_value' => 30,
        ]);
        foreach (['Completed', 'Cancelled'] as $s) {
            if (! QueueStatus::where('status', $s)->exists()) {
                $this->makeQueueStatus($s, $s);
            }
        }
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus($status.' '.$this->nextSequence(), $status), $world['owner'],
        );

        return ['world' => $world, 'queue' => $queue, 'sacco_id' => (int) $world['sacco']->id];
    }

    private function passenger(array $trip, float $points = 0): User
    {
        $u = $this->makeUser();
        $u->forceFill(['type' => UserType::Passenger])->save();
        LoyaltyAccount::withoutGlobalScopes()->create(['user_id' => $u->id, 'sacco_id' => $trip['sacco_id'], 'balance' => $points]);

        return $u;
    }

    private function paidByMpesa(array $trip, User $u, float $amount = 150): Booking
    {
        $b = $this->makeBooking($trip['queue'], $u, $trip['world']['from'], $trip['world']['to']);
        $b->forceFill(['amount' => $amount])->save();
        $b->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);

        return $b->fresh();
    }

    private function unpaid(array $trip, User $u): Booking
    {
        $b = $this->makeBooking($trip['queue'], $u, $trip['world']['from'], $trip['world']['to']);
        $b->forceFill(['amount' => 150])->save();

        return $b->fresh();
    }

    private function balance(User $u, int $saccoId): float
    {
        return (float) LoyaltyAccount::withoutGlobalScopes()->where('user_id', $u->id)->where('sacco_id', $saccoId)->value('balance');
    }

    private function crew(array $trip): User
    {
        $d = $this->makeUser(['Edit Queues'], $trip['world']['sacco']);
        $d->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $d->id, 'vehicle_id' => $trip['world']['vehicle']->id,
            'sacco_id' => $trip['sacco_id'], 'status' => true, 'start_date' => now(),
        ]);

        return $d;
    }

    private function assertSettled(Booking $b, User $u, int $saccoId, float $expectPoints): void
    {
        $b = $b->fresh();
        $this->assertFalse((bool) $b->status, "booking #{$b->id} cancelled");
        $this->assertSame(BookingCancellationReason::TripOver, $b->cancellation_reason);
        $this->assertNotNull($b->cancelled_at);
        $this->assertEqualsWithDelta($expectPoints, $this->balance($u, $saccoId), 0.001, 'KES 150 at KES 30 a point, net of the 1.5 earned');
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $b->id)->where('type', LoyaltyTransactionType::Refunded->value)->count());
    }

    // ------------------------------------------------------------------ the six

    #[Test]
    public function the_bus_pulling_out_of_the_queue_refunds_who_had_paid(): void
    {
        Event::fake([BookingCancelled::class]);
        $trip = $this->trip('Pending');
        $tom = $this->passenger($trip);
        $paid = $this->paidByMpesa($trip, $tom);
        $reserved = $this->unpaid($trip, $this->passenger($trip));

        Sanctum::actingAs($this->crew($trip));
        $this->postJson('/api/v1/auth/queues/exit')->assertOk();

        $this->assertSettled($paid, $tom, $trip['sacco_id'], 5); // 1.5 earned then reversed; 5 refunded
        $this->assertFalse((bool) $reserved->fresh()->status, 'the unpaid hold is closed too');
        $this->assertSame(BookingCancellationReason::TripOver, $reserved->fresh()->cancellation_reason);
        Event::assertDispatched(BookingCancelled::class, fn ($e) => $e->booking->id === $paid->id && $e->reason === BookingCancellationReason::TripOver && $e->refunded > 0);
        Event::assertDispatched(BookingCancelled::class, fn ($e) => $e->booking->id === $reserved->id && $e->refunded === null);
    }

    #[Test]
    public function a_new_driver_signing_into_the_bus_refunds_the_old_trips_passengers(): void
    {
        $trip = $this->trip('Active');
        $tom = $this->passenger($trip);
        $paid = $this->paidByMpesa($trip, $tom);

        // The queue belongs to the outgoing driver; the incoming one signs in.
        $outgoing = $this->crew($trip);
        $trip['queue']->update(['user_id' => $outgoing->id]);
        $incoming = $this->makeUser([], $trip['world']['sacco']);
        $incoming->forceFill(['type' => UserType::Driver, 'phone' => '0724160975'])->save();

        $this->postJson('/api/v1/auth/driver/login', ['phone' => '0724160975', 'plate' => $trip['world']['vehicle']->plate])->assertOk();

        $this->assertSame('Cancelled', $trip['queue']->fresh()->queue_status->status);
        $this->assertSettled($paid, $tom, $trip['sacco_id'], 5);
    }

    #[Test]
    public function the_stale_queue_sweep_refunds_before_it_closes(): void
    {
        $trip = $this->trip('Active');
        $trip['queue']->update(['start_time' => now()->subHours(30)]);
        $tom = $this->passenger($trip);
        $paid = $this->paidByMpesa($trip, $tom);

        $this->artisan('queues:close-stale')->assertSuccessful();

        $this->assertSame('Cancelled', $trip['queue']->fresh()->queue_status->status);
        $this->assertSettled($paid, $tom, $trip['sacco_id'], 5);
    }

    #[Test]
    public function the_dashboard_completing_a_queue_refunds_who_had_paid(): void
    {
        $trip = $this->trip('Active');
        $tom = $this->passenger($trip);
        $paid = $this->paidByMpesa($trip, $tom);

        Sanctum::actingAs($this->makeUser(['Edit Queues'], $trip['world']['sacco']));
        $this->postJson('/api/v1/auth/queues/complete/queue', ['id' => $trip['queue']->id])->assertOk();

        $this->assertSettled($paid, $tom, $trip['sacco_id'], 5);
    }

    #[Test]
    public function a_boarded_passenger_is_never_touched_by_any_of_it(): void
    {
        $trip = $this->trip('Active');
        $tom = $this->passenger($trip);
        $rode = $this->paidByMpesa($trip, $tom);
        $rode->update(['boarded' => true]);
        $before = $this->balance($tom, $trip['sacco_id']);

        Sanctum::actingAs($this->makeUser(['Edit Queues'], $trip['world']['sacco']));
        $this->postJson('/api/v1/auth/queues/complete/queue', ['id' => $trip['queue']->id])->assertOk();

        $this->assertTrue((bool) $rode->fresh()->status, 'they rode; nothing to cancel');
        $this->assertEqualsWithDelta($before, $this->balance($tom, $trip['sacco_id']), 0.001, 'and nothing to refund');
    }

    // ---------------------------------------------------------- paying a dead trip

    #[Test]
    public function points_cannot_buy_a_ride_on_a_trip_that_has_ended(): void
    {
        $trip = $this->trip('Completed');
        $tom = $this->passenger($trip, points: 50);
        $booking = $this->unpaid($trip, $tom);

        Sanctum::actingAs($tom);
        $this->postJson('/api/v1/auth/book_a_ride/loyalty/redeem', ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This trip has ended. Please book another.');

        $this->assertEqualsWithDelta(50, $this->balance($tom, $trip['sacco_id']), 0.001);
        $this->assertFalse((bool) $booking->fresh()->paid);
    }

    #[Test]
    public function an_stk_push_is_refused_on_a_trip_that_has_ended(): void
    {
        Http::fake();
        $trip = $this->trip('Completed');
        $tom = $this->passenger($trip);
        $booking = $this->unpaid($trip, $tom);

        Sanctum::actingAs($tom);
        $this->postJson('/api/v1/auth/mpesa/stk', ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This trip has ended. Please book another.');

        Http::assertNothingSent();
    }

    #[Test]
    public function money_that_lands_after_the_trip_ended_comes_straight_back_as_points(): void
    {
        // The passenger opened the PIN prompt while the trip was live; the crew
        // ended it; Safaricom answered afterwards. The money is on the till,
        // the ride is gone, and nobody is left to no-show the booking.
        Event::fake([BookingCancelled::class]);
        $trip = $this->trip('Active');
        $tom = $this->passenger($trip);
        $booking = $this->unpaid($trip, $tom);
        $nonce = str_repeat('c', 64);
        MpesaStkCallback::create(['booking_id' => $booking->id, 'callback_nonce' => $nonce, 'callback' => '{}']);

        // The trip ends first (the sweep finds the booking unpaid: closed, no refund).
        Sanctum::actingAs($this->crew($trip));
        $this->postJson('/api/v1/auth/driver/trip/end')->assertOk();
        $this->assertFalse((bool) $booking->fresh()->status);
        $this->assertEqualsWithDelta(0, $this->balance($tom, $trip['sacco_id']), 0.001, 'nothing to refund yet');

        // Then the money lands.
        $body = json_encode(['Body' => ['stkCallback' => [
            'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'c-1', 'ResultCode' => 0,
            'ResultDesc' => 'The service request is processed successfully.',
            'CallbackMetadata' => ['Item' => [
                ['Name' => 'Amount', 'Value' => 150],
                ['Name' => 'MpesaReceiptNumber', 'Value' => 'RCT150'],
                ['Name' => 'TransactionDate', 'Value' => Carbon::now()->format('YmdHis')],
                ['Name' => 'PhoneNumber', 'Value' => 254700111222],
            ]],
        ]]], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/testing/stk/push/response/'.$nonce, [], [], [], [], $body)->assertOk();

        $b = $booking->fresh();
        $this->assertTrue((bool) $b->paid, 'the money is real; it is recorded');
        $this->assertFalse((bool) $b->status, 'but the booking stays closed');
        $this->assertEqualsWithDelta(5, $this->balance($tom, $trip['sacco_id']), 0.001, 'KES 150 back as 5 points, net of the earn reversed');
        Event::assertDispatched(BookingCancelled::class, fn ($e) => $e->booking->id === $booking->id && $e->reason === BookingCancellationReason::TripOver && $e->refunded > 0);
    }

    // ---------------------------------------------------------------- the net

    #[Test]
    public function a_refund_that_did_not_happen_is_finished_by_the_hourly_sweep(): void
    {
        // Cancelled-for-refund with no Refunded row: exactly what a ledger
        // failure or a mid-request deploy leaves behind.
        $trip = $this->trip('Active');
        $tom = $this->passenger($trip);
        $paid = $this->paidByMpesa($trip, $tom);
        Booking::withoutGlobalScopes()->whereKey($paid->id)->update([
            'status' => false, 'cancellation_reason' => BookingCancellationReason::NoShow->value, 'cancelled_at' => now(),
        ]);
        $this->assertEqualsWithDelta(1.5, $this->balance($tom, $trip['sacco_id']), 0.001, 'only the earn so far');

        $this->artisan('bookings:repair-refunds')->assertSuccessful();

        $this->assertEqualsWithDelta(5, $this->balance($tom, $trip['sacco_id']), 0.001);
        $this->artisan('bookings:repair-refunds')->assertSuccessful();
        $this->assertEqualsWithDelta(5, $this->balance($tom, $trip['sacco_id']), 0.001, 'and running again refunds nothing twice');
    }

    #[Test]
    public function the_crews_own_trip_end_still_refuses_to_guess(): void
    {
        // The gate stays: at the door, "unmarked" is a decision, not a default.
        $trip = $this->trip('Active');
        $tom = $this->passenger($trip);
        $paid = $this->paidByMpesa($trip, $tom);

        Sanctum::actingAs($this->crew($trip));
        $this->postJson('/api/v1/auth/driver/trip/end')->assertStatus(409)->assertJsonPath('unmarked.0.id', $paid->id);
        $this->assertTrue((bool) $paid->fresh()->status);
        $this->assertSame('Active', $trip['queue']->fresh()->queue_status->status);
    }
}
