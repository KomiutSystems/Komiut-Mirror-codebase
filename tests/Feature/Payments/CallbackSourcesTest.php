<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\PlatformNotification;
use App\Models\RefusedCallback;
use App\Models\Transaction;
use App\Models\Vehicle;
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

    #[Test]
    public function an_empty_list_switches_that_check_off(): void
    {
        config(['services.safaricom.source_ips' => []]);
        $this->busOn('1417368');

        $this->postFrom('41.90.12.7', '/api/confirmation/16', $this->confirmation('OPEN000001'))->assertOk();

        $this->assertNotNull($this->recorded('OPEN000001'));
    }
}
