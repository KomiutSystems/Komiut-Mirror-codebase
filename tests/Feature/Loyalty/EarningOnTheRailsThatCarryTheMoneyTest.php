<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

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
 * Earning had to follow the money, and the money is not in bookings.
 *
 * Points were credited by exactly one thing: a Booking flipping to `paid`. The
 * rails that actually carry fares create no Booking at all — a C2B/till
 * confirmation (~98.6% of revenue) writes only mpesas/transactions/summaries,
 * and a QR fare writes a QrcodePayment. So a passenger paying the way almost
 * every passenger pays earned nothing, forever, and the QR case was a straight
 * regression: the legacy earner did credit it.
 *
 * The ledger could not express those credits either — its only idempotency key
 * was (booking_id, type), and neither rail has a booking. Hence
 * (source_type, source_id, type), which these tests pin as the thing standing
 * between a Safaricom retry and a double credit.
 */
final class EarningOnTheRailsThatCarryTheMoneyTest extends QueueTestCase
{
    private const TILL_URL = '/api/confirmation/4';

    private function programFor(Sacco $sacco, float $divisor = 100): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'sacco_id' => $sacco->id,
            'divisor' => $divisor,
            'redemption_threshold' => 50,
            'is_active' => true,
        ]);
    }

    /**
     * A payer whose number is stored the LOCAL way (07…) while Safaricom sends
     * the international one (2547…). That mismatch is the whole reason
     * Phone::lookupForms exists, and a direct comparison would match nobody.
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
            // NOW, not the fixture's August date: earnForFare declines any fare
            // older than the programme, so a stale stamp would silently make
            // every earning assertion here vacuous.
            'TransTime' => Carbon::now()->format('YmdHis'),
            'TransAmount' => '300.00',
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

    private function balanceOf(User $user, Sacco $sacco): float
    {
        return (float) (LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->where('sacco_id', $sacco->id)->value('balance') ?? 0);
    }

    // ---------------------------------------------------------------- till

    #[Test]
    public function a_till_fare_earns_points_for_the_payer(): void
    {
        // THE 98.6% CASE. KES 300 at 1 point per 100 = 3 points.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->busOnTill($world['sacco']);
        $payer = $this->payer();

        $this->call('POST', self::TILL_URL, $this->tillPayload())->assertOk();

        $this->assertEqualsWithDelta(3.0, $this->balanceOf($payer, $world['sacco']), 0.001);
    }

    #[Test]
    public function the_payers_number_is_matched_across_both_stored_forms(): void
    {
        // Safaricom sends 254700111222; the account holds 0700111222. Same
        // number, and a naive where('phone', $msisdn) finds neither.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->busOnTill($world['sacco']);
        $payer = $this->payer('0700111222');

        $this->call('POST', self::TILL_URL, $this->tillPayload(['MSISDN' => '254700111222']))->assertOk();

        $this->assertGreaterThan(0, $this->balanceOf($payer, $world['sacco']));
    }

    #[Test]
    public function a_replayed_confirmation_credits_exactly_once(): void
    {
        // Safaricom retries. The (source_type, source_id, type) unique index is
        // what stops the retry paying twice.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->busOnTill($world['sacco']);
        $payer = $this->payer();

        $this->call('POST', self::TILL_URL, $this->tillPayload())->assertOk();
        $this->call('POST', self::TILL_URL, $this->tillPayload())->assertOk();

        $this->assertEqualsWithDelta(3.0, $this->balanceOf($payer, $world['sacco']), 0.001);
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('source_type', 'mpesa')->count());
    }

    #[Test]
    public function a_fare_paid_from_a_number_we_do_not_know_credits_nobody(): void
    {
        // Most people who pay a matatu till have never opened the app. That is
        // not an error, and it must not cost the payment.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->busOnTill($world['sacco']);

        $this->call('POST', self::TILL_URL, $this->tillPayload(['MSISDN' => '254799999999']))->assertOk();

        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()->count());
        $this->assertSame(1, Transaction::withoutGlobalScopes()->count(), 'the fare is still recorded');
    }

    #[Test]
    public function a_sacco_running_no_programme_earns_nothing_and_still_banks_the_fare(): void
    {
        $world = $this->makeWorld();
        $this->busOnTill($world['sacco']);
        $payer = $this->payer();

        $this->call('POST', self::TILL_URL, $this->tillPayload())->assertOk();

        $this->assertEqualsWithDelta(0.0, $this->balanceOf($payer, $world['sacco']), 0.001);
        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_fare_that_predates_the_programme_earns_nothing(): void
    {
        // THE BACKFILL GUARD. This recorder is also the save chain for
        // payments:backfill-from-legacy, and the outstanding NCBA backfill alone
        // is 46,819 payments. Crediting those would conjure a liability out of an
        // import — points for rides taken before any SACCO agreed to a scheme.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->busOnTill($world['sacco']);
        $payer = $this->payer();

        $this->call('POST', self::TILL_URL, $this->tillPayload([
            'TransTime' => Carbon::now()->subMonths(2)->format('YmdHis'),
        ]))->assertOk();

        $this->assertEqualsWithDelta(0.0, $this->balanceOf($payer, $world['sacco']), 0.001);
        $this->assertSame(1, Transaction::withoutGlobalScopes()->count(), 'the historical fare is still imported');
    }

    #[Test]
    public function an_unattributable_payment_earns_nothing_but_is_never_lost(): void
    {
        // No vehicle carries this shortcode, so there is no SACCO to earn
        // against. The money must still land — 52 payments once vanished for
        // exactly this class of reason.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->payer();

        $this->call('POST', self::TILL_URL, $this->tillPayload(['BusinessShortCode' => '9999999']))->assertOk();

        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()->count());
        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ qr

    private function qrCallbackBody(float $amount = 300): string
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

    #[Test]
    public function a_qr_fare_earns_points_again(): void
    {
        // A REGRESSION BEING PUT BACK: the legacy earner credited QR payments;
        // the replacement earned only on bookings, and a QR fare has none.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $payer = $this->payer();

        $qr = QrcodePayment::create([
            'vehicle_id' => $world['vehicle']->id,
            'user_id' => $payer->id,
            'amount' => 300,
            'status' => false,
        ]);
        $nonce = str_repeat('c', 64);
        MpesaStkCallback::create([
            'qrcode_payment_id' => $qr->id,
            'callback_nonce' => $nonce,
            'callback' => '{}',
        ]);

        $this->call('POST', '/api/testing/stk/push/response/'.$nonce, [], [], [], [], $this->qrCallbackBody())
            ->assertOk();

        $this->assertEqualsWithDelta(3.0, $this->balanceOf($payer, $world['sacco']), 0.001);
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('source_type', 'qrcode_payment')->count());
    }

    #[Test]
    public function a_replayed_qr_callback_credits_exactly_once(): void
    {
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $payer = $this->payer();

        $qr = QrcodePayment::create([
            'vehicle_id' => $world['vehicle']->id,
            'user_id' => $payer->id,
            'amount' => 300,
            'status' => false,
        ]);
        $nonce = str_repeat('d', 64);
        MpesaStkCallback::create([
            'qrcode_payment_id' => $qr->id,
            'callback_nonce' => $nonce,
            'callback' => '{}',
        ]);

        $url = '/api/testing/stk/push/response/'.$nonce;
        $this->call('POST', $url, [], [], [], [], $this->qrCallbackBody())->assertOk();
        $this->call('POST', $url, [], [], [], [], $this->qrCallbackBody())->assertOk();

        $this->assertEqualsWithDelta(3.0, $this->balanceOf($payer, $world['sacco']), 0.001);
    }

    #[Test]
    public function the_two_rails_do_not_collide_on_the_ledgers_idempotency_key(): void
    {
        // source_id is a bare integer, so an mpesa row and a qrcode_payment row
        // will share ids constantly. Only source_TYPE keeps them apart, and if it
        // did not, the second rail to fire would be silently swallowed as a
        // duplicate of the first.
        $world = $this->makeWorld();
        $this->programFor($world['sacco']);
        $this->busOnTill($world['sacco']);
        $payer = $this->payer();

        $this->call('POST', self::TILL_URL, $this->tillPayload())->assertOk();

        $qr = QrcodePayment::create([
            'vehicle_id' => $world['vehicle']->id,
            'user_id' => $payer->id,
            'amount' => 300,
            'status' => false,
        ]);
        $nonce = str_repeat('e', 64);
        MpesaStkCallback::create([
            'qrcode_payment_id' => $qr->id,
            'callback_nonce' => $nonce,
            'callback' => '{}',
        ]);
        $this->call('POST', '/api/testing/stk/push/response/'.$nonce, [], [], [], [], $this->qrCallbackBody())
            ->assertOk();

        $this->assertEqualsWithDelta(6.0, $this->balanceOf($payer, $world['sacco']), 0.001,
            'both fares earn: 3 from the till, 3 from the QR scan');
        $this->assertSame(2, LoyaltyTransaction::withoutGlobalScopes()->count());
    }
}
