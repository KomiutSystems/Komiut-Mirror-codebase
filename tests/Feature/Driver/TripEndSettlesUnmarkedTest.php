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
use App\Models\User;
use App\Models\VehicleUser;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A trip cannot end with paid passengers in limbo.
 *
 * driver/trip/end used to touch only the queue row. A passenger who paid, was
 * never boarded, and was never marked not-boarded stayed exactly there forever:
 * money kept, seat consumed, no refund, no notification.
 *
 * THE OBVIOUS FIX IS THE WRONG ONE. Refunding every unmarked passenger at trip
 * end reads well until you ask what "unmarked" means: it is EITHER a no-show the
 * conductor forgot to record OR a passenger who rode and the conductor forgot to
 * tap board. Auto-refunding refunds the second kind too — and it hands a
 * conductor a collusion move: never tap board, the friend rides AND gets their
 * fare back. So every refund stays an explicit decision. The trip refuses to end
 * while `confirmed` passengers (paid, active, not boarded) remain, names them so
 * the app can put them in front of the conductor, and offers one deliberate
 * call — `unmarked: "no_show"` — for the conductor at the far terminus who knows
 * nobody left on the list ever turned up.
 */
final class TripEndSettlesUnmarkedTest extends QueueTestCase
{
    private const END = '/api/v1/auth/driver/trip/end';

    /** @return array{driver: User, queue: Queue, world: array<string, mixed>, sacco_id: int} */
    private function departedTrip(float $threshold = 5, bool $completedConfigured = true): array
    {
        $world = $this->makeWorld();
        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'is_active' => true,
            'redemption_threshold' => $threshold, 'divisor' => 100,
        ]);
        if ($completedConfigured) {
            $this->makeQueueStatus('Completed '.$this->nextSequence(), 'Completed');
        }

        $active = $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active');
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner'],
        );

        $driver = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return ['driver' => $driver, 'queue' => $queue, 'world' => $world, 'sacco_id' => (int) $world['sacco']->id];
    }

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

    #[Test]
    public function a_trip_refuses_to_end_while_a_paid_passenger_is_unmarked(): void
    {
        $trip = $this->departedTrip();
        [$passenger, $booking] = $this->paidWithPoints($trip);

        Sanctum::actingAs($trip['driver']);
        $this->postJson(self::END)
            ->assertStatus(409)
            ->assertJsonPath('unmarked.0.id', $booking->id)
            ->assertJsonPath('unmarked.0.payment_method', 'loyalty_points');

        $this->assertNull($trip['queue']->fresh()->end_time, 'the trip must not have ended');
        $this->assertEqualsWithDelta(45, $this->balance($passenger, $trip['sacco_id']), 0.001,
            'refusing to end must not itself refund anything -- that is the decision being demanded');
    }

    #[Test]
    public function marking_the_passenger_boarded_lets_the_trip_end_with_no_refund(): void
    {
        // The forgot-to-tap-board case. The guard surfaces it; the conductor
        // resolves it correctly; the passenger who rode keeps nothing they are not
        // owed and the SACCO keeps the fare.
        $trip = $this->departedTrip();
        [$passenger, $booking] = $this->paidWithPoints($trip);

        Sanctum::actingAs($trip['driver']);
        $this->postJson('/api/auth/driver/bookings/'.$booking->id.'/mark', ['action' => 'board'])->assertOk();
        $this->postJson(self::END)->assertOk()->assertJsonPath('no_shows', 0);

        $this->assertNotNull($trip['queue']->fresh()->end_time);
        $this->assertEqualsWithDelta(45, $this->balance($passenger, $trip['sacco_id']), 0.001);
        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Refunded->value)->count());
    }

    #[Test]
    public function ending_with_unmarked_no_show_refunds_each_one_through_the_same_path_as_the_tap(): void
    {
        // The fast path for the conductor at the far terminus. Every passenger
        // left `confirmed` is no-showed: seat released, refund written, passenger
        // told — via the identical code the per-passenger tap uses.
        Event::fake([BookingCancelled::class]);
        $trip = $this->departedTrip(threshold: 5);
        [$a, $bookingA] = $this->paidWithPoints($trip);
        [$b, $bookingB] = $this->paidWithPoints($trip);

        Sanctum::actingAs($trip['driver']);
        $this->postJson(self::END, ['unmarked' => 'no_show'])
            ->assertOk()
            ->assertJsonPath('no_shows', 2);

        $this->assertNotNull($trip['queue']->fresh()->end_time);
        foreach ([[$a, $bookingA], [$b, $bookingB]] as [$p, $bk]) {
            $this->assertEqualsWithDelta(50, $this->balance($p, $trip['sacco_id']), 0.001, 'points back');
            $this->assertFalse((bool) $bk->fresh()->status, 'seat released');
            $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
                ->where('booking_id', $bk->id)->where('type', LoyaltyTransactionType::Refunded->value)->count());
        }
        Event::assertDispatchedTimes(BookingCancelled::class, 2);
        Event::assertDispatched(BookingCancelled::class,
            fn (BookingCancelled $e) => $e->reason === BookingCancellationReason::NoShow);
    }

    #[Test]
    public function an_unpaid_reservation_does_not_block_the_end_and_is_not_refunded(): void
    {
        // `reserved` (paid=false) is not `confirmed`. The passenger never paid, so
        // there is nothing in limbo and nothing to give back; the ordinary expiry
        // sweep owns that row.
        $trip = $this->departedTrip();
        $passenger = $this->makeUser();
        $booking = $this->makeBooking($trip['queue'], $passenger, $trip['world']['from'], $trip['world']['to']);

        Sanctum::actingAs($trip['driver']);
        $this->postJson(self::END)->assertOk()->assertJsonPath('no_shows', 0);

        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()->where('booking_id', $booking->id)->count());
    }

    #[Test]
    public function the_fast_path_is_idempotent_with_the_tap(): void
    {
        // A passenger no-showed by tap and then swept by `unmarked: no_show` on a
        // retried end call must be refunded exactly once. The tap already moved
        // them out of `confirmed`, so the end sees nothing -- and even if it did,
        // the ledger's unique key would refuse a second Refunded row.
        $trip = $this->departedTrip(threshold: 5);
        [$passenger, $booking] = $this->paidWithPoints($trip);

        Sanctum::actingAs($trip['driver']);
        $this->postJson('/api/auth/driver/bookings/'.$booking->id.'/mark', ['action' => 'no_show'])->assertOk();
        $this->postJson(self::END, ['unmarked' => 'no_show'])->assertOk()->assertJsonPath('no_shows', 0);

        $this->assertEqualsWithDelta(50, $this->balance($passenger, $trip['sacco_id']), 0.001);
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Refunded->value)->count());
    }

    #[Test]
    public function an_end_that_cannot_complete_refunds_nobody(): void
    {
        // The Completed status lookup used to sit AFTER the no-show sweep, so on
        // an environment missing it every unmarked passenger was refunded,
        // released and told so, and then the call 422'd with the trip still
        // Active. Every precondition that can refuse the end runs before
        // anything irreversible does.
        Event::fake([BookingCancelled::class]);
        $trip = $this->departedTrip(threshold: 5, completedConfigured: false);
        [$passenger, $booking] = $this->paidWithPoints($trip);

        Sanctum::actingAs($trip['driver']);
        $this->postJson(self::END, ['unmarked' => 'no_show'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'No completed status configured.');

        $this->assertNull($trip['queue']->fresh()->end_time, 'the trip did not end');
        $this->assertTrue((bool) $booking->fresh()->status, 'and the passenger was not no-showed for an end that never happened');
        $this->assertEqualsWithDelta(45, $this->balance($passenger, $trip['sacco_id']), 0.001, 'nothing refunded');
        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', LoyaltyTransactionType::Refunded->value)->count());
        Event::assertNotDispatched(BookingCancelled::class);
    }
}
