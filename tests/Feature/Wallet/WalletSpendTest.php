<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\PaymentMethod;
use App\Enums\WalletEntryKind;
use App\Enums\WalletStatus;
use App\Events\FarePaidFromWallet;
use App\Events\PassengerBalanceChanged;
use App\Models\Booking;
use App\Models\QrcodePayment;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Loyalty\BookingSettlement;
use App\Services\Payments\QrTokenService;
use App\Services\Wallet\WalletPaymentService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Paying a fare out of the wallet.
 *
 * Mostly about the guards, because every one of them mirrors a guard
 * LoyaltyService grew after something went wrong on a real bus: a reservation
 * that expired while the sheet was open, a trip the crew ended mid-tap, a
 * retried request answered as a failure, a receipt left behind by a refused
 * debit.
 *
 * The other half is the reporting, which is where a money rail quietly lies if
 * nobody checks: a wallet fare must reach the bus's takings, and must NOT be
 * counted as cash the SACCO can bank, because it is still in the Komiut float.
 */
final class WalletSpendTest extends QueueTestCase
{
    private const PAY = '/api/v1/auth/wallet/payments';

    /** @return array{0: User, 1: array<string, mixed>} */
    private function fundedPassenger(int $shillings = 1000): array
    {
        $world = $this->makeWorld();
        $user = $this->makeUser();

        app(WalletService::class)->credit(
            (int) $user->id,
            $shillings * 100,
            WalletEntryKind::TopUp,
            'seed:'.$user->id,
        );

        // THE TAP LIMIT IS RAISED ON PURPOSE. It defaults to KES 100, so a
        // 150-shilling fare would legitimately demand a PIN -- correct
        // behaviour, and exactly what the PIN tests below assert. Lifting it
        // here keeps every other test about the thing it is named for instead
        // of all of them being PIN tests by accident.
        Wallet::where('user_id', $user->id)->update(['tap_limit_cents' => 30000]);

        Sanctum::actingAs($user);

        return [$user, $world];
    }

    private function bookingOnALiveRun(array $world, User $user, float $fare = 150): Booking
    {
        $active = $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner']);
        $booking = $this->makeBooking($queue, $user, $world['from'], $world['to'], 'Marylyne');

        $booking->forceFill(['amount' => $fare, 'user_id' => $user->id])->save();

        return $booking->refresh();
    }

    private function balance(User $user): int
    {
        return app(WalletService::class)->balanceCents((int) $user->id);
    }

    // ---------------------------------------------------------------- bookings

    #[Test]
    public function paying_for_a_booking_settles_it_and_debits_the_wallet(): void
    {
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $body = $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertOk()->json('payment');

        $this->assertSame(150, $body['amount']);
        $this->assertSame(850, $body['balance_after']);
        $this->assertFalse($body['replayed']);
        $this->assertSame(85000, $this->balance($user));

        $booking->refresh();
        $this->assertTrue((bool) $booking->paid);
        $this->assertSame(PaymentMethod::Wallet, $booking->payment_method);
    }

    #[Test]
    public function another_passengers_booking_cannot_be_paid(): void
    {
        [$user, $world] = $this->fundedPassenger();
        $stranger = $this->makeUser();
        $booking = $this->bookingOnALiveRun($world, $stranger, 150);

        $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertStatus(403);

        $this->assertSame(100000, $this->balance($user), 'nothing moved');
        $this->assertFalse((bool) $booking->refresh()->paid);
    }

    #[Test]
    public function a_retried_payment_is_a_success_not_a_conflict(): void
    {
        // THE ONE THAT MATTERS MOST ON A MOVING MATATU. The request that
        // settled the ride is exactly the one whose response is most likely to
        // be lost, and answering the retry with "already paid" tells the
        // passenger their paid ride failed.
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $first = $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertOk()->json('payment');
        $again = $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertOk()->json('payment');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($again['replayed'], 'the retry says so, so a client can tell');
        $this->assertSame($first['reference'], $again['reference']);

        // Charged ONCE.
        $this->assertSame(85000, $this->balance($user));
        $this->assertSame(1, WalletTransaction::where('kind', WalletEntryKind::Fare->value)->count());
    }

    #[Test]
    public function a_booking_already_paid_by_another_rail_is_a_genuine_conflict(): void
    {
        // Paid, but with no wallet fare behind it -- M-Pesa, cash or a QR scan
        // got there first. The passenger keeps their money.
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);
        $booking->forceFill(['paid' => true])->save();

        $this->postJson(self::PAY, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This booking is already paid.');

        $this->assertSame(100000, $this->balance($user));
    }

    #[Test]
    public function an_expired_reservation_cannot_be_paid_for(): void
    {
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        // What the cancellation sweep does to a hold nobody paid for.
        $booking->forceFill(['status' => false])->save();

        $this->postJson(self::PAY, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This reservation has expired. Please book again.');

        $this->assertSame(100000, $this->balance($user));
        $this->assertSame(0, Transaction::withoutGlobalScopes()->whereNotNull('wallet_transaction_id')->count());
    }

    #[Test]
    public function a_trip_that_has_ended_cannot_be_paid_for(): void
    {
        // Money spent on a finished trip buys a ride nobody will take, and the
        // only settlement path for a paid unboarded booking is the crew's --
        // and the crew has gone home.
        [$user, $world] = $this->fundedPassenger();

        $done = $this->makeQueueStatus('Completed '.$this->nextSequence(), 'Completed');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $done, $world['owner']);
        $booking = $this->makeBooking($queue, $user, $world['from'], $world['to'], 'Marylyne');
        $booking->forceFill(['amount' => 150, 'user_id' => $user->id])->save();

        $this->postJson(self::PAY, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This trip has ended. Please book another.');

        $this->assertSame(100000, $this->balance($user));
    }

    #[Test]
    public function a_fare_beyond_the_balance_is_refused_with_the_balance(): void
    {
        [$user, $world] = $this->fundedPassenger(100);
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $this->postJson(self::PAY, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_balance')
            ->assertJsonPath('balance', 100);

        $this->assertFalse((bool) $booking->refresh()->paid, 'an unpaid booking stays unpaid');
    }

    #[Test]
    public function a_frozen_wallet_cannot_pay(): void
    {
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        Wallet::where('user_id', $user->id)->update(['status' => WalletStatus::Frozen->value]);

        $this->postJson(self::PAY, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'wallet_frozen');

        $this->assertSame(100000, $this->balance($user));
    }

    // -------------------------------------------------------------------- PIN

    #[Test]
    public function a_fare_above_the_tap_limit_needs_the_pin(): void
    {
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        app(WalletService::class)->setPin((int) $user->id, '2846', null);
        Wallet::where('user_id', $user->id)->update(['tap_limit_cents' => 10000]); // KES 100

        $this->postJson(self::PAY, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'pin_required');

        $this->postJson(self::PAY, ['booking_id' => $booking->id, 'pin' => '0000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'wrong_pin');

        $this->assertSame(100000, $this->balance($user), 'nothing moved on a wrong PIN');

        $this->postJson(self::PAY, ['booking_id' => $booking->id, 'pin' => '2846'])->assertOk();
        $this->assertSame(85000, $this->balance($user));
    }

    #[Test]
    public function a_fare_at_or_below_the_tap_limit_needs_no_pin(): void
    {
        // STRICTLY GREATER THAN, matching the app's needsPin(amount) exactly.
        // An off-by-one here shows a PIN sheet the backend then rejects as
        // unnecessary, or takes a fare the app promised would need one.
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 100);

        app(WalletService::class)->setPin((int) $user->id, '2846', null);
        Wallet::where('user_id', $user->id)->update(['tap_limit_cents' => 10000]);

        $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertOk();
        $this->assertSame(90000, $this->balance($user));
    }

    #[Test]
    public function a_wallet_with_no_pin_cannot_pay_above_its_limit(): void
    {
        // It cannot be asked for a PIN it does not have, so it is held to the
        // limit rather than prompted for nothing.
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 500);

        Wallet::where('user_id', $user->id)->update(['tap_limit_cents' => 10000]);

        $this->postJson(self::PAY, ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'pin_required');

        $this->assertSame(100000, $this->balance($user));
    }

    // ------------------------------------------------------------------ QR tap

    #[Test]
    public function tapping_the_bus_qr_pays_a_fare_with_no_booking(): void
    {
        [$user, $world] = $this->fundedPassenger();
        $token = $this->signedTokenFor($world['vehicle']);

        $body = $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk()->json('payment');

        $this->assertSame(70, $body['amount']);
        $this->assertSame(930, $body['balance_after']);
        $this->assertSame(93000, $this->balance($user));

        $receipt = QrcodePayment::firstOrFail();
        $this->assertSame('wallet', $receipt->payment_method);
        // ZERO SHILLINGS in `amount`, the fare in `fare`: no money reached the
        // SACCO's till, it is in the Komiut float until settlement. Same
        // reasoning the points path uses.
        $this->assertSame(0.0, (float) $receipt->amount);
        $this->assertSame(70.0, (float) $receipt->fare);
    }

    #[Test]
    public function a_tampered_qr_is_refused(): void
    {
        [$user] = $this->fundedPassenger();

        $this->postJson(self::PAY, ['qr_token' => 'not.a.real.token', 'amount' => 70])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_qr');

        $this->assertSame(100000, $this->balance($user));
    }

    #[Test]
    public function a_withdrawn_sticker_is_gone_not_broken(): void
    {
        [$user, $world] = $this->fundedPassenger();
        $token = $this->signedTokenFor($world['vehicle']);

        $world['vehicle']->forceFill(['status' => false])->save();

        $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])
            ->assertStatus(410)
            ->assertJsonPath('code', 'vehicle_inactive');

        $this->assertSame(100000, $this->balance($user));
    }

    #[Test]
    public function a_double_tap_charges_once(): void
    {
        [$user, $world] = $this->fundedPassenger();
        $token = $this->signedTokenFor($world['vehicle']);

        $first = $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk()->json('payment');
        $again = $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk()->json('payment');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($again['replayed']);
        $this->assertSame(93000, $this->balance($user), 'charged once');
        $this->assertSame(1, QrcodePayment::count());
        $this->assertSame(1, WalletTransaction::where('kind', WalletEntryKind::Fare->value)->count());
    }

    #[Test]
    public function a_tap_refused_before_the_transaction_writes_no_receipt(): void
    {
        // An honest, narrower claim than this test used to make. The balance,
        // frozen and PIN checks all run BEFORE the transaction opens, so an
        // insufficient balance never reaches the receipt write at all -- which
        // means this does NOT exercise the rollback, and the test that said it
        // did would have passed with the rollback broken. The real rollback is
        // pinned below.
        [$user, $world] = $this->fundedPassenger(50);
        $token = $this->signedTokenFor($world['vehicle']);

        $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_balance');

        $this->assertSame(0, QrcodePayment::count());
        $this->assertSame(5000, $this->balance($user));
    }

    #[Test]
    public function a_debit_that_fails_inside_the_transaction_rolls_the_receipt_back(): void
    {
        // THE ROLLBACK, actually exercised. The receipt is written BEFORE the
        // debit so the ledger row can key on its id, so a debit that fails
        // afterwards must take the receipt with it -- otherwise a
        // qrcode_payments row survives claiming a ride nobody paid for, and the
        // crew's takings show a fare that was never collected.
        //
        // Reaching that state needs the debit to fail for a reason the
        // pre-checks cannot see, so the ledger insert is sabotaged with a
        // temporary constraint: fares are stored NEGATIVE, so demanding a
        // positive amount makes exactly the debit fail while leaving the receipt
        // insertable.
        [$user, $world] = $this->fundedPassenger(1000);
        $token = $this->signedTokenFor($world['vehicle']);

        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT tmp_debits_blocked CHECK (amount_cents > 0)');

        try {
            $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])
                ->assertStatus(500);
        } finally {
            DB::statement('ALTER TABLE wallet_transactions DROP CONSTRAINT tmp_debits_blocked');
        }

        $this->assertSame(0, QrcodePayment::count(), 'the receipt rolled back with the failed debit');
        $this->assertSame(100000, $this->balance($user), 'and nothing was charged');
    }

    // -------------------------------------------------------------- reporting

    #[Test]
    public function a_wallet_fare_reaches_the_buses_takings_in_its_own_bucket(): void
    {
        // It MUST reach the takings: the ride happened and the SACCO earned the
        // fare, so a fare that wrote nothing here would leave the bus's day
        // short with nothing explaining why.
        //
        // And it must land in `wallet_amount`, NOT `mpesa_amount`: operators
        // reconcile mpesa_amount against money actually in their till, and this
        // is still in the Komiut float.
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertOk();

        // withoutGlobalScopes: Transaction is brand/sacco-scoped via its
        // vehicle and the caller here is a passenger with sacco_id NULL, which
        // SaccoScope fails closed on -- so a scoped read would hide the row
        // that was just written. Same reason C2bPaymentRecorder drops them.
        $row = Transaction::withoutGlobalScopes()->whereNotNull('wallet_transaction_id')->firstOrFail();
        $this->assertSame((int) $world['vehicle']->id, (int) $row->vehicle_id);
        $this->assertSame(150.0, (float) $row->amount);
        $this->assertNull($row->mpesa_id, 'not an M-Pesa payment');
        $this->assertNull($row->cash_id, 'and not cash');

        // The scheduler runs this with NO authenticated user, and the command's
        // aggregate is sacco-scoped via the vehicle. Leaving the passenger
        // signed in here would make SaccoScope fail closed on their null
        // sacco_id and the command would summarise nothing -- a test artifact,
        // not a production one, but it has to be dropped to exercise the real
        // path.
        $this->app['auth']->forgetGuards();

        $this->artisan('app:generate-vehicle-summaries')->assertSuccessful();

        $summary = Summary::withoutGlobalScopes()->where('vehicle_id', $world['vehicle']->id)->firstOrFail();
        $this->assertSame(150.0, (float) $summary->wallet_amount);
        $this->assertSame(1, (int) $summary->wallet_txn);
        $this->assertSame(0.0, (float) $summary->mpesa_amount, 'never counted as till money');
        $this->assertSame(0.0, (float) $summary->cash_amount);
    }

    #[Test]
    public function a_wallet_fare_is_earned_but_not_collected(): void
    {
        // The distinction the whole settlement story rests on. Counting it as
        // collected would tell an operator their takings are in their account
        // when they are not, and their bank reconciliation would fail by the
        // amount of every wallet ride.
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertOk();

        $annotated = BookingSettlement::annotateOne($booking->refresh());

        $this->assertSame('wallet', $annotated->paid_with);
        $this->assertSame(0.0, $annotated->amount_collected, 'not in the SACCO till yet');
        $this->assertSame(150.0, $annotated->amount_pending_settlement, 'but owed to them');
        $this->assertNull($annotated->points_spent);
    }

    #[Test]
    public function the_ledger_beats_a_stale_payment_method(): void
    {
        // `payment_method` is stamped from the RESERVE request -- the rail the
        // app intended -- so a booking held with "wallet" in mind and then
        // settled by M-Pesa must not read as a wallet fare and under-report the
        // SACCO's takings.
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $booking->forceFill([
            'payment_method' => PaymentMethod::Wallet,
            'paid' => true,
        ])->save();

        $annotated = BookingSettlement::annotateOne($booking->refresh());

        // NO WALLET LEDGER ROW EXISTS, so the float never paid for this ride --
        // whatever settled it was a money rail that reached the SACCO. Reading
        // the stale column as authoritative would report a settlement Komiut
        // owes them and does not.
        $this->assertSame(150.0, $annotated->amount_collected, 'a money rail paid this, so it is collected');
        $this->assertSame(0.0, $annotated->amount_pending_settlement, 'and nothing is owed');
    }

    #[Test]
    public function the_passengers_phone_is_told_the_balance_moved(): void
    {
        Event::fake([PassengerBalanceChanged::class]);

        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertOk();

        Event::assertDispatched(PassengerBalanceChanged::class, function (PassengerBalanceChanged $e) use ($user) {
            return $e->userId === (int) $user->id
                && $e->scheme === PassengerBalanceChanged::SCHEME_WALLET
                && (int) $e->balance === 850
                && (int) $e->delta === -150
                && $e->reason === 'fare';
        });
    }

    #[Test]
    public function the_fare_appears_in_the_passengers_own_history(): void
    {
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertOk();

        $rows = $this->getJson('/api/v1/auth/wallet/transactions')->assertOk()->json('transactions');

        $this->assertSame('fare', $rows[0]['kind']);
        $this->assertSame('debit', $rows[0]['direction']);
        $this->assertSame(150, $rows[0]['amount']);
        $this->assertSame(850, $rows[0]['balance_after']);

        $month = $this->getJson('/api/v1/auth/wallet')->assertOk()->json('wallet.this_month');
        $this->assertSame(150, $month['spent']);
        $this->assertSame(1, $month['fares']);
    }

    #[Test]
    public function paying_is_unreachable_without_a_token(): void
    {
        $this->postJson(self::PAY, ['booking_id' => 1])->assertUnauthorized();
    }

    #[Test]
    public function a_retried_tap_replays_even_when_the_balance_is_now_spent(): void
    {
        // THE ORDERING BUG THIS PINS. A passenger whose balance is exactly the
        // fare taps, it succeeds, the response is lost, the app retries. With
        // the balance checked before the replay, the retry sees zero and
        // answers insufficient_balance -- telling somebody who has just paid
        // that their ride failed, which is the precise failure the replay
        // exists to prevent.
        [$user, $world] = $this->fundedPassenger(70);
        $token = $this->signedTokenFor($world['vehicle']);

        $first = $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk()->json('payment');
        $this->assertSame(0, $this->balance($user), 'the balance is now exactly empty');

        $again = $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk()->json('payment');

        $this->assertTrue($again['replayed']);
        $this->assertSame($first['reference'], $again['reference']);
        $this->assertSame(1, QrcodePayment::count());
    }

    #[Test]
    public function paying_for_a_companion_is_not_swallowed_as_a_replay(): void
    {
        // Two fares, same bus, seconds apart -- ordinary on a matatu. A
        // window-only replay rule answers the second with the first receipt and
        // `replayed: true`: the passenger is never charged, the conductor sees a
        // paid screen, and the SACCO loses the fare. The amount is part of the
        // fallback match for exactly this.
        [$user, $world] = $this->fundedPassenger();
        $token = $this->signedTokenFor($world['vehicle']);

        $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk();
        $second = $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 50])->assertOk()->json('payment');

        $this->assertFalse($second['replayed'], 'a different fare is a different ride');
        $this->assertSame(88000, $this->balance($user), 'both were charged: 1000 - 70 - 50');
        $this->assertSame(2, QrcodePayment::count());
    }

    #[Test]
    public function the_clients_idempotency_key_decides_what_is_a_retry(): void
    {
        // The app mints one per payment and pay() sends it, so a retry carries
        // the same key and a second genuine fare carries a new one. That is
        // stronger than any time window: it distinguishes the two cases the
        // window can only guess between.
        [$user, $world] = $this->fundedPassenger();
        $token = $this->signedTokenFor($world['vehicle']);

        $a = $this->withHeaders(['Idempotency-Key' => 'tap-1'])
            ->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk()->json('payment');

        // Same key, same amount -> a retry.
        $retry = $this->withHeaders(['Idempotency-Key' => 'tap-1'])
            ->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk()->json('payment');

        $this->assertTrue($retry['replayed']);
        $this->assertSame($a['reference'], $retry['reference']);

        // DIFFERENT key, same amount, same minute -> a second real fare, which a
        // window-only rule would have swallowed.
        $companion = $this->withHeaders(['Idempotency-Key' => 'tap-2'])
            ->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk()->json('payment');

        $this->assertFalse($companion['replayed']);
        $this->assertSame(86000, $this->balance($user), '1000 - 70 - 70');
        $this->assertSame(2, QrcodePayment::count());
    }

    #[Test]
    public function the_crew_is_told_about_a_wallet_tap(): void
    {
        // A wallet tap leaves NOTHING at the door -- no cash, no SMS, no till
        // confirmation -- so without this the conductor has only the
        // passenger's word and a phone screen that could be an old receipt.
        // Same reasoning as the crew announce in redeemForVehicle.
        Event::fake([FarePaidFromWallet::class]);

        [, $world] = $this->fundedPassenger();
        $token = $this->signedTokenFor($world['vehicle']);

        $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk();

        Event::assertDispatched(FarePaidFromWallet::class, function (FarePaidFromWallet $e) use ($world) {
            $row = $e->broadcastWith();

            return (string) $e->broadcastOn()[0] === 'private-vehicle.'.$world['vehicle']->id
                && $e->broadcastAs() === 'payment.recorded'
                && $row['method'] === 'wallet'
                && $row['amount'] === 0.0
                && $row['fare'] === 70.0
                // Namespaced so a wallet fare cannot collide with a points fare
                // or a transaction of the same id -- the crew app dedups by id.
                && $row['id'] === 'qr-wlt-'.$e->payment->id;
        });
    }

    #[Test]
    public function a_replayed_tap_does_not_tell_the_crew_twice(): void
    {
        Event::fake([FarePaidFromWallet::class]);

        [, $world] = $this->fundedPassenger();
        $token = $this->signedTokenFor($world['vehicle']);

        $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk();
        $this->postJson(self::PAY, ['qr_token' => $token, 'amount' => 70])->assertOk();

        Event::assertDispatchedTimes(FarePaidFromWallet::class, 1);
    }

    /** A signed sticker token for this bus, as QrStickerResolver verifies them. */
    private function signedTokenFor($vehicle): string
    {
        return app(QrTokenService::class)->generate(['vehicle_id' => (int) $vehicle->id]);
    }

    #[Test]
    public function the_loser_of_a_concurrent_payment_replays_instead_of_being_refused(): void
    {
        // THE RACE THIS PINS. The replay check before the transaction reads the
        // ledger, but it runs BEFORE the lock -- so two concurrent retries of
        // one payment both pass it, both block on the booking row, and the loser
        // used to be answered "This booking is already paid": the same string a
        // passenger gets when M-Pesa beat them to it. A retry whose first
        // attempt SUCCEEDED was reported as a failure.
        //
        // Simulated faithfully by handing the service a STALE booking model --
        // exactly what the winner leaves the loser holding -- while the row in
        // the database is already paid with a wallet fare behind it.
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $stale = Booking::withoutGlobalScopes()->with(['from:id,name', 'to:id,name'])->findOrFail($booking->id);
        $this->assertFalse((bool) $stale->paid, 'the loser still thinks it is unpaid');

        // The winner commits: the fare is debited and the booking marked paid.
        $this->postJson(self::PAY, ['booking_id' => $booking->id])->assertOk();

        $result = app(WalletPaymentService::class)->payForBooking($user->fresh(), $stale, null);

        $this->assertTrue($result['ok'], 'the loser must not be told their paid ride failed');
        $this->assertTrue($result['replayed']);
        $this->assertSame(85000, $this->balance($user), 'charged exactly once');
        $this->assertSame(1, WalletTransaction::where('kind', WalletEntryKind::Fare->value)->count());
    }

    #[Test]
    public function a_booking_paid_by_another_rail_is_still_a_conflict_under_the_lock(): void
    {
        // The other half: paid, but with NO wallet fare behind it. That is a
        // genuine conflict and the passenger keeps their money -- the ledger
        // check must not turn every "already paid" into a success.
        [$user, $world] = $this->fundedPassenger();
        $booking = $this->bookingOnALiveRun($world, $user, 150);

        $stale = Booking::withoutGlobalScopes()->findOrFail($booking->id);
        Booking::withoutGlobalScopes()->whereKey($booking->id)->update(['paid' => true]);

        $result = app(WalletPaymentService::class)->payForBooking($user->fresh(), $stale, null);

        $this->assertFalse($result['ok']);
        $this->assertSame(422, $result['status']);
        $this->assertSame(100000, $this->balance($user), 'nothing was charged');
    }
}
