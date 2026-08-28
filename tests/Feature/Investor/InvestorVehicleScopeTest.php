<?php

declare(strict_types=1);

namespace Tests\Feature\Investor;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Models\Cash;
use App\Models\ExpenseFee;
use App\Models\Mpesa;
use App\Models\QrcodePayment;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The ownership boundary: an investor's money screens show THEIR buses.
 *
 * The Investor role carries View Summaries, View Transactions, View QRCode
 * Payments and View Expense And Fees, and each one resolved to a SACCO-WIDE
 * read — SaccoScope was the only filter in the path, and its question is "which
 * SACCO?", not "which buses?". At NICCO MOVERS that meant ~147 reporting
 * vehicles and KES 2,619,683 of a single day's takings in front of somebody who
 * owns one or two. Twelve accounts hold the role.
 *
 * The fixture is that shape deliberately: ONE SACCO, one brand, no financier, so
 * SaccoScope, BrandScope and FinancierScope all pass every row and OWNERSHIP is
 * the only thing that can separate the four buses. If a future edit splits them
 * by SACCO or bank, these assertions would pass without the ownership filter
 * existing at all — which is the mistake this suite exists to catch.
 *
 * Ownership is read from vehicle_users, never vehicles.user_id: that column
 * records whoever last SAVED the row, and 168 of NICCO's 180 point at the
 * migration account.
 */
final class InvestorVehicleScopeTest extends QueueTestCase
{
    private Sacco $nicco;

    private Seat $seat;

    private User $migrationAccount;

    /** @var array<string, Vehicle> */
    private array $buses = [];

    /** The investor's half of the fixture: 3000 M-Pesa + 500 cash over two buses. */
    private const OWNED_COLLECTIONS = 3500.0;

    /** The rest of the SACCO's: 1000 M-Pesa + 1000 cash over two more. */
    private const OTHER_COLLECTIONS = 2000.0;

    protected function setUp(): void
    {
        parent::setUp();

        // The totals tiles and page counts are cached on the query's SQL and
        // bindings (PaginatesResults). Tests in one process share the array
        // store, so flush between them rather than reason about key collisions.
        Cache::flush();

        $this->nicco = $this->makeSacco();
        $this->seat = $this->makeSeat();

        // vehicles.user_id points here for every bus, exactly as production
        // looks after the migration. Nothing may treat it as ownership.
        $this->migrationAccount = $this->makeUser([], $this->nicco);

        $this->buses['KDI001A'] = $this->busWithADay('KDI001A', mpesa: 1000, cash: 500, expense: 100);
        $this->buses['KDI002A'] = $this->busWithADay('KDI002A', mpesa: 2000, cash: 0, expense: 200);
        $this->buses['KDX003N'] = $this->busWithADay('KDX003N', mpesa: 300, cash: 100, expense: 300);
        $this->buses['KDX004N'] = $this->busWithADay('KDX004N', mpesa: 700, cash: 900, expense: 400);
    }

    /** A bus in NICCO carrying one day of every kind of money this platform records. */
    private function busWithADay(string $plate, float $mpesa, float $cash, float $expense): Vehicle
    {
        $vehicle = $this->makeVehicle($this->nicco, $this->migrationAccount, $this->seat);
        $vehicle->plate = $plate;
        $vehicle->save();

        Summary::create([
            'vehicle_id' => $vehicle->id,
            'mpesa_amount' => $mpesa,
            'cash_amount' => $cash,
            'mpesa_txn' => $mpesa > 0 ? 1 : 0,
            'cash_txn' => $cash > 0 ? 1 : 0,
            // A STRING: the column was added later as varchar with default '0'.
            'expense_fee_amount' => (string) $expense,
            'trans_date' => today(),
        ]);

        $payment = Mpesa::create([
            'TransID' => $plate.'-TX',
            'MSISDN' => '254700111222',
            'TransAmount' => $mpesa,
            'TransTime' => now(),
            'FirstName' => 'Investor', 'LastName' => 'Test',
            'BusinessShortCode' => '5557936',
        ]);

        Transaction::create([
            'mpesa_id' => $payment->id,
            'vehicle_id' => $vehicle->id,
            'amount' => $mpesa,
            'trans_date' => now(),
        ]);

        QrcodePayment::create([
            'vehicle_id' => $vehicle->id,
            'user_id' => $this->migrationAccount->id,
            'amount' => $mpesa,
            'status' => true,
        ]);

        // The cash tab of the same Transactions screen. Written for every bus
        // including the ones taking 0 — a row worth nothing still names a
        // vehicle, and leaking WHICH buses exist is part of what this closes.
        Cash::create([
            'trans_id' => $plate.'-CASH',
            'vehicle_id' => $vehicle->id,
            'user_id' => $this->migrationAccount->id,
            'firstname' => 'Investor', 'lastname' => 'Test',
            // Every one of the five money columns is NOT NULL in the migration —
            // including luggage_amount, which has no default. Omitting one is a
            // failed insert, not a zero.
            'total_amount' => $cash,
            'fare_amount' => $cash,
            'recieved_amount' => $cash,
            'luggage_amount' => 0,
            'change_amount' => 0,
            'passengers' => 1,
            'trans_date' => now(),
        ]);

        // ExpenseFee is itself sacco-scoped while every row carries a NULL
        // sacco_id, so a plain firstOrCreate would depend on who is logged in.
        $category = ExpenseFee::withoutGlobalScopes()
            ->firstOrCreate(['name' => 'Fuel '.$this->nextSequence()], ['status' => true]);

        VehicleExpenseAndFee::withoutGlobalScopes()->create([
            'vehicle_id' => $vehicle->id,
            'expense_fee_id' => $category->id,
            'amount' => $expense,
            'trans_date' => now(),
            'status' => 1,
        ]);

        return $vehicle;
    }

    /**
     * An account holding the Investor role, with open assignments on the given
     * buses.
     *
     * Permissions are granted DIRECTLY as well as through the role: the routes
     * are gated by `permission:...` middleware and RoleSeeder does not run in
     * this suite, so a user carrying only an empty Investor role would be
     * refused by the middleware. A fail-closed assertion would then pass on a
     * 403 without the filter ever being reached.
     *
     * @param  array<int, string>  $ownedPlates
     * @param  array<int, string>  $alsoRoles
     */
    private function investor(array $ownedPlates, array $alsoRoles = []): User
    {
        $user = $this->makeUser(
            ['View Summaries', 'View Transactions', 'View QRCode Payments', 'View Expense And Fees'],
            $this->nicco,
        );

        foreach (array_merge([Roles::INVESTOR], $alsoRoles) as $role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        foreach ($ownedPlates as $plate) {
            $this->assign($user, $this->buses[$plate]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * An OPEN assignment: status = true AND end_date IS NULL.
     *
     * withoutGlobalScopes for the same reason OwnerFleetTest uses it — this is
     * fixture setup, and the row must land regardless of who happens to be
     * authenticated while the fixture is built.
     */
    private function assign(User $user, Vehicle $vehicle, ?string $endDate = null, bool $status = true): VehicleUser
    {
        return VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $vehicle->sacco_id,
            'status' => $status,
            'start_date' => now()->subMonth(),
            'end_date' => $endDate,
        ]);
    }

    /** An ordinary SACCO admin — the no-regression control. */
    private function saccoAdmin(): User
    {
        $user = $this->makeUser(
            ['View Summaries', 'View Transactions', 'View QRCode Payments', 'View Expense And Fees'],
            $this->nicco,
        );

        Role::findOrCreate(Roles::SACCO_ADMIN, 'web');
        $user->assignRole(Roles::SACCO_ADMIN);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /*
     |---------------------------------------------------------------------
     | Readers. One per screen the Investor role can reach.
     |---------------------------------------------------------------------
     */

    /** @return array<int, string> */
    private function summaryPlates(): array
    {
        $rows = $this->getJson('/api/v1/auth/summaries')->assertOk()->json('summaries');

        return array_column(array_column($rows, 'vehicle'), 'plate');
    }

    /** @return array<int, string> */
    private function transactionPlates(): array
    {
        $rows = $this->getJson('/api/v1/auth/transactions')->assertOk()->json('transactions');

        return array_column(array_column($rows, 'vehicle'), 'plate');
    }

    /** @return array<int, string> */
    private function mpesaReceipts(): array
    {
        return array_column(
            $this->getJson('/api/v1/auth/transactions/mpesa')->assertOk()->json('mpesa'),
            'TransID',
        );
    }

    /** @return array<int, string> */
    private function cashReceipts(): array
    {
        return array_column(
            $this->getJson('/api/v1/auth/transactions/cash')->assertOk()->json('cash'),
            'trans_id',
        );
    }

    /** @return array<int, string> */
    private function qrPlates(): array
    {
        $rows = $this->getJson('/api/v1/auth/qrcode/payments')->assertOk()->json('payments');

        return array_column(array_column($rows, 'vehicle'), 'plate');
    }

    /** @return array<int, string> */
    private function expensePlates(): array
    {
        $rows = $this->getJson('/api/v1/auth/expense_and_fees')
            ->assertOk()->json('vehicle_expense_and_fees');

        return array_column(array_column($rows, 'vehicle'), 'plate');
    }

    /*
     |---------------------------------------------------------------------
     | The fixture itself.
     |---------------------------------------------------------------------
     */

    #[Test]
    public function the_fixture_separates_the_buses_by_ownership_alone(): void
    {
        // A guard on the test, not on the code. If a later edit gives the two
        // halves different SACCOs, brands or financiers, an existing scope
        // starts doing the hiding and every assertion below stops proving that
        // the ownership filter exists.
        $vehicles = Vehicle::withoutGlobalScopes()->get();

        $this->assertSame([$this->nicco->id], $vehicles->pluck('sacco_id')->unique()->values()->all());
        $this->assertSame(['testing'], $vehicles->pluck('brand')->unique()->values()->all());
        $this->assertSame([null], $vehicles->pluck('financier')->unique()->values()->all());
    }

    #[Test]
    public function vehicles_user_id_is_not_ownership(): void
    {
        // Every bus points at the migration account, exactly as production does
        // for 168 of NICCO's 180. Anything keyed on this column would hand one
        // account the whole fleet and every real investor nothing.
        $this->assertSame(
            [$this->migrationAccount->id],
            Vehicle::withoutGlobalScopes()->pluck('user_id')->unique()->values()->all(),
        );
    }

    /*
     |---------------------------------------------------------------------
     | An investor sees their own buses, and only those.
     |---------------------------------------------------------------------
     */

    #[Test]
    public function an_investor_sees_only_their_own_buses_takings(): void
    {
        Sanctum::actingAs($this->investor(['KDI001A', 'KDI002A']));

        $plates = $this->summaryPlates();

        $this->assertEqualsCanonicalizing(['KDI001A', 'KDI002A'], $plates);
        $this->assertNotContains('KDX003N', $plates, 'An investor must never see a bus they do not own.');
    }

    #[Test]
    public function the_summaries_totals_footer_narrows_with_the_list(): void
    {
        // The sharpest case, and the reason the filter lives in baseQuery. The
        // footer is an UNGROUPED sum over the filtered set: narrow the table but
        // not the footer and an investor sees two rows under the whole SACCO's
        // headline, which reads as missing money rather than as a bug.
        Sanctum::actingAs($this->investor(['KDI001A', 'KDI002A']));

        $body = $this->getJson('/api/v1/auth/summaries')->assertOk()->json();

        $this->assertSame(self::OWNED_COLLECTIONS, (float) $body['totals']['collections']);
        $this->assertSame(2, $body['totals']['vehicles']);
        $this->assertNotSame(
            self::OWNED_COLLECTIONS + self::OTHER_COLLECTIONS,
            (float) $body['totals']['collections'],
            'The footer must not be the whole SACCO\'s takings.',
        );

        // The two legacy keys the dashboard still reads directly must narrow too.
        $this->assertSame(3000.0, (float) $body['mpesa']);
        $this->assertSame(500.0, (float) $body['cash']);
    }

    #[Test]
    public function the_summaries_export_narrows_with_the_screen(): void
    {
        // CSV and PDF read through the same baseQuery as the list and the
        // footer, so this pins that a download cannot drift away from the screen
        // it was taken from.
        Sanctum::actingAs($this->investor(['KDI001A', 'KDI002A']));

        $csv = $this->get('/api/v1/auth/summaries/export?format=csv')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('KDI001A', $csv);
        $this->assertStringContainsString('KDI002A', $csv);
        $this->assertStringNotContainsString('KDX003N', $csv);
        $this->assertStringNotContainsString('KDX004N', $csv);
        $this->assertStringContainsString('2 vehicle(s)', $csv);
        $this->assertStringContainsString('3500.00', $csv);
    }

    #[Test]
    public function an_investor_sees_only_their_own_buses_transactions(): void
    {
        Sanctum::actingAs($this->investor(['KDI001A', 'KDI002A']));

        $body = $this->getJson('/api/v1/auth/transactions')->assertOk()->json();
        $plates = array_column(array_column($body['transactions'], 'vehicle'), 'plate');

        $this->assertEqualsCanonicalizing(['KDI001A', 'KDI002A'], $plates);

        // The tiles beside the table are separate SUMs over the same filtered
        // query and must narrow with it.
        $this->assertSame(3000.0, (float) $body['mpesa']);
        $this->assertSame(2, $body['total'], 'The pager must count the narrowed set.');
    }

    #[Test]
    public function an_investor_sees_only_their_own_buses_mpesa_payments(): void
    {
        Sanctum::actingAs($this->investor(['KDI001A', 'KDI002A']));

        $this->assertEqualsCanonicalizing(['KDI001A-TX', 'KDI002A-TX'], $this->mpesaReceipts());
    }

    #[Test]
    public function an_investor_sees_only_their_own_buses_cash_payments(): void
    {
        // The other tab of the Transactions screen, gated on the SAME 'View
        // Transactions' permission as the M-Pesa tab. NICCO's KES 2,619,683 is
        // the two tabs added together, so narrowing one and not the other would
        // have moved the leak one click to the left rather than closing it.
        Sanctum::actingAs($this->investor(['KDI001A', 'KDI002A']));

        $this->assertEqualsCanonicalizing(
            ['KDI001A-CASH', 'KDI002A-CASH'],
            $this->cashReceipts(),
        );
    }

    #[Test]
    public function an_investor_sees_only_their_own_buses_qr_payments(): void
    {
        Sanctum::actingAs($this->investor(['KDI001A', 'KDI002A']));

        $this->assertEqualsCanonicalizing(['KDI001A', 'KDI002A'], $this->qrPlates());
    }

    #[Test]
    public function an_investor_sees_only_their_own_buses_expenses(): void
    {
        Sanctum::actingAs($this->investor(['KDI001A', 'KDI002A']));

        $this->assertEqualsCanonicalizing(['KDI001A', 'KDI002A'], $this->expensePlates());
    }

    #[Test]
    public function an_investor_with_one_bus_sees_one_bus(): void
    {
        // The common production shape — most of the twelve hold a single bus.
        Sanctum::actingAs($this->investor(['KDI001A']));

        $this->assertSame(['KDI001A'], $this->summaryPlates());

        $body = $this->getJson('/api/v1/auth/summaries')->assertOk()->json();
        $this->assertSame(1500.0, (float) $body['totals']['collections']);
        $this->assertSame(1, $body['totals']['vehicles']);
    }

    /*
     |---------------------------------------------------------------------
     | FAIL CLOSED. The single most important behaviour here.
     |---------------------------------------------------------------------
     */

    #[Test]
    public function an_investor_with_no_assignments_sees_nothing_at_all(): void
    {
        // An ownership filter that cannot work out what to narrow by must return
        // NOTHING, never everything. The old code did the opposite — the
        // expenses listing guarded its vehicle filter with `count() > 0`, so an
        // account with no assignments fell through to an unfiltered read. That
        // is also how SaccoScope once handed a passenger 5,033 summaries worth
        // KES 78,223,947.
        //
        // 200 with an empty body, not 403: the caller genuinely holds the
        // permissions, there is simply nothing they own yet.
        Sanctum::actingAs($this->investor([]));

        $this->assertSame([], $this->summaryPlates(), 'Summaries must be empty.');
        $this->assertSame([], $this->transactionPlates(), 'Transactions must be empty.');
        $this->assertSame([], $this->mpesaReceipts(), 'M-Pesa payments must be empty.');
        $this->assertSame([], $this->cashReceipts(), 'Cash payments must be empty.');
        $this->assertSame([], $this->qrPlates(), 'QR payments must be empty.');
        $this->assertSame([], $this->expensePlates(), 'Expenses must be empty.');

        $body = $this->getJson('/api/v1/auth/summaries')->assertOk()->json();
        $this->assertSame(0.0, (float) $body['totals']['collections']);
        $this->assertSame(0, $body['totals']['vehicles']);
        $this->assertSame(0.0, (float) $body['mpesa']);
    }

    #[Test]
    public function the_export_is_empty_for_an_investor_who_owns_nothing(): void
    {
        // The download has to fail closed too, or it becomes the way around the
        // screen.
        Sanctum::actingAs($this->investor([]));

        $csv = $this->get('/api/v1/auth/summaries/export?format=csv')
            ->assertOk()->streamedContent();

        $this->assertStringNotContainsString('KDI001A', $csv);
        $this->assertStringNotContainsString('KDX003N', $csv);
        $this->assertStringContainsString('0 vehicle(s)', $csv);
    }

    #[Test]
    public function a_closed_assignment_confers_no_ownership(): void
    {
        // An OPEN assignment is status = true AND end_date IS NULL. A bus an
        // investor has sold must stop reporting to them, and testing only
        // `status` would keep it visible on any row where the flag was left set.
        $user = $this->investor([]);
        $this->assign($user, $this->buses['KDI001A'], endDate: now()->subDay()->toDateTimeString());
        $this->assign($user, $this->buses['KDI002A'], status: false);

        Sanctum::actingAs($user->fresh());

        $this->assertSame([], $this->summaryPlates());
        $this->assertSame([], $this->expensePlates());
    }

    #[Test]
    public function a_vehicles_filter_cannot_widen_an_investor_past_their_own_buses(): void
    {
        // ?vehicles is a picker on the screen, not an authorization input. It
        // ANDs with the ownership filter, so naming somebody else's bus returns
        // nothing rather than that bus.
        Sanctum::actingAs($this->investor(['KDI001A']));

        $target = $this->buses['KDX003N']->id;

        $rows = $this->getJson('/api/v1/auth/summaries?vehicles=['.$target.']')
            ->assertOk()->json('summaries');

        $this->assertSame([], $rows);
    }

    /*
     |---------------------------------------------------------------------
     | Who must NOT be narrowed.
     |---------------------------------------------------------------------
     */

    #[Test]
    public function an_investor_who_is_also_a_sacco_admin_keeps_the_whole_sacco(): void
    {
        // Millicent Gichimu at NICCO is exactly this: an Investor and a SACCO
        // Admin. She runs the SACCO from this dashboard, and narrowing her to
        // the buses she personally owns would break it. Staff role wins.
        Sanctum::actingAs($this->investor(['KDI001A'], alsoRoles: [Roles::SACCO_ADMIN]));

        $this->assertEqualsCanonicalizing(
            ['KDI001A', 'KDI002A', 'KDX003N', 'KDX004N'],
            $this->summaryPlates(),
        );

        $body = $this->getJson('/api/v1/auth/summaries')->assertOk()->json();
        $this->assertSame(self::OWNED_COLLECTIONS + self::OTHER_COLLECTIONS, (float) $body['totals']['collections']);
        $this->assertSame(4, $body['totals']['vehicles']);
    }

    #[Test]
    public function a_plain_sacco_admin_is_completely_unaffected(): void
    {
        // The no-regression control. This filter must narrow investors and
        // nobody else — an admin with no vehicle_users row at all still sees
        // every bus, on every one of the five screens.
        Sanctum::actingAs($this->saccoAdmin());

        $all = ['KDI001A', 'KDI002A', 'KDX003N', 'KDX004N'];

        $this->assertEqualsCanonicalizing($all, $this->summaryPlates());
        $this->assertEqualsCanonicalizing($all, $this->transactionPlates());
        $this->assertEqualsCanonicalizing($all, $this->qrPlates());
        $this->assertCount(4, $this->mpesaReceipts());
        $this->assertCount(4, $this->cashReceipts());

        $body = $this->getJson('/api/v1/auth/summaries')->assertOk()->json();
        $this->assertSame(self::OWNED_COLLECTIONS + self::OTHER_COLLECTIONS, (float) $body['totals']['collections']);
    }

    #[Test]
    public function an_investor_who_is_a_superadmin_is_not_narrowed(): void
    {
        // Matches how SaccoScope, BrandScope and FinancierScope all treat the
        // platform role: it sits above every boundary.
        $super = $this->investor([]);
        $super->forceFill(['type' => UserType::Superadmin])->save();

        Sanctum::actingAs($super->fresh());

        $this->assertCount(4, $this->summaryPlates());
    }

    /*
     |---------------------------------------------------------------------
     | The decision itself, without a controller in the path.
     |---------------------------------------------------------------------
     */

    /**
     * The trait's own answer, with no controller in the path.
     *
     * Composed into a throwaway class rather than called statically on the
     * trait: a static call directly on a trait is deprecated as of PHP 8.1, and
     * this is also how the real callers reach it.
     */
    private function narrowsFor(?User $user): bool
    {
        if ($user !== null) {
            $this->actingAs($user);
        }

        $probe = new class
        {
            use ScopesToOwnedVehicles;

            public function narrowed(): bool
            {
                return $this->ownedVehicleIds() !== null;
            }
        };

        return $probe->narrowed();
    }

    #[Test]
    public function only_an_investor_who_is_nothing_else_is_narrowed(): void
    {
        $this->assertTrue($this->narrowsFor($this->investor([])));

        $this->assertFalse(
            $this->narrowsFor($this->investor([], alsoRoles: [Roles::SACCO_ADMIN])),
            'A SACCO admin who also invests is staff first.',
        );
        $this->assertFalse(
            $this->narrowsFor($this->investor([], alsoRoles: [Roles::FINANCE])),
            'Finance reads the whole SACCO by job description.',
        );
        $this->assertFalse(
            $this->narrowsFor($this->saccoAdmin()),
            'A caller with no Investor role must never be narrowed.',
        );
    }

    #[Test]
    public function an_unauthenticated_context_is_never_narrowed(): void
    {
        // Webhooks, callbacks and console commands run with no user. They must
        // be left exactly as they are — the M-Pesa confirmation handler has to
        // match a payment to any till on the platform.
        $this->assertFalse($this->narrowsFor(null));
    }

    #[Test]
    public function a_conductor_who_also_invests_is_still_narrowed(): void
    {
        // Crew roles do NOT lift the narrowing. A conductor's bundle carries no
        // fleet-wide job, so an investor who also works a stage stays an
        // investor for the purposes of the money screens.
        Sanctum::actingAs($this->investor(['KDI001A'], alsoRoles: [Roles::CONDUCTOR]));

        $this->assertSame(['KDI001A'], $this->summaryPlates());
    }
}
