<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\LoyaltyTransactionType;
use App\Enums\UserType;
use App\Models\ExpenseFee;
use App\Models\LoyaltyTransaction;
use App\Models\Mpesa;
use App\Models\Place;
use App\Models\QrcodePayment;
use App\Models\Route;
use App\Models\Sacco;
use App\Models\Terminus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Database\Seeders\ExpenseFeeSeeder;
use Database\Seeders\TerminusSeeder;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The driver app's own screens.
 *
 * The thing worth pinning down is WHOSE money a driver sees. Every endpoint
 * keys on the vehicle from the caller's current assignment, never on the driver
 * — crews rotate between matatus, and money is paid to the vehicle's till, so a
 * driver who changed buses this morning must see this bus and not yesterday's.
 */
final class DriverPortalTest extends QueueTestCase
{
    /** @return array{0:User, 1:Vehicle} */
    private function crewedDriver(?Sacco $sacco = null): array
    {
        $sacco ??= $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $vehicle = $this->makeVehicle($sacco, $owner, $this->makeSeat());

        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return [$driver, $vehicle];
    }

    #[Test]
    public function capacity_serves_a_seat_map_when_the_vehicle_has_a_layout(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $seat = $this->makeSeat(4);
        $this->makeSeatArrangements($seat, 4);
        $vehicle = $this->makeVehicle($sacco, $owner, $seat);

        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id, 'status' => true, 'start_date' => now(),
        ]);
        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/home')
            ->assertOk()
            ->assertJsonPath('capacity.seats', 4)
            ->assertJsonPath('capacity.seats_configured', true)
            ->assertJsonCount(4, 'capacity.seat_map')
            ->assertJsonStructure([
                'capacity' => ['seats', 'occupied', 'available', 'seats_configured', 'seat_map' => [['id', 'name', 'occupied']]],
            ]);
    }

    #[Test]
    public function capacity_falls_back_to_the_default_for_a_vehicle_with_no_seat_layout(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        // A street-onboarded matatu: no seat row yet.
        $vehicle->forceFill(['seat_id' => null])->save();
        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/home')
            ->assertOk()
            ->assertJsonPath('capacity.seats', 14)   // config('booking.default_seats')
            ->assertJsonPath('capacity.seats_configured', false)
            ->assertJsonCount(0, 'capacity.seat_map');
    }

    private function payment(Vehicle $vehicle, float $amount, ?string $at = null): Transaction
    {
        // trans_date STORES Nairobi wall-clock (it is M-Pesa's TransTime) and
        // the takings window is bound the same way. Writing bare now() -- UTC --
        // put the row three hours early, which between 00:00 and 03:00 UTC is
        // before the 03:00 EAT business-day boundary: "today" summed to 0 and
        // this suite failed only when CI happened to run in that window.
        return Transaction::create([
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'trans_date' => $at ? Carbon::parse($at) : BusinessDay::forLocalColumn(now()),
            'mpesa_id' => 0,
            'cash_id' => 0,
        ]);
    }

    #[Test]
    public function home_reports_todays_running_total_for_the_assigned_vehicle(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $this->payment($vehicle, 200);
        $this->payment($vehicle, 350);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/home')
            ->assertOk()
            ->assertJsonPath('vehicle.plate', $vehicle->plate)
            ->assertJsonPath('today.earnings', 550)
            ->assertJsonPath('today.payments', 2)
            ->assertJsonStructure([
                'vehicle' => ['id', 'plate'],
                'today' => ['earnings', 'mpesa', 'cash', 'payments', 'trips', 'expenses', 'net', 'first_at', 'last_at'],
                'capacity' => ['seats', 'occupied', 'available'],
                'recent_transactions',
            ]);
    }

    #[Test]
    public function a_driver_never_sees_another_vehicles_money(): void
    {
        // The defect this guards: the SACCO-wide `transactions` endpoint showed
        // a driver every other bus in the SACCO, including its daily totals.
        $sacco = $this->makeSacco();
        [$driver, $mine] = $this->crewedDriver($sacco);
        [, $theirs] = $this->crewedDriver($sacco);

        $this->payment($mine, 100);
        $this->payment($theirs, 999);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/home')
            ->assertOk()
            ->assertJsonPath('today.earnings', 100);

        $body = $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->json();
        $this->assertSame(1, $body['total'], 'Only the assigned vehicle\'s payments may appear.');
        $this->assertSame(100.0, (float) $body['data'][0]['amount']);
    }

    #[Test]
    public function net_is_takings_minus_expenses(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $this->payment($vehicle, 1000);

        $fuel = ExpenseFee::create(['name' => 'Fuel', 'status' => true]);
        VehicleExpenseAndFee::create([
            'vehicle_id' => $vehicle->id,
            'expense_fee_id' => $fuel->id,
            'amount' => 400,
            'trans_date' => BusinessDay::forLocalColumn(now()),
            'status' => true,
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/home')
            ->assertOk()
            ->assertJsonPath('today.earnings', 1000)
            ->assertJsonPath('today.expenses', 400)
            // What the driver actually goes home with.
            ->assertJsonPath('today.net', 600);
    }

    #[Test]
    public function a_driver_records_an_expense_against_their_own_vehicle_only(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $fuel = ExpenseFee::create(['name' => 'Fuel', 'status' => true]);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/auth/driver/expenses', [
            'expense_fee_id' => $fuel->id,
            'amount' => 250,
        ])->assertCreated();

        $this->assertDatabaseHas('vehicle_expense_and_fees', [
            'vehicle_id' => $vehicle->id,
            'expense_fee_id' => $fuel->id,
            'amount' => 250,
        ]);

        // Recording an expense must move `net` immediately, not after the cache
        // expires — the driver is looking at the screen when they enter it.
        $this->getJson('/api/v1/auth/driver/home')->assertOk()->assertJsonPath('today.net', -250);
    }

    #[Test]
    public function an_expense_at_five_in_the_morning_is_todays(): void
    {
        // 05:00 EAT is 02:00 UTC. The column stores Nairobi wall-clock and the
        // business day starts at 03:00 EAT, so an expense stamped with bare UTC
        // now() read "02:00" -- an hour BEFORE the boundary -- and filed under
        // yesterday. Fuelling up is exactly when this hour happens, and CI
        // caught it only because a run landed between 00:00 and 03:00 UTC.
        Carbon::setTestNow(Carbon::today('UTC')->setTime(2, 0));

        [$driver, $vehicle] = $this->crewedDriver();
        $fuel = ExpenseFee::create(['name' => 'Fuel', 'status' => true]);

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/auth/driver/expenses', ['expense_fee_id' => $fuel->id, 'amount' => 250])
            ->assertCreated();

        $this->getJson('/api/v1/auth/driver/home')->assertOk()
            ->assertJsonPath('today.expenses', 250)
            ->assertJsonPath('today.net', -250);
    }

    #[Test]
    public function transactions_are_paginated_at_twenty(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        for ($i = 0; $i < 25; $i++) {
            $this->payment($vehicle, 10);
        }

        Sanctum::actingAs($driver);

        $first = $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->json();
        $this->assertCount(20, $first['data']);
        $this->assertSame(25, $first['total']);
        $this->assertSame(2, $first['last_page']);

        $second = $this->getJson('/api/v1/auth/driver/transactions?page=2')->assertOk()->json();
        $this->assertCount(5, $second['data']);
    }

    private function mpesaPayment(Vehicle $vehicle, float $amount, string $receipt, string $payer, ?string $at = null): Transaction
    {
        $when = $at ? Carbon::parse($at) : BusinessDay::forLocalColumn(now());
        $mpesa = Mpesa::create([
            'TransID' => $receipt, 'MSISDN' => '254700111222', 'TransAmount' => $amount,
            'TransTime' => $when, 'FirstName' => $payer, 'LastName' => 'Test', 'BusinessShortCode' => '5557936',
        ]);

        return Transaction::create([
            'vehicle_id' => $vehicle->id, 'amount' => $amount, 'trans_date' => $when,
            'mpesa_id' => $mpesa->id, 'cash_id' => 0,
        ]);
    }

    #[Test]
    public function search_finds_a_payment_the_app_has_not_loaded_yet(): void
    {
        // The Earnings screen filtered only the pages it had fetched, so last
        // week's payment could not be found without scrolling back to it.
        [$driver, $vehicle] = $this->crewedDriver();
        $this->mpesaPayment($vehicle, 120, 'UJ6OLDONE1', 'Wanjiku', now()->subDays(8)->toDateTimeString());
        for ($i = 0; $i < 30; $i++) {
            $this->mpesaPayment($vehicle, 50, 'UJ7NEW'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'Otieno');
        }

        Sanctum::actingAs($driver);

        // Receipt, any case, any part of it.
        $this->getJson('/api/v1/auth/driver/transactions?search=uj6old')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.reference', 'UJ6OLDONE1')
            ->assertJsonPath('search', 'uj6old');

        // Payer's first name.
        $this->getJson('/api/v1/auth/driver/transactions?search=wanj')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.payer', 'Wanjiku');

        // The matches page like the whole list.
        $body = $this->getJson('/api/v1/auth/driver/transactions?search=otieno')->assertOk()->json();
        $this->assertSame(30, $body['total']);
        $this->assertSame(2, $body['last_page']);
        $this->assertCount(20, $body['data']);
        $this->assertCount(10, $this->getJson('/api/v1/auth/driver/transactions?search=otieno&page=2')->json('data'));

        // No search is the whole list, as before.
        $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->assertJsonPath('total', 31)->assertJsonPath('search', null);
    }

    #[Test]
    public function a_number_finds_payments_of_that_amount_cash_included(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $this->mpesaPayment($vehicle, 150, 'UJ6AAA0001', 'Kamau');
        $this->payment($vehicle, 150);               // cash
        $this->mpesaPayment($vehicle, 70, 'UJ6AAA0002', 'Akinyi');

        Sanctum::actingAs($driver);

        $rows = $this->getJson('/api/v1/auth/driver/transactions?search=150')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['mpesa', 'cash'], array_column($rows, 'method'));
    }

    #[Test]
    public function search_is_literal_and_never_reaches_another_bus(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        [, $otherBus] = $this->crewedDriver();
        $this->mpesaPayment($vehicle, 100, 'UJ6MINE001', 'Njeri');
        $this->mpesaPayment($otherBus, 100, 'UJ6THEIRS1', 'Njeri');

        Sanctum::actingAs($driver);

        // "%" and "_" are characters, not wildcards.
        $this->getJson('/api/v1/auth/driver/transactions?search=%25')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/auth/driver/transactions?search=_')->assertOk()->assertJsonPath('total', 0);

        // Same name on another bus: only this bus's payment comes back.
        $this->getJson('/api/v1/auth/driver/transactions?search=njeri')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.reference', 'UJ6MINE001');
    }

    #[Test]
    public function every_word_must_match_and_dates_match_the_nairobi_day(): void
    {
        // The driver app's contract: words AND together; a whole number is an
        // exact amount; a date in any of three spellings is a Nairobi day.
        [$driver, $vehicle] = $this->crewedDriver();
        $day = Carbon::now('Africa/Nairobi')->subDays(3)->startOfDay();
        $at = fn (Carbon $d, int $h, int $m = 0) => $d->copy()->setTime($h, $m)->toDateTimeString(); // Nairobi wall-clock

        $this->mpesaPayment($vehicle, 30, 'UJ6DAY0001', 'Wanjiku', $at($day, 23, 30)); // late, still that day
        $this->mpesaPayment($vehicle, 130, 'UJ6DAY0002', 'Wanjiku', $at($day, 9));
        $this->mpesaPayment($vehicle, 30, 'UJ6DAY0003', 'Wanjiku', $at($day->copy()->subDays(10), 9));
        $this->mpesaPayment($vehicle, 30, 'UJ6DAY0004', 'Otieno', $at($day, 12));
        $this->mpesaPayment($vehicle, 30, 'UJ6DAY0005', 'Otieno', $at($day->copy()->addDay(), 0, 30)); // just after midnight

        Sanctum::actingAs($driver);
        $refs = fn (string $q) => collect($this->getJson('/api/v1/auth/driver/transactions?search='.urlencode($q))->assertOk()->json('data'))
            ->pluck('reference')->sort()->values()->all();

        $this->assertSame(['UJ6DAY0001', 'UJ6DAY0003'], $refs('wanjiku 30'), 'both words, and 30 is not 130');
        $this->assertSame(['UJ6DAY0001'], $refs('Wanjiku 30 '.$day->format('Y-m-d')));
        $this->assertSame(['UJ6DAY0001', 'UJ6DAY0002', 'UJ6DAY0004'], $refs($day->format('d/m/Y')));
        $this->assertSame(['UJ6DAY0001', 'UJ6DAY0002', 'UJ6DAY0004'], $refs($day->format('j/n')));
        $this->assertSame(['UJ6DAY0001', 'UJ6DAY0003', 'UJ6DAY0004', 'UJ6DAY0005'], $refs('30'));
        $this->assertSame([], $refs('wanjiku 999'));

        // A month and a day are one date, either order, any case, short or full.
        $this->assertSame(['UJ6DAY0001', 'UJ6DAY0002', 'UJ6DAY0004'], $refs(strtolower($day->format('M j'))));
        $this->assertSame(['UJ6DAY0001', 'UJ6DAY0002', 'UJ6DAY0004'], $refs($day->format('j F')));
        $this->assertSame(['UJ6DAY0001', 'UJ6DAY0002'], $refs('wanjiku '.$day->format('M j')));

        // A month alone is the whole month.
        $dates = [
            'UJ6DAY0001' => $day, 'UJ6DAY0002' => $day, 'UJ6DAY0003' => $day->copy()->subDays(10),
            'UJ6DAY0004' => $day, 'UJ6DAY0005' => $day->copy()->addDay(),
        ];
        $inMonth = collect($dates)->filter(fn (Carbon $d) => $d->format('Y-m') === $day->format('Y-m'))->keys()->sort()->values()->all();
        $this->assertSame($inMonth, $refs(strtolower($day->format('M'))));

        // Whitespace only is no search at all.
        $this->getJson('/api/v1/auth/driver/transactions?search=%20%20')
            ->assertOk()->assertJsonPath('total', 5)->assertJsonPath('search', null);
    }

    #[Test]
    public function a_points_fare_is_found_by_its_reference_or_the_passengers_name(): void
    {
        [$driver, $vehicle] = $this->crewedDriver();
        $passenger = $this->makeUser();
        $passenger->forceFill(['firstname' => 'Marylyne'])->save();
        $fare = QrcodePayment::create(['vehicle_id' => $vehicle->id, 'user_id' => $passenger->id, 'amount' => 0, 'fare' => 60, 'status' => true]);
        LoyaltyTransaction::create([
            'user_id' => $passenger->id, 'sacco_id' => $vehicle->sacco_id, 'value' => -2,
            'type' => LoyaltyTransactionType::Redeemed, 'source_type' => 'qrcode_payment', 'source_id' => $fare->id,
        ]);
        $this->mpesaPayment($vehicle, 60, 'UJ6MPESA01', 'Kiprop');
        $this->payment($vehicle, 60); // cash

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/transactions?search=QR-PTS-'.$fare->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.method', 'points');
        $this->getJson('/api/v1/auth/driver/transactions?search=maryl')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', 'qr-pts-'.$fare->id);
        // A number matches a points fare of that size too.
        $this->getJson('/api/v1/auth/driver/transactions?search=60')->assertOk()->assertJsonPath('total', 3);

        // How it was paid.
        $method = fn (string $q) => array_column($this->getJson('/api/v1/auth/driver/transactions?search='.$q)->assertOk()->json('data'), 'method');
        $this->assertSame(['points'], $method('points'));
        $this->assertSame(['mpesa'], $method('m-pesa'));
        $this->assertSame(['mpesa'], $method('MPESA'));
        $this->assertSame(['cash'], $method('cash'));
        $this->assertSame(['mpesa'], $method('mpesa%20kiprop'));
        $this->assertSame([], $method('cash%20kiprop'));
    }

    #[Test]
    public function a_driver_with_no_assignment_is_refused(): void
    {
        // The state a conductor is in before they check in for a shift. It must
        // be a clear 403, not an empty dashboard that looks like a zero day.
        $driver = $this->makeUser();
        $driver->forceFill(['type' => UserType::Driver])->save();

        Sanctum::actingAs($driver);

        foreach (['home', 'earnings', 'transactions', 'bookings', 'expenses'] as $screen) {
            $this->getJson('/api/v1/auth/driver/'.$screen)
                ->assertStatus(403)
                ->assertJsonStructure(['error']);
        }
    }

    #[Test]
    public function the_seeders_fill_what_the_write_paths_require(): void
    {
        // Every driver write path returned 400 on production because these two
        // tables were empty: no terminus means no queue, hence no trip and no
        // location broadcast; no expense type means the expense form rejects
        // every submission.
        // Termini are derived from route origins, so a route must exist first.
        $from = Place::create(['name' => 'Origin Stage', 'status' => true]);
        $to = Place::create(['name' => 'Destination', 'status' => true]);
        Route::create(['name' => 'Origin - Destination', 'from_id' => $from->id, 'to_id' => $to->id, 'status' => true]);

        $this->seed(TerminusSeeder::class);
        $this->seed(ExpenseFeeSeeder::class);

        $this->assertGreaterThan(0, Terminus::count(), 'queues/join needs a terminus to exist.');

        // Existing is not enough: queues/join rejects a terminus that is not the
        // START of the route being joined ("Terminus is not the start of this
        // route"), so a terminus at a place no route departs from is unusable.
        // Every seeded terminus must be a real route origin.
        $orphan = Terminus::whereNotIn('place_id', Route::whereNotNull('from_id')->pluck('from_id'))->count();
        $this->assertSame(0, $orphan, 'Every terminus must be the origin of at least one route.');
        $this->assertGreaterThan(0, ExpenseFee::count(), 'driver/expenses needs an expense type to exist.');

        // Idempotent: a redeploy re-runs seeders and must not duplicate.
        $termini = Terminus::count();
        $fees = ExpenseFee::count();
        $this->seed(TerminusSeeder::class);
        $this->seed(ExpenseFeeSeeder::class);

        $this->assertSame($termini, Terminus::count());
        $this->assertSame($fees, ExpenseFee::count());
    }

    #[Test]
    public function the_expense_types_endpoint_feeds_the_apps_picker(): void
    {
        [$driver] = $this->crewedDriver();
        $this->seed(ExpenseFeeSeeder::class);

        Sanctum::actingAs($driver);

        $body = $this->getJson('/api/v1/auth/driver/expenses')->assertOk()->json();
        $this->assertNotEmpty($body['types'], 'The app cannot render an expense form without types.');
        $this->assertSame(0, $body['total']);
    }

    #[Test]
    public function the_picker_shows_shared_types_and_mine_but_not_another_saccos(): void
    {
        // ExpenseFee is SaccoScoped and the shared types carry sacco_id NULL,
        // so the scope filtered every one of them out and the picker came back
        // empty for everybody. Fixed by dropping scopes and re-stating the
        // boundary — which must still exclude another SACCO's categories.
        [$driver, $vehicle] = $this->crewedDriver();
        $other = $this->makeSacco();

        ExpenseFee::create(['name' => 'Shared Fuel', 'sacco_id' => null, 'status' => true]);
        ExpenseFee::create(['name' => 'My Levy', 'sacco_id' => $vehicle->sacco_id, 'status' => true]);
        ExpenseFee::create(['name' => 'Their Secret Levy', 'sacco_id' => $other->id, 'status' => true]);

        Sanctum::actingAs($driver);

        $names = collect($this->getJson('/api/v1/auth/driver/expenses')->assertOk()->json('types'))
            ->pluck('name')->all();

        $this->assertContains('Shared Fuel', $names);
        $this->assertContains('My Levy', $names);
        $this->assertNotContains('Their Secret Levy', $names);
    }

    #[Test]
    public function every_transaction_carries_the_time_it_happened(): void
    {
        // THE BUG. `transactions.trans_date` is not cast on the model, so
        // Eloquent hands back a plain string -- and `optional($string)->
        // toIso8601String()` returns null the moment that method does not exist
        // on a string. Every payment in the driver app therefore arrived with
        // `"at": null` while the timestamp sat in the row all along.
        //
        // A payment with no time on it cannot be placed in a shift, so the
        // screen reads as empty even though the money is right there.
        [$driver, $vehicle] = $this->crewedDriver();
        $this->payment($vehicle, 50, '2026-08-31 09:15:00');

        Sanctum::actingAs($driver);

        $row = $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->json('data.0');

        $this->assertNotNull($row['at'], 'a payment with no timestamp cannot be placed in a shift');
        $this->assertSame('2026-08-31', substr((string) $row['at'], 0, 10));
    }

    #[Test]
    public function an_old_payment_still_shows_on_the_transactions_screen(): void
    {
        // The list is deliberately NOT filtered by date -- it is "recent" only
        // in the sense of newest-first. A bus that last collected weeks ago must
        // still show what it collected, or a quiet vehicle looks like a broken
        // one.
        [$driver, $vehicle] = $this->crewedDriver();
        $this->payment($vehicle, 10, '2026-07-13 07:30:00');

        Sanctum::actingAs($driver);

        $body = $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->json();

        $this->assertSame(1, $body['total']);
        $this->assertNotNull($body['data'][0]['at']);
    }
}
