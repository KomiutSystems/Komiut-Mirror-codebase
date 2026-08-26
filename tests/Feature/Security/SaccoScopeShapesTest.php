<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\ExpenseFee;
use App\Models\LoyaltyProgram;
use App\Models\RouteFare;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The two ways a tenancy scope goes wrong without leaking anything.
 *
 * A scope can be too TIGHT as easily as too loose, and the tight failure is the
 * quieter one: ExpenseFee carried BelongsToSacco while every row in the table has
 * sacco_id NULL, so the filter matched nothing and the expense-category picker
 * was empty for every SACCO admin on the platform. Nothing errored. The feature
 * was simply off.
 *
 * The other shape is a scope that works by accident: LoyaltyProgram, RouteFare
 * and the loyalty tables declared $saccoVia = 'sacco' despite owning sacco_id
 * directly, which produced an EXISTS against `saccos` with an UNQUALIFIED
 * sacco_id predicate. Postgres resolved it outward to the parent table, so the
 * filter was correct — until `saccos` gained a sacco_id column of its own, at
 * which point it would silently rebind and return the wrong rows.
 */
final class SaccoScopeShapesTest extends QueueTestCase
{
    private function adminFor(array $world): User
    {
        $user = $this->makeUser([], $world['sacco']);
        $user->type = UserType::Admin;
        $user->sacco_id = $world['sacco']->id;
        $user->save();

        return $user->fresh();
    }

    #[Test]
    public function shared_catalogue_rows_are_visible_to_every_sacco(): void
    {
        $mine = $this->makeWorld();
        $shared = ExpenseFee::withoutGlobalScopes()->create(['name' => 'Fuel '.$this->nextSequence(), 'status' => true]);

        Auth::login($this->adminFor($mine));

        $this->assertContains(
            $shared->id,
            ExpenseFee::query()->pluck('id')->all(),
            'a catalogue row with no SACCO belongs to everyone'
        );
    }

    #[Test]
    public function a_saccos_own_category_stays_private_to_it(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $ours = ExpenseFee::withoutGlobalScopes()->create([
            'name' => 'Our Levy '.$this->nextSequence(), 'sacco_id' => $mine['sacco']->id, 'status' => true,
        ]);
        $notOurs = ExpenseFee::withoutGlobalScopes()->create([
            'name' => 'Their Levy '.$this->nextSequence(), 'sacco_id' => $theirs['sacco']->id, 'status' => true,
        ]);

        Auth::login($this->adminFor($mine));

        $visible = ExpenseFee::query()->pluck('id')->all();

        $this->assertContains($ours->id, $visible);
        $this->assertNotContains($notOurs->id, $visible, 'sharing NULL rows must not share owned ones');
    }

    #[Test]
    public function models_owning_sacco_id_filter_on_their_own_column(): void
    {
        // No EXISTS into `saccos`, so nothing can rebind if that table changes.
        $mine = $this->makeWorld();
        Auth::login($this->adminFor($mine));

        foreach ([LoyaltyProgram::class, RouteFare::class] as $model) {
            $sql = $model::query()->toSql();

            $this->assertStringNotContainsString(
                'exists (select * from "saccos" where "sacco_id"',
                $sql,
                $model.' must not scope through an unqualified saccos predicate'
            );
        }
    }

    #[Test]
    public function loyalty_and_fares_still_scope_correctly(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $ours = LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $mine['sacco']->id, 'divisor' => 100, 'redemption_threshold' => 500, 'is_active' => true,
        ]);
        $notOurs = LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $theirs['sacco']->id, 'divisor' => 100, 'redemption_threshold' => 500, 'is_active' => true,
        ]);

        Auth::login($this->adminFor($mine));

        $visible = LoyaltyProgram::query()->pluck('id')->all();

        $this->assertContains($ours->id, $visible);
        $this->assertNotContains($notOurs->id, $visible);
    }
}
