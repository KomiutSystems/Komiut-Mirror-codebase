<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\MpesaStkCallback;
use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A ride paid with points must never read as revenue.
 *
 * The SACCO dashboard showed booking #8 (NICCO, 2026-09-12, paid with points)
 * as "Paid · Ksh 150" and counted the 150 into Revenue. No shilling moved. The
 * row carried `payment_method: "loyalty_points"`, but a client reading `paid`
 * and `amount` has no reason to look further -- so the list now says it
 * outright: `paid_with`, `points_spent`, and `amount_collected` (the KES the
 * SACCO actually received, which for a points ride is 0). The crew's trip
 * list gets the same three, which is the first time a conductor has been able
 * to check "I paid with points" against anything.
 */
final class ListsSayHowABookingWasPaidTest extends QueueTestCase
{
    private const LIST = '/api/v1/auth/bookings/passengers?range=all';

    /** @return array{world: array<string, mixed>, queue: Queue} */
    private function scene(): array
    {
        $world = $this->makeWorld();
        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'is_active' => true,
            'divisor' => 100, 'redemption_threshold' => 5, 'point_value' => 30,
        ]);
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'), $world['owner'],
        );

        return ['world' => $world, 'queue' => $queue];
    }

    private function passengerWithPoints(array $scene, float $balance = 50): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Passenger])->save();
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'sacco_id' => $scene['world']['sacco']->id, 'balance' => $balance,
        ]);

        return $user;
    }

    private function booking(array $scene, User $user, float $amount = 150): Booking
    {
        $booking = $this->makeBooking($scene['queue'], $user, $scene['world']['from'], $scene['world']['to']);
        $booking->forceFill(['amount' => $amount])->save();

        return $booking->fresh();
    }

    private function paidWithPoints(array $scene, User $user): Booking
    {
        $booking = $this->booking($scene, $user);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/auth/book_a_ride/loyalty/redeem', ['booking_id' => $booking->id])->assertOk();

        return $booking->fresh();
    }

    #[Test]
    public function the_dashboard_list_says_points_and_collects_nothing_for_a_points_ride(): void
    {
        $scene = $this->scene();
        $tom = $this->passengerWithPoints($scene);
        $points = $this->paidWithPoints($scene, $tom);
        $mpesa = $this->booking($scene, $this->passengerWithPoints($scene));
        $mpesa->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $unpaid = $this->booking($scene, $this->passengerWithPoints($scene));

        Sanctum::actingAs($this->makeUser(['View Passengers'], $scene['world']['sacco']));
        $rows = collect($this->getJson(self::LIST)->assertOk()->json('bookings'))->keyBy('id');

        $this->assertSame('points', $rows[$points->id]['paid_with']);
        $this->assertEqualsWithDelta(5, $rows[$points->id]['points_spent'], 0.001, 'KES 150 at KES 30 a point');
        $this->assertEqualsWithDelta(0, $rows[$points->id]['amount_collected'], 0.001, 'no shilling moved');
        $this->assertEqualsWithDelta(150, $rows[$points->id]['amount'], 0.001, 'the fare is still the fare');

        $this->assertSame('mpesa', $rows[$mpesa->id]['paid_with']);
        $this->assertNull($rows[$mpesa->id]['points_spent']);
        $this->assertEqualsWithDelta(150, $rows[$mpesa->id]['amount_collected'], 0.001);

        $this->assertNull($rows[$unpaid->id]['paid_with']);
        $this->assertEqualsWithDelta(0, $rows[$unpaid->id]['amount_collected'], 0.001);

        // The number the Revenue card should be summing.
        $this->assertEqualsWithDelta(150, $rows->sum('amount_collected'), 0.001, 'two paid rides, one of them free');
    }

    #[Test]
    public function the_single_booking_view_says_the_same(): void
    {
        $scene = $this->scene();
        $points = $this->paidWithPoints($scene, $this->passengerWithPoints($scene));

        Sanctum::actingAs($this->makeUser(['View Passengers'], $scene['world']['sacco']));
        $this->getJson('/api/v1/auth/bookings/passengers/view/'.$points->id)
            ->assertOk()
            ->assertJsonPath('booking.paid_with', 'points')
            ->assertJsonPath('booking.points_spent', 5)
            ->assertJsonPath('booking.amount_collected', 0);
    }

    #[Test]
    public function the_crew_can_finally_see_a_points_fare(): void
    {
        $scene = $this->scene();
        $points = $this->paidWithPoints($scene, $this->passengerWithPoints($scene));

        $driver = $this->makeUser(['Edit Queues'], $scene['world']['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $scene['world']['vehicle']->id,
            'sacco_id' => $scene['world']['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        Sanctum::actingAs($driver);
        $row = collect($this->getJson('/api/v1/auth/trips/bookings')->assertOk()->json('bookings'))
            ->firstWhere('bookingId', $points->id);

        $this->assertSame('PAID', $row['status']);
        $this->assertSame('points', $row['paidWith']);
        $this->assertEqualsWithDelta(5, $row['pointsSpent'], 0.001);
        $this->assertEqualsWithDelta(0, $row['amountCollected'], 0.001, 'nothing in the conductor\'s takings for this seat');
    }

    #[Test]
    public function the_ledger_decides_not_the_reservation_intent(): void
    {
        // payment_method is stamped from the RESERVE request -- what the app
        // meant to do -- and a booking reserved "with points" can still be
        // settled by STK. Until 2026-09-12 the callback left the column alone,
        // so such a ride listed as points and collected no revenue. Now the
        // callback stamps the rail that settled it, and the list reads the
        // ledger anyway.
        $scene = $this->scene();
        $booking = $this->booking($scene, $this->passengerWithPoints($scene));
        $booking->update(['payment_method' => PaymentMethod::LoyaltyPoints]);

        $nonce = str_repeat('a', 64);
        MpesaStkCallback::create(['booking_id' => $booking->id, 'callback_nonce' => $nonce, 'callback' => '{}']);
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

        $this->assertSame(PaymentMethod::Mpesa, $booking->fresh()->payment_method, 'the rail that settled it');

        Sanctum::actingAs($this->makeUser(['View Passengers'], $scene['world']['sacco']));
        $row = collect($this->getJson(self::LIST)->assertOk()->json('bookings'))->firstWhere('id', $booking->id);
        $this->assertSame('mpesa', $row['paid_with']);
        $this->assertEqualsWithDelta(150, $row['amount_collected'], 0.001);
    }
}
