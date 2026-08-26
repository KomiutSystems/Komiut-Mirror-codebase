<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\LoyaltyProgram;
use App\Models\Queue;
use App\Models\RouteFare;
use App\Models\SaccoVehicle;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A tenant id in the payload must never beat the caller's own.
 *
 * Several dashboard endpoints preferred a request-supplied sacco_id:
 *
 *     $saccoId = $request->filled('sacco_id') ? (int) $request->sacco_id : auth()->user()->currentSaccoId();
 *
 * On a read that is mostly inert — SaccoScope narrows the result to nothing. On a
 * WRITE the scope makes it worse, not better: updateOrCreate does a SCOPED select
 * that can never match the victim's existing row, decides nothing is there, and
 * INSERTS one stamped with the victim's sacco_id. The boundary turns a hijack
 * into a clean create.
 *
 * The same shape, differently spelled, let a vehicle be pushed into another
 * SACCO's fleet and a priced queue be planted on their bus.
 */
final class ForeignSaccoWriteTest extends QueueTestCase
{
    private function adminFor(array $world, array $permissions): User
    {
        $user = $this->makeUser($permissions, $world['sacco']);
        $user->type = UserType::Admin;
        $user->sacco_id = $world['sacco']->id;
        $user->save();

        return $user->fresh();
    }

    #[Test]
    public function a_fare_cannot_be_set_for_another_sacco(): void
    {
        // The money one: this is the price a SACCO charges its passengers.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        Sanctum::actingAs($this->adminFor($mine, ['Add Fares', 'Edit Fares']));

        $this->postJson('/api/v1/auth/saccos/fares/add', [
            'sacco_id' => $theirs['sacco']->id,
            'route_id' => $theirs['route']->id,
            'from_place_id' => $theirs['from']->id,
            'to_place_id' => $theirs['to']->id,
            'amount' => 1,
        ])->assertStatus(403);

        $this->assertSame(
            0,
            RouteFare::withoutGlobalScopes()->where('sacco_id', $theirs['sacco']->id)->count(),
            'no fare may be created inside another SACCO'
        );
    }

    #[Test]
    public function a_loyalty_program_cannot_be_created_for_another_sacco(): void
    {
        // threshold 0 + divisor 1 on someone else's program mints free rides.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        Sanctum::actingAs($this->adminFor($mine, ['Edit Loyalty', 'View Loyalty']));

        $this->postJson('/api/v1/auth/saccos/loyalty/save', [
            'sacco_id' => $theirs['sacco']->id,
            'divisor' => 1,
            'redemption_threshold' => 0,
        ])->assertStatus(403);

        $this->assertSame(
            0,
            LoyaltyProgram::withoutGlobalScopes()->where('sacco_id', $theirs['sacco']->id)->count()
        );
    }

    #[Test]
    public function a_vehicle_cannot_be_pushed_into_another_saccos_fleet(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        Sanctum::actingAs($this->adminFor($mine, ['Add Sacco Vehicles', 'Edit Sacco Vehicles']));

        $this->postJson('/api/v1/auth/saccos/vehicles/add', [
            'id' => 0,
            'sacco' => $theirs['sacco']->id,
            'vehicle' => $mine['vehicle']->id,
            'status' => 1,
        ])->assertStatus(403);

        $this->assertSame(
            $mine['sacco']->id,
            (int) Vehicle::withoutGlobalScopes()->find($mine['vehicle']->id)->sacco_id,
            'our vehicle must not have been moved into their SACCO'
        );
        $this->assertSame(
            0,
            SaccoVehicle::withoutGlobalScopes()->where('sacco_id', $theirs['sacco']->id)->count()
        );
    }

    #[Test]
    public function a_queue_cannot_be_created_on_another_saccos_vehicle(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $status = $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending');

        Sanctum::actingAs($this->adminFor($mine, ['Add Queues']));

        $this->postJson('/api/v1/auth/queues/add', [
            'id' => 0,
            'vehicle' => $theirs['vehicle']->id,
            'terminus' => $theirs['terminus']->id,
            'status' => $status->id,
            'route' => $theirs['route']->id,
            'choice' => 0,
            'amount' => 1,
        ])->assertStatus(404);

        $this->assertSame(
            0,
            Queue::withoutGlobalScopes()->where('vehicle_id', $theirs['vehicle']->id)->count(),
            'no phantom trip may be planted on another SACCO bus'
        );
    }

    #[Test]
    public function omitting_sacco_id_still_works_on_your_own_sacco(): void
    {
        // The guard must not break the ordinary case it exists to protect.
        $mine = $this->makeWorld();

        Sanctum::actingAs($this->adminFor($mine, ['Add Fares', 'Edit Fares']));

        $this->postJson('/api/v1/auth/saccos/fares/add', [
            'route_id' => $mine['route']->id,
            'from_place_id' => $mine['from']->id,
            'to_place_id' => $mine['to']->id,
            'amount' => 120,
        ])->assertOk();

        $this->assertSame(
            1,
            RouteFare::withoutGlobalScopes()->where('sacco_id', $mine['sacco']->id)->count()
        );
    }

    #[Test]
    public function naming_your_own_sacco_explicitly_is_allowed(): void
    {
        $mine = $this->makeWorld();

        Sanctum::actingAs($this->adminFor($mine, ['Add Fares', 'Edit Fares']));

        $this->postJson('/api/v1/auth/saccos/fares/add', [
            'sacco_id' => $mine['sacco']->id,
            'route_id' => $mine['route']->id,
            'from_place_id' => $mine['from']->id,
            'to_place_id' => $mine['to']->id,
            'amount' => 150,
        ])->assertOk();
    }
}
