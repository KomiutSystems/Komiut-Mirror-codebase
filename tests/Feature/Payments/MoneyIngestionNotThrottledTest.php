<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Http\Controllers\APIs\MpesaPaymentsController;
use App\Support\Http\MoneyIngestion;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The C2B confirmation URL must never answer HTTP 429.
 *
 * THE INCIDENT THIS PINS. The `api` middleware group carries `throttle:api`:
 * 60 requests per minute, keyed on the caller's IP. Every till in the fleet is
 * registered against a legacy forwarder, so all ~17,000 daily confirmations
 * reach this host from ONE address. The limiter could not see 800 matatus — it
 * saw one client — and began refusing the fleet's own payments at morning peak.
 *
 * On 2026-08-31 that produced 879 HTTP 429s between 06:10 and 09:40 EAT, and
 * 1,379 payments legacy holds are absent here. KDX 439C alone lost 16 fares
 * (KES 850) between 06:38 and 07:44; NICCO's finance officer found it because
 * the till balance on the M-Pesa portal disagreed with the dashboard.
 *
 * WHY IT WAS UNRECOVERABLE, and why this test sits at the HTTP layer rather than
 * in the controller: ThrottleRequests answers before the route handler runs. So
 * C2bConfirmationController never executed — including its very first statement,
 * the raw-body MpesaLog write that exists precisely so a payment dropped further
 * down can be rebuilt. A 429 leaves nothing at all behind. Zero of those 16
 * receipts appear in `mpesa_logs`.
 *
 * The cap is therefore removed rather than raised — see MoneyIngestion for why
 * any finite number is a bet the fleet eventually loses.
 */
final class MoneyIngestionNotThrottledTest extends QueueTestCase
{
    /** Comfortably past the 60/min the `api` limiter used to apply. */
    private const BEYOND_THE_OLD_CAP = 90;

    /** @param array<string,mixed> $override */
    private function payload(array $override = []): array
    {
        return array_merge([
            'TransactionType' => 'Customer Merchant Payment',
            'TransID' => 'UHQQ349A09',
            'TransTime' => '20260831071500',
            'TransAmount' => '50.00',
            'BusinessShortCode' => '4560045',
            'MSISDN' => '254700111222',
            'FirstName' => 'WANJIKU',
            'LastName' => 'KAMAU',
        ], $override);
    }

    /** The production shape: every till's traffic from one forwarding host. */
    private function postFromForwarder(string $url, array $body): int
    {
        return $this->call('POST', $url, $body, [], [], ['REMOTE_ADDR' => '13.201.15.163'])
            ->getStatusCode();
    }

    #[Test]
    public function a_peak_burst_of_confirmations_from_one_ip_is_never_refused(): void
    {
        $statuses = [];

        for ($i = 0; $i < self::BEYOND_THE_OLD_CAP; $i++) {
            $statuses[] = $this->postFromForwarder(
                '/api/confirmation/3',
                $this->payload(['TransID' => 'UHVBURST'.$i])
            );
        }

        $this->assertNotContains(
            429,
            $statuses,
            'a refused confirmation is a fare deleted with no trace — see the class docblock'
        );

        // Asserted as well as the absence of 429, so this test cannot pass
        // vacuously: a route that 404'd would satisfy "no 429" while proving
        // nothing about throttling.
        $this->assertSame([200], array_values(array_unique($statuses)));
    }

    #[Test]
    public function the_validation_url_is_exempt_too(): void
    {
        // Safaricom fails the whole transaction when a registered ValidationURL
        // does not answer, so throttling this one declines the payment outright,
        // before any money moves at all.
        $statuses = [];

        for ($i = 0; $i < self::BEYOND_THE_OLD_CAP; $i++) {
            $statuses[] = $this->postFromForwarder('/api/validation/3', []);
        }

        $this->assertNotContains(429, $statuses);
        $this->assertSame([200], array_values(array_unique($statuses)));
    }

    #[Test]
    public function ordinary_api_routes_still_carry_the_per_ip_limit(): void
    {
        // The exemption has to be surgical. Dropping the per-IP backstop across
        // the whole API would be a worse trade than the bug it fixes.
        $sawThrottle = false;

        for ($i = 0; $i < 200; $i++) {
            if ($this->getJson('/api/up')->getStatusCode() === 429) {
                $sawThrottle = true;
                break;
            }
        }

        $this->assertTrue($sawThrottle, 'the 60/min per-IP cap must still apply off the money path');
    }

    #[Test]
    public function an_stk_push_trigger_is_not_treated_as_ingestion(): void
    {
        // STK push is OUTBOUND: it raises a real PIN prompt on a member's
        // handset. Exempting it would hand anyone an unmetered way to spam
        // prompts at arbitrary numbers. Only inbound callbacks are exempt, which
        // is why the list matches Controller@method and not a controller class.
        $exempt = $this->exemptHandlers();

        $this->assertNotContains(MpesaPaymentsController::class.'@customerMpesaSTKPush', $exempt);
        $this->assertNotContains(MpesaPaymentsController::class.'@customerQRCodeSTKPush', $exempt);
    }

    #[Test]
    public function every_exempt_handler_still_exists(): void
    {
        // A renamed method drops silently out of the exemption and puts that
        // callback back behind the 60/min cap — this bug returning by accident,
        // and it would show up as missing money, not as a failure.
        foreach ($this->exemptHandlers() as $action) {
            [$class, $method] = explode('@', $action);

            $this->assertTrue(class_exists($class), $class.' no longer exists');
            $this->assertTrue(
                method_exists($class, $method),
                $action.' is listed as exempt but no longer exists — that route is capped again'
            );
        }
    }

    /** @return list<string> */
    private function exemptHandlers(): array
    {
        return (new \ReflectionClass(MoneyIngestion::class))->getConstant('HANDLERS');
    }
}
