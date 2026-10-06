<?php

declare(strict_types=1);

namespace Tests\Feature\Investor;

use App\Auth\Roles;
use App\Enums\Financier;
use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The fleet list narrows an investor (and a driver) to the buses they hold,
 * exactly as every money screen already did.
 *
 * GET vehicles never applied ScopesToOwnedVehicles, so a NICCO investor's
 * Vehicles page listed all 180 of the SACCO's buses — plates, tills and
 * merchant codes — and the bank chips counted the whole SACCO, while the same
 * investor's Summaries, Transactions and M-Pesa screens showed only their own
 * two buses. Ownership is an OPEN vehicle_users assignment (status = true AND
 * end_date IS NULL), never vehicles.user_id.
 *
 * And a refused edit is 403, not 401: the dashboard reads 401 as an expired
 * session, so an investor clicking "Set" (Add Vehicles, not Edit Vehicles) was
 * bounced into a token refresh or signed out.
 */
final class InvestorSeesOwnVehiclesTest extends QueueTestCase
{
    private const LIST_URL = '/api/v1/auth/vehicles';

    private Sacco $sacco;

    private Seat $seat;

    private User $migrationAccount;

    /** @var array<string, Vehicle> */
    private array $buses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->sacco = $this->makeSacco();
        $this->seat = $this->makeSeat();
        $this->migrationAccount = $this->makeUser([], $this->sacco);

        $this->buses['ncba1'] = $this->bus(Financier::Ncba->value);
        $this->buses['ncba2'] = $this->bus(Financier::Ncba->value);
        $this->buses['coop'] = $this->bus(Financier::Coop->value);
        $this->buses['none'] = $this->bus(null);
    }

    private function bus(?string $financier): Vehicle
    {
        $vehicle = $this->makeVehicle($this->sacco, $this->migrationAccount, $this->seat);
        $vehicle->financier = $financier;
        $vehicle->save();

        return $vehicle;
    }

    /** @param  array<int, string>  $roles */
    private function userWithRoles(array $roles, array $permissions = ['View Vehicles', 'Add Vehicles']): User
    {
        $user = $this->makeUser($permissions, $this->sacco);
        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function assign(User $user, Vehicle $vehicle, ?string $endDate = null, bool $status = true): void
    {
        VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $vehicle->sacco_id,
            'status' => $status,
            'start_date' => now()->subMonth(),
            'end_date' => $endDate,
        ]);
    }

    /** @return array<int, int> */
    private function listedIds(): array
    {
        $ids = array_map(fn (array $v) => (int) $v['id'], $this->getJson(self::LIST_URL)->assertOk()->json('vehicles'));
        sort($ids);

        return $ids;
    }

    #[Test]
    public function an_investor_sees_only_the_buses_they_hold(): void
    {
        $investor = $this->userWithRoles([Roles::INVESTOR]);
        $this->assign($investor, $this->buses['ncba1']);
        $this->assign($investor, $this->buses['coop']);
        Sanctum::actingAs($investor);

        $expected = [$this->buses['ncba1']->id, $this->buses['coop']->id];
        sort($expected);

        $this->assertSame($expected, $this->listedIds());
        $this->assertSame(2, $this->getJson(self::LIST_URL)->json('total'));
    }

    #[Test]
    public function an_investors_bank_counts_cover_only_their_buses(): void
    {
        $investor = $this->userWithRoles([Roles::INVESTOR]);
        $this->assign($investor, $this->buses['ncba1']);
        $this->assign($investor, $this->buses['coop']);
        Sanctum::actingAs($investor);

        $this->assertSame(
            ['NCBA' => 1, 'coop-bank' => 1, 'none' => 0],
            $this->getJson(self::LIST_URL)->json('financier_counts'),
        );
    }

    #[Test]
    public function an_investor_who_holds_nothing_sees_nothing(): void
    {
        // [] must compile to 0 = 1, never fall open to the whole SACCO.
        Sanctum::actingAs($this->userWithRoles([Roles::INVESTOR]));

        $this->assertSame([], $this->listedIds());
        $this->assertSame(['NCBA' => 0, 'coop-bank' => 0, 'none' => 0], $this->getJson(self::LIST_URL)->json('financier_counts'));
    }

    #[Test]
    public function an_ended_or_suspended_assignment_is_not_ownership(): void
    {
        $investor = $this->userWithRoles([Roles::INVESTOR]);
        $this->assign($investor, $this->buses['ncba1'], endDate: now()->subDay()->toDateString());
        $this->assign($investor, $this->buses['ncba2'], status: false);
        $this->assign($investor, $this->buses['coop']);
        Sanctum::actingAs($investor);

        $this->assertSame([$this->buses['coop']->id], $this->listedIds());
    }

    #[Test]
    public function an_investor_who_is_also_sacco_staff_keeps_the_whole_fleet(): void
    {
        // Millicent at NICCO is an Investor AND a SACCO Admin: staff first.
        $investor = $this->userWithRoles([Roles::INVESTOR, Roles::SACCO_ADMIN]);
        $this->assign($investor, $this->buses['ncba1']);
        Sanctum::actingAs($investor);

        $this->assertCount(4, $this->listedIds());
        $this->assertSame(['NCBA' => 2, 'coop-bank' => 1, 'none' => 1], $this->getJson(self::LIST_URL)->json('financier_counts'));
    }

    #[Test]
    public function a_fleet_manager_still_sees_the_whole_fleet(): void
    {
        Sanctum::actingAs($this->userWithRoles([Roles::FLEET_MANAGER], ['View Vehicles', 'Edit Vehicles']));

        $this->assertCount(4, $this->listedIds());
    }

    #[Test]
    public function a_driver_sees_only_the_bus_they_are_on(): void
    {
        $driver = $this->userWithRoles([Roles::DRIVER], ['View Vehicles']);
        $driver->forceFill(['type' => UserType::Driver])->save();
        $this->assign($driver, $this->buses['ncba2']);
        Sanctum::actingAs($driver->fresh());

        $this->assertSame([$this->buses['ncba2']->id], $this->listedIds());
    }

    #[Test]
    public function a_refused_edit_is_403_not_401(): void
    {
        // The Investor bundle holds Add Vehicles but not Edit Vehicles.
        $investor = $this->userWithRoles([Roles::INVESTOR], ['View Vehicles', 'Add Vehicles']);
        $this->assign($investor, $this->buses['ncba1']);
        Sanctum::actingAs($investor);

        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => $this->buses['ncba1']->id,
            'plate' => $this->buses['ncba1']->plate,
            'seat' => $this->seat->name,
            'till_number' => 999999,
            'status' => 1,
        ])->assertStatus(403)->assertJsonPath('error', 'Permissions to Add/Edit Vehicle Denied');

        $this->assertNotSame('999999', (string) $this->buses['ncba1']->fresh()->till_number);
    }
}
