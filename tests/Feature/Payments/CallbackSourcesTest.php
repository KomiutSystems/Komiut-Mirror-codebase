<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\PlatformNotification;
use App\Models\RefusedCallback;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Support\Http\MoneyIngestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Only the sender may post a payment notification.
 *
 * /api/confirmation/{id} took any POST from anywhere: a body naming a real
 * bus's store number became that bus's takings. Safaricom, Co-op and NCBA do
 * not sign their POSTs, so where the POST comes from is the check -- and a POST
 * refused for its address is kept whole, so a real payment from a sender's new
 * address is recorded later instead of lost.
 */
final class CallbackSourcesTest extends QueueTestCase
{
    private const SAFARICOM = '196.201.214.200';

    private const NCBA = '196.28.11.2';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.safaricom.source_ips' => ['196.201.214.200', '196.201.212.0/24'],
            'services.ncba.source_ips' => [self::NCBA],
            'services.coop.source_ips' => ['196.11.190.75'],
            'services.ncba.username' => 'ncbauser',
            'services.ncba.password' => 'ncbapass',
        ]);
    }

    private function busOn(string $shortCode): Vehicle
    {
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['merchant_short_code' => $shortCode])->save();

        return $world['vehicle'];
    }

    private function confirmation(string $transId, string $shortCode = '1417368'): array
    {
        return [
            'TransactionType' => 'Buy Goods', 'TransID' => $transId, 'TransTime' => '20261125120000',
            'TransAmount' => '100.00', 'BusinessShortCode' => $shortCode, 'BillRefNumber' => '',
            'OrgAccountBalance' => '5000.00', 'MSISDN' => '2547XXXXX', 'FirstName' => 'JANE',
        ];
    }

    private function postFrom(string $ip, string $url, array $body): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($url, $body);
    }

    private function recorded(string $transId): ?Transaction
    {
        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', $transId)->first();

        return $mpesa === null ? null : Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
    }

    #[Test]
    public function safaricom_confirmations_are_recorded(): void
    {
        $bus = $this->busOn('1417368');

        $this->postFrom(self::SAFARICOM, '/api/confirmation/16', $this->confirmation('UKPREAL001'))->assertOk();
        $this->postFrom('196.201.212.69', '/api/confirmation/16', $this->confirmation('UKPREAL002'))->assertOk();

        $this->assertSame($bus->id, (int) $this->recorded('UKPREAL001')?->vehicle_id);
        $this->assertSame($bus->id, (int) $this->recorded('UKPREAL002')?->vehicle_id, 'a listed range covers its addresses');
    }

    #[Test]
    public function a_forged_confirmation_adds_nothing_to_a_bus(): void
    {
        $this->busOn('1417368');

        $this->postFrom('41.90.12.7', '/api/confirmation/16', $this->confirmation('FORGED0001'))->assertForbidden();

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'FORGED0001')->count());
        $kept = RefusedCallback::sole();
        $this->assertSame('safaricom', $kept->source);
        $this->assertSame('41.90.12.7', $kept->ip);
        $this->assertSame('/api/confirmation/16', $kept->path);
        $this->assertSame('FORGED0001', json_decode((string) $kept->body, true)['TransID']);
        $this->assertTrue(PlatformNotification::where('event', 'payment.callback.refused')->exists(), 'the console is told');
    }

    #[Test]
    public function a_forwarded_for_header_cannot_borrow_safaricoms_address(): void
    {
        // The caller is not one of our proxies, so its X-Forwarded-For is not
        // believed: the address checked is the one the connection came from.
        $this->busOn('1417368');

        $this->withServerVariables(['REMOTE_ADDR' => '41.90.12.7'])
            ->withHeader('X-Forwarded-For', self::SAFARICOM)
            ->postJson('/api/confirmation/16', $this->confirmation('FORGED0002'))
            ->assertForbidden();

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'FORGED0002')->count());
    }

    #[Test]
    public function behind_the_load_balancer_the_real_sender_is_the_one_checked(): void
    {
        $bus = $this->busOn('1417368');

        // The ALB (a private address, trusted) appends the true client last.
        $this->withServerVariables(['REMOTE_ADDR' => '172.31.14.2'])
            ->withHeader('X-Forwarded-For', '41.90.12.7, '.self::SAFARICOM)
            ->postJson('/api/confirmation/16', $this->confirmation('UKPALB0001'))
            ->assertOk();
        $this->assertSame($bus->id, (int) $this->recorded('UKPALB0001')?->vehicle_id);

        $this->withServerVariables(['REMOTE_ADDR' => '172.31.14.2'])
            ->withHeader('X-Forwarded-For', self::SAFARICOM.', 41.90.12.7')
            ->postJson('/api/confirmation/16', $this->confirmation('FORGED0003'))
            ->assertForbidden();
    }

    #[Test]
    public function a_refused_payment_from_a_genuine_new_address_is_recorded_by_replaying_it(): void
    {
        $bus = $this->busOn('1417368');
        $this->postFrom('196.201.215.9', '/api/confirmation/16', $this->confirmation('UKPNEWIP01'))->assertForbidden();
        $this->assertNull($this->recorded('UKPNEWIP01'));

        $this->artisan('payments:replay-refused', ['--ip' => '196.201.215.9'])->assertSuccessful();

        $this->assertSame($bus->id, (int) $this->recorded('UKPNEWIP01')?->vehicle_id);
        $kept = RefusedCallback::sole();
        $this->assertNotNull($kept->replayed_at);
        $this->assertSame(200, (int) $kept->replay_status);

        // Replaying again changes nothing: the row is done, and the payment
        // would be a duplicate anyway.
        $this->artisan('payments:replay-refused', ['ids' => [$kept->id]])->expectsOutput('Nothing to replay.');
        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'UKPNEWIP01')->count());
    }

    #[Test]
    public function ncba_must_post_from_its_address_and_its_password_is_never_kept(): void
    {
        $this->busOn('880100');
        $body = [
            'Username' => 'ncbauser', 'Password' => 'ncbapass', 'TransID' => 'NCBA000001', 'TransAmount' => 500,
            'BusinessShortCode' => '880100', 'TransTime' => '20261125120000', 'Mobile' => '254700111222',
            'BillRefNumber' => '948948', 'Name' => 'John Doe',
        ];

        $this->postFrom(self::NCBA, '/api/rest/mpesa/confirmation_new', $body)->assertOk();
        $this->assertNotNull($this->recorded('NCBA000001'));

        // Right password, wrong place: the password is what leaked.
        $this->postFrom('41.90.12.7', '/api/rest/mpesa/confirmation_new', ['TransID' => 'NCBA000002'] + $body)->assertForbidden();
        $this->assertNull($this->recorded('NCBA000002'));
        $kept = RefusedCallback::sole();
        $this->assertStringNotContainsString('ncbapass', (string) $kept->body);
        $this->assertStringNotContainsString('ncbauser', (string) $kept->body);

        // A replay signs NCBA back in with the configured credentials.
        $this->artisan('payments:replay-refused', ['ids' => [$kept->id]])->assertSuccessful();
        $this->assertNotNull($this->recorded('NCBA000002'));
    }

    #[Test]
    public function the_legacy_ncba_path_takes_either_sender_and_nobody_else(): void
    {
        $this->busOn('1417368');
        $url = '/api/testing/rest/mpesa/confirmation';

        $this->postFrom(self::SAFARICOM, $url, $this->confirmation('LEGACY0001'))->assertOk();
        $this->postFrom(self::NCBA, $url, $this->confirmation('LEGACY0002'))->assertOk();
        $this->postFrom('41.90.12.7', $url, $this->confirmation('LEGACY0003'))->assertForbidden();

        $this->assertNotNull($this->recorded('LEGACY0001'));
        $this->assertNotNull($this->recorded('LEGACY0002'));
        $this->assertNull($this->recorded('LEGACY0003'));
    }

    #[Test]
    public function an_invoice_cannot_be_marked_paid_by_a_forged_billing_confirmation(): void
    {
        $this->postFrom('41.90.12.7', '/api/testing/billing/mpesa/confirmation', [
            'BillRefNumber' => 'INV-1-202611', 'TransAmount' => 800, 'TransID' => 'BILLFORGE1',
        ])->assertForbidden();

        $this->assertSame('safaricom', RefusedCallback::sole()->source);
    }

    private function ncbaBody(string $transId, string $password = 'ncbapass'): array
    {
        return [
            'Username' => 'ncbauser', 'Password' => $password, 'TransID' => $transId, 'TransAmount' => 500,
            'BusinessShortCode' => '880100', 'TransTime' => '20261125120000', 'Mobile' => '254700111222',
            'BillRefNumber' => '948948', 'Name' => 'John Doe',
        ];
    }

    #[Test]
    public function a_payment_is_kept_however_many_arrive_from_a_new_address_but_junk_is_capped(): void
    {
        // A real sender on a new address posts thousands an hour; each is a fare.
        $key = 'callback-refused:junk:safaricom:196.201.215.9';
        for ($i = 0; $i < 200; $i++) {
            RateLimiter::hit($key, 3600);
        }

        $this->postFrom('196.201.215.9', '/api/confirmation/16', $this->confirmation('UKPPEAK001'))->assertForbidden();
        $this->postFrom('196.201.215.9', '/api/confirmation/16', ['hello' => 'world'])->assertForbidden();

        $this->assertSame(1, RefusedCallback::count(), 'the payment is kept past the cap; the junk is not');
        $this->assertSame('UKPPEAK001', json_decode((string) RefusedCallback::sole()->body, true)['TransID']);
    }

    #[Test]
    public function a_replay_that_records_nothing_is_not_marked_done_and_can_be_retried(): void
    {
        $this->busOn('880100');
        $this->postFrom('41.90.12.7', '/api/rest/mpesa/confirmation_new', $this->ncbaBody('NCBARETRY1'))->assertForbidden();
        $kept = RefusedCallback::sole();
        $this->assertTrue($kept->credentials_ok);

        // The receiver turns it down (credentials not configured in this box).
        config(['services.ncba.password' => '']);
        $this->artisan('payments:replay-refused', ['ids' => [$kept->id]])->assertFailed();
        $kept->refresh();
        $this->assertNull($kept->replayed_at, 'not recorded, so not done');
        $this->assertStringContainsString('declined', (string) $kept->replay_note);
        $this->assertNull($this->recorded('NCBARETRY1'));

        config(['services.ncba.password' => 'ncbapass']);
        $this->artisan('payments:replay-refused', ['ids' => [$kept->id]])->assertSuccessful();
        $this->assertNotNull($this->recorded('NCBARETRY1'));
        $this->assertNotNull($kept->refresh()->replayed_at);
    }

    #[Test]
    public function replaying_a_forged_ncba_post_does_not_sign_it_in(): void
    {
        $this->busOn('880100');
        $this->postFrom('41.90.12.7', '/api/rest/mpesa/confirmation_new', $this->ncbaBody('NCBAFORGE1', 'guess'))->assertForbidden();
        $kept = RefusedCallback::sole();
        $this->assertFalse($kept->credentials_ok);

        $this->artisan('payments:replay-refused', ['ids' => [$kept->id]])->assertFailed();

        $this->assertNull($this->recorded('NCBAFORGE1'));
        $this->assertNull($kept->refresh()->replayed_at);
    }

    #[Test]
    public function a_conflicting_forwarded_header_does_not_lose_a_safaricom_payment(): void
    {
        $bus = $this->busOn('1417368');

        $this->withServerVariables(['REMOTE_ADDR' => '172.31.14.2'])
            ->withHeaders(['X-Forwarded-For' => self::SAFARICOM, 'Forwarded' => 'for=10.9.8.7'])
            ->postJson('/api/confirmation/16', $this->confirmation('UKPFWD0001'))
            ->assertOk();

        $this->assertSame($bus->id, (int) $this->recorded('UKPFWD0001')?->vehicle_id);
    }

    #[Test]
    public function the_wallet_confirmation_is_never_throttled_like_the_other_money_routes(): void
    {
        $request = Request::create('/api/wallet/c2b/'.str_repeat('a', 40).'/confirmation', 'POST');
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));

        $this->assertTrue(MoneyIngestion::matches($request));
    }

    #[Test]
    public function a_coop_credit_in_prose_is_verified_under_the_banks_own_id(): void
    {
        $this->postFrom('41.90.12.7', '/api/coop/payments', [
            'Amount' => '5000', 'TransactionDate' => '2026-11-25+10:00:00', 'EventType' => 'CREDIT',
            'TransactionId' => 'CB0001_25112026_2', 'Narration' => 'SACCO FLOAT TOP UP',
        ])->assertForbidden();

        $this->artisan('payments:replay-refused', ['--ip' => '41.90.12.7'])->assertSuccessful();

        $this->assertNotNull(RefusedCallback::sole()->replayed_at);
        $this->assertNotNull($this->recorded('CB0001_25112026_2'));
    }

    #[Test]
    public function a_coop_debit_is_done_with_nothing_to_record(): void
    {
        $this->postFrom('41.90.12.7', '/api/coop/payments', [
            'Amount' => '300', 'TransactionDate' => '2026-11-25+10:00:00', 'EventType' => 'DEBIT',
            'TransactionId' => 'CB0002', 'Narration' => 'EXCISE DUTY',
        ])->assertForbidden();

        $this->artisan('payments:replay-refused', ['--ip' => '41.90.12.7'])->assertSuccessful();

        $this->assertStringContainsString('debit', (string) RefusedCallback::sole()->replay_note);
    }

    #[Test]
    public function a_billing_payment_with_no_invoice_is_not_marked_done(): void
    {
        $this->postFrom('41.90.12.7', '/api/testing/billing/mpesa/confirmation', [
            'BillRefNumber' => 'INV-NOPE', 'TransAmount' => 800, 'TransID' => 'BILLNOINV1',
        ])->assertForbidden();

        $this->artisan('payments:replay-refused', ['--ip' => '41.90.12.7'])->assertFailed();

        $this->assertNull(RefusedCallback::sole()->replayed_at);
    }

    #[Test]
    public function a_payment_saved_but_not_in_any_takings_is_completed_by_the_replay(): void
    {
        $bus = $this->busOn('1417368');
        $this->postFrom('41.90.12.7', '/api/confirmation/16', $this->confirmation('UKPHALF001'))->assertForbidden();
        // An earlier attempt got as far as the mpesas row and no further.
        Mpesa::forceCreate(['TransID' => 'UKPHALF001', 'TransAmount' => 100, 'BusinessShortCode' => '1417368',
            'MSISDN' => '2547XXXXX', 'TransTime' => now()]);

        $this->artisan('payments:replay-refused', ['--ip' => '41.90.12.7'])->assertSuccessful();

        $this->assertSame($bus->id, (int) $this->recorded('UKPHALF001')?->vehicle_id);
    }

    #[Test]
    public function an_ncba_xml_post_sent_as_a_form_is_kept_as_it_came_and_replays(): void
    {
        $this->busOn('880100');
        $xml = '<?xml version="1.0"?><Request><Username>ncbauser</Username><Password>ncbapass</Password>'
            .'<TransID>NCBAXML001</TransID><TransAmount>500</TransAmount><BusinessShortCode>880100</BusinessShortCode>'
            .'<TransTime>20261125120000</TransTime><Mobile>254700111222</Mobile><BillRefNumber>948948</BillRefNumber>'
            .'<Name>John Doe</Name></Request>';
        parse_str($xml, $asForm); // what PHP-FPM makes of XML labelled as a form

        $this->withServerVariables(['REMOTE_ADDR' => '41.90.12.7'])
            ->call('POST', '/api/mpesa/confirmation_new', $asForm, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $xml)
            ->assertForbidden();

        $kept = RefusedCallback::sole();
        $this->assertTrue($kept->credentials_ok);
        $this->assertStringNotContainsString('ncbapass', (string) $kept->body);
        $this->assertStringContainsString('<TransID>NCBAXML001</TransID>', (string) $kept->body);

        $this->artisan('payments:replay-refused', ['ids' => [$kept->id]])->assertSuccessful();
        $this->assertNotNull($this->recorded('NCBAXML001'));
    }

    #[Test]
    public function an_empty_list_switches_that_check_off(): void
    {
        config(['services.safaricom.source_ips' => []]);
        $this->busOn('1417368');

        $this->postFrom('41.90.12.7', '/api/confirmation/16', $this->confirmation('OPEN000001'))->assertOk();

        $this->assertNotNull($this->recorded('OPEN000001'));
    }
}
