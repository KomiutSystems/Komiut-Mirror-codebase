<?php

declare(strict_types=1);

namespace Tests\Feature\Bank;

use App\Auth\Roles;
use App\Enums\Financier;
use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The bank boundary, on the fixture that makes it necessary.
 *
 * Production has exactly one mixed SACCO — NICCO MOVERS, 180 vehicles, 126
 * financed by NCBA and 54 by Co-op — and it is the whole reason this axis
 * exists. Every other boundary in the codebase collapses on it: sacco_id puts
 * both banks in one bucket, and brand is the wrong key by 11 vehicles for NCBA
 * while being accidentally exact for Co-op.
 *
 * The fixture below mirrors that: ONE SACCO, two financier sets, money on both.
 *
 * It deliberately does NOT mirror production's brand split. In prod the Co-op
 * vehicles are also brand=safiri, and Summary/Transaction reach brand through
 * the vehicle — so a fixture that copied that would let BrandScope hide the
 * Co-op rows and every assertion here would pass without the financier
 * predicate existing at all. Green for Co-op, wrong for NCBA: precisely the
 * mistake this feature exists to avoid. Both sets are brand 'testing', so
 * financier is the only thing that can separate them.
 */
final class FinancierScopeTest extends QueueTestCase
{
    private Sacco $nicco;

    private Seat $seat;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // One SACCO holding both banks' vehicles — the NICCO shape.
        $this->nicco = $this->makeSacco();
        $this->owner = $this->makeUser([], $this->nicco);
        $this->seat = $this->makeSeat();

        $this->financedVehicle('KDN001N', Financier::Ncba, mpesa: 1000, cash: 500);
        $this->financedVehicle('KDN002N', Financier::Ncba, mpesa: 2000, cash: 0);
        $this->financedVehicle('KDC003C', Financier::Coop, mpesa: 300, cash: 100);
        $this->financedVehicle('KDC004C', Financier::Coop, mpesa: 700, cash: 900);
    }

    /** NCBA's half of the fixture: 3000 M-Pesa + 500 cash over two vehicles. */
    private const NCBA_COLLECTIONS = 3500.0;

    /** Co-op's half: 1000 M-Pesa + 1000 cash over two vehicles. */
    private const COOP_COLLECTIONS = 2000.0;

    /** A vehicle in NICCO financed by one bank, with a day's takings on it. */
    private function financedVehicle(string $plate, Financier $financier, float $mpesa, float $cash): Vehicle
    {
        $vehicle = $this->makeVehicle($this->nicco, $this->owner, $this->seat);
        $vehicle->plate = $plate;
        $vehicle->financier = $financier->value;
        $vehicle->save();

        Summary::create([
            'vehicle_id' => $vehicle->id,
            'mpesa_amount' => $mpesa,
            'cash_amount' => $cash,
            'mpesa_txn' => $mpesa > 0 ? 1 : 0,
            'cash_txn' => $cash > 0 ? 1 : 0,
            'expense_fee_amount' => '0',
            'trans_date' => today(),
        ]);

        $payment = Mpesa::create([
            'TransID' => $plate.'-TX',
            'MSISDN' => '254700111222',
            'TransAmount' => $mpesa,
            'TransTime' => now(),
            'FirstName' => 'Bank', 'LastName' => 'Test',
            'BusinessShortCode' => '5557936',
        ]);

        Transaction::create([
            'mpesa_id' => $payment->id,
            'vehicle_id' => $vehicle->id,
            'amount' => $mpesa,
            'trans_date' => now(),
        ]);

        return $vehicle;
    }

    /**
     * A bank's staff account.
     *
     * Saccoless, because a bank is not a SACCO — pinning one to NICCO would
     * hide the 47 other SACCOs' vehicles NCBA also finances.
     *
     * The permissions are granted DIRECTLY rather than through the role: the
     * routes are gated by `permission:...` middleware and RoleSeeder does not
     * run in this suite, so a user holding only an empty Bank Viewer role would
     * be refused by the middleware. A fail-closed test would then pass on a 403
     * without the scope ever being reached.
     */
    private function bankUser(?string $financier): User
    {
        $user = $this->makeUser(
            ['View Vehicles', 'View Summaries', 'View Transactions'],
            null,
        );

        Role::findOrCreate(Roles::BANK_VIEWER, 'web');
        $user->assignRole(Roles::BANK_VIEWER);

        if ($financier !== null) {
            $user->financier = $financier;
            $user->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** An ordinary SACCO admin inside NICCO — the no-regression control. */
    private function saccoAdmin(): User
    {
        return $this->makeUser(
            ['View Vehicles', 'View Summaries', 'View Transactions'],
            $this->nicco,
        );
    }

    /** @return array<int, string> */
    private function visiblePlates(): array
    {
        return array_column(
            $this->getJson('/api/v1/auth/vehicles')->assertOk()->json('vehicles'),
            'plate',
        );
    }

    /** @return array<int, string> */
    private function visiblePayments(): array
    {
        return array_column(
            $this->getJson('/api/v1/auth/transactions/mpesa')->assertOk()->json('mpesa'),
            'TransID',
        );
    }

    #[Test]
    public function the_fixture_separates_the_two_banks_by_financier_alone(): void
    {
        // A guard on the test, not on the code. If a future edit gives the
        // Co-op vehicles their production brand, BrandScope starts doing the
        // hiding and every assertion below stops proving anything.
        $brands = Vehicle::withoutGlobalScopes()->pluck('brand')->unique()->values()->all();
        $saccos = Vehicle::withoutGlobalScopes()->pluck('sacco_id')->unique()->values()->all();

        $this->assertSame(['testing'], $brands, 'Both banks\' vehicles must share a brand.');
        $this->assertSame([$this->nicco->id], $saccos, 'Both banks\' vehicles must share a SACCO.');
    }

    #[Test]
    public function an_ncba_user_sees_only_the_vehicles_ncba_financed(): void
    {
        Sanctum::actingAs($this->bankUser(Financier::Ncba->value));

        $plates = $this->visiblePlates();

        $this->assertEqualsCanonicalizing(['KDN001N', 'KDN002N'], $plates);
        $this->assertNotContains('KDC003C', $plates, 'NCBA must never see a Co-op-financed bus.');
    }

    #[Test]
    public function a_coop_user_sees_only_the_vehicles_coop_financed(): void
    {
        // The reverse case, and the one live in production today: the single
        // Bank Viewer account belongs to a Co-op employee.
        Sanctum::actingAs($this->bankUser(Financier::Coop->value));

        $plates = $this->visiblePlates();

        $this->assertEqualsCanonicalizing(['KDC003C', 'KDC004C'], $plates);
        $this->assertNotContains('KDN001N', $plates, 'Co-op must never see an NCBA-financed bus.');
    }

    #[Test]
    public function the_summaries_list_shows_a_bank_only_its_own_fleet(): void
    {
        Sanctum::actingAs($this->bankUser(Financier::Ncba->value));

        $rows = $this->getJson('/api/v1/auth/summaries')->assertOk()->json('summaries');
        $plates = array_column(array_column($rows, 'vehicle'), 'plate');

        $this->assertEqualsCanonicalizing(['KDN001N', 'KDN002N'], $plates);
    }

    #[Test]
    public function the_summaries_totals_footer_is_the_banks_fleet_not_the_saccos(): void
    {
        // The sharpest case. The footer is an UNGROUPED sum over the filtered
        // set, so before this change a bank looking at NICCO was handed
        // 5500 — both banks' money added together, a number that belongs to
        // neither of them and reconciles against nothing.
        Sanctum::actingAs($this->bankUser(Financier::Ncba->value));

        $body = $this->getJson('/api/v1/auth/summaries')->assertOk()->json();

        $this->assertSame(self::NCBA_COLLECTIONS, (float) $body['totals']['collections']);
        $this->assertSame(2, $body['totals']['vehicles']);
        $this->assertNotSame(
            self::NCBA_COLLECTIONS + self::COOP_COLLECTIONS,
            (float) $body['totals']['collections'],
            'The footer must not be the whole SACCO\'s takings.',
        );
    }

    #[Test]
    public function the_summaries_export_totals_only_the_callers_fleet(): void
    {
        // CSV and PDF read through the same baseQuery as the list, so this
        // pins that the export cannot drift away from the screen.
        Sanctum::actingAs($this->bankUser(Financier::Coop->value));

        $csv = $this->get('/api/v1/auth/summaries/export?format=csv')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('KDC003C', $csv);
        $this->assertStringNotContainsString('KDN001N', $csv);
        $this->assertStringContainsString('2 vehicle(s)', $csv);
        $this->assertStringContainsString('2000.00', $csv);
    }

    #[Test]
    public function the_mpesa_list_shows_a_bank_only_its_own_fleets_payments(): void
    {
        Sanctum::actingAs($this->bankUser(Financier::Ncba->value));

        $ids = $this->visiblePayments();

        $this->assertEqualsCanonicalizing(['KDN001N-TX', 'KDN002N-TX'], $ids);
    }

    #[Test]
    public function a_bank_user_with_no_financier_receives_nothing(): void
    {
        // Fail CLOSED, and this is the whole point of the scope. A bank user is
        // saccoless, and SaccoScope returns early on a null sacco_id — so
        // inheriting that shape would have handed a misconfigured bank account
        // every SACCO's vehicles and every SACCO's money. 200 with an empty
        // body, not 403: the caller holds the permissions, there is simply
        // nothing they are entitled to until their bank is recorded.
        Sanctum::actingAs($this->bankUser(null));

        $this->assertSame([], $this->visiblePlates());
        $this->assertSame([], $this->visiblePayments());

        $body = $this->getJson('/api/v1/auth/summaries')->assertOk()->json();
        $this->assertSame([], $body['summaries']);
        $this->assertSame(0.0, (float) $body['totals']['collections']);
        $this->assertSame(0, $body['totals']['vehicles']);
    }

    #[Test]
    public function a_sacco_admin_sees_the_whole_sacco_exactly_as_before(): void
    {
        // The no-regression control. This scope must narrow bank users and
        // nobody else — a SACCO admin still sees both banks' buses, because
        // both banks' buses are theirs.
        Sanctum::actingAs($this->saccoAdmin());

        $this->assertEqualsCanonicalizing(
            ['KDN001N', 'KDN002N', 'KDC003C', 'KDC004C'],
            $this->visiblePlates(),
        );

        $body = $this->getJson('/api/v1/auth/summaries')->assertOk()->json();
        $this->assertSame(self::NCBA_COLLECTIONS + self::COOP_COLLECTIONS, (float) $body['totals']['collections']);
        $this->assertSame(4, $body['totals']['vehicles']);

        $this->assertCount(4, $this->visiblePayments());
    }

    #[Test]
    public function a_superadmin_is_not_confined_to_a_bank(): void
    {
        $super = $this->bankUser(Financier::Coop->value);
        $super->forceFill(['type' => UserType::Superadmin])->save();

        Sanctum::actingAs($super);

        // Exempt even while carrying a financier, matching how SaccoScope and
        // BrandScope treat the platform role: financier is a column they can
        // filter on, not a wall.
        $this->assertCount(4, $this->visiblePlates());
    }

    #[Test]
    public function a_saccoless_non_bank_caller_gets_no_mpesa_payments(): void
    {
        // The tier below the bank one. SaccoScope skips a user with no home
        // SACCO, so a passenger or crew member holding 'View Transactions' but
        // belonging to no SACCO previously read every SACCO's payments.
        Sanctum::actingAs($this->makeUser(['View Transactions'], null));

        $this->assertSame([], $this->visiblePayments());
    }

    #[Test]
    public function the_users_financier_column_is_constrained_to_the_known_banks(): void
    {
        // Unlike vehicles.financier — which ImportLegacyVehicles still writes
        // verbatim from legacy data, unrecognised values included — nothing
        // backfills users.financier, so it can carry a hard constraint. Without
        // it a typo is a bank staring at an empty dashboard with no error to
        // explain why.
        $constraint = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
            ['users_financier_check'],
        );

        $this->assertNotNull($constraint, 'The CHECK constraint added by the migration is missing.');

        foreach (Financier::values() as $bank) {
            $this->assertStringContainsString($bank, $constraint->def);
        }
    }

    #[Test]
    public function an_unrecognised_financier_resolves_to_nothing_rather_than_throwing(): void
    {
        // The resolver behind the fail-closed branch. tryFrom, never from: an
        // authorization key read off a legacy free-text column has to degrade
        // to a denial, because a ValueError here would be a 500 on every read.
        $this->assertNull(Financier::tryParse('Barclays'));
        $this->assertNull(Financier::tryParse('ncba'));
        $this->assertNull(Financier::tryParse(''));
        $this->assertNull(Financier::tryParse(null));
        $this->assertSame(Financier::Ncba, Financier::tryParse('  NCBA  '));
    }

    /*
     |---------------------------------------------------------------------
     | The global scope itself.
     |
     | The tests above reach the boundary through HTTP endpoints, whose
     | controllers call FinancierScope's helpers by hand. That proves those
     | three screens are confined -- it does NOT prove the trait on the models
     | does anything, because a controller-only fix would pass every one of
     | them. These query the models directly, with no controller in the path,
     | so they fail if BelongsToFinancier is removed from a model.
     |
     | This matters because a per-controller boundary is exactly how Cash,
     | Mpesa and QrcodePayment came to be unscoped in the first place: the
     | next endpoint someone adds inherits the model's scope for free, and
     | inherits nothing from a controller.
     |---------------------------------------------------------------------
     */

    #[Test]
    public function the_global_scope_confines_direct_model_queries(): void
    {
        Sanctum::actingAs($this->bankUser(Financier::Ncba->value));

        // Two of the four fixture vehicles are NCBA's, and each carries one
        // summary, one transaction and one M-Pesa payment.
        $this->assertSame(2, Vehicle::count(), 'Vehicle is not financier-scoped.');
        $this->assertSame(2, Summary::count(), 'Summary is not financier-scoped.');
        $this->assertSame(2, Transaction::count(), 'Transaction is not financier-scoped.');
        $this->assertSame(2, Mpesa::count(), 'Mpesa is not financier-scoped.');

        $this->assertEqualsCanonicalizing(
            ['KDN001N', 'KDN002N'],
            Vehicle::pluck('plate')->all(),
        );

        // The money, not just the row count: NCBA's half of the fixture.
        $this->assertSame(
            self::NCBA_COLLECTIONS,
            (float) (Summary::sum('mpesa_amount') + Summary::sum('cash_amount')),
        );
    }

    #[Test]
    public function the_global_scope_confines_the_other_bank_to_its_own_half(): void
    {
        Sanctum::actingAs($this->bankUser(Financier::Coop->value));

        $this->assertEqualsCanonicalizing(
            ['KDC003C', 'KDC004C'],
            Vehicle::pluck('plate')->all(),
        );

        $this->assertSame(
            self::COOP_COLLECTIONS,
            (float) (Summary::sum('mpesa_amount') + Summary::sum('cash_amount')),
        );
    }

    #[Test]
    public function the_global_scope_denies_a_bank_whose_financier_is_unset(): void
    {
        // The fail-closed branch at model level. A bank user whose column was
        // never set must read nothing at all -- never the whole platform.
        Sanctum::actingAs($this->bankUser(null));

        $this->assertSame(0, Vehicle::count());
        $this->assertSame(0, Summary::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Mpesa::count());
    }

    #[Test]
    public function the_global_scope_is_a_no_op_for_everyone_who_is_not_a_bank(): void
    {
        // The regression guard. Adding the trait to seven models must not
        // change what a SACCO admin sees, or the feature has broken the
        // product to secure it.
        Sanctum::actingAs($this->saccoAdmin());

        $this->assertSame(4, Vehicle::count());
        $this->assertSame(4, Summary::count());
        $this->assertSame(4, Transaction::count());
        $this->assertSame(4, Mpesa::count());
    }

    #[Test]
    public function the_bank_viewer_role_itself_carries_the_fleet_permission(): void
    {
        // THE PRODUCTION FAILURE. Every other test in this file grants
        // 'View Vehicles' DIRECTLY, so they proved the scope was right while the
        // ROLE still lacked the permission -- and the `permission:` middleware
        // refuses on the role, returning 403 before FinancierScope is ever
        // reached. A bank opened the fleet screen to "Couldn't load vehicles,
        // 403: User does not have the right permissions" with the money screens
        // beside it working, which reads as broken rather than as forbidden.
        //
        // So this builds the role from the real bundle and grants NOTHING
        // directly.
        $bundle = Roles::bundles()[Roles::BANK_VIEWER];

        foreach ($bundle as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $role = Role::findOrCreate(Roles::BANK_VIEWER, 'web');
        $role->syncPermissions($bundle);

        $user = $this->makeUser([], null);
        $user->assignRole($role);
        $user->financier = Financier::Ncba->value;
        $user->save();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user->fresh());

        $plates = $this->visiblePlates();

        $this->assertEqualsCanonicalizing(
            ['KDN001N', 'KDN002N'],
            $plates,
            'the role alone must open the fleet screen, and only to its own fleet',
        );
        $this->assertNotContains('KDC003C', $plates);
    }

    #[Test]
    public function the_fleet_permission_does_not_come_with_write_access(): void
    {
        // Read-only is the point of a bank partner. The screen renders an "Add
        // vehicle" control, so the guarantee has to be enforced here rather than
        // left to whatever the frontend happens to draw.
        $bundle = Roles::bundles()[Roles::BANK_VIEWER];

        $this->assertContains('View Vehicles', $bundle);

        foreach (['Add Vehicles', 'Edit Vehicles', 'Delete Vehicles'] as $write) {
            $this->assertNotContains($write, $bundle, $write.' must never sit on a bank role.');
        }
    }
}
