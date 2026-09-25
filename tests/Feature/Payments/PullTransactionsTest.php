<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Console\Commands\PullMpesaTransactions;
use App\Models\Mpesa;
use App\Models\MpesaLog;
use App\Models\MpesaPaymentSetting;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Services\Mpesa\PullTransactionImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Recovering payments Safaricom took but never delivered.
 *
 * The shape under test is the morning of 2026-09-25: a till whose M-Pesa
 * statement had 137 completed payments while our books had 34, because the
 * confirmations for the rest were never sent. The Pull API returns the whole
 * window; we must record exactly the ones we lack, on the right bus, on the
 * right business day, and leave everything we already hold untouched.
 */
final class PullTransactionsTest extends QueueTestCase
{
    private const TILL = '3702865';

    /**
     * The id in the till's ConfirmationURL: the LEGACY payments server's app
     * for Head Office 5342498, which is not our setting's id.
     */
    private const URL_ID = 13;

    protected function setUp(): void
    {
        parent::setUp();
        // Till discovery looks back 14 days from now; pin now to the morning in question.
        $this->travelTo(Carbon::parse('2026-09-25 12:00:00'));
    }

    private function setting(): MpesaPaymentSetting
    {
        return MpesaPaymentSetting::create([
            'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'business_short_code' => '5342498',
            'pass_key' => 'pk', 'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true,
        ]);
    }

    private function bus(): Vehicle
    {
        $vehicle = $this->makeWorld()['vehicle'];
        $vehicle->forceFill(['merchant_short_code' => self::TILL])->save();

        return $vehicle->fresh();
    }

    /** A payment we received the normal way, confirmation URL id $urlId. */
    private function held(Vehicle $bus, int $urlId, string $receipt, string $when, float $amount): void
    {
        $m = new Mpesa;
        $m->forceFill([
            'TransID' => $receipt, 'TransAmount' => (string) $amount, 'TransTime' => $when, 'MSISDN' => 'hash',
            'FirstName' => 'HELD', 'BusinessShortCode' => self::TILL, 'OrgAccountBalance' => '8000.00',
            'TransactionType' => 'Buy Goods', 'mpesa_setting_id' => $urlId,
        ])->save();
        Transaction::withoutGlobalScopes()->create(['mpesa_id' => $m->id, 'vehicle_id' => $bus->id, 'amount' => $amount, 'trans_date' => $when]);
    }

    /** Safaricom's Pull answer: the documented list-inside-a-list. */
    private function safaricomHas(array $rows, string $code = '1000'): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/pulltransactions/v1/query' => Http::response(['ResponseRefID' => 'r1', 'ResponseCode' => $code,
                'ResponseMessage' => $rows ? 'Success' : 'No records found or Organization Name not available', 'Response' => [$rows]]),
            // Verbatim shape from Safaricom's published collection: spaced keys.
            '*/pulltransactions/v1/register' => Http::response(['ResponseRefID' => '27079-7249935-2', 'Response Status' => '1000',
                'ShortCode' => '5342498', 'Response Description' => 'Short Code  5342498  Registered Successfully']),
        ]);
    }

    private function row(string $receipt, string $trxDate, string $amount, string $type = 'c2b-buy-goods-debit'): array
    {
        return ['transactionId' => $receipt, 'trxDate' => $trxDate, 'msisdn' => 254700000000, 'sender' => 'JANE WANJIKU MWANGI',
            'transactiontype' => $type, 'billreference' => '', 'amount' => $amount, 'organizationname' => 'NICCO MOVERS - KDV 672W'];
    }

    private function pull(array $extra = []): PendingCommand
    {
        return $this->artisan('payments:pull', ['--from' => '2026-09-25 05:00', '--to' => '2026-09-25 10:00'] + $extra);
    }

    #[Test]
    public function the_payments_that_were_never_delivered_are_recorded_on_their_bus(): void
    {
        $this->setting();
        $bus = $this->bus();
        $this->held($bus, self::URL_ID, 'UIPDS807K6', '2026-09-25 09:32:37', 30);        // arrived normally
        $this->safaricomHas([
            $this->row('UIPDS807K6', '2026-09-25T09:32:37Z', '30'),                // we hold it
            $this->row('UIP7H7OI90', '2026-09-25T06:04:11Z', '100'),               // never delivered
            $this->row('UIPOE815WT', '2026-09-25T06:05:40Z', '200'),               // never delivered
        ]);

        $this->pull(['--write' => true])->assertSuccessful();

        foreach (['UIP7H7OI90' => [100, '2026-09-25 06:04:11'], 'UIPOE815WT' => [200, '2026-09-25 06:05:40']] as $receipt => [$amount, $when]) {
            $m = Mpesa::withoutGlobalScopes()->where('TransID', $receipt)->firstOrFail();
            $this->assertSame($when, substr((string) $m->TransTime, 0, 19), 'the time the passenger paid, Nairobi clock');
            $this->assertSame((float) $amount, (float) $m->TransAmount);
            $this->assertSame(self::URL_ID, (int) $m->mpesa_setting_id, 'the ConfirmationURL id, as a delivered confirmation carries');
            $t = Transaction::withoutGlobalScopes()->where('mpesa_id', $m->id)->firstOrFail();
            $this->assertSame($bus->id, (int) $t->vehicle_id, 'attributed by the till shortcode, like a live confirmation');
            $this->assertSame('daraja-pull', MpesaLog::where('trans_id', $receipt)->value('ip_address'), 'the raw pulled row is kept, marked as pulled');
        }

        // The day's total now includes the recovered fares.
        $this->assertEqualsWithDelta(300.0, (float) Summary::withoutGlobalScopes()->where('vehicle_id', $bus->id)->sum('mpesa_amount'), 0.01);
    }

    #[Test]
    public function a_receipt_we_already_hold_is_never_rewritten(): void
    {
        $this->setting();
        $bus = $this->bus();
        $this->held($bus, self::URL_ID, 'UIPDS807K6', '2026-09-25 09:32:37', 30);
        $this->safaricomHas([$this->row('UIPDS807K6', '2026-09-25T09:32:37Z', '30')]);

        $this->pull(['--write' => true])->assertSuccessful();

        $m = Mpesa::withoutGlobalScopes()->where('TransID', 'UIPDS807K6')->sole();
        $this->assertSame('HELD', $m->FirstName, 'the delivered confirmation carried more (balance, names) and stays as it was');
        $this->assertSame('8000.00', (string) $m->OrgAccountBalance);
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('mpesa_id', $m->id)->count());
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $this->setting();
        $bus = $this->bus();
        $this->held($bus, self::URL_ID, 'UIPDS807K6', '2026-09-25 09:32:37', 30);
        $this->safaricomHas([$this->row('UIPDS807K6', '2026-09-25T09:32:37Z', '30'), $this->row('UIP7H7OI90', '2026-09-25T06:04:11Z', '100')]);

        $this->pull()->expectsOutputToContain('missing 1 (KES 100.00)')->assertSuccessful();

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UIP7H7OI90')->count());
    }

    #[Test]
    public function a_clock_that_disagrees_with_our_own_receipts_refuses_the_write(): void
    {
        // If Safaricom's trxDate were UTC and we read it as Nairobi time, every
        // recovered fare would land three hours early -- some on the wrong day.
        $this->setting();
        $bus = $this->bus();
        $this->held($bus, self::URL_ID, 'UIPDS807K6', '2026-09-25 09:32:37', 30);
        $this->safaricomHas([$this->row('UIPDS807K6', '2026-09-25T06:32:37Z', '30'), $this->row('UIP7H7OI90', '2026-09-25T03:04:11Z', '100')]);

        $this->pull(['--write' => true])->assertFailed();
        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UIP7H7OI90')->count());

        // Read as UTC, the same answer agrees with our clock and is written at 06:04 Nairobi.
        $this->pull(['--write' => true, '--utc' => true])->assertSuccessful();
        $this->assertSame('2026-09-25 06:04:11', substr((string) Mpesa::withoutGlobalScopes()->where('TransID', 'UIP7H7OI90')->sole()->TransTime, 0, 19));
    }

    #[Test]
    public function with_nothing_to_check_the_clock_against_it_refuses_to_guess(): void
    {
        $this->setting();
        $bus = $this->bus();
        $this->held($bus, self::URL_ID, 'OLDER00001', '2026-09-20 09:00:00', 30);   // makes the till a target, outside the window
        $this->safaricomHas([$this->row('UIP7H7OI90', '2026-09-25T06:04:11Z', '100')]);

        $this->pull(['--write' => true])->assertFailed();
        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UIP7H7OI90')->count());
    }

    #[Test]
    public function settlement_sweeps_are_not_fares(): void
    {
        $this->setting();
        $bus = $this->bus();
        $this->held($bus, self::URL_ID, 'UIPDS807K6', '2026-09-25 09:32:37', 30);
        $this->safaricomHas([
            $this->row('UIPDS807K6', '2026-09-25T09:32:37Z', '30'),
            $this->row('UIPSZ4CQV5', '2026-09-25T03:31:04Z', '22779.95', 'merchant-to-organization-settlement'),
        ]);

        $this->pull(['--write' => true])->assertSuccessful();
        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UIPSZ4CQV5')->count());
    }

    #[Test]
    public function an_unregistered_till_is_reported_not_taken_for_a_quiet_one(): void
    {
        $this->setting();
        $this->held($this->bus(), self::URL_ID, 'OLDER00001', '2026-09-24 09:00:00', 30);
        $this->safaricomHas([], '1001');

        $this->pull()->expectsOutputToContain('No records found or Organization Name not available (code 1001)')->assertSuccessful();
    }

    #[Test]
    public function registering_needs_the_nominated_number_and_then_registers_each_till(): void
    {
        $s = $this->setting();
        $this->held($this->bus(), self::URL_ID, 'OLDER00001', '2026-09-24 09:00:00', 30);
        $this->safaricomHas([], '1001');

        $this->pull(['--register' => true])->assertExitCode(Command::INVALID);

        $this->pull(['--register' => true, '--nominated' => '0722000000'])
            ->expectsOutputToContain('Registered Successfully (1000)')->assertSuccessful();

        // "The Organization ShortCode that was used during the Go-Live process":
        // the app's own shortcode, not the till.
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/pulltransactions/v1/register')
            && $req['ShortCode'] === '5342498' && $req['RequestType'] === 'Pull' && $req['NominatedNumber'] === '254722000000'
            && str_ends_with($req['CallBackURL'], '/api/pull/callback/'.$s->id));
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/pulltransactions/v1/register') && $req['ShortCode'] === self::TILL);

        // --register-tills registers the till as well.
        $this->pull(['--register-tills' => true, '--nominated' => '0722000000'])->assertSuccessful();
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/pulltransactions/v1/register') && $req['ShortCode'] === self::TILL);
    }

    #[Test]
    public function the_confirmation_url_id_names_the_legacy_app_not_our_setting(): void
    {
        // The fleet was registered by the legacy payments server, whose app
        // table numbers differently from ours: URL id 13 is HO 5342498 there,
        // while our id 13 is another app entirely. Pulling with our #13 would
        // ask Safaricom with credentials that do not own the till.
        foreach (range(1, 13) as $i) {
            MpesaPaymentSetting::create(['consumer_key' => "decoy{$i}", 'consumer_secret' => 'x', 'business_short_code' => (string) (9000000 + $i),
                'pass_key' => 'pk', 'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true]);
        }
        $s = MpesaPaymentSetting::create(['consumer_key' => 'right', 'consumer_secret' => 'cs', 'business_short_code' => '5342498',
            'pass_key' => 'pk', 'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true]);
        $this->assertNotSame(self::URL_ID, $s->id);
        $bus = $this->bus();
        $this->held($bus, self::URL_ID, 'UIPDS807K6', '2026-09-25 09:32:37', 30);
        $this->safaricomHas([$this->row('UIPDS807K6', '2026-09-25T09:32:37Z', '30')]);

        $this->pull(['--list' => true])->expectsOutputToContain('5342498')->assertSuccessful();
        $this->pull()->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/oauth/v1/generate') && $req->header('Authorization')[0] === 'Basic '.base64_encode('right:cs'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/oauth/v1/generate') && str_contains(base64_decode(substr($req->header('Authorization')[0] ?? '', 6)), 'decoy'));
    }

    #[Test]
    public function a_till_whose_app_we_hold_no_credentials_for_is_listed_not_pulled(): void
    {
        $this->setting();
        $this->held($this->bus(), 28, 'UIPDS807K6', '2026-09-25 09:32:37', 30);   // HO 3020809: legacy-only app
        $this->safaricomHas([]);

        $this->pull(['--list' => true])->expectsOutputToContain('No credentials held for: 3702865 (HO 3020809)')->assertSuccessful();
        $this->pull()->assertSuccessful();
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/pulltransactions/'));
    }

    #[Test]
    public function a_till_moved_by_our_registrar_uses_our_own_setting(): void
    {
        MpesaPaymentSetting::create(['consumer_key' => 'legacyapp', 'consumer_secret' => 'cs', 'business_short_code' => '5342498',
            'pass_key' => 'pk', 'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true]);
        $ours = MpesaPaymentSetting::create(['consumer_key' => 'ours', 'consumer_secret' => 'cs', 'business_short_code' => '7071220',
            'pass_key' => 'pk', 'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true]);
        $bus = $this->bus();
        $bus->forceFill(['till_registered_url' => 'https://api.komiut.com/api/confirmation/'.$ours->id, 'till_registered_at' => now()])->save();
        $this->held($bus, self::URL_ID, 'OLDER00001', '2026-09-24 09:00:00', 30);   // before the move
        $this->held($bus, $ours->id, 'UIPDS807K6', '2026-09-25 09:32:37', 30);      // after it
        $this->safaricomHas([$this->row('UIPDS807K6', '2026-09-25T09:32:37Z', '30')]);

        $this->pull()->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/oauth/v1/generate') && $req->header('Authorization')[0] === 'Basic '.base64_encode('ours:cs'));
        $this->assertCount(1, array_filter(Http::recorded()->all(), fn ($r) => str_ends_with($r[0]->url(), '/pulltransactions/v1/query')), 'one till, pulled once');
    }

    #[Test]
    public function the_nominated_number_is_sent_in_the_form_safaricom_accepts(): void
    {
        $this->assertSame('254114887501', PullMpesaTransactions::msisdn('0114887501'));
        $this->assertSame('254722000000', PullMpesaTransactions::msisdn('+254 722 000 000'));
        $this->assertSame('254722000000', PullMpesaTransactions::msisdn('0722000000'));
    }

    #[Test]
    public function every_page_is_read_whatever_size_safaricom_pages_by(): void
    {
        // Safaricom's own example pages by 100 ("results 101-200 -> offset 100").
        // Stopping at the first short page would read one page of a busy till and
        // call it complete. Here pages of two, then an empty page.
        $this->setting();
        $bus = $this->bus();
        $this->held($bus, self::URL_ID, 'UIPDS807K6', '2026-09-25 09:32:37', 30);
        $page = fn (array $rows) => Http::response(['ResponseCode' => $rows ? '1000' : '1001', 'ResponseMessage' => $rows ? 'Success' : 'Null', 'Response' => [$rows]]);
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/pulltransactions/v1/query' => Http::sequence()
                ->pushResponse($page([$this->row('UIPDS807K6', '2026-09-25T09:32:37Z', '30'), $this->row('P1', '2026-09-25T06:01:00Z', '10')]))
                ->pushResponse($page([$this->row('P2', '2026-09-25T06:02:00Z', '20'), $this->row('P3', '2026-09-25T06:03:00Z', '30')]))
                ->pushResponse($page([])),
        ]);

        $this->pull(['--write' => true])->assertSuccessful();

        $this->assertSame(3, Mpesa::withoutGlobalScopes()->whereIn('TransID', ['P1', 'P2', 'P3'])->count(), 'the second page was read');
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/pulltransactions/v1/query') && $req['OffSetValue'] === '2');
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/pulltransactions/v1/query') && $req['OffSetValue'] === '4');
    }

    #[Test]
    public function safaricoms_no_transactions_answer_is_read_even_on_a_500(): void
    {
        // Documented: ResponseCode 500 "Failed to retrieve transactions" means the
        // shortcode has none. A readable answer is not an outage.
        $this->setting();
        $this->held($this->bus(), self::URL_ID, 'OLDER00001', '2026-09-24 09:00:00', 30);
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/pulltransactions/v1/query' => Http::response(['RequestID' => '6769-7119060-7', 'ResponseCode' => '500', 'ResponseMessage' => 'Failed to retrieve transactions'], 500),
        ]);

        $this->pull()->expectsOutputToContain('Failed to retrieve transactions (code 500)')->assertSuccessful();
    }

    #[Test]
    public function the_pull_callback_is_answered(): void
    {
        $this->postJson('/api/pull/callback/15', ['anything' => 'at all'])->assertOk()->assertJsonPath('ResponseCode', '0');
    }

    #[Test]
    public function safaricoms_row_nesting_is_read_either_way(): void
    {
        $a = ['transactionId' => 'A'];
        $this->assertSame([$a], PullTransactionImporter::rowsOf(['Response' => [[$a]]]));
        $this->assertSame([$a], PullTransactionImporter::rowsOf(['Response' => [$a]]));
        $this->assertSame([], PullTransactionImporter::rowsOf(['Response' => [[]]]));
        $this->assertSame([], PullTransactionImporter::rowsOf([]));
    }
}
