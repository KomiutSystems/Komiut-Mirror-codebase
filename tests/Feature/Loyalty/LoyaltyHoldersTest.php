<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Enums\UserType;
use App\Models\LoyaltyAccount;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Point holder listings — the SACCO view and the platform view.
 *
 * The property these tests exist to hold is that both are READ ONLY. Points are
 * money (ten of them is a free ride), they are earned server-side from a paid
 * fare and spent by the passenger on their own token, and no dashboard route may
 * credit a balance. A SACCO admin who could would be issuing free rides against
 * their own SACCO's revenue with no payment behind them.
 */
final class LoyaltyHoldersTest extends QueueTestCase
{
    private function admin(array $world, array $perms = ['View Loyalty']): User
    {
        $u = $this->makeUser($perms, $world['sacco']);
        $u->type = UserType::Admin;
        $u->sacco_id = $world['sacco']->id;
        $u->save();

        return $u->fresh();
    }

    private function superAdmin(): User
    {
        $u = $this->makeUser(['View Platform Notifications'], null);
        $u->type = UserType::Superadmin;
        $u->save();

        return $u->fresh();
    }

    private function holder(array $world, float $balance, ?User $user = null): User
    {
        $user ??= $this->makeUser([], null);
        $user->type = UserType::Passenger;
        $user->save();

        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'sacco_id' => $world['sacco']->id,
            'balance' => $balance,
        ]);

        return $user->fresh();
    }

    #[Test]
    public function a_sacco_admin_sees_only_their_own_holders(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $ours = $this->holder($mine, 40);
        $notOurs = $this->holder($theirs, 500);

        Sanctum::actingAs($this->admin($mine));

        $ids = collect($this->getJson('/api/v1/auth/saccos/loyalty/holders')->assertOk()->json('holders'))
            ->pluck('user_id');

        $this->assertContains($ours->id, $ids->all());
        $this->assertNotContains($notOurs->id, $ids->all(), 'another SACCO holder must not be visible');
    }

    #[Test]
    public function holders_are_returned_highest_balance_first(): void
    {
        $world = $this->makeWorld();
        $small = $this->holder($world, 5);
        $big = $this->holder($world, 900);

        Sanctum::actingAs($this->admin($world));

        $ids = collect($this->getJson('/api/v1/auth/saccos/loyalty/holders')->assertOk()->json('holders'))
            ->pluck('user_id')->all();

        $this->assertSame($big->id, $ids[0]);
        $this->assertContains($small->id, $ids);
    }

    #[Test]
    public function spent_out_accounts_are_hidden_unless_asked_for(): void
    {
        $world = $this->makeWorld();
        $spent = $this->holder($world, 0);

        Sanctum::actingAs($this->admin($world));

        $without = collect($this->getJson('/api/v1/auth/saccos/loyalty/holders')->assertOk()->json('holders'))->pluck('user_id');
        $this->assertNotContains($spent->id, $without->all());

        $with = collect($this->getJson('/api/v1/auth/saccos/loyalty/holders?include_zero=1')->assertOk()->json('holders'))->pluck('user_id');
        $this->assertContains($spent->id, $with->all());
    }

    #[Test]
    public function the_sacco_view_carries_the_programme_so_points_can_be_read_as_rides(): void
    {
        $world = $this->makeWorld();
        \App\Models\LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'divisor' => 50,
            'redemption_threshold' => 10, 'is_active' => true,
        ]);
        $this->holder($world, 20);

        Sanctum::actingAs($this->admin($world));

        // Cast before comparing: JSON has no int/float distinction, so a whole
        // number encoded from a PHP float decodes as int and assertJsonPath's
        // strict comparison rejects 50.0 against 50.
        $body = $this->getJson('/api/v1/auth/saccos/loyalty/holders')->assertOk()->json();

        $this->assertSame(50.0, (float) $body['programme']['divisor']);
        $this->assertSame(10.0, (float) $body['programme']['redemption_threshold']);
        $this->assertTrue($body['programme']['is_active']);
    }

    #[Test]
    public function a_person_holding_points_with_several_saccos_appears_once_on_the_platform_view(): void
    {
        // The crews-page mistake, not repeated: a passenger rides several SACCOs,
        // so a row-per-account listing would show them once per SACCO.
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        $person = $this->holder($a, 30);
        $this->holder($b, 70, $person);

        Sanctum::actingAs($this->superAdmin());

        $rows = collect($this->getJson('/api/super/loyalty/holders')->assertOk()->json('holders'));
        $mine = $rows->where('user_id', $person->id);

        $this->assertCount(1, $mine, 'one row per person, not per account');
        $this->assertSame(100.0, (float) $mine->first()['total_balance']);
        $this->assertSame(2, (int) $mine->first()['sacco_count']);
        $this->assertCount(2, $mine->first()['saccos']);
    }

    #[Test]
    public function the_platform_view_can_be_filtered_to_one_sacco(): void
    {
        $a = $this->makeWorld();
        $b = $this->makeWorld();
        $onlyA = $this->holder($a, 12);
        $onlyB = $this->holder($b, 12);

        Sanctum::actingAs($this->superAdmin());

        $ids = collect($this->getJson('/api/super/loyalty/holders?sacco_id='.$a['sacco']->id)
            ->assertOk()->json('holders'))->pluck('user_id');

        $this->assertContains($onlyA->id, $ids->all());
        $this->assertNotContains($onlyB->id, $ids->all());
    }

    #[Test]
    public function platform_totals_are_derived_from_the_same_filtered_query_as_the_rows(): void
    {
        // A header tile that disagrees with the table under it reads as missing
        // money, so both come from one query.
        $a = $this->makeWorld();
        $b = $this->makeWorld();
        $person = $this->holder($a, 30);
        $this->holder($b, 70, $person);
        $this->holder($a, 5);

        Sanctum::actingAs($this->superAdmin());

        $body = $this->getJson('/api/super/loyalty/holders')->assertOk()->json();

        $this->assertSame(2, $body['totals']['holders'], 'distinct people');
        $this->assertSame(3, $body['totals']['accounts'], 'accounts across saccos');
        $this->assertSame(105.0, (float) $body['totals']['points']);
    }

    #[Test]
    public function a_sacco_admin_cannot_reach_the_platform_view(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $this->getJson('/api/super/loyalty/holders')->assertForbidden();
    }

    #[Test]
    public function there_is_no_write_route_for_point_balances(): void
    {
        // The guarantee this whole feature rests on. If someone later adds a
        // POST next to these, this test is the thing that objects.
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'loyalty/holders'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()->values()->all();

        sort($routes);
        $this->assertSame(['GET', 'HEAD'], $routes, 'loyalty holder routes must be read-only');
    }
}
