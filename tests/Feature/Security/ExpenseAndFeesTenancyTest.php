<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\ExpenseFee;
use App\Models\User;
use App\Models\VehicleExpenseAndFee;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Vehicle expenses are tenant data.
 *
 * VehicleExpenseAndFee carried no tenancy trait, and the listing endpoint's only
 * narrowing was the caller's own vehicle assignments — applied ONLY when they had
 * any. An office admin has none, so the filter was skipped and the listing ran
 * unconstrained. Verified in production: a NICCO admin was reading expense rows
 * for vehicles owned by Marafiki and Fins Travel.
 *
 * The write half was worse: findOrFail() resolved any expense row on the platform
 * by id, and the posted vehicle id was only checked to EXIST.
 */
final class ExpenseAndFeesTenancyTest extends QueueTestCase
{
    /**
     * ExpenseFee is itself sacco-scoped while every row carries sacco_id NULL, so
     * a plain firstOrCreate here would depend on who happens to be logged in.
     * Bypass the scope: this is fixture setup, not the thing under test.
     */
    private function category(string $name): ExpenseFee
    {
        return ExpenseFee::withoutGlobalScopes()->firstOrCreate(['name' => $name], ['status' => true]);
    }

    private function adminFor(array $world): User
    {
        $user = $this->makeUser(['View Expense And Fees', 'Add Expense And Fees', 'Edit Expense And Fees'], $world['sacco']);
        $user->type = UserType::Admin;
        $user->sacco_id = $world['sacco']->id;
        $user->save();

        return $user->fresh();
    }

    private function expenseFor(array $world, float $amount): VehicleExpenseAndFee
    {
        $category = $this->category('Fuel '.$this->nextSequence());

        return VehicleExpenseAndFee::withoutGlobalScopes()->create([
            'vehicle_id' => $world['vehicle']->id,
            'expense_fee_id' => $category->id,
            'amount' => $amount,
            'trans_date' => now(),
            'status' => 1,
        ]);
    }

    #[Test]
    public function an_admin_with_no_vehicle_assignments_sees_only_their_own_saccos_expenses(): void
    {
        // The exact production shape: an office admin has zero vehicle_user rows,
        // so the crew filter is skipped and the tenant scope is the only guard.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $ours = $this->expenseFor($mine, 450);
        $notOurs = $this->expenseFor($theirs, 234);

        Sanctum::actingAs($this->adminFor($mine));

        $visible = VehicleExpenseAndFee::query()->pluck('id')->all();

        $this->assertContains($ours->id, $visible);
        $this->assertNotContains($notOurs->id, $visible, 'another SACCO expense row must not be readable');
    }

    #[Test]
    public function another_saccos_expense_row_cannot_be_overwritten_by_id(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $victim = $this->expenseFor($theirs, 234);

        Sanctum::actingAs($this->adminFor($mine));

        $this->postJson('/api/v1/auth/expense_and_fees/add', [
            'id' => $victim->id,
            'amount' => 1,
            'vehicle' => $mine['vehicle']->id,
            'trans_date' => now()->toDateString(),
            'expense_fee' => $victim->expense_fee_id,
            'status' => 1,
        ])->assertStatus(404);

        $this->assertSame(234.0, (float) $victim->fresh()->amount);
    }

    #[Test]
    public function an_expense_cannot_be_planted_on_another_saccos_vehicle(): void
    {
        // exists:vehicles,id proves the vehicle exists, never that it is ours.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        Sanctum::actingAs($this->adminFor($mine));

        $this->postJson('/api/v1/auth/expense_and_fees/add', [
            'id' => 0,
            'amount' => 5000,
            'vehicle' => $theirs['vehicle']->id,
            'trans_date' => now()->toDateString(),
            'expense_fee' => $this->category('Fuel X')->id,
            'status' => 1,
        ])->assertStatus(404);

        $this->assertSame(
            0,
            VehicleExpenseAndFee::withoutGlobalScopes()->where('vehicle_id', $theirs['vehicle']->id)->count(),
            'no expense row may be created against another SACCO vehicle'
        );
        // And no summary may be minted in their books either.
        $this->assertSame(
            0,
            \App\Models\Summary::withoutGlobalScopes()->where('vehicle_id', $theirs['vehicle']->id)->count()
        );
    }

    #[Test]
    public function an_admin_can_still_record_an_expense_on_their_own_vehicle(): void
    {
        $mine = $this->makeWorld();
        Sanctum::actingAs($this->adminFor($mine));

        $this->postJson('/api/v1/auth/expense_and_fees/add', [
            'id' => 0,
            'amount' => 450,
            'vehicle' => $mine['vehicle']->id,
            'trans_date' => now()->toDateString(),
            'expense_fee' => $this->category('Fuel Y')->id,
            'status' => 1,
        ])->assertOk();

        $this->assertSame(
            1,
            VehicleExpenseAndFee::withoutGlobalScopes()->where('vehicle_id', $mine['vehicle']->id)->count()
        );
    }
}
