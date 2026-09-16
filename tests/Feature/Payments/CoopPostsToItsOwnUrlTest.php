<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Brands\BrandRegistry;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\Vehicle;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Co-operative Bank posts to https://bankpayments.komiut.com/api/coop/payments.
 *
 * Not to /api/{brand}/coop/mpesa: that is the path the legacy MAIN app
 * exposed, and the bank was never pointed at it. The bank was given a
 * separate host with a separate one-route app behind it, and it will go on
 * posting to that exact URL after the DNS record moves here. So this system
 * must answer on that host and that path, or the day the record moves,
 * ~400 payments a day 404 and the bank -- which does not retry -- is gone.
 *
 * The bank also sends no credential. The only thing that says a POST is the
 * bank's is the address it comes from, which is the second half of this file.
 */
final class CoopPostsToItsOwnUrlTest extends QueueTestCase
{
    private const BANK_IP = '196.11.190.75';

    private function payment(string $transId, string $shortCode): array
    {
        return [
            'Amount' => '50',
            'TransactionDate' => '2026-09-16+07:53:37',
            'Narration' => implode('~', [$transId, $shortCode, '254700111222', 'JOYCE WANJIKU MWANGI', '2026-09-16 07:53:37']),
        ];
    }

    private function vehicleOn(string $shortCode): Vehicle
    {
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['merchant_short_code' => $shortCode])->save();

        return $world['vehicle'];
    }

    /** The brand answers on these hosts and no other, as KOMIUT_HOSTS does in production. */
    private function brandAnswersOn(array $hosts): void
    {
        config(['brands.testing.hosts' => $hosts]);
        $this->app->forgetInstance(BrandRegistry::class);
    }

    private function postFrom(string $ip, string $url, array $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($url, $body, $headers);
    }

    #[Test]
    public function a_payment_on_the_banks_url_is_recorded_exactly_as_one_on_the_brand_path(): void
    {
        $this->brandAnswersOn(['localhost', 'bankpayments.komiut.com']);
        $vehicle = $this->vehicleOn('6624890');

        $this->postJson('https://bankpayments.komiut.com/api/coop/payments', $this->payment('TIH5ABC123', '6624890'))
            ->assertOk()
            ->assertExactJson(['MessageCode' => '200', 'Message' => 'Successfully received data']);

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'TIH5ABC123')->first();
        $this->assertNotNull($mpesa, 'the payment must be recorded');
        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertNotNull($txn);
        $this->assertSame($vehicle->id, (int) $txn->vehicle_id, 'attributed by the shortcode in the narration');
    }

    #[Test]
    public function a_host_the_brand_does_not_list_is_refused_before_any_money_is_touched(): void
    {
        // This is what happens if DNS moves before KOMIUT_HOSTS gains the host.
        $this->brandAnswersOn(['localhost']);
        $this->vehicleOn('6624890');

        $this->postJson('https://bankpayments.komiut.com/api/coop/payments', $this->payment('TIH5LOST', '6624890'))
            ->assertNotFound();

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'TIH5LOST')->count());
    }

    #[Test]
    public function with_no_allowlist_the_receiver_is_open_as_it_has_always_been(): void
    {
        config(['services.coop.source_ips' => []]);
        $this->vehicleOn('6624890');

        $this->postFrom('41.90.1.1', '/api/coop/payments', $this->payment('TIH5OPEN', '6624890'))->assertOk();

        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'TIH5OPEN')->count());
    }

    #[Test]
    public function with_an_allowlist_only_the_bank_may_write_money(): void
    {
        config(['services.coop.source_ips' => [self::BANK_IP]]);
        $this->vehicleOn('6624890');

        $this->postFrom(self::BANK_IP, '/api/coop/payments', $this->payment('TIH5BANK', '6624890'))->assertOk();
        $this->postFrom('41.90.1.1', '/api/coop/payments', $this->payment('TIH5FAKE', '6624890'))
            ->assertForbidden();

        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'TIH5BANK')->count());
        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'TIH5FAKE')->count(), 'a forged payment is not takings');
    }

    #[Test]
    public function the_brand_path_is_guarded_the_same_way(): void
    {
        config(['services.coop.source_ips' => [self::BANK_IP]]);
        $this->vehicleOn('6624890');

        $this->postFrom('41.90.1.1', '/api/testing/coop/mpesa', $this->payment('TIH5FAKE2', '6624890'))
            ->assertForbidden();
        $this->postFrom(self::BANK_IP, '/api/testing/coop/mpesa', $this->payment('TIH5BANK2', '6624890'))
            ->assertOk();
    }

    #[Test]
    public function a_forged_forwarded_for_header_does_not_get_past_the_load_balancers_own_entry(): void
    {
        // In production the request reaches PHP from the ALB, which APPENDS the
        // address it actually saw to X-Forwarded-For. A caller who writes the
        // bank's address into the header themselves arrives as
        // "196.11.190.75, <their real address>", and it is the rightmost
        // untrusted entry that counts.
        config(['services.coop.source_ips' => [self::BANK_IP]]);
        $this->vehicleOn('6624890');

        $this->postFrom('10.0.1.5', '/api/coop/payments', $this->payment('TIH5SPOOF', '6624890'), [
            'X-Forwarded-For' => self::BANK_IP.', 41.90.1.1',
        ])->assertForbidden();

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'TIH5SPOOF')->count());
    }

    #[Test]
    public function a_cidr_covers_a_bank_that_posts_from_a_range(): void
    {
        config(['services.coop.source_ips' => ['196.11.190.0/24']]);
        $this->vehicleOn('6624890');

        $this->postFrom('196.11.190.200', '/api/coop/payments', $this->payment('TIH5CIDR', '6624890'))->assertOk();
        $this->postFrom('196.11.191.1', '/api/coop/payments', $this->payment('TIH5OUT', '6624890'))->assertForbidden();
    }
}
