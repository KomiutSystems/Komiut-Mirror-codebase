<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Paying for a ride with loyalty points, end to end.
 *
 * The flow is: reserve an unpaid booking, then POST loyalty/redeem with its id,
 * which spends the SACCO's threshold and settles the ride. Before these tests it
 * had never run once in production, and three things were wrong with it in ways
 * that only showed up against real data:
 *
 *   1. A CANCELLED booking was still redeemable. redeemForBooking never looked at
 *      bookings.status, so a passenger could spend points on a reservation the
 *      expiry sweep had already killed and whose seats were back on sale. Verified
 *      against production booking 1 on 2026-09-09 — cancelled at 09:08, seats 10
 *      and 11 released, and every guard still passed. They would have been told
 *      "Free ride redeemed!", the driver would have been pushed a seat-confirmed
 *      notification, and the manifest would have shown nobody.
 *
 *   2. A RETRY looked like a failure. The paid guard fired before anything could
 *      tell a points replay from an M-Pesa settlement, so re-sending a redeem that
 *      had already succeeded returned 422 "This booking is already paid." — the
 *      identical string a genuine conflict returns. On a handset on a moving
 *      matatu, the request whose response is lost is the common case.
 *
 *   3. The window was two minutes, not ten. CheckPassengerPayments hardcoded
 *      subMinutes(2) while config('booking.hold_minutes') is 10 and every other
 *      caller honoured it. Both bookings that have ever existed in production died
 *      three minutes after creation. A passenger cannot read a payment sheet,
 *      choose "free ride" and post inside two minutes.
 */
final class PointsBookingWorksTest extends QueueTestCase
{
    private const URL = '/api/auth/book_a_ride/loyalty/redeem';

    /** A passenger holding enough points in the booking's SACCO. */
    private function passengerWith(int $saccoId, float $balance, float $threshold): User
    {
        $user = $this->makeUser();

        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $saccoId,
            'is_active' => true,
            'redemption_threshold' => $threshold,
            // KES 30 a point: the KES 150 fare below costs 5 points, so every
            // figure in this file reads as it did when a ride cost the flat
            // threshold -- except that it is now the FARE being paid.
            'point_value' => 30,
            'divisor' => 100,
        ]);
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'sacco_id' => $saccoId, 'balance' => $balance,
        ]);

        return $user;
    }

    /** @return array{0: User, 1: Booking} */
    private function reservation(float $balance = 50, float $threshold = 5, bool $active = true): array
    {
        $world = $this->makeWorld();
        $status = $this->makeQueueStatus('pb-'.$this->nextSequence(), 'Pending');
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $status, $world['owner'],
        );

        $user = $this->passengerWith((int) $world['sacco']->id, $balance, $threshold);

        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'queue_id' => $queue->id,
            'from_id' => $world['from']->id,
            'to_id' => $world['to']->id,
            'passengers' => 1,
            'amount' => 150,
            'paid' => false,
            'status' => $active,
            'name' => 'Test Passenger',
            'phone' => '254700111222',
            'created_by' => $user->id,
        ]);

        return [$user, $booking];
    }

    #[Test]
    public function a_passenger_pays_for_a_ride_with_points(): void
    {
        // The happy path, which is the whole point of the feature.
        [$user, $booking] = $this->reservation(balance: 50, threshold: 5);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('booking_id', $booking->id)
            ->assertJsonPath('points_spent', 5);

        $booking->refresh();
        $this->assertTrue((bool) $booking->paid, 'the ride has to actually be settled');
        $this->assertSame(PaymentMethod::LoyaltyPoints->value, $booking->payment_method->value ?? $booking->payment_method);

        $this->assertEqualsWithDelta(45, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001);

        $ledger = LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)
            ->where('type', LoyaltyTransactionType::Redeemed->value)
            ->first();
        $this->assertNotNull($ledger, 'the spend must be on the ledger, not only on the balance');
        $this->assertEqualsWithDelta(-5, (float) $ledger->value, 0.001);
    }

    #[Test]
    public function an_expired_reservation_cannot_be_paid_for(): void
    {
        // THE ONE THAT COST POINTS FOR NOTHING. The sweep has already released
        // these seats; settling the booking buys a seat somebody else can now buy.
        [$user, $booking] = $this->reservation(balance: 50, threshold: 5, active: false);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This reservation has expired. Please book again.');

        $this->assertFalse((bool) $booking->refresh()->paid);
        $this->assertEqualsWithDelta(50, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001,
            'an expired booking must not cost the passenger a single point');
    }

    #[Test]
    public function retrying_a_redemption_reports_the_success_that_already_happened(): void
    {
        // The retry is the normal case on a matatu, not the exception. It must not
        // spend twice, and it must not report failure for something that worked.
        [$user, $booking] = $this->reservation(balance: 50, threshold: 5);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['booking_id' => $booking->id])->assertOk();
        $this->postJson(self::URL, ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('points_spent', 5);

        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)
            ->where('type', LoyaltyTransactionType::Redeemed->value)->count(),
            'a retry must not write a second ledger row');
        $this->assertEqualsWithDelta(45, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001,
            'and must not debit twice');
    }

    #[Test]
    public function a_booking_already_paid_by_another_rail_is_still_a_conflict(): void
    {
        // The replay path must not swallow a real conflict: if M-Pesa settled the
        // ride first, the passenger keeps their points.
        [$user, $booking] = $this->reservation(balance: 50, threshold: 5);
        $booking->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);

        // Settling by M-Pesa EARNS points -- BookingPaid fires from Booking::updated
        // and EarnLoyaltyPoints credits fare/divisor. That is the product working,
        // so the balance here is 51.5, not 50. What matters is that the REFUSED
        // redeem moves nothing, so measure from after the M-Pesa settlement rather
        // than pinning a number that encodes the earn rate.
        $before = (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance');

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This booking is already paid.');

        $this->assertEqualsWithDelta($before, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001,
            'a refused redemption must not move the balance');
    }

    #[Test]
    public function too_few_points_leaves_the_booking_unpaid(): void
    {
        [$user, $booking] = $this->reservation(balance: 2, threshold: 5);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This ride costs 5 points and you have 2.')
            ->assertJsonPath('points_needed', 5);

        $this->assertFalse((bool) $booking->refresh()->paid);
        $this->assertEqualsWithDelta(2, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001);
    }
}
