<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\RewardPartner;
use App\Events\PassengerBalanceChanged;
use App\Models\Booking;
use App\Models\CarbonCreditAccount;
use App\Models\CarbonCreditReward;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\Sacco;
use App\Models\User;
use App\Services\CarbonCredits\CarbonCreditService;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Queues\QueueTestCase;

/**
 * PassengerBalanceChanged — the realtime signal behind the app's Activity screen.
 *
 * Nothing broadcast when a passenger's points or carbon credits moved, so the
 * only way to see a fare turn into a reward was to pull to refresh. These pin
 * the three things that make the signal safe to rely on and safe to ship:
 *
 *  1. THE WIRE SHAPE IS OURS. Laravel's notification broadcaster overwrites
 *     `type` with get_class($notification) — a live notification once arrived as
 *     "App\Notifications\PlatformNotification" while the REST copy said "trip"
 *     (see BroadcastPayloadShapeTest). A ShouldBroadcast EVENT is not run through
 *     that class, so the name and every key are ours; this asserts it rather than
 *     assuming it.
 *  2. IT NEVER COSTS A PAYMENT. Earning runs inside the settlement transaction,
 *     under EarnLoyaltyPoints' savepoint. A throw from the broadcast would roll
 *     that savepoint back and the passenger would silently lose the points a
 *     completed payment had already bought them. The two "reverb is down" tests
 *     are the regression guard for exactly that, and they fail if the catch in
 *     PassengerBalanceChanged::announce() is ever removed.
 *  3. IT SAYS NOTHING WHEN NOTHING MOVED. Replays are the normal case here —
 *     Safaricom redelivers, BookingPaid can fire twice — and both ledgers are
 *     idempotent. A duplicate must not produce a second "your balance changed".
 */
final class BalanceBroadcastTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pinned, not inherited from env: every number below is arithmetic on
        // this one. 300 KSh per credit, matching CarbonCreditTest.
        config(['carbon_credits.ksh_per_credit' => 300]);
    }

    // ------------------------------------------------------------- fixtures

    private function program(Sacco $sacco, float $divisor = 100, float $threshold = 500): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'sacco_id' => $sacco->id,
            'divisor' => $divisor,
            'redemption_threshold' => $threshold,
            'is_active' => true,
        ]);
    }

    /** An unpaid booking on its own queue. makeWorld() brings its own sacco/route/vehicle. */
    private function booking(array $world, User $user, float $amount = 200): Booking
    {
        $status = $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending');
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $status, $world['owner'],
            'QN-'.$this->nextSequence(),
        );

        $booking = $this->makeBooking($queue, $user, $world['from'], $world['to'], 'Wanjiku');
        $booking->forceFill(['amount' => $amount])->save();

        return $booking;
    }

    private function reward(int $credits = 2): CarbonCreditReward
    {
        return CarbonCreditReward::create([
            'name' => '500MB Safaricom data',
            'partner' => RewardPartner::Safaricom,
            'credits_required' => $credits,
            'is_active' => true,
        ]);
    }

    /**
     * Every PassengerBalanceChanged dispatched so far, optionally for one scheme.
     *
     * A paid ride moves BOTH ledgers, so an unfiltered count is not a useful
     * assertion — this suite always asks about one scheme at a time.
     *
     * @return array<int, PassengerBalanceChanged>
     */
    private function fired(?string $scheme = null): array
    {
        $all = [];

        // Passes as long as at least one fired; the closure sees every one.
        Event::assertDispatched(PassengerBalanceChanged::class,
            function (PassengerBalanceChanged $event) use (&$all) {
                $all[] = $event;

                return true;
            });

        if ($scheme === null) {
            return $all;
        }

        return array_values(array_filter($all, fn (PassengerBalanceChanged $e) => $e->scheme === $scheme));
    }

    // ------------------------------------------------------- the wire shape

    #[Test]
    public function the_client_binds_to_our_own_name_never_a_php_class(): void
    {
        $event = new PassengerBalanceChanged(
            userId: 7, scheme: PassengerBalanceChanged::SCHEME_LOYALTY,
            balance: 12.5, delta: 2.0, reason: 'earned', saccoId: 3,
        );

        $this->assertSame('balance.changed', $event->broadcastAs());
        $this->assertStringNotContainsString('App\\', $event->broadcastAs());
        $this->assertStringNotContainsString('PassengerBalanceChanged', $event->broadcastAs());
    }

    #[Test]
    public function no_field_on_the_wire_carries_a_php_class_name(): void
    {
        // The trap this whole file exists to avoid. BroadcastNotificationCreated
        // merges `['id' => ..., 'type' => get_class($notification)]` OVER the
        // notification's own data. An event is not run through that class —
        // Laravel sends broadcastWith() as-is and adds only `socket` — so the
        // discriminator here is `scheme`, and it is ours.
        $event = new PassengerBalanceChanged(
            userId: 7, scheme: PassengerBalanceChanged::SCHEME_CARBON,
            balance: 3, delta: 1, reason: 'earned', progressCents: 4500,
        );

        $payload = $event->broadcastWith();

        $this->assertSame('carbon', $payload['scheme']);

        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString('App\\', $value,
                    "the `{$key}` field leaked a PHP class name onto the socket");
            }
        }
    }

    #[Test]
    public function the_payload_key_set_is_exactly_this_camel_case_shape(): void
    {
        // Pinned because the app parses this without a transform layer, the same
        // way NotificationResource is pinned. A rename here is a client break.
        $event = new PassengerBalanceChanged(
            userId: 7, scheme: PassengerBalanceChanged::SCHEME_LOYALTY,
            balance: 12.5, delta: -500.0, reason: 'redeemed', saccoId: 3,
        );

        $payload = $event->broadcastWith();

        $this->assertSame(
            ['scheme', 'saccoId', 'balance', 'delta', 'reason', 'progressCents', 'at'],
            array_keys($payload),
        );
        $this->assertSame(3, $payload['saccoId']);
        $this->assertSame(12.5, $payload['balance']);
        $this->assertSame(-500.0, $payload['delta']);
        $this->assertNull($payload['progressCents'], 'progressCents is carbon-only');
        $this->assertNotEmpty($payload['at']);
    }

    #[Test]
    public function it_goes_to_the_passengers_own_private_channel_and_no_one_elses(): void
    {
        // The channel routes/channels.php already authorises on
        // `(int) $user->id === (int) $id`, which BroadcastAuthTest covers. Reusing
        // it means no new authorisation surface for balances.
        $event = new PassengerBalanceChanged(
            userId: 41, scheme: PassengerBalanceChanged::SCHEME_CARBON,
            balance: 3, delta: 1, reason: 'earned', progressCents: 0,
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-App.Models.User.41', (string) $channels[0]);
    }

    // ------------------------------------------------------------- loyalty

    #[Test]
    public function earning_points_on_a_paid_ride_tells_that_passenger(): void
    {
        Event::fake([PassengerBalanceChanged::class]);

        $world = $this->makeWorld();
        $this->program($world['sacco'], divisor: 100);   // 1 point per KES 100
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->booking($world, $passenger, 200);

        $booking->paid = true;
        $booking->save();                                 // BookingPaid → earn

        $points = $this->fired(PassengerBalanceChanged::SCHEME_LOYALTY);

        $this->assertCount(1, $points);
        $this->assertSame((int) $passenger->id, $points[0]->userId);
        $this->assertSame((int) $world['sacco']->id, $points[0]->saccoId);
        $this->assertSame(2.0, $points[0]->broadcastWith()['delta']);
        $this->assertSame(2.0, $points[0]->broadcastWith()['balance'], 'the balance AFTER the move');
        $this->assertSame('earned', $points[0]->reason);
    }

    #[Test]
    public function the_balance_on_the_wire_is_the_running_total_not_this_fare(): void
    {
        // The client patches its card from `balance`, so a second earn has to
        // carry 4.0 and not 2.0 again.
        Event::fake([PassengerBalanceChanged::class]);

        $world = $this->makeWorld();
        $this->program($world['sacco'], divisor: 100);
        $passenger = $this->makeUser([], $world['sacco']);

        foreach ([200, 200] as $fare) {
            $booking = $this->booking($world, $passenger, $fare);
            $booking->paid = true;
            $booking->save();
        }

        $balances = array_map(
            fn (PassengerBalanceChanged $e) => $e->broadcastWith()['balance'],
            $this->fired(PassengerBalanceChanged::SCHEME_LOYALTY),
        );

        $this->assertSame([2.0, 4.0], $balances);
    }

    #[Test]
    public function a_replayed_earn_says_nothing_the_second_time(): void
    {
        // The ledger is idempotent on (booking_id, type); the signal must be too,
        // or a redelivered callback shows the passenger a phantom earn.
        Event::fake([PassengerBalanceChanged::class]);

        $world = $this->makeWorld();
        $this->program($world['sacco'], divisor: 100);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->booking($world, $passenger, 200);

        $booking->paid = true;
        $booking->save();

        // Two more attempts at the same fare, the shape a redelivered callback
        // takes. Both find the ledger row already there.
        app(LoyaltyService::class)->earnForBooking($booking);
        app(LoyaltyService::class)->earnForBooking($booking);

        $this->assertCount(1, $this->fired(PassengerBalanceChanged::SCHEME_LOYALTY));
    }

    #[Test]
    public function spending_points_on_a_free_ride_tells_the_passenger_too(): void
    {
        Event::fake([PassengerBalanceChanged::class]);

        $world = $this->makeWorld();
        $this->program($world['sacco'], divisor: 100, threshold: 500);
        $passenger = $this->makeUser([], $world['sacco']);
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $passenger->id, 'sacco_id' => $world['sacco']->id, 'balance' => 800,
        ]);
        $booking = $this->booking($world, $passenger, 200);

        $result = app(LoyaltyService::class)->redeemForBooking($passenger, $booking);

        $this->assertTrue($result['ok']);
        // The public contract of this method is unchanged by the broadcast.
        $this->assertArrayNotHasKey('moved', $result);

        $spent = $this->fired(PassengerBalanceChanged::SCHEME_LOYALTY);

        // Exactly one: settling the booking flips it to paid and fires
        // BookingPaid, but a ride bought with points earns nothing back.
        $this->assertCount(1, $spent);
        $this->assertSame(-500.0, $spent[0]->broadcastWith()['delta']);
        $this->assertSame(300.0, $spent[0]->broadcastWith()['balance']);
        $this->assertSame('redeemed', $spent[0]->reason);
    }

    #[Test]
    public function a_fare_that_earns_no_points_broadcasts_nothing(): void
    {
        // No active program for this SACCO — nothing is credited, so there is no
        // balance change to announce.
        Event::fake([PassengerBalanceChanged::class]);

        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->booking($world, $passenger, 200);

        $booking->paid = true;
        $booking->save();

        Event::assertNotDispatched(PassengerBalanceChanged::class,
            fn (PassengerBalanceChanged $e) => $e->scheme === PassengerBalanceChanged::SCHEME_LOYALTY);
    }

    // -------------------------------------------------------------- carbon

    #[Test]
    public function a_fare_that_mints_no_credit_still_reports_the_accumulator(): void
    {
        // 150 KSh is half a credit at 300. The delta is legitimately zero — but
        // progress_cents moved, which is the "X KSh to your next credit" line on
        // the card, and the ledger records the ride. The passenger can see it, so
        // the socket says so.
        Event::fake([PassengerBalanceChanged::class]);

        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->booking($world, $passenger, 150);

        $booking->paid = true;
        $booking->save();

        $carbon = $this->fired(PassengerBalanceChanged::SCHEME_CARBON);

        $this->assertCount(1, $carbon);
        $this->assertSame(0, $carbon[0]->broadcastWith()['delta']);
        $this->assertSame(0, $carbon[0]->broadcastWith()['balance']);
        $this->assertSame(15000, $carbon[0]->broadcastWith()['progressCents']);
        $this->assertNull($carbon[0]->broadcastWith()['saccoId'],
            'carbon is platform-wide — one balance across every SACCO and brand');
    }

    #[Test]
    public function minting_a_credit_reports_the_new_balance(): void
    {
        Event::fake([PassengerBalanceChanged::class]);

        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->booking($world, $passenger, 900);   // 3 credits at 300

        $booking->paid = true;
        $booking->save();

        $carbon = $this->fired(PassengerBalanceChanged::SCHEME_CARBON);

        $this->assertCount(1, $carbon);
        $this->assertSame(3, $carbon[0]->broadcastWith()['delta']);
        $this->assertSame(3, $carbon[0]->broadcastWith()['balance']);
        $this->assertSame(0, $carbon[0]->broadcastWith()['progressCents']);
        $this->assertSame('earned', $carbon[0]->reason);
    }

    #[Test]
    public function claiming_a_reward_and_cancelling_it_both_move_the_balance(): void
    {
        Event::fake([PassengerBalanceChanged::class]);

        $passenger = $this->makeUser();
        CarbonCreditAccount::updateOrCreate(['user_id' => $passenger->id], ['credits' => 5]);

        $service = app(CarbonCreditService::class);
        $redemption = $service->redeem($passenger, $this->reward(2))['redemption'];
        $service->cancel($redemption, 'Partner out of stock.');

        $carbon = $this->fired(PassengerBalanceChanged::SCHEME_CARBON);

        $this->assertCount(2, $carbon);

        $this->assertSame(-2, $carbon[0]->broadcastWith()['delta']);
        $this->assertSame(3, $carbon[0]->broadcastWith()['balance']);
        $this->assertSame('redeemed', $carbon[0]->reason);

        $this->assertSame(2, $carbon[1]->broadcastWith()['delta']);
        $this->assertSame(5, $carbon[1]->broadcastWith()['balance']);
        $this->assertSame('refunded', $carbon[1]->reason);
    }

    #[Test]
    public function delivering_a_claim_moves_no_balance_and_so_says_nothing(): void
    {
        // The credits left at redeem(). Fulfilment is a status change, and a
        // "your balance changed" with a zero delta would be a lie — the passenger
        // gets a real notification that the reward shipped instead.
        $passenger = $this->makeUser();
        CarbonCreditAccount::updateOrCreate(['user_id' => $passenger->id], ['credits' => 5]);
        $service = app(CarbonCreditService::class);
        $redemption = $service->redeem($passenger, $this->reward(2))['redemption'];

        Event::fake([PassengerBalanceChanged::class]);
        $service->fulfil($redemption, 'SAF-9931');

        Event::assertNotDispatched(PassengerBalanceChanged::class);
    }

    // ----------------------------------------- it must never cost a payment

    #[Test]
    public function a_broadcast_failure_never_costs_the_passenger_their_points(): void
    {
        // THE ONE THAT MATTERS. earnForBooking runs inside EarnLoyaltyPoints'
        // savepoint, which in the reconcile path is inside the settlement
        // transaction. Without the catch in PassengerBalanceChanged::announce(),
        // a throw here rolls that savepoint back and the balance below is 0 — a
        // completed payment that silently bought no points because a socket
        // server was unreachable.
        Event::listen(PassengerBalanceChanged::class, function (): void {
            throw new RuntimeException('reverb is down');
        });

        $world = $this->makeWorld();
        $this->program($world['sacco'], divisor: 100);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->booking($world, $passenger, 200);

        $booking->paid = true;
        $booking->save();   // must not throw

        $this->assertTrue($booking->refresh()->paid, 'the payment itself must stand');
        $this->assertEquals(2.0, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $passenger->id)
            ->where('sacco_id', $world['sacco']->id)
            ->value('balance'), 'the points survived the broadcast failure');
    }

    #[Test]
    public function a_broadcast_failure_never_costs_the_passenger_their_carbon_credits(): void
    {
        // Same guard on the other ledger: EarnCarbonCredits wraps the accrual in
        // its own savepoint for the same reason.
        Event::listen(PassengerBalanceChanged::class, function (): void {
            throw new RuntimeException('reverb is down');
        });

        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->booking($world, $passenger, 900);   // 3 credits at 300

        $booking->paid = true;
        $booking->save();   // must not throw

        $account = CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail();
        $this->assertSame(3, $account->credits, 'the credits survived the broadcast failure');
        $this->assertSame(90000, $account->lifetime_spend_cents);
    }
}
