<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\Queue;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A ride costs what it costs -- in points as in shillings.
 *
 * THE BUG, 2026-09-12. A free ride cost `redemption_threshold` points FLAT:
 * the same 5 points for a KES 60 hop and a KES 400 trip, for one seat and for
 * four. "Tom has 50 points, meaning a free ride" -- and found he could book any
 * distance, for any number of people, ten times over. Found on the first real
 * booking on Frankfurt.
 *
 * Now a point is worth `point_value` shillings of fare and a booking costs
 * (fare x seats) / point_value. The money rail was wrong in the same way --
 * bookings.amount was the per-seat fare while `passengers` counted seats, so
 * M-Pesa would have collected one fare for four -- and is fixed at the source:
 * bookings.amount is the whole fare. Both rails price off that one number.
 */
final class PointsArePricedByFareTest extends QueueTestCase
{
    private const REDEEM = '/api/v1/auth/book_a_ride/loyalty/redeem';

    private const SCAN = '/api/v1/auth/qrcode/redeem_points';

    private const ADD = '/api/v1/auth/book_a_ride/booking/add';

    /** KES 3 a point: a KES 150 ride costs 50 points, the figure the bug was reported with. */
    private function program(array $world, ?float $pointValue = 3.0, float $threshold = 50): LoyaltyProgram
    {
        return LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'is_active' => true, 'divisor' => 100,
            'redemption_threshold' => $threshold, 'point_value' => $pointValue,
        ]);
    }

    private function passengerWith(array $world, float $balance): User
    {
        $user = $this->makeUser();
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'sacco_id' => $world['sacco']->id, 'balance' => $balance,
        ]);

        return $user;
    }

    private function queue(array $world): Queue
    {
        return $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'), $world['owner'],
        );
    }

    /** An unpaid booking whose `amount` is already the whole fare, as addBooking writes it. */
    private function reservation(array $world, User $user, float $amount, int $seats = 1): Booking
    {
        $booking = $this->makeBooking($this->queue($world), $user, $world['from'], $world['to']);
        $booking->forceFill(['amount' => $amount, 'passengers' => $seats])->save();

        return $booking->fresh();
    }

    private function balance(User $u, array $world): float
    {
        return (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $u->id)->where('sacco_id', $world['sacco']->id)->value('balance');
    }

    #[Test]
    public function a_standard_ride_costs_the_threshold_and_a_longer_one_costs_more(): void
    {
        $world = $this->makeWorld();
        $this->program($world, pointValue: 3.0, threshold: 50);
        $tom = $this->passengerWith($world, balance: 120);

        Sanctum::actingAs($tom);

        // KES 150 = 50 points: the "one free ride" Tom was promised.
        $short = $this->reservation($world, $tom, amount: 150);
        $this->postJson(self::REDEEM, ['booking_id' => $short->id])->assertOk()->assertJsonPath('points_spent', 50);
        $this->assertEqualsWithDelta(70, $this->balance($tom, $world), 0.001);

        // KES 210 = 70 points. Not 50. A longer ride is a longer ride.
        $long = $this->reservation($world, $tom, amount: 210);
        $this->postJson(self::REDEEM, ['booking_id' => $long->id])->assertOk()->assertJsonPath('points_spent', 70);
        $this->assertEqualsWithDelta(0, $this->balance($tom, $world), 0.001);

        $this->assertEqualsWithDelta(-70, (float) LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $long->id)->where('type', LoyaltyTransactionType::Redeemed->value)->value('value'), 0.001,
            'the ledger carries the real cost, not the threshold');
    }

    #[Test]
    public function enough_points_for_one_ride_is_not_enough_for_a_longer_one(): void
    {
        // The exact report: 50 points, "a free ride", used on a KES 300 trip.
        $world = $this->makeWorld();
        $this->program($world, pointValue: 3.0, threshold: 50);
        $tom = $this->passengerWith($world, balance: 50);
        $booking = $this->reservation($world, $tom, amount: 300);

        Sanctum::actingAs($tom);
        $this->postJson(self::REDEEM, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This ride costs 100 points and you have 50.')
            ->assertJsonPath('points_needed', 100);

        $this->assertFalse((bool) $booking->fresh()->paid);
        $this->assertEqualsWithDelta(50, $this->balance($tom, $world), 0.001, 'a refusal moves nothing');
    }

    #[Test]
    public function four_seats_cost_four_times_one_seat(): void
    {
        $world = $this->makeWorld();
        $this->program($world, pointValue: 3.0);
        $tom = $this->passengerWith($world, balance: 250);

        Sanctum::actingAs($tom);

        $one = $this->reservation($world, $tom, amount: 150, seats: 1);
        $four = $this->reservation($world, $tom, amount: 600, seats: 4);

        $this->postJson(self::REDEEM, ['booking_id' => $one->id])->assertOk()->assertJsonPath('points_spent', 50);
        $this->postJson(self::REDEEM, ['booking_id' => $four->id])->assertOk()->assertJsonPath('points_spent', 200);
        $this->assertEqualsWithDelta(0, $this->balance($tom, $world), 0.001);
    }

    #[Test]
    public function a_booking_is_written_with_the_whole_fare_not_one_seat_of_it(): void
    {
        // makeWorld prices the route at a flat KES 200 a seat. Three seats is
        // KES 600 -- the figure M-Pesa will be asked for and the figure points
        // will be priced on. It used to be 200 with `passengers` = 3.
        $world = $this->makeWorld();
        $queue = $this->queue($world);
        $user = $this->makeUser([], $world['sacco']);
        $seats = $world['arrangements'];

        Sanctum::actingAs($user);
        $response = $this->postJson(self::ADD, [
            'id' => $queue->id,
            'seats' => $seats[0]->id.','.$seats[1]->id.','.$seats[2]->id,
            'name' => 'Wanjiku', 'phone' => '0722123456',
        ]);

        $response->assertOk()
            ->assertJsonPath('amount', 600)
            ->assertJsonPath('fare_per_seat', 200)
            ->assertJsonPath('passengers', 3);

        $booking = Booking::withoutGlobalScopes()->findOrFail($response->json('booking_id'));
        $this->assertEqualsWithDelta(600, (float) $booking->amount, 0.001);
        $this->assertSame(3, (int) $booking->passengers);
    }

    #[Test]
    public function a_booking_holds_at_most_five_seats(): void
    {
        // Decided 2026-09-12: the booker and four others. Six is refused before
        // anything is written; five goes through.
        $world = $this->makeWorld();
        $queue = $this->queue($world);
        $user = $this->makeUser([], $world['sacco']);
        $seats = array_merge($world['arrangements'], $this->makeSeatArrangements($world['seat'], 4));
        $ids = fn (int $n) => implode(',', array_map(fn ($s) => $s->id, array_slice($seats, 0, $n)));

        Sanctum::actingAs($user);

        $this->postJson(self::ADD, ['id' => $queue->id, 'seats' => $ids(6), 'name' => 'Wanjiku', 'phone' => '0722123456'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'You can book up to 5 seats at a time: yourself and 4 others.');
        $this->assertSame(0, Booking::withoutGlobalScopes()->count());

        $this->postJson(self::ADD, ['id' => $queue->id, 'seats' => $ids(5), 'name' => 'Wanjiku', 'phone' => '0722123456'])
            ->assertOk()
            ->assertJsonPath('passengers', 5)
            ->assertJsonPath('amount', 1000);
    }

    #[Test]
    public function a_scanned_ride_costs_the_fare_the_passenger_names(): void
    {
        // A scan identifies a bus, not a journey, so the passenger says what the
        // conductor asked for -- exactly as they do for an M-Pesa QR payment.
        $world = $this->makeWorld();
        $this->program($world, pointValue: 3.0);
        $tom = $this->passengerWith($world, balance: 50);

        Sanctum::actingAs($tom);

        $this->postJson(self::SCAN, ['vehicle_id' => $world['vehicle']->id])
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['amount']]);

        $this->postJson(self::SCAN, ['vehicle_id' => $world['vehicle']->id, 'amount' => 60])
            ->assertOk()
            ->assertJsonPath('points_spent', 20)
            ->assertJsonPath('fare', 60)
            ->assertJsonPath('balance', 30);
    }

    #[Test]
    public function a_program_that_has_not_valued_its_points_cannot_redeem_them(): void
    {
        // No fallback to the flat threshold this retires. The card still shows;
        // paying does not, until the SACCO says what a point is worth.
        $world = $this->makeWorld();
        $this->program($world, pointValue: null);
        $tom = $this->passengerWith($world, balance: 500);
        $booking = $this->reservation($world, $tom, amount: 150);

        Sanctum::actingAs($tom);
        $this->postJson(self::REDEEM, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Point redemption is not set up for this SACCO yet.');
        $this->postJson(self::SCAN, ['vehicle_id' => $world['vehicle']->id, 'amount' => 60])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Point redemption is not set up for this SACCO yet.');

        $this->assertEqualsWithDelta(500, $this->balance($tom, $world), 0.001);
    }

    #[Test]
    public function the_card_says_what_the_points_are_worth(): void
    {
        $world = $this->makeWorld();
        $this->program($world, pointValue: 3.0, threshold: 50);
        $tom = $this->passengerWith($world, balance: 40);

        Sanctum::actingAs($tom);
        $card = $this->getJson('/api/v1/auth/book_a_ride/loyalty/summary')->assertOk()->json('loyalty.0');

        $this->assertEqualsWithDelta(3.0, $card['point_value'], 0.001);
        $this->assertEqualsWithDelta(120, $card['balance_value'], 0.001, '40 points buys KES 120 of travel');
        $this->assertEqualsWithDelta(50, $card['redemption_threshold'], 0.001, 'the goal on the card is unchanged');
        $this->assertFalse($card['eligible_to_redeem'], 'below the standard-ride goal');
    }

    #[Test]
    public function earning_follows_the_whole_fare_too(): void
    {
        // KES 600 for four seats at divisor 100 earns 6 points, not 1.5.
        $world = $this->makeWorld();
        $this->program($world, pointValue: 3.0);
        $tom = $this->passengerWith($world, balance: 0);
        $booking = $this->reservation($world, $tom, amount: 600, seats: 4);

        $booking->update(['paid' => true, 'payment_method' => \App\Enums\PaymentMethod::Mpesa]);

        $this->assertEqualsWithDelta(6, $this->balance($tom, $world), 0.001);
    }
}
