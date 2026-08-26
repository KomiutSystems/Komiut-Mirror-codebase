<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\Place;
use App\Models\Route;
use App\Models\Terminus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * ResourceStateController's tenant boundary was a comment, not a mechanism.
 *
 * It asserted that SaccoScope stops one SACCO acting on another's records, and
 * that is true for `vehicles` — but Terminus, Place and Route carry no tenancy
 * trait at all (`termini` has no sacco_id column to hang one on), and `User`
 * does not either. So any SACCO's Operations Manager holding 'Edit Termini'
 * could suspend a terminus the other 47 SACCOs queue at, and any SACCO admin
 * holding 'Edit Sacco Members' could disable any account on the platform by id.
 *
 * Termini/places/routes are platform records and their suspension is now a
 * platform action; members are confined to the caller's own SACCO.
 */
final class SharedResourceStateTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/sacco';

    private function superadmin(array $permissions): User
    {
        $user = $this->makeUser($permissions);
        $user->forceFill(['type' => UserType::Superadmin])->save();

        return $user;
    }

    #[Test]
    public function an_operations_manager_cannot_suspend_a_shared_terminus(): void
    {
        // The exact production shape: Operations Manager carries 'Edit Termini'
        // by design (they add and edit them). Standing one down is different —
        // it takes the stage away from every SACCO that queues there.
        $terminus = $this->makeTerminus($this->makePlace('Machakos Country Bus'));

        Sanctum::actingAs($this->makeUser(['Edit Termini'], $this->makeSacco()));

        $this->postJson(self::ENDPOINT."/termini/{$terminus->id}/state", ['suspend' => true])
            ->assertStatus(403);

        $this->assertTrue((bool) $terminus->fresh()->status, 'A refused request must change nothing.');
    }

    #[Test]
    public function places_and_routes_are_platform_records_too(): void
    {
        $from = $this->makePlace('Nairobi CBD');
        $to = $this->makePlace('Thika');
        $route = $this->makeRoute($from, $to);

        Sanctum::actingAs($this->makeUser(['Edit Places', 'Edit Routes'], $this->makeSacco()));

        $this->postJson(self::ENDPOINT."/places/{$from->id}/state", ['suspend' => true])->assertStatus(403);
        $this->postJson(self::ENDPOINT."/routes/{$route->id}/state", ['suspend' => true])->assertStatus(403);

        // withoutGlobalScopes on the ROUTE read: this asserts the row was not
        // mutated, which is a data question, not a visibility one. `routes`
        // became SACCO-owned, and this fixture's route deliberately has no
        // owner, so a scoped find() returns null for a caller who has a SACCO.
        //
        // NOTE the inconsistency this leaves, which is a decision for a human
        // rather than something to quietly change here: a SACCO now OWNS its
        // routes and can set routes.status through saccos/routes/build, while
        // ResourceStateController still refuses it the same column through the
        // state endpoint. Places are genuinely still shared, so their half of
        // this test is unchanged and correct.
        $this->assertTrue((bool) Place::find($from->id)->status);
        $this->assertTrue((bool) Route::withoutGlobalScopes()->find($route->id)->status);
    }

    #[Test]
    public function a_superadmin_can_still_suspend_a_terminus(): void
    {
        // The right is not removed, it moves to the tier that owns the record.
        $terminus = $this->makeTerminus($this->makePlace('Machakos Country Bus'));

        Sanctum::actingAs($this->superadmin(['Edit Termini']));

        $this->postJson(self::ENDPOINT."/termini/{$terminus->id}/state", ['suspend' => true])
            ->assertOk()
            ->assertJsonPath('status', false);

        $this->assertFalse((bool) Terminus::find($terminus->id)->status);
    }

    #[Test]
    public function the_permission_is_still_required_of_a_superadmin(): void
    {
        $terminus = $this->makeTerminus($this->makePlace('Machakos Country Bus'));

        Sanctum::actingAs($this->superadmin([]));

        $this->postJson(self::ENDPOINT."/termini/{$terminus->id}/state", ['suspend' => true])
            ->assertStatus(403);
    }

    #[Test]
    public function a_sacco_admin_cannot_suspend_another_saccos_member(): void
    {
        // User carries no tenancy trait — it is the model SaccoScope reads its
        // sacco FROM — so find() happily returned another SACCO's staff.
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $victim = $this->makeUser([], $theirs);

        Sanctum::actingAs($this->makeUser(['Edit Sacco Members'], $mine));

        $this->postJson(self::ENDPOINT."/members/{$victim->id}/state", ['suspend' => true])
            ->assertStatus(404);

        $this->assertTrue((bool) $victim->fresh()->status);
    }

    #[Test]
    public function a_saccoless_admin_cannot_suspend_anybody(): void
    {
        $victim = $this->makeUser([], $this->makeSacco());

        Sanctum::actingAs($this->makeUser(['Edit Sacco Members']));   // no sacco, not super

        $this->postJson(self::ENDPOINT."/members/{$victim->id}/state", ['suspend' => true])
            ->assertStatus(404);

        $this->assertTrue((bool) $victim->fresh()->status);
    }

    #[Test]
    public function a_sacco_admin_can_still_suspend_their_own_member(): void
    {
        $sacco = $this->makeSacco();
        $member = $this->makeUser([], $sacco);

        Sanctum::actingAs($this->makeUser(['Edit Sacco Members'], $sacco));

        $this->postJson(self::ENDPOINT."/members/{$member->id}/state", ['suspend' => true])
            ->assertOk()
            ->assertJsonPath('status', false);

        $this->assertFalse((bool) $member->fresh()->status);
    }
}
