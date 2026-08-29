<?php

declare(strict_types=1);

namespace Tests\Feature\CarbonCredits;

use App\Enums\CarbonCreditType;
use App\Enums\RedemptionStatus;
use App\Enums\RewardPartner;
use App\Models\Booking;
use App\Models\CarbonCreditAccount;
use App\Models\CarbonCreditRedemption;
use App\Models\CarbonCreditReward;
use App\Models\CarbonCreditTransaction;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\CarbonCredits\CarbonCreditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The platform's carbon credit scheme.
 *
 * The load-bearing property is the accumulator: a credit is 1,000 KSh and a
 * matatu fare is 30–150, so crediting per ride would earn nothing forever. The
 * remainder has to carry between rides.
 */
final class CarbonCreditTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pinned, not inherited from env: every number below is arithmetic on
        // these two.
        config(['carbon_credits.ksh_per_credit' => 300]);
        config(['carbon_credits.notify_every_credits' => 10]);
    }

    private function service(): CarbonCreditService
    {
        return app(CarbonCreditService::class);
    }

    /** A paid booking for $amount, through the normal earning path. */
    private function paidRide(array $world, User $passenger, float $amount): Booking
    {
        $pending = $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-'.$this->nextSequence());

        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to']);
        $booking->forceFill(['amount' => $amount, 'paid' => true])->save();

        return $booking;
    }

    #[Test]
    public function a_single_matatu_fare_earns_no_credit_but_is_not_lost(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        $this->paidRide($world, $passenger, 150);

        $account = CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail();
        $this->assertSame(0, $account->credits, '150 KSh is half a credit at 300');
        // The whole point: it is carried, not discarded.
        $this->assertSame(15000, $account->progress_cents);
        $this->assertSame(15000, $account->lifetime_spend_cents);
    }

    #[Test]
    public function fares_accumulate_across_rides_until_they_cross_a_credit(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        // Seven 150 KSh rides = 1,050 KSh: three credits (900), 150 carried.
        for ($i = 0; $i < 7; $i++) {
            $this->paidRide($world, $passenger, 150);
        }

        $account = CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail();
        $this->assertSame(3, $account->credits);
        $this->assertSame(15000, $account->progress_cents, '150 KSh carried toward the next');
        $this->assertSame(105000, $account->lifetime_spend_cents);
    }

    #[Test]
    public function one_large_fare_can_mint_several_credits_at_once(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        // 3,000 KSh is the headline: exactly 10 credits at 300 apiece.
        $this->paidRide($world, $passenger, 3000);

        $account = CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail();
        $this->assertSame(10, $account->credits);
        $this->assertSame(0, $account->progress_cents);
    }

    #[Test]
    public function every_paid_ride_is_recorded_even_when_it_mints_nothing(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        $this->paidRide($world, $passenger, 100);

        // Otherwise a passenger cannot see why their progress moved.
        $row = CarbonCreditTransaction::where('user_id', $passenger->id)->firstOrFail();
        $this->assertSame(0, $row->credits);
        $this->assertSame(10000, $row->spend_cents);
        $this->assertSame(CarbonCreditType::Earned, $row->type);
    }

    #[Test]
    public function the_same_booking_cannot_be_credited_twice(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);
        // Paying it already earned, through the BookingPaid listener.
        $booking = $this->paidRide($world, $passenger, 600);
        $this->assertSame(2, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);

        // BookingPaid can fire more than once for one booking, and a re-credited
        // ride is money.
        $this->assertSame(0, $this->service()->earnForBooking($booking));

        $this->assertSame(2, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
        $this->assertSame(1, CarbonCreditTransaction::where('booking_id', $booking->id)->count());
    }

    #[Test]
    public function an_unpaid_booking_earns_nothing(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to']);
        $booking->forceFill(['amount' => 600, 'paid' => false])->save();

        $this->assertSame(0, $this->service()->earnForBooking($booking));
        $this->assertSame(0, CarbonCreditTransaction::count());
    }

    #[Test]
    public function credits_are_platform_wide_not_per_sacco(): void
    {
        $passenger = $this->makeUser();

        // Two different SACCOs, one balance — that is the difference from
        // loyalty points, which are per-SACCO.
        foreach ([$this->makeWorld(), $this->makeWorld()] as $world) {
            $this->paidRide($world, $passenger, 150);
        }

        $this->assertSame(1, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
        $this->assertSame(1, CarbonCreditAccount::where('user_id', $passenger->id)->count());
    }

    #[Test]
    public function the_earning_is_wired_to_the_paid_booking_event(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to']);
        $booking->forceFill(['amount' => 300])->save();

        // Flipping paid is what fires BookingPaid.
        $booking->forceFill(['paid' => true])->save();

        $this->assertSame(1, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
    }

    // ------------------------------------------------------------- redeeming

    private function reward(array $attributes = []): CarbonCreditReward
    {
        return CarbonCreditReward::create(array_merge([
            'name' => '500MB Safaricom data',
            'partner' => RewardPartner::Safaricom,
            'credits_required' => 2,
            'is_active' => true,
        ], $attributes));
    }

    private function giveCredits(User $user, int $credits): void
    {
        CarbonCreditAccount::updateOrCreate(['user_id' => $user->id], ['credits' => $credits]);
    }

    #[Test]
    public function a_reward_can_be_claimed_and_debits_the_balance(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 5);

        $result = $this->service()->redeem($passenger, $this->reward());

        $this->assertTrue($result['ok']);
        $this->assertSame(3, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
        $this->assertSame(RedemptionStatus::Pending, $result['redemption']->status);
    }

    #[Test]
    public function a_claim_beyond_the_balance_is_refused(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 1);

        $result = $this->service()->redeem($passenger, $this->reward());

        $this->assertFalse($result['ok']);
        $this->assertSame(422, $result['status']);
        $this->assertSame(1, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
    }

    #[Test]
    public function a_sold_out_reward_cannot_be_claimed(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 10);

        $result = $this->service()->redeem($passenger, $this->reward(['stock' => 0]));

        $this->assertFalse($result['ok']);
        $this->assertSame(10, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
    }

    #[Test]
    public function cancelling_a_claim_returns_the_credits_and_the_stock(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 5);
        $reward = $this->reward(['stock' => 1]);

        $redemption = $this->service()->redeem($passenger, $reward)['redemption'];
        $this->assertSame(0, $reward->fresh()->stock);

        $this->service()->cancel($redemption, 'Partner could not deliver');

        $this->assertSame(5, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
        $this->assertSame(1, $reward->fresh()->stock, 'a cancelled claim must not shrink the catalogue');
        $this->assertSame(RedemptionStatus::Cancelled, $redemption->fresh()->status);
    }

    #[Test]
    public function a_settled_claim_cannot_be_settled_again(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 5);
        $redemption = $this->service()->redeem($passenger, $this->reward())['redemption'];

        $this->assertTrue($this->service()->fulfil($redemption, 'SAF-123')['ok']);
        $this->assertFalse($this->service()->fulfil($redemption, 'SAF-123')['ok']);
        // And cancelling a delivered reward must not hand the credits back.
        $this->assertFalse($this->service()->cancel($redemption)['ok']);
        $this->assertSame(3, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
    }

    #[Test]
    public function repricing_a_reward_does_not_change_what_someone_already_paid(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 5);
        $reward = $this->reward(['credits_required' => 2]);

        $redemption = $this->service()->redeem($passenger, $reward)['redemption'];
        $reward->forceFill(['credits_required' => 4])->save();

        $this->assertSame(2, $redemption->fresh()->credits_spent);
    }

    // ------------------------------------------------------------- endpoints

    #[Test]
    public function a_passenger_reads_their_own_balance_and_progress(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);
        $this->paidRide($world, $passenger, 250);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/auth/carbon-credits')
            ->assertOk()
            ->assertJsonPath('carbon_credits.credits', 0)
            ->assertJsonPath('carbon_credits.progress_ksh', 250)
            ->assertJsonPath('carbon_credits.ksh_to_next_credit', 50);
    }

    #[Test]
    public function a_passenger_can_see_and_claim_a_reward_over_the_api(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 3);
        $reward = $this->reward();

        Sanctum::actingAs($passenger);

        $this->getJson('/api/auth/carbon-credits/rewards')
            ->assertOk()
            ->assertJsonPath('credits', 3)
            ->assertJsonPath('rewards.0.affordable', true);

        $this->postJson('/api/auth/carbon-credits/redeem', ['reward_id' => $reward->id])
            ->assertOk();

        $this->assertSame(1, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
        $this->assertSame(1, CarbonCreditRedemption::where('user_id', $passenger->id)->count());
    }

    #[Test]
    public function a_passenger_never_sees_another_passengers_credits(): void
    {
        $world = $this->makeWorld();
        $mine = $this->makeUser([], $world['sacco']);
        $theirs = $this->makeUser([], $world['sacco']);

        $this->paidRide($world, $theirs, 5000);

        Sanctum::actingAs($mine);

        $this->getJson('/api/auth/carbon-credits')->assertOk()->assertJsonPath('carbon_credits.credits', 0);
        $this->getJson('/api/auth/carbon-credits/history')->assertOk()->assertJsonCount(0, 'history');
    }

    // --------------------------------------------------------- notifications

    #[Test]
    public function crossing_ten_credits_pushes_the_passenger(): void
    {
        Notification::fake();
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        // 3,000 KSh = 10 credits at 300 apiece — the headline milestone.
        $this->paidRide($world, $passenger, 3000);

        Notification::assertSentTo($passenger, PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title === '10 carbon credits'
                && $n->referenceId === 'carbon-milestone-10');
    }

    #[Test]
    public function the_very_first_credit_is_called_out_on_its_own(): void
    {
        Notification::fake();
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        $this->paidRide($world, $passenger, 300);

        Notification::assertSentTo($passenger, PlatformNotification::class,
            fn (PlatformNotification $n) => $n->referenceId === 'carbon-first');
    }

    #[Test]
    public function travel_that_mints_nothing_pushes_nothing(): void
    {
        Notification::fake();
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        // 150 KSh is half a credit — real progress, but not worth a push.
        $this->paidRide($world, $passenger, 150);

        Notification::assertNotSentTo($passenger, PlatformNotification::class,
            fn (PlatformNotification $n) => str_starts_with((string) $n->referenceId, 'carbon-'));
    }

    #[Test]
    public function credits_between_milestones_do_not_push(): void
    {
        Notification::fake();
        $world = $this->makeWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        // 3,300 KSh = 11 credits. Milestone 10 fires; the 11th says nothing.
        $this->paidRide($world, $passenger, 3300);

        $milestones = [];
        Notification::assertSentTo($passenger, PlatformNotification::class,
            function (PlatformNotification $n) use (&$milestones) {
                if (str_starts_with((string) $n->referenceId, 'carbon-milestone-')) {
                    $milestones[] = $n->referenceId;
                }

                return true;
            });

        $this->assertSame(['carbon-milestone-10'], $milestones);
    }

    #[Test]
    public function a_fulfilled_reward_tells_the_passenger_it_is_coming(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 5);
        $redemption = $this->service()->redeem($passenger, $this->reward())['redemption'];

        Notification::fake();
        $this->service()->fulfil($redemption, 'SAF-9931');

        Notification::assertSentTo($passenger, PlatformNotification::class,
            fn (PlatformNotification $n) => $n->referenceId === 'carbon-redemption-'.$redemption->id
                && str_contains($n->message, 'SAF-9931'));
    }

    #[Test]
    public function a_cancelled_reward_says_the_credits_came_back(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 5);
        $redemption = $this->service()->redeem($passenger, $this->reward())['redemption'];

        Notification::fake();
        $this->service()->cancel($redemption, 'Partner out of stock.');

        Notification::assertSentTo($passenger, PlatformNotification::class,
            fn (PlatformNotification $n) => $n->referenceId === 'carbon-cancelled-'.$redemption->id
                && str_contains($n->message, '2 carbon credits have been returned'));
    }

    // ------------------------------------------------------------ granting

    #[Test]
    public function credits_can_be_granted_by_hand_with_a_reason(): void
    {
        $passenger = $this->makeUser();

        $this->assertSame(0, Artisan::call('carbon:grant', [
            'email' => $passenger->email,
            'credits' => 9,
            '--reason' => 'Launch thank-you',
        ]));

        $this->assertSame(9, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);

        // An adjusted ledger row, not a silent balance edit — the passenger can
        // see where it came from and the platform totals still reconcile.
        $row = CarbonCreditTransaction::where('user_id', $passenger->id)->firstOrFail();
        $this->assertSame(CarbonCreditType::Adjusted, $row->type);
        $this->assertSame(9, $row->credits);
        $this->assertSame('Launch thank-you', $row->description);
    }

    #[Test]
    public function a_grant_can_be_rehearsed_without_writing(): void
    {
        $passenger = $this->makeUser();

        Artisan::call('carbon:grant', [
            'email' => $passenger->email, 'credits' => 5, '--dry-run' => true,
        ]);

        $this->assertSame(0, CarbonCreditTransaction::count());
    }

    #[Test]
    public function a_deduction_cannot_push_a_balance_below_zero(): void
    {
        $passenger = $this->makeUser();
        $this->giveCredits($passenger, 2);

        Artisan::call('carbon:grant', ['email' => $passenger->email, 'credits' => -10]);

        // A negative balance has no meaning at redemption and would silently
        // swallow the next credits they earn.
        $this->assertSame(0, CarbonCreditAccount::where('user_id', $passenger->id)->firstOrFail()->credits);
        $this->assertSame(-2, CarbonCreditTransaction::where('user_id', $passenger->id)->firstOrFail()->credits);
    }

    #[Test]
    public function granting_to_an_unknown_email_fails_loudly(): void
    {
        $this->assertSame(1, Artisan::call('carbon:grant', [
            'email' => 'nobody@example.test', 'credits' => 5,
        ]));
    }
}
