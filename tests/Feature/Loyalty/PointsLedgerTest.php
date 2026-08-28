<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Enums\UserType;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * One holder's points ledger.
 *
 * The holders list answers "who has points". This answers "how did they get
 * there" — which is the question a SACCO gets asked, by a passenger at a stage
 * who thinks their balance is wrong.
 *
 * The property that matters most is the RUNNING BALANCE. It is accumulated
 * oldest-first because that is the only order in which it means anything, then
 * reversed for display. Subtracting from the current balance while walking
 * backwards gives the same answer only while no row is ever written out of
 * order, and silently wrong history the first time one is.
 *
 * READ ONLY, like the rest of this controller. Points are money — ten of them is
 * a free ride — and a SACCO admin who could mint them would be issuing free
 * rides against their own revenue.
 */
final class PointsLedgerTest extends QueueTestCase
{
    private function admin(array $world): User
    {
        $u = $this->makeUser(['View Loyalty'], $world['sacco']);
        $u->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();

        return $u->fresh();
    }

    private function holder(array $world, float $balance): User
    {
        $u = $this->makeUser([], null);
        $u->forceFill(['type' => UserType::Passenger])->save();

        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $u->id,
            'sacco_id' => $world['sacco']->id,
            'balance' => $balance,
        ]);

        return $u->fresh();
    }

    private function entry(array $world, User $u, LoyaltyTransactionType $type, float $value, string $at): void
    {
        $t = LoyaltyTransaction::withoutGlobalScopes()->create([
            'user_id' => $u->id,
            'sacco_id' => $world['sacco']->id,
            'value' => $value,
            'type' => $type,
        ]);

        // created_at drives the ledger order, so it has to be set explicitly.
        $t->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function url(array $world, User $holder): string
    {
        return '/api/v1/auth/saccos/loyalty/holders/'.$holder->id.'/history';
    }

    #[Test]
    public function the_balance_after_each_movement_is_the_running_total(): void
    {
        // The whole point of a ledger: not just what moved, but where it left you.
        $world = $this->makeWorld();
        $holder = $this->holder($world, 40);

        $this->entry($world, $holder, LoyaltyTransactionType::Earned, 30, '2026-08-01 08:00:00');
        $this->entry($world, $holder, LoyaltyTransactionType::Earned, 20, '2026-08-02 08:00:00');
        $this->entry($world, $holder, LoyaltyTransactionType::Redeemed, 10, '2026-08-03 08:00:00');

        Sanctum::actingAs($this->admin($world));

        $entries = $this->getJson($this->url($world, $holder))->assertOk()->json('entries');

        // Newest first.
        $this->assertSame('redeemed', $entries[0]['type']);
        $this->assertSame(40.0, (float) $entries[0]['balance_after']);
        $this->assertSame(50.0, (float) $entries[1]['balance_after']);
        $this->assertSame(30.0, (float) $entries[2]['balance_after']);
    }

    #[Test]
    public function a_redemption_reads_as_a_minus_and_an_earn_as_a_plus(): void
    {
        // The sign is decided server-side. A client inferring it from the type
        // string gets `reversed` wrong — it reads like an undo and it is a debit.
        $world = $this->makeWorld();
        $holder = $this->holder($world, 0);

        $this->entry($world, $holder, LoyaltyTransactionType::Earned, 30, '2026-08-01 08:00:00');
        $this->entry($world, $holder, LoyaltyTransactionType::Redeemed, 10, '2026-08-02 08:00:00');
        $this->entry($world, $holder, LoyaltyTransactionType::Reversed, 5, '2026-08-03 08:00:00');
        $this->entry($world, $holder, LoyaltyTransactionType::Refunded, 2, '2026-08-04 08:00:00');

        Sanctum::actingAs($this->admin($world));

        $signs = collect($this->getJson($this->url($world, $holder))->assertOk()->json('entries'))
            ->mapWithKeys(fn ($e) => [$e['type'] => $e['sign']])->all();

        $this->assertSame('+', $signs['earned']);
        $this->assertSame('-', $signs['redeemed']);
        $this->assertSame('-', $signs['reversed'], 'a reversal takes points away');
        $this->assertSame('+', $signs['refunded']);
    }

    #[Test]
    public function a_reversal_lowers_the_running_balance(): void
    {
        $world = $this->makeWorld();
        $holder = $this->holder($world, 25);

        $this->entry($world, $holder, LoyaltyTransactionType::Earned, 30, '2026-08-01 08:00:00');
        $this->entry($world, $holder, LoyaltyTransactionType::Reversed, 5, '2026-08-02 08:00:00');

        Sanctum::actingAs($this->admin($world));

        $entries = $this->getJson($this->url($world, $holder))->assertOk()->json('entries');

        $this->assertSame(25.0, (float) $entries[0]['balance_after']);
    }

    #[Test]
    public function the_holder_header_carries_the_authoritative_balance(): void
    {
        // From the ACCOUNT, not the walk. If the two ever disagree the ledger
        // and the balance have drifted, and that is worth being able to see.
        $world = $this->makeWorld();
        $holder = $this->holder($world, 40);
        $this->entry($world, $holder, LoyaltyTransactionType::Earned, 30, '2026-08-01 08:00:00');

        Sanctum::actingAs($this->admin($world));

        $body = $this->getJson($this->url($world, $holder))->assertOk()->json();

        $this->assertSame($holder->id, $body['holder']['user_id']);
        $this->assertSame(40.0, (float) $body['holder']['balance']);
        $this->assertSame($holder->phone, $body['holder']['phone']);
    }

    #[Test]
    public function another_saccos_holder_is_not_found(): void
    {
        // "Not found" rather than "forbidden": a 403 would confirm that the
        // person banks points with somebody else.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $notMine = $this->holder($theirs, 500);

        Sanctum::actingAs($this->admin($mine));

        $this->getJson($this->url($mine, $notMine))->assertStatus(404);
    }

    #[Test]
    public function the_ledger_shows_only_this_saccos_movements(): void
    {
        // A passenger rides several SACCOs. Each one sees the points it issued
        // and nothing about the others.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $holder = $this->holder($mine, 30);
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $holder->id, 'sacco_id' => $theirs['sacco']->id, 'balance' => 90,
        ]);

        $this->entry($mine, $holder, LoyaltyTransactionType::Earned, 30, '2026-08-01 08:00:00');
        $this->entry($theirs, $holder, LoyaltyTransactionType::Earned, 90, '2026-08-02 08:00:00');

        Sanctum::actingAs($this->admin($mine));

        $entries = $this->getJson($this->url($mine, $holder))->assertOk()->json('entries');

        $this->assertCount(1, $entries, "another SACCO's movements must not appear");
        $this->assertSame(30.0, (float) $entries[0]['value']);
    }

    #[Test]
    public function reading_a_ledger_needs_the_loyalty_permission(): void
    {
        $world = $this->makeWorld();
        $holder = $this->holder($world, 10);

        $nobody = $this->makeUser([], $world['sacco']);
        $nobody->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();

        Sanctum::actingAs($nobody->fresh());

        $this->getJson($this->url($world, $holder))->assertStatus(403);
    }

    #[Test]
    public function there_is_still_no_way_to_write_a_points_balance(): void
    {
        // The guarantee the whole feature rests on. Adding a history route must
        // not have opened a write path beside it.
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'loyalty/holders'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()->values()->all();

        sort($routes);
        $this->assertSame(['GET', 'HEAD'], $routes, 'loyalty holder routes must stay read-only');
    }

    #[Test]
    public function the_holders_list_carries_earned_and_redeemed_totals(): void
    {
        // So the screen does not have to reconstruct them by grouping raw
        // movements in the browser — which can only be as complete as the page
        // it was handed.
        $world = $this->makeWorld();
        $holder = $this->holder($world, 40);

        $this->entry($world, $holder, LoyaltyTransactionType::Earned, 30, '2026-08-01 08:00:00');
        $this->entry($world, $holder, LoyaltyTransactionType::Earned, 20, '2026-08-02 08:00:00');
        $this->entry($world, $holder, LoyaltyTransactionType::Redeemed, 10, '2026-08-03 08:00:00');

        Sanctum::actingAs($this->admin($world));

        $row = collect($this->getJson('/api/v1/auth/saccos/loyalty/holders')->assertOk()->json('holders'))
            ->firstWhere('user_id', $holder->id);

        $this->assertSame(50.0, (float) $row['earned']);
        $this->assertSame(10.0, (float) $row['redeemed']);
        $this->assertNotNull($row['last_at'], 'the most recent movement dates the row');
    }

    #[Test]
    public function a_reversal_counts_against_earnings_not_towards_them(): void
    {
        // Split by TYPE, not by sign: `reversed` reads like an undo and is a
        // DEBIT. Counting it as earned would inflate what the SACCO thinks it
        // has issued.
        $world = $this->makeWorld();
        $holder = $this->holder($world, 25);

        $this->entry($world, $holder, LoyaltyTransactionType::Earned, 30, '2026-08-01 08:00:00');
        $this->entry($world, $holder, LoyaltyTransactionType::Reversed, 5, '2026-08-02 08:00:00');

        Sanctum::actingAs($this->admin($world));

        $row = collect($this->getJson('/api/v1/auth/saccos/loyalty/holders')->assertOk()->json('holders'))
            ->firstWhere('user_id', $holder->id);

        $this->assertSame(30.0, (float) $row['earned']);
        $this->assertSame(5.0, (float) $row['redeemed'], 'a reversal is a debit');
    }

    #[Test]
    public function a_holder_with_no_movements_reports_zeros_not_nulls(): void
    {
        // A migrated holder arrives with an opening balance and no history. The
        // screen must render 0, not blank.
        $world = $this->makeWorld();
        $holder = $this->holder($world, 120);

        Sanctum::actingAs($this->admin($world));

        $row = collect($this->getJson('/api/v1/auth/saccos/loyalty/holders')->assertOk()->json('holders'))
            ->firstWhere('user_id', $holder->id);

        $this->assertSame(0.0, (float) $row['earned']);
        $this->assertSame(0.0, (float) $row['redeemed']);
        $this->assertSame(120.0, (float) $row['balance']);
        $this->assertNull($row['last_at']);
    }

    #[Test]
    public function the_totals_cost_the_same_whether_there_are_five_holders_or_fifteen(): void
    {
        // CONSTANT, not "under some number". A threshold only catches a
        // regression if I guessed it tightly enough, and it has to be retuned
        // every time an unrelated query is added. Comparing two page sizes tests
        // the property that actually matters: the totals are one aggregate for
        // the page, so adding holders must not add queries.
        $world = $this->makeWorld();
        $admin = $this->admin($world);

        $seed = function (int $n) use ($world): void {
            foreach (range(1, $n) as $i) {
                $h = $this->holder($world, 10);
                $this->entry($world, $h, LoyaltyTransactionType::Earned, 10, '2026-08-01 08:00:00');
            }
        };

        $count = function () use ($admin): int {
            Sanctum::actingAs($admin);
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->getJson('/api/v1/auth/saccos/loyalty/holders')->assertOk();
            $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $n;
        };

        $seed(5);
        $withFive = $count();

        $seed(10);
        $withFifteen = $count();

        // NOT assertSame. The first call also warms pageMeta's cached count and
        // the permission lookup, so the second is measured a few queries LIGHTER
        // — 12 then 9, when this was first written. Equality would be asserting
        // the warm-up, not the property. What must hold is that adding holders
        // never adds queries.
        $this->assertLessThanOrEqual(
            $withFive,
            $withFifteen,
            'tripling the holders on the page must not add queries'
        );
    }

}
