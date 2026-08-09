<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserType;
use App\Models\Booking;
use App\Models\User;
use App\Models\Vehicle;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Denial tests for the SACCO tenancy scope. The existing suite proves happy-path
 * access; these prove the thing that was broken — that one SACCO admin cannot
 * reach another SACCO's rows. Reuses QueueTestCase purely for its builders.
 */
final class SaccoScopeTest extends QueueTestCase
{
    #[Test]
    public function an_admin_sees_only_their_own_saccos_vehicles(): void
    {
        [$adminA, $vehicleA, $vehicleB] = $this->twoSaccoWorld();

        $this->actingAs($adminA);

        $ids = Vehicle::all()->pluck('id');

        $this->assertTrue($ids->contains($vehicleA->id), 'Admin A must see their own vehicle.');
        $this->assertFalse($ids->contains($vehicleB->id), 'Admin A must NOT see SACCO B vehicles.');
    }

    #[Test]
    public function find_does_not_resolve_another_saccos_vehicle(): void
    {
        [$adminA, $vehicleA, $vehicleB] = $this->twoSaccoWorld();

        $this->actingAs($adminA);

        $this->assertNotNull(Vehicle::find($vehicleA->id), 'Own vehicle must still resolve.');
        $this->assertNull(Vehicle::find($vehicleB->id), 'Another SACCO vehicle must resolve to null (was an IDOR).');
    }

    #[Test]
    public function an_unauthenticated_request_is_not_scoped(): void
    {
        // Webhooks (M-Pesa/NCBA/Coop) run with no auth user and must still see
        // every vehicle to match a payment to its till/short-code.
        [, $vehicleA, $vehicleB] = $this->twoSaccoWorld();

        $ids = Vehicle::all()->pluck('id');

        $this->assertTrue($ids->contains($vehicleA->id));
        $this->assertTrue($ids->contains($vehicleB->id), 'Unauthenticated (webhook) context must not be scoped.');
    }

    #[Test]
    public function a_passenger_without_a_sacco_is_not_scoped(): void
    {
        // A passenger books across SACCOs, so their vehicle/queue queries must
        // not be restricted to a single SACCO.
        [, $vehicleA, $vehicleB] = $this->twoSaccoWorld();

        $passenger = $this->makeUser();          // sacco_id null
        $passenger->forceFill(['type' => UserType::Passenger])->save();

        $this->actingAs($passenger);

        $this->assertSame(2, Vehicle::whereIn('id', [$vehicleA->id, $vehicleB->id])->count());
    }

    #[Test]
    public function a_superadmin_sees_every_saccos_vehicles(): void
    {
        [, $vehicleA, $vehicleB] = $this->twoSaccoWorld();

        Role::findOrCreate('Super Admin', 'web');
        $super = $this->makeUser();
        $super->assignRole('Super Admin');

        $this->actingAs($super);

        $this->assertSame(2, Vehicle::whereIn('id', [$vehicleA->id, $vehicleB->id])->count());
    }

    #[Test]
    public function relation_reached_booking_is_scoped_through_queue_vehicle(): void
    {
        // Booking has no sacco_id of its own — it is scoped via queue.vehicle.
        // This is the case the audit was unsure about: does whereHas actually
        // constrain find()?
        $saccoA = $this->makeSacco();
        $saccoB = $this->makeSacco();
        $adminA = $this->makeUser(['View Sacco Members'], $saccoA);
        $ownerB = $this->makeUser(['View Sacco Members'], $saccoB);

        $status = $this->makeQueueStatus('Pending', 'Pending');
        $worldA = $this->bookingIn($saccoA, $adminA, $status);
        $worldB = $this->bookingIn($saccoB, $ownerB, $status);

        $this->actingAs($adminA);

        $this->assertNotNull(Booking::find($worldA), 'Own booking must resolve.');
        $this->assertNull(Booking::find($worldB), 'Another SACCO booking must NOT resolve (whereHas must constrain find).');

        $ids = Booking::all()->pluck('id');
        $this->assertTrue($ids->contains($worldA));
        $this->assertFalse($ids->contains($worldB), 'List must exclude other SACCO bookings.');
    }

    #[Test]
    public function the_users_api_lists_only_the_admins_own_sacco_users(): void
    {
        // User cannot be globally scoped (auth model), so getUsers was fixed by
        // hand to derive the sacco from the authed user, not a request param.
        $saccoA = $this->makeSacco();
        $saccoB = $this->makeSacco();
        $adminA = $this->makeUser(['View Sacco Members'], $saccoA);
        $memberB = $this->makeUser(['View Sacco Members'], $saccoB);

        \Laravel\Sanctum\Sanctum::actingAs($adminA);

        // Even explicitly asking for SACCO B's id must not leak B's users.
        $response = $this->getJson('/api/auth/users?sacco=' . $saccoB->id);

        $response->assertOk();
        $ids = collect($response->json('users'))->pluck('id');
        $this->assertTrue($ids->contains($adminA->id), 'Own SACCO user must be listed.');
        $this->assertFalse($ids->contains($memberB->id), 'Another SACCO user must never be listed, even when requested by id.');
    }

    /** Builds a full queue+booking graph in one sacco, returns the booking id. */
    private function bookingIn($sacco, User $owner, $status): int
    {
        $from = $this->makePlace('From ' . $this->nextSequence());
        $to = $this->makePlace('To ' . $this->nextSequence());
        $route = $this->makeRoute($from, $to);
        $terminus = $this->makeTerminus($from);
        $vehicle = $this->makeVehicle($sacco, $owner, $this->makeSeat());
        $queue = $this->makeQueue($vehicle, $terminus, $route, $status, $owner);

        return $this->makeBooking($queue, $owner, $from, $to)->id;
    }

    /**
     * Two SACCOs, each with one vehicle, and an admin belonging to SACCO A.
     *
     * @return array{0: User, 1: Vehicle, 2: Vehicle}
     */
    private function twoSaccoWorld(): array
    {
        $saccoA = $this->makeSacco();
        $saccoB = $this->makeSacco();

        $adminA = $this->makeUser(['View Sacco Members'], $saccoA);
        $ownerB = $this->makeUser(['View Sacco Members'], $saccoB);

        $vehicleA = $this->makeVehicle($saccoA, $adminA, $this->makeSeat());
        $vehicleB = $this->makeVehicle($saccoB, $ownerB, $this->makeSeat());

        return [$adminA, $vehicleA, $vehicleB];
    }
}
