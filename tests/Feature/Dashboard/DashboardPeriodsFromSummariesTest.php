<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

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
use App\Models\VehicleUser;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The dashboard's 3-month and 6-month periods, read from the daily rollup.
 *
 * Every period figure on GET /dashboard used to be a SUM over `transactions`:
 * a six-month window is 5.8M rows on production, 5.0 s for NICCO's SACCO admin
 * and 8.6 s for a superadmin, run twice per load. HomeAPIController now reads
 * closed days from `summaries` (one row per bus per Nairobi day, 6–22 ms for
 * the same totals) and only TODAY from `transactions`, which is exact where the
 * rollup lags (cash reaches it on a five-minute recompute keyed on the UTC
 * date).
 *
 * So the fixtures below write past days as summaries ONLY — the shape that
 * proves the periods read the rollup, since a controller still summing raw
 * transactions would see none of it — and today as a fare with its summary,
 * the way C2bPaymentRecorder writes it. Today's summary must not be counted on
 * top of today's transactions; every expected total below would be off by
 * today's takings if it were.
 *
 * The clock is pinned to Thursday 15 Oct 2026, 12:00 in Nairobi:
 *   3 months = 1 Aug – 31 Oct   (Aug, Sep, Oct)
 *   6 months = 1 May – 31 Oct   (May … Oct)
 */
final class DashboardPeriodsFromSummariesTest extends QueueTestCase
{
    private Sacco $sacco;

    private Seat $seat;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 09:00:00');

        $this->sacco = $this->makeSacco();
        $this->owner = $this->makeUser([], $this->sacco);
        $this->seat = $this->makeSeat();
    }

    /*
     |---------------------------------------------------------------------
     | Fixture
     |---------------------------------------------------------------------
     */

    private function bus(?Sacco $sacco = null, ?Financier $financier = null): Vehicle
    {
        $vehicle = $this->makeVehicle($sacco ?? $this->sacco, $this->owner, $this->seat);

        if ($financier !== null) {
            $vehicle->financier = $financier->value;
            $vehicle->save();
        }

        return $vehicle;
    }

    /** A closed day's takings for a bus, as the rollup holds it. */
    private function day(Vehicle $bus, string $date, float $mpesa, float $cash = 0): void
    {
        Summary::create([
            'vehicle_id' => $bus->id,
            'trans_date' => $date,
            'mpesa_amount' => $mpesa,
            'cash_amount' => $cash,
            'mpesa_txn' => $mpesa > 0 ? 1 : 0,
            'cash_txn' => $cash > 0 ? 1 : 0,
            'expense_fee_amount' => '0',
        ]);
    }

    /**
     * An M-Pesa fare, written the way production writes it: trans_date is
     * M-Pesa's TransTime (Nairobi wall-clock), and the fare is rolled into its
     * day's summary as C2bPaymentRecorder::rollIntoSummary does.
     */
    private function fare(Vehicle $bus, string $nairobiTime, float $amount): void
    {
        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'TX'.$this->nextSequence(),
            'TransAmount' => (string) $amount,
            'TransTime' => $nairobiTime,
            'MSISDN' => '254712345678',
            'BusinessShortCode' => '7100466',
        ]);

        Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $bus->id,
            'mpesa_id' => $mpesa->id,
            'amount' => $amount,
            'trans_date' => $nairobiTime,
        ]);

        $summary = Summary::withoutGlobalScopes()->firstOrNew(
            ['vehicle_id' => $bus->id, 'trans_date' => substr($nairobiTime, 0, 10)],
            ['mpesa_amount' => 0, 'cash_amount' => 0, 'mpesa_txn' => 0, 'cash_txn' => 0],
        );
        $summary->mpesa_amount = (float) $summary->mpesa_amount + $amount;
        $summary->mpesa_txn = (int) $summary->mpesa_txn + 1;
        $summary->save();
    }

    /** One bus with money on both sides of both window edges. */
    private function busAcrossTheWindows(): Vehicle
    {
        $bus = $this->bus();

        $this->day($bus, '2026-04-30', 90000);     // before 6 months
        $this->day($bus, '2026-05-01', 1000);      // first day of 6 months
        $this->day($bus, '2026-07-31', 7000);      // last day before 3 months
        $this->day($bus, '2026-08-01', 100, 10);   // first day of 3 months
        $this->day($bus, '2026-09-15', 200, 20);
        $this->day($bus, '2026-10-14', 300);       // yesterday
        $this->fare($bus, '2026-10-15 10:00:00', 50); // today

        return $bus;
    }

    private function saccoAdmin(?Sacco $sacco = null): User
    {
        $user = $this->makeUser(['View Transactions'], $sacco ?? $this->sacco);
        $user->forceFill(['type' => UserType::Admin])->save();

        return $user->fresh();
    }

    /**
     * An Investor with open assignments on $owned.
     *
     * View Transactions is granted directly because RoleSeeder does not run
     * here; an investor refused by the permission middleware would make the
     * "sees nothing" assertion pass on a 403 without the narrowing running.
     *
     * @param  array<int, Vehicle>  $owned
     */
    private function investor(array $owned): User
    {
        $user = $this->makeUser(['View Transactions'], $this->sacco);

        Role::findOrCreate(Roles::INVESTOR, 'web');
        $user->assignRole(Roles::INVESTOR);

        foreach ($owned as $bus) {
            VehicleUser::withoutGlobalScopes()->create([
                'user_id' => $user->id,
                'vehicle_id' => $bus->id,
                'sacco_id' => $bus->sacco_id,
                'status' => true,
                'start_date' => now()->subYear(),
                'end_date' => null,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A bank's staff account: saccoless, Bank Viewer, its bank on the user. */
    private function bankViewer(Financier $financier): User
    {
        $user = $this->makeUser(['View Transactions']);

        Role::findOrCreate(Roles::BANK_VIEWER, 'web');
        $user->assignRole(Roles::BANK_VIEWER);
        $user->financier = $financier->value;
        $user->save();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        $user = $this->makeUser(['View Transactions']);
        $user->forceFill(['type' => UserType::Superadmin, 'sacco_id' => null])->save();

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function dashboard(int $period, string $query = ''): array
    {
        return $this->getJson('/api/v1/auth/dashboard?year='.$period.$query)->assertOk()->json();
    }

    /**
     * The `transactions` series as month => total (periods 2–4).
     *
     * @return array<int, float>
     */
    private function byMonth(array $body): array
    {
        $out = [];
        foreach (json_decode($body['transactions'], true) as $row) {
            $out[(int) $row['month']] = (float) $row['totals'];
        }
        ksort($out);

        return $out;
    }

    /**
     * The decoded `transactions` series with every `totals` as a float, keys
     * left in the order the controller wrote them.
     *
     * The series is json_encode()d with no flags, as the old toJson() was, so a
     * whole-number total such as 300.0 goes out as `300` and decodes back as
     * int 300. assertSame against 300.0 would then fail on int-vs-float alone.
     * The web dashboard runs every total through Number(), so only the value
     * matters. Normalising here keeps assertSame strict on everything else:
     * the key order, the day name, and day/year/month as ints.
     *
     * @return array<int, array<string, mixed>>
     */
    private function series(array $body): array
    {
        $rows = json_decode($body['transactions'], true);
        $this->assertIsArray($rows, 'transactions is a JSON array');

        foreach ($rows as $i => $row) {
            $this->assertTrue(
                is_int($row['totals']) || is_float($row['totals']),
                'totals is a JSON number, not a string',
            );
            $rows[$i]['totals'] = (float) $row['totals'];
        }

        return $rows;
    }

    /*
     |---------------------------------------------------------------------
     | The periods
     |---------------------------------------------------------------------
     */

    #[Test]
    public function three_months_is_the_rollup_from_the_first_of_august_plus_today(): void
    {
        $this->busAcrossTheWindows();
        Sanctum::actingAs($this->saccoAdmin());

        $body = $this->dashboard(2);

        // 100 + 200 + 300 from the rollup, 50 from today's fare. Not 31 July's
        // 7,000, and today's 50 once — not again from today's summary row.
        $this->assertSame(650.0, (float) $body['mpesa']);
        $this->assertSame(30.0, (float) $body['cash']);
        $this->assertSame(680.0, (float) $body['totals']);

        $this->assertSame('2026-08-01', $body['period']['from']);
        $this->assertSame('2026-10-31', $body['period']['to']);
        $this->assertSame(650.0, (float) $body['period']['mpesa']);
        $this->assertSame(30.0, (float) $body['period']['cash']);
        $this->assertSame(680.0, (float) $body['period']['total']);

        $this->assertSame([8 => 110.0, 9 => 220.0, 10 => 350.0], $this->byMonth($body));
        $this->assertSame(['Aug', 'Sep', 'Oct'], json_decode($body['xaxis'], true));

        $this->assertSame('2026-10-15', $body['today']['date']);
        $this->assertSame(50.0, (float) $body['today']['total']);
    }

    #[Test]
    public function six_months_is_the_rollup_from_the_first_of_may_plus_today(): void
    {
        $this->busAcrossTheWindows();
        Sanctum::actingAs($this->saccoAdmin());

        $body = $this->dashboard(3);

        // Everything above plus 1 May's 1,000 and 31 July's 7,000. Not 30
        // April's 90,000.
        $this->assertSame(8650.0, (float) $body['mpesa']);
        $this->assertSame(30.0, (float) $body['cash']);
        $this->assertSame(8680.0, (float) $body['totals']);

        $this->assertSame('2026-05-01', $body['period']['from']);
        $this->assertSame('2026-10-31', $body['period']['to']);
        $this->assertSame(8680.0, (float) $body['period']['total']);

        $this->assertSame(
            [5 => 1000.0, 7 => 7000.0, 8 => 110.0, 9 => 220.0, 10 => 350.0],
            $this->byMonth($body),
        );
        $this->assertSame(['May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct'], json_decode($body['xaxis'], true));
    }

    #[Test]
    public function year_to_date_reaches_back_to_january(): void
    {
        $this->busAcrossTheWindows();
        $this->day($this->bus(), '2025-12-31', 123456); // last year: never

        Sanctum::actingAs($this->saccoAdmin());

        $body = $this->dashboard(4);

        $this->assertSame('2026-01-01', $body['period']['from']);
        $this->assertSame(98680.0, (float) $body['period']['total']);
        $this->assertCount(12, json_decode($body['xaxis'], true));
    }

    /*
     |---------------------------------------------------------------------
     | Who sees what. Summary carries the same tenancy as Transaction, and the
     | investor narrowing is applied by hand — both must hold on the rollup.
     |---------------------------------------------------------------------
     */

    #[Test]
    public function an_investor_sees_only_the_buses_they_own(): void
    {
        $mine = $this->bus();
        $theirs = $this->bus();

        $this->day($mine, '2026-09-15', 400);
        $this->day($theirs, '2026-09-15', 600);
        $this->fare($mine, '2026-10-15 10:00:00', 40);
        $this->fare($theirs, '2026-10-15 10:05:00', 60);

        Sanctum::actingAs($this->investor([$mine]));

        $body = $this->dashboard(2);

        $this->assertSame(440.0, (float) $body['totals']);
        $this->assertSame(440.0, (float) $body['period']['total']);
        $this->assertSame(40.0, (float) $body['today']['total']);
        $this->assertSame([9 => 400.0, 10 => 40.0], $this->byMonth($body));
    }

    #[Test]
    public function an_investor_who_owns_nothing_sees_nothing(): void
    {
        // ownedVehicleIds() === [] must narrow to NOTHING. A count() guard
        // around it would hand this investor the whole SACCO.
        $bus = $this->bus();
        $this->day($bus, '2026-09-15', 600);
        $this->fare($bus, '2026-10-15 10:00:00', 60);

        Sanctum::actingAs($this->investor([]));

        foreach ([2, 3] as $period) {
            $body = $this->dashboard($period);

            $this->assertSame(0.0, (float) $body['totals'], "period {$period}");
            $this->assertSame(0.0, (float) $body['mpesa'], "period {$period}");
            $this->assertSame(0.0, (float) $body['cash'], "period {$period}");
            $this->assertSame(0.0, (float) $body['period']['total'], "period {$period}");
            $this->assertSame(0.0, (float) $body['today']['total'], "period {$period}");
            $this->assertSame([], json_decode($body['transactions'], true), "period {$period}");
        }
    }

    #[Test]
    public function another_saccos_rollup_is_not_counted(): void
    {
        $this->busAcrossTheWindows();

        $elsewhere = $this->bus($this->makeSacco());
        $this->day($elsewhere, '2026-09-15', 55555);
        $this->fare($elsewhere, '2026-10-15 11:00:00', 777);

        Sanctum::actingAs($this->saccoAdmin());

        $body = $this->dashboard(3);

        $this->assertSame(8680.0, (float) $body['period']['total']);
        $this->assertSame(50.0, (float) $body['today']['total']);
        $this->assertSame(220.0, $this->byMonth($body)[9]);
    }

    #[Test]
    public function the_sacco_filter_narrows_a_superadmin_on_the_rollup_too(): void
    {
        // `?sacco=` is a filter, not a boundary — a superadmin sees every SACCO
        // without it. It used to be a whereHas on Transaction and is now one on
        // Summary; it must still narrow.
        $this->busAcrossTheWindows();

        $other = $this->makeSacco();
        $elsewhere = $this->bus($other);
        $this->day($elsewhere, '2026-09-15', 55555);
        $this->fare($elsewhere, '2026-10-15 11:00:00', 777);

        Sanctum::actingAs($this->superAdmin());

        $all = $this->dashboard(3);
        $this->assertSame(8680.0 + 55555.0 + 777.0, (float) $all['period']['total']);

        $one = $this->dashboard(3, '&sacco='.$other->id);
        $this->assertSame(55555.0 + 777.0, (float) $one['period']['total']);
        $this->assertSame(777.0, (float) $one['today']['total']);
    }

    #[Test]
    public function a_bank_viewer_sees_only_the_buses_its_bank_financed(): void
    {
        // Both banks' buses in ONE SACCO and one brand — the NICCO shape — so
        // the financier is the only thing that can separate them.
        $ncba = $this->bus(null, Financier::Ncba);
        $coop = $this->bus(null, Financier::Coop);

        $this->day($ncba, '2026-09-15', 1000);
        $this->day($coop, '2026-09-15', 2000);
        $this->fare($ncba, '2026-10-15 10:00:00', 10);
        $this->fare($coop, '2026-10-15 10:05:00', 20);

        Sanctum::actingAs($this->bankViewer(Financier::Ncba));

        $body = $this->dashboard(3);

        $this->assertSame(1010.0, (float) $body['period']['total']);
        $this->assertSame(10.0, (float) $body['today']['total']);
        $this->assertSame([9 => 1000.0, 10 => 10.0], $this->byMonth($body));
    }

    /*
     |---------------------------------------------------------------------
     | The Nairobi day
     |---------------------------------------------------------------------
     */

    #[Test]
    public function at_one_in_the_morning_nairobi_today_and_this_month_are_nairobis(): void
    {
        // 22:00 UTC on 30 Sep is 01:00 EAT on 1 Oct. The old code took
        // Carbon::today() — the UTC date — so for three hours every night the
        // today tile showed YESTERDAY's takings, and on the 1st "this month"
        // was still last month.
        Carbon::setTestNow('2026-09-30 22:00:00');

        $bus = $this->bus();
        $this->fare($bus, '2026-10-01 00:30:00', 300); // after midnight in Nairobi
        $this->fare($bus, '2026-09-30 23:00:00', 999); // the evening before

        Sanctum::actingAs($this->saccoAdmin());

        $month = $this->dashboard(1);

        $this->assertSame('2026-10-01', $month['today']['date']);
        $this->assertSame(300.0, (float) $month['today']['total']);

        $this->assertSame('2026-10-01', $month['period']['from']);
        $this->assertSame('2026-10-31', $month['period']['to']);
        $this->assertSame(300.0, (float) $month['period']['total']);
        $this->assertSame(
            [['totals' => 300.0, 'day' => 1]],
            $this->series($month),
        );

        // The evening before is a closed day in the rollup, and still counts in
        // a window that reaches it.
        $six = $this->dashboard(3);
        $this->assertSame(1299.0, (float) $six['period']['total']);
        $this->assertSame([9 => 999.0, 10 => 300.0], $this->byMonth($six));
    }

    /*
     |---------------------------------------------------------------------
     | The contract the web dashboard renders against
     |---------------------------------------------------------------------
     */

    #[Test]
    public function the_payload_keeps_its_shape_for_every_period(): void
    {
        $bus = $this->bus();
        $this->day($bus, '2026-10-13', 70);              // Tuesday, this week
        $this->fare($bus, '2026-10-15 10:00:00', 30);    // Thursday, today

        Sanctum::actingAs($this->saccoAdmin());

        foreach ([0, 1, 2, 3, 4] as $period) {
            $body = $this->dashboard($period);

            $this->assertSame(
                ['mpesa', 'cash', 'totals', 'transactions', 'xaxis', 'today', 'period'],
                array_keys($body),
                "period {$period}",
            );
            $this->assertIsString($body['transactions'], 'transactions is a JSON STRING, not an array');
            $this->assertIsString($body['xaxis'], 'xaxis is a JSON STRING, not an array');
            $this->assertSame(['date', 'mpesa', 'cash', 'total'], array_keys($body['today']));
            $this->assertSame(['from', 'to', 'mpesa', 'cash', 'total'], array_keys($body['period']));

            $expectedRowKeys = $period <= 1 ? ['totals', 'day'] : ['totals', 'year', 'month'];
            foreach (json_decode($body['transactions'], true) as $row) {
                $this->assertSame($expectedRowKeys, array_keys($row), "period {$period}");
            }
        }

        // Week: keyed by day NAME — the web dashboard lower-cases and matches
        // it against the axis labels — in the old alphabetical order.
        $week = $this->dashboard(0);
        $this->assertSame(
            [['totals' => 30.0, 'day' => 'Thursday'], ['totals' => 70.0, 'day' => 'Tuesday']],
            $this->series($week),
        );
        $this->assertSame('2026-10-12', $week['period']['from'], 'the week runs Monday to Sunday');
        $this->assertSame('2026-10-18', $week['period']['to']);

        // Month: keyed by day of month, and the axis now reaches the 31st.
        $month = $this->dashboard(1);
        $this->assertSame(
            [['totals' => 70.0, 'day' => 13], ['totals' => 30.0, 'day' => 15]],
            $this->series($month),
        );
        $axis = json_decode($month['xaxis'], true);
        $this->assertCount(31, $axis);
        $this->assertSame('01', $axis[0]);
        $this->assertSame('31', $axis[30]);

        // Longer periods: year and month as numbers.
        $this->assertSame(
            [['totals' => 100.0, 'year' => 2026, 'month' => 10]],
            $this->series($this->dashboard(2)),
        );
    }
}
