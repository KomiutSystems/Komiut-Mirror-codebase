<?php

declare(strict_types=1);

namespace Tests\Feature\Crew;

use App\Enums\UserType;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A SACCO can put a crew member's account type right from the dashboard.
 *
 * `roleTypeMismatch()` has always been able to SPOT the commonest break on this
 * platform — an account holding an operational role while `type` still says
 * passenger — and nothing could FIX it. There was no endpoint anywhere that
 * wrote `users.type` except member creation, and `users.phone` is unique, so a
 * SACCO could not even re-create the person on their real number.
 *
 * Driver login is the gate that makes it matter: DriverAuthController checks
 * `type === UserType::Driver` and 403s with "This account is not a driver",
 * whatever roles the account holds. Found in production on 2026-09-07 —
 * KDP 514E, KDT 448T and KDT 711S, three buses taking over a thousand payments
 * a week each, with nobody aboard who could sign in.
 *
 * The boundary is the point of the tests below: crew types only, never admin,
 * and an admin's own type is untouchable here in either direction.
 */
final class PromotingCrewToDriverTest extends QueueTestCase
{
    private function url(User $crew): string
    {
        return '/api/v1/auth/crew/'.$crew->id;
    }

    /** A SACCO admin who may edit members. */
    private function admin(array $world): User
    {
        $admin = $this->makeUser(['View Vehicle Users', 'Edit Sacco Members'], $world['sacco']);
        $admin->forceFill(['type' => UserType::Admin])->save();

        return $admin->fresh();
    }

    /** Someone working a bus whose account was never promoted past passenger. */
    private function strandedCrew(array $world): User
    {
        $crew = $this->makeUser([], $world['sacco']);
        $crew->forceFill(['type' => UserType::Passenger, 'phone' => '0722000111'])->save();

        VehicleUser::create([
            'user_id' => $crew->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return $crew->fresh();
    }

    /** The fields the endpoint requires on every write. */
    private function payload(User $crew, array $extra = []): array
    {
        return array_merge([
            'firstname' => $crew->firstname,
            'lastname' => $crew->lastname,
            'phone' => $crew->phone,
        ], $extra);
    }

    #[Test]
    public function a_passenger_typed_crew_member_can_be_promoted_to_driver(): void
    {
        $world = $this->makeWorld();
        $crew = $this->strandedCrew($world);

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($crew), $this->payload($crew, ['type' => 'driver']))->assertOk();

        $this->assertSame(UserType::Driver, $crew->fresh()->type);
    }

    #[Test]
    public function once_promoted_they_can_actually_sign_into_the_driver_app(): void
    {
        // THE POINT OF THE WHOLE CHANGE. Promotion that does not end in a
        // working login has fixed nothing — this is the assertion that ties the
        // dashboard to the thing the driver is standing at a stage trying to do.
        $world = $this->makeWorld();
        $crew = $this->strandedCrew($world);

        $refused = $this->postJson('/api/v1/auth/driver/login', [
            'phone' => $crew->phone,
            'plate' => $world['vehicle']->plate,
        ]);
        $refused->assertStatus(403)->assertJsonPath('error', 'This account is not a driver');

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($crew), $this->payload($crew, ['type' => 'driver']))->assertOk();

        $this->app->get('auth')->forgetGuards();
        $this->postJson('/api/v1/auth/driver/login', [
            'phone' => $crew->phone,
            'plate' => $world['vehicle']->plate,
        ])->assertOk();
    }

    #[Test]
    public function conductor_is_stored_as_a_driver_type(): void
    {
        // SACCOs think and speak in conductors, but UserType has no such case —
        // the legacy migration moved every conductor to Driver. Stored raw the
        // value would be unreadable by the cast and the account unloadable.
        $world = $this->makeWorld();
        $crew = $this->strandedCrew($world);

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($crew), $this->payload($crew, ['type' => 'conductor']))->assertOk();

        $this->assertSame(UserType::Driver, $crew->fresh()->type);
    }

    #[Test]
    public function a_driver_can_be_put_back_to_passenger(): void
    {
        // Movement between crew types goes both ways — someone who left the road
        // should stop being able to sign in as a driver.
        $world = $this->makeWorld();
        $crew = $this->strandedCrew($world);
        $crew->forceFill(['type' => UserType::Driver])->save();

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($crew), $this->payload($crew, ['type' => 'passenger']))->assertOk();

        $this->assertSame(UserType::Passenger, $crew->fresh()->type);
    }

    #[Test]
    public function nobody_can_be_promoted_to_admin_through_the_crew_screen(): void
    {
        // A screen for editing drivers must not mint administrators. Same
        // reasoning that keeps BANK_VIEWER out of Roles::saccoAssignable().
        $world = $this->makeWorld();
        $crew = $this->strandedCrew($world);

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($crew), $this->payload($crew, ['type' => 'admin']))
            ->assertStatus(422);

        $this->assertSame(UserType::Passenger, $crew->fresh()->type);
    }

    #[Test]
    public function nobody_can_be_promoted_to_superadmin_either(): void
    {
        $world = $this->makeWorld();
        $crew = $this->strandedCrew($world);

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($crew), $this->payload($crew, ['type' => 'superadmin']))
            ->assertStatus(422);

        $this->assertSame(UserType::Passenger, $crew->fresh()->type);
    }

    #[Test]
    public function an_existing_admin_cannot_be_demoted_here(): void
    {
        // Demotion looks harmless next to promotion, but there is no way back:
        // this endpoint cannot set `admin` by design, so demoting one would
        // strand them with no dashboard route to restore. One SACCO admin could
        // quietly lock out another.
        $world = $this->makeWorld();
        $other = $this->makeUser([], $world['sacco']);
        $other->forceFill(['type' => UserType::Admin, 'phone' => '0722000222'])->save();

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($other), $this->payload($other->fresh(), ['type' => 'driver']))
            ->assertStatus(422);

        $this->assertSame(UserType::Admin, $other->fresh()->type);
    }

    #[Test]
    public function leaving_type_out_changes_nothing(): void
    {
        // The dashboard has been calling this endpoint without `type` since it
        // shipped; those calls must keep behaving exactly as before.
        $world = $this->makeWorld();
        $crew = $this->strandedCrew($world);

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($crew), $this->payload($crew, ['firstname' => 'Renamed']))->assertOk();

        $fresh = $crew->fresh();
        $this->assertSame(UserType::Passenger, $fresh->type, 'type is untouched when not sent');
        $this->assertSame('Renamed', $fresh->firstname);
    }

    #[Test]
    public function promoting_needs_the_edit_members_permission(): void
    {
        $world = $this->makeWorld();
        $crew = $this->strandedCrew($world);

        $weak = $this->makeUser(['View Vehicle Users'], $world['sacco']);
        Sanctum::actingAs($weak);

        $this->postJson($this->url($crew), $this->payload($crew, ['type' => 'driver']))
            ->assertStatus(403);

        $this->assertSame(UserType::Passenger, $crew->fresh()->type);
    }

    #[Test]
    public function another_saccos_crew_cannot_be_promoted(): void
    {
        // findCrew() scopes to the caller's own SACCO — the tenant boundary for
        // this endpoint. Promotion must not become a way around it.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $crew = $this->strandedCrew($theirs);

        Sanctum::actingAs($this->admin($mine));
        $this->postJson($this->url($crew), $this->payload($crew, ['type' => 'driver']))
            ->assertStatus(404);

        $this->assertSame(UserType::Passenger, $crew->fresh()->type);
    }
}
