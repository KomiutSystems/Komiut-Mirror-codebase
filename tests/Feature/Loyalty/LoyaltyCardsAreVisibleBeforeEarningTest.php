<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The rewards card has to exist before the reward does.
 *
 * summary() read the passenger's loyalty_accounts and returned [] when there
 * were none, so the card was a receipt for points already earned rather than
 * the thing that tells you the scheme exists. The app hides the stack on an
 * empty list, so a passenger who had never earned saw nothing at all — and on
 * 2026-09-07 that was EVERY passenger on the platform: zero accounts, zero
 * transactions, because earning is booking-driven and there were no bookings.
 *
 * You cannot earn toward a reward nobody showed you. So the list is now driven
 * by active PROGRAMS, carrying a zero balance where the passenger has not
 * earned yet, unioned with every SACCO they already hold points with.
 */
final class LoyaltyCardsAreVisibleBeforeEarningTest extends QueueTestCase
{
    private const URL = '/api/auth/book_a_ride/loyalty/summary';

    private function program(Sacco $sacco, float $threshold = 500, bool $active = true): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'sacco_id' => $sacco->id,
            'divisor' => 100,
            'redemption_threshold' => $threshold,
            'is_active' => $active,
        ]);
    }

    private function giveBalance(User $user, Sacco $sacco, float $balance): void
    {
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'sacco_id' => $sacco->id, 'balance' => $balance,
        ]);
    }

    private function cards(User $user): array
    {
        Sanctum::actingAs($user);

        return $this->getJson(self::URL)->assertOk()->json('loyalty');
    }

    #[Test]
    public function a_passenger_who_has_never_earned_still_sees_the_card(): void
    {
        // THE WHOLE BUG. No account, no transactions — and previously no card.
        $world = $this->makeWorld();
        $this->program($world['sacco']);
        $passenger = $this->makeUser([], $world['sacco']);

        $cards = $this->cards($passenger);

        $this->assertCount(1, $cards, 'an active program is a card, earned or not');
        $this->assertSame($world['sacco']->id, $cards[0]['sacco_id']);
    }

    #[Test]
    public function the_untouched_card_reads_zero_with_the_full_threshold_to_go(): void
    {
        $world = $this->makeWorld();
        $this->program($world['sacco'], threshold: 500);
        $passenger = $this->makeUser([], $world['sacco']);

        $card = $this->cards($passenger)[0];

        $this->assertEqualsWithDelta(0.0, (float) $card['balance'], 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $card['points_to_reward'], 0.001,
            'the whole point of the card is showing how far away the reward is');
        $this->assertFalse($card['eligible_to_redeem']);
        $this->assertTrue($card['is_active']);
    }

    #[Test]
    public function a_passenger_belonging_to_no_sacco_still_sees_cards(): void
    {
        // The account that found this: a Google sign-in passenger with
        // sacco_id NULL. SaccoScope FAILS CLOSED on a null tenant (whereRaw
        // '1 = 0'), so scoping the program lookup by SACCO would have returned
        // nothing for exactly the users this screen is built for.
        $world = $this->makeWorld();
        $this->program($world['sacco']);
        $passenger = $this->makeUser();                  // no sacco

        $this->assertNull($passenger->sacco_id);
        $this->assertCount(1, $this->cards($passenger));
    }

    #[Test]
    public function every_participating_sacco_gets_a_card_not_just_the_passengers_own(): void
    {
        // A rewards screen showing one SACCO reads as broken. The passenger
        // belongs to the first one and has ridden none of them.
        $world = $this->makeWorld();
        $this->program($world['sacco']);
        foreach (range(1, 3) as $i) {
            $this->program($this->makeSacco());
        }
        $passenger = $this->makeUser([], $world['sacco']);

        $this->assertCount(4, $this->cards($passenger));
    }

    #[Test]
    public function points_already_earned_survive_the_sacco_switching_its_program_off(): void
    {
        // Program-driven must not mean program-only. Dropping a deactivated
        // SACCO would erase points the passenger actually earned from their
        // screen, which is worse than the bug this replaced.
        $world = $this->makeWorld();
        $this->program($world['sacco'], threshold: 500, active: false);
        $passenger = $this->makeUser([], $world['sacco']);
        $this->giveBalance($passenger, $world['sacco'], 620);

        $cards = $this->cards($passenger);

        $this->assertCount(1, $cards);
        $this->assertEqualsWithDelta(620.0, (float) $cards[0]['balance'], 0.001);
        $this->assertFalse($cards[0]['is_active'], 'shown, but honestly marked as not earning');
        $this->assertFalse($cards[0]['eligible_to_redeem'], 'an inactive program cannot be redeemed against');
    }

    #[Test]
    public function a_sacco_running_no_programme_is_never_advertised(): void
    {
        $world = $this->makeWorld();
        $this->program($world['sacco']);
        $silent = $this->makeSacco();                     // no program at all
        $passenger = $this->makeUser([], $world['sacco']);

        $ids = array_column($this->cards($passenger), 'sacco_id');

        $this->assertContains($world['sacco']->id, $ids);
        $this->assertNotContains($silent->id, $ids);
    }

    #[Test]
    public function redeemable_cards_come_first_then_the_ones_holding_points(): void
    {
        $world = $this->makeWorld();
        $ready = $world['sacco'];
        $started = $this->makeSacco();
        $untouched = $this->makeSacco();

        $this->program($ready, threshold: 500);
        $this->program($started, threshold: 500);
        $this->program($untouched, threshold: 500);

        $passenger = $this->makeUser([], $ready);
        $this->giveBalance($passenger, $ready, 620);       // redeemable
        $this->giveBalance($passenger, $started, 100);     // partway

        $ids = array_column($this->cards($passenger), 'sacco_id');

        $this->assertSame([$ready->id, $started->id, $untouched->id], $ids);
    }

    #[Test]
    public function another_brands_sacco_is_never_offered(): void
    {
        // BrandScope is deliberately KEPT while SaccoScope is dropped. A Komiut
        // passenger must not be advertised a 2Safiri SACCO's rewards.
        //
        // THE PASSENGER MUST BE TENANTLESS for this to be the real scenario.
        // BrandScope exempts anyone with a sacco_id on purpose — see
        // BrandScope::boundedBySomethingTighter(), written for NICCO, whose 180
        // buses run under two brands and whose own finance officer must see all
        // of them. A passenger belongs to no SACCO, so brand is the only wall
        // standing, and it is the one that has to hold. An earlier version of
        // this test handed the passenger a SACCO and failed for exactly that
        // reason: the exemption fired and the other brand came back.
        $world = $this->makeWorld();                      // brand 'testing'
        $this->program($world['sacco']);

        $other = Sacco::create([
            'name' => 'Other Brand SACCO', 'slogan' => 'elsewhere',
            'phone' => '0700999999', 'status' => 1, 'brand' => 'elsewhere',
        ]);
        $this->program($other);

        $passenger = $this->makeUser();                   // no sacco, like a real passenger
        $this->assertNull($passenger->sacco_id);
        // The other-brand SACCO runs no vehicles at all, so it operates nothing
        // in this brand — which is the test the scoping now actually applies.

        Context::add('brand', 'testing');
        try {
            $ids = array_column($this->cards($passenger), 'sacco_id');
        } finally {
            Context::forget('brand');
        }

        $this->assertContains($world['sacco']->id, $ids);
        $this->assertNotContains($other->id, $ids, "another brand's rewards are not ours to advertise");
    }

    #[Test]
    public function a_sacco_spanning_two_brands_is_offered_to_both(): void
    {
        // NICCO. 180 buses, 126 komiut and 54 safiri, and the only SACCO on the
        // platform that spans two. `saccos.brand` is ONE column, so whichever
        // value it holds, half its passengers were shown nothing — while earning
        // regardless, because earnForFare drops all scopes. Brand is therefore
        // taken from the VEHICLES, which the schema calls the authoritative one.
        $world = $this->makeWorld();                       // sacco + a 'testing' bus
        $this->program($world['sacco']);

        // The same SACCO also runs a bus under a different brand.
        $crossBrand = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $crossBrand->forceFill(['brand' => 'elsewhere'])->save();

        $passenger = $this->makeUser();
        $ids = [];

        foreach (['testing', 'elsewhere'] as $brand) {
            Context::add('brand', $brand);
            try {
                $ids[$brand] = array_column($this->cards($passenger), 'sacco_id');
            } finally {
                Context::forget('brand');
            }
        }

        $this->assertContains($world['sacco']->id, $ids['testing']);
        $this->assertContains(
            $world['sacco']->id,
            $ids['elsewhere'],
            'a passenger on the safiri half of NICCO earns on it, so must be shown it',
        );
    }
}
