<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Models\CarbonCreditAccount;
use App\Models\CarbonCreditReward;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A passenger must see the SACCO's NAME, not a blank where it should be.
 *
 * `withoutGlobalScopes()` applies only to the builder it is called on. An eager
 * load — `->with('sacco:id,name')` — builds a FRESH query on Sacco, and that one
 * gets the global scopes back. BrandScope exempts super admins, bank users and
 * anyone holding a sacco_id (BrandScope::boundedBySomethingTighter); a passenger
 * is none of those, so it applies to them and filters on `saccos.brand`.
 *
 * The result was not an error. It was `null` where the SACCO's name belongs, on
 * every row, for any passenger whose brand did not match the SACCO's — and since
 * `saccos.brand` is a single column the schema itself calls non-authoritative,
 * "did not match" is a routine state rather than an exotic one. NICCO runs 126
 * komiut buses and 54 safiri ones under one row branded komiut.
 *
 * These pin the three passenger-facing paths that did it.
 */
final class SaccoNamesSurviveBrandScopeTest extends QueueTestCase
{
    /** A passenger belongs to no SACCO — that is what makes BrandScope apply. */
    private function passenger(): User
    {
        $user = $this->makeUser();
        $this->assertNull($user->sacco_id, 'the whole trap needs a tenantless caller');

        return $user;
    }

    /** A SACCO on a DIFFERENT brand from the one the request carries. */
    private function otherBrandSacco(): Sacco
    {
        return Sacco::create([
            'name' => 'Cross Brand Movers', 'slogan' => 'elsewhere',
            'phone' => '0700123456', 'status' => 1, 'brand' => 'elsewhere',
        ]);
    }

    private function underBrand(callable $fn): mixed
    {
        Context::add('brand', 'testing');
        try {
            return $fn();
        } finally {
            Context::forget('brand');
        }
    }

    #[Test]
    public function the_points_ledger_names_the_sacco_even_across_brands(): void
    {
        $passenger = $this->passenger();
        $sacco = $this->otherBrandSacco();

        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $passenger->id, 'sacco_id' => $sacco->id, 'balance' => 50,
        ]);
        LoyaltyTransaction::create([
            'user_id' => $passenger->id, 'sacco_id' => $sacco->id,
            'value' => 50, 'type' => 'earned', 'booking_id' => null,
        ]);

        Sanctum::actingAs($passenger);
        $rows = $this->underBrand(fn () => $this->getJson('/api/v1/auth/book_a_ride/loyalty/history')
            ->assertOk()->json('transactions.data'));

        $this->assertNotEmpty($rows, 'the ledger row itself is unscoped and must be here');
        $this->assertNotNull($rows[0]['sacco'], 'the SACCO block came back null — BrandScope on the eager load');
        $this->assertSame('Cross Brand Movers', $rows[0]['sacco']['name']);
    }

    #[Test]
    public function a_sacco_funded_reward_names_its_sacco(): void
    {
        // A reward the SACCO pays for, shown to a passenger with no SACCO of
        // their own. Anonymous is the one thing it must not be.
        $passenger = $this->passenger();
        $sacco = $this->otherBrandSacco();
        CarbonCreditAccount::create([
            'user_id' => $passenger->id, 'credits' => 0,
            'progress_cents' => 0, 'lifetime_spend_cents' => 0,
        ]);
        CarbonCreditReward::create([
            'name' => 'Free ride', 'partner' => 'sacco', 'credits_required' => 10,
            'sacco_id' => $sacco->id, 'stock' => 5, 'is_active' => true,
        ]);

        Sanctum::actingAs($passenger);
        $rewards = $this->underBrand(fn () => $this->getJson('/api/v1/auth/carbon-credits/rewards')
            ->assertOk()->json('rewards'));

        $funded = collect($rewards)->firstWhere('name', 'Free ride');
        $this->assertNotNull($funded);
        $this->assertNotNull($funded['sacco'], 'a SACCO-funded reward showed no SACCO');
        $this->assertSame('Cross Brand Movers', $funded['sacco']['name']);
    }

    #[Test]
    public function the_eager_load_is_what_was_broken_not_the_row(): void
    {
        // Names the failure precisely: the transaction row was always returned —
        // it is read withoutGlobalScopes — so this never looked like a missing
        // record or a 500. Only the nested name vanished, which is why it
        // survived a code review and an audit.
        $passenger = $this->passenger();
        $sacco = $this->otherBrandSacco();
        LoyaltyTransaction::create([
            'user_id' => $passenger->id, 'sacco_id' => $sacco->id,
            'value' => 12.5, 'type' => 'earned', 'booking_id' => null,
        ]);

        Sanctum::actingAs($passenger);
        $rows = $this->underBrand(fn () => $this->getJson('/api/v1/auth/book_a_ride/loyalty/history')
            ->assertOk()->json('transactions.data'));

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(12.5, (float) $rows[0]['value'], 0.001, 'the row was never the problem');
        $this->assertNotNull($rows[0]['sacco']['name'], 'the name was');
    }
}
