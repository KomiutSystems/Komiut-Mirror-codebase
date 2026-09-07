<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Models\CarbonCreditAccount;
use App\Models\CarbonCreditTransaction;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * REWARDS ARE EARNED ON AN IN-APP PAYMENT, AND ONLY ON AN IN-APP PAYMENT.
 *
 * The two schemes exist to move passengers onto the app's own rails. A direct
 * till payment — somebody typing a paybill into M-Pesa — needs no app and proves
 * no app use, so rewarding it would pay for the very behaviour we are trying to
 * change, and would reward a phone number rather than a passenger we know. It
 * earns nothing, deliberately, and these tests are what stop that quietly
 * regressing: crediting till payments is a one-line change and looks generous.
 *
 * The rails that DO earn are the two the app owns:
 *   - an STK push against a booking  (BookingPaid -> the two earn listeners)
 *   - a QR scan on the bus           (the STK callback's non-booking branch)
 *
 * Both schemes were blind to the QR rail, each for the same structural reason:
 * they keyed earning on a Booking, and a QR fare writes a QrcodePayment instead.
 * For loyalty that was a regression — the legacy earner did credit QR payments.
 *
 * ONE FARE, TWO LEDGERS. SACCO points are the SACCO's own, spendable on its
 * buses; carbon credits are the platform's, one balance across every SACCO and
 * brand. A QR fare owes both.
 */
final class RewardsFollowAppPaymentsTest extends QueueTestCase
{
    private const TILL_URL = '/api/confirmation/4';

    /** At the configured 300 KSh per credit, a 300 KSh fare mints exactly one. */
    private const FARE = 300;

    private function programFor(Sacco $sacco): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'sacco_id' => $sacco->id,
            'divisor' => 100,               // 1 point per KSh 100
            'redemption_threshold' => 50,
            'is_active' => true,
        ]);
    }

    /**
     * A payer whose number is stored the LOCAL way (07…) while Safaricom sends
     * the international one (2547…) — the mismatch Phone::lookupForms exists for.
     */
    private function payer(string $localPhone = '0700111222'): User
    {
        $user = $this->makeUser();
        $user->forceFill(['phone' => $localPhone])->save();

        return $user->fresh();
    }

    private function busOnTill(Sacco $sacco, string $code = '7100466'): Vehicle
    {
        $vehicle = Vehicle::withoutGlobalScopes()->where('sacco_id', $sacco->id)->firstOrFail();
        $vehicle->merchant_short_code = $code;
        $vehicle->save();

        return $vehicle;
    }

    /** @param array<string,mixed> $override */
    private function tillPayload(array $override = []): array
    {
        return array_merge([
            'TransactionType' => 'Customer Merchant Payment',
            'TransID' => 'UHQQ349A09',
            'TransTime' => Carbon::now()->format('YmdHis'),
            'TransAmount' => (string) self::FARE,
            'BusinessShortCode' => '7100466',
            'BillRefNumber' => '',
            'InvoiceNumber' => '',
            'OrgAccountBalance' => '1000.00',
            'ThirdPartyTransID' => '',
            'MSISDN' => '254700111222',
            'FirstName' => 'WANJIKU',
            'MiddleName' => '',
            'LastName' => 'KAMAU',
        ], $override);
    }

    private function qrCallbackBody(float $amount = self::FARE): string
    {
        return json_encode([
            'Body' => ['stkCallback' => [
                'MerchantRequestID' => 'merch-1',
                'CheckoutRequestID' => 'checkout-1',
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => $amount],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'QRC123XYZ'],
                    ['Name' => 'TransactionDate', 'Value' => Carbon::now()->format('YmdHis')],
                    ['Name' => 'PhoneNumber', 'Value' => 254700111222],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** Pay a QR fare on this bus and return the resulting response. */
    private function payByQr(array $world, ?User $payer, string $nonce, float $amount = self::FARE)
    {
        $qr = QrcodePayment::create([
            'vehicle_id' => $world['vehicle']->id,
            'user_id' => $payer?->id,
            'amount' => $amount,
            'status' => false,
        ]);

        MpesaStkCallback::create([
            'qrcode_payment_id' => $qr->id,
            'callback_nonce' => $nonce,
            'callback' => '{}',
        ]);

        return $this->call('POST', '/api/testing/stk/push/response/'.$nonce, [], [], [], [], $this->qrCallbackBody($amount));
    }

    private function pointsOf(User $user, Sacco $sacco): float
    {
        return (float) (LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->where('sacco_id', $sacco->id)->value('balance') ?? 0);
    }

    private function creditsOf(User $user): int
    {
        return (int) (CarbonCreditAccount::where('user_id', $user->id)->value('credits') ?? 0);
    }

    // ------------------------------------------------- a till payment earns nothing

    #[Test]
    public function a_direct_till_payment_earns_no_sacco_points(): void
    {
        // THE POLICY. The payer even has an account and the SACCO runs a
        // programme — it still earns nothing, because paying a till directly
        // is not using the app.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->busOnTill($world['sacco']);
        $payer = $this->payer();

        $this->call('POST', self::TILL_URL, $this->tillPayload())->assertOk();

        $this->assertEqualsWithDelta(0.0, $this->pointsOf($payer, $world['sacco']), 0.001);
        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_direct_till_payment_earns_no_carbon_credits(): void
    {
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->busOnTill($world['sacco']);
        $payer = $this->payer();

        $this->call('POST', self::TILL_URL, $this->tillPayload())->assertOk();

        $this->assertSame(0, $this->creditsOf($payer));
        $this->assertSame(0, CarbonCreditTransaction::where('user_id', $payer->id)->count());
    }

    #[Test]
    public function the_till_fare_itself_is_still_banked(): void
    {
        // Not earning must never become not recording. 52 payments once vanished
        // because something downstream of the save threw.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->busOnTill($world['sacco']);
        $this->payer();

        $this->call('POST', self::TILL_URL, $this->tillPayload())->assertOk();

        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------- a QR fare earns both

    #[Test]
    public function a_qr_fare_earns_sacco_points(): void
    {
        // KSh 300 at 1 point per 100 = 3 points.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $payer = $this->payer();

        $this->payByQr($world, $payer, str_repeat('a', 64))->assertOk();

        $this->assertEqualsWithDelta(3.0, $this->pointsOf($payer, $world['sacco']), 0.001);
    }

    #[Test]
    public function a_qr_fare_earns_carbon_credits(): void
    {
        // KSh 300 of travel at 300 per credit = exactly one credit.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $payer = $this->payer();

        $this->payByQr($world, $payer, str_repeat('b', 64))->assertOk();

        $this->assertSame(1, $this->creditsOf($payer));
        $this->assertSame(1, CarbonCreditTransaction::where('user_id', $payer->id)
            ->where('source_type', 'qrcode_payment')->count());
    }

    #[Test]
    public function one_fare_feeds_both_ledgers_independently(): void
    {
        // They are separate schemes: the SACCO's points and the platform's
        // credits. A single QR fare owes both, and neither is a substitute for
        // the other.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $payer = $this->payer();

        $this->payByQr($world, $payer, str_repeat('c', 64))->assertOk();

        $this->assertEqualsWithDelta(3.0, $this->pointsOf($payer, $world['sacco']), 0.001);
        $this->assertSame(1, $this->creditsOf($payer));
    }

    #[Test]
    public function carbon_credits_still_accrue_where_the_sacco_runs_no_programme(): void
    {
        // Carbon credits are the PLATFORM's, earned across every SACCO. A SACCO
        // that has not opted into its own points scheme must not silently cost
        // the passenger their platform credits.
        $world = $this->makeWorld();          // no LoyaltyProgram
        $payer = $this->payer();

        $this->payByQr($world, $payer, str_repeat('d', 64))->assertOk();

        $this->assertSame(1, $this->creditsOf($payer));
        $this->assertEqualsWithDelta(0.0, $this->pointsOf($payer, $world['sacco']), 0.001);
    }

    #[Test]
    public function a_replayed_qr_callback_credits_each_scheme_exactly_once(): void
    {
        // Safaricom retries. Two partial unique indexes — one per ledger — are
        // what stop a retry paying twice.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $payer = $this->payer();

        $qr = QrcodePayment::create([
            'vehicle_id' => $world['vehicle']->id,
            'user_id' => $payer->id,
            'amount' => self::FARE,
            'status' => false,
        ]);
        $nonce = str_repeat('e', 64);
        MpesaStkCallback::create([
            'qrcode_payment_id' => $qr->id,
            'callback_nonce' => $nonce,
            'callback' => '{}',
        ]);

        $url = '/api/testing/stk/push/response/'.$nonce;
        $this->call('POST', $url, [], [], [], [], $this->qrCallbackBody())->assertOk();
        $this->call('POST', $url, [], [], [], [], $this->qrCallbackBody())->assertOk();

        $this->assertEqualsWithDelta(3.0, $this->pointsOf($payer, $world['sacco']), 0.001);
        $this->assertSame(1, $this->creditsOf($payer));
    }

    #[Test]
    public function a_qr_fare_paid_by_a_signed_out_stranger_credits_nobody(): void
    {
        // QrcodePayment.user_id is null and the callback's number belongs to no
        // account. Nothing to credit, and the payment must still settle.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);

        $qr = QrcodePayment::create([
            'vehicle_id' => $world['vehicle']->id,
            'user_id' => null,
            'amount' => self::FARE,
            'status' => false,
        ]);
        $nonce = str_repeat('f', 64);
        MpesaStkCallback::create([
            'qrcode_payment_id' => $qr->id,
            'callback_nonce' => $nonce,
            'callback' => '{}',
        ]);

        $this->call('POST', '/api/testing/stk/push/response/'.$nonce, [], [], [], [], $this->qrCallbackBody())
            ->assertOk();

        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()->count());
        $this->assertSame(0, CarbonCreditTransaction::count());
        $this->assertTrue((bool) $qr->fresh()->status, 'the fare is still settled');
    }

    #[Test]
    public function the_payers_number_is_matched_across_both_stored_forms(): void
    {
        // QrcodePayment carries no user_id, so the payer is resolved from the
        // callback's 254700111222 against an account stored as 0700111222.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $payer = $this->payer('0700111222');

        $qr = QrcodePayment::create([
            'vehicle_id' => $world['vehicle']->id,
            'user_id' => null,
            'amount' => self::FARE,
            'status' => false,
        ]);
        // Hex only: the route constrains the nonce to [a-f0-9]{64}, so a stray
        // letter past 'f' does not 404 the handler — it never reaches it.
        $nonce = str_repeat('9', 64);
        MpesaStkCallback::create([
            'qrcode_payment_id' => $qr->id,
            'callback_nonce' => $nonce,
            'callback' => '{}',
        ]);

        $this->call('POST', '/api/testing/stk/push/response/'.$nonce, [], [], [], [], $this->qrCallbackBody())
            ->assertOk();

        $this->assertGreaterThan(0, $this->pointsOf($payer, $world['sacco']));
        $this->assertSame(1, $this->creditsOf($payer));
    }
}
