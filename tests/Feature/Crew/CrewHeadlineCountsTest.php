<?php

declare(strict_types=1);

namespace Tests\Feature\Crew;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The numbers above the crew list describe the SACCO, not the page.
 *
 * The endpoint returns a `counts` block whose own comment promises "whole-set
 * totals, NOT page totals — a headline computed from the 20 rows in front of you
 * is a lie that changes when you turn the page". It was computed from a builder
 * that had ALREADY been paginated: skip()/take() mutate, and the clone was taken
 * afterwards, so the query still carried the page offset. Page 1 hid it (skip 0);
 * from page 2 the headline quietly dropped the first twenty people, and kept
 * shrinking.
 *
 * The second half is the warning that cried wolf. `roleTypeMismatch` demanded the
 * Driver role of any driver-typed account, but a conductor IS driver-typed here —
 * the legacy migration moved every conductor to UserType::Driver, and the
 * controller's own docblock records that all 171 NICCO drivers carry the role
 * Conductor. So the flag fired on the fleet-wide norm: 171 of NICCO's 200 crew,
 * every driver they have. It buried the cases that genuinely matter — the
 * passenger-typed crew who cannot sign into the driver app at all.
 */
final class CrewHeadlineCountsTest extends QueueTestCase
{
    private const URL = '/api/v1/auth/crew';

    private function admin(array $world): User
    {
        return $this->makeUser(['View Vehicle Users', 'Edit Sacco Members'], $world['sacco']);
    }

    private function crewMember(array $world, UserType $type, ?string $role, int $n): User
    {
        $user = $this->makeUser([], $world['sacco']);
        $user->forceFill(['type' => $type, 'firstname' => sprintf('Crew%03d', $n)])->save();

        if ($role !== null) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        VehicleUser::create([
            'user_id' => $user->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return $user->fresh();
    }

    #[Test]
    public function the_headline_total_is_the_same_on_every_page(): void
    {
        // THE PAGINATION LEAK. 25 crew across two pages of 20.
        $world = $this->makeWorld();
        foreach (range(1, 25) as $n) {
            $this->crewMember($world, UserType::Driver, Roles::CONDUCTOR, $n);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->admin($world));

        $first = $this->getJson(self::URL.'?page=1')->assertOk()->json('counts.total');
        $second = $this->getJson(self::URL.'?page=2')->assertOk()->json('counts.total');

        $this->assertSame($first, $second, 'the SACCO does not shrink because you turned the page');
        $this->assertGreaterThanOrEqual(25, $first, 'the total counts the whole set, not one page of 20');
    }

    #[Test]
    public function the_page_itself_still_holds_twenty(): void
    {
        // The fix must not have turned pagination off.
        $world = $this->makeWorld();
        foreach (range(1, 25) as $n) {
            $this->crewMember($world, UserType::Driver, Roles::CONDUCTOR, $n);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->admin($world));

        $this->assertCount(20, $this->getJson(self::URL.'?page=1')->assertOk()->json('crew'));
    }

    #[Test]
    public function a_conductor_does_not_disagree_with_their_own_account_type(): void
    {
        // The norm, not a fault: driver-typed, role Conductor. This is what 171
        // of NICCO's crew look like.
        $world = $this->makeWorld();
        $this->crewMember($world, UserType::Driver, Roles::CONDUCTOR, 1);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->admin($world));

        $this->assertSame(0, $this->getJson(self::URL)->assertOk()->json('counts.role_type_mismatch'));
    }

    #[Test]
    public function a_driver_holding_the_driver_role_is_fine_too(): void
    {
        $world = $this->makeWorld();
        $this->crewMember($world, UserType::Driver, Roles::DRIVER, 1);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->admin($world));

        $this->assertSame(0, $this->getJson(self::URL)->assertOk()->json('counts.role_type_mismatch'));
    }

    #[Test]
    public function a_passenger_typed_crew_member_is_still_flagged(): void
    {
        // The case that actually matters and was being buried: holds an
        // operational role, but the account type says passenger, so driver login
        // 403s with "This account is not a driver".
        $world = $this->makeWorld();
        $this->crewMember($world, UserType::Passenger, Roles::CONDUCTOR, 1);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->admin($world));

        $this->assertSame(1, $this->getJson(self::URL)->assertOk()->json('counts.role_type_mismatch'));
    }

    #[Test]
    public function a_driver_with_no_operational_role_at_all_is_still_flagged(): void
    {
        // Narrowing the rule must not blunt it: a driver-typed account holding
        // neither Driver nor Conductor is a real disagreement.
        $world = $this->makeWorld();
        $this->crewMember($world, UserType::Driver, null, 1);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->admin($world));

        $this->assertSame(1, $this->getJson(self::URL)->assertOk()->json('counts.role_type_mismatch'));
    }
}
