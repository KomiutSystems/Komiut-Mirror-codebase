<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Jobs\MirrorConfirmationToLegacy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Copying each C2B confirmation back to legacy, so a rollback stays possible.
 *
 * Repointing payments.komiut.com at Frankfurt is a one-record DNS change with a
 * 60-second TTL, so rolling forward is trivial. Rolling BACK is only trivial while
 * legacy's ledger has no hole: from the instant that record moves, komiut_payments
 * receives nothing, and every minute on Frankfurt is a minute legacy cannot
 * account for. Roll back after an hour and the two ledgers can never be
 * reconciled against each other again — which is the single check that has been
 * telling us this migration is safe.
 *
 * THE FIRST TEST IS THE IMPORTANT ONE. services.legacy_payments.url defaults to
 * `https://payments.komiut.com` — the exact hostname being repointed. The moment
 * the DNS moves, that URL is US. Mirroring to it would post every confirmation to
 * ourselves, which records it, which mirrors it again: an unbounded loop on the
 * money path that would read as traffic rather than as a bug. The guard checks
 * the target against the brand registry's own host list, so it starts protecting
 * as soon as the hostname is LISTED — which happens before its DNS moves, not
 * after.
 */
final class MirrorConfirmationToLegacyTest extends QueueTestCase
{
    private const URL = '/api/confirmation/4';

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'TransactionType' => 'Customer Merchant Payment',
            'TransID' => 'MIRROR001',
            'TransTime' => '20260909031000',
            'TransAmount' => '50.00',
            'BusinessShortCode' => '7100466',
            'BillRefNumber' => '',
            'MSISDN' => '254700111222',
            'FirstName' => 'WANJIKU',
        ];
    }

    #[Test]
    public function it_refuses_to_mirror_to_a_hostname_this_application_serves(): void
    {
        // The loop. Configure the target as a host we answer on -- which is what
        // the DEFAULT becomes after the flip -- and the job must refuse rather
        // than post, and must not retry, because retrying a self-post loops harder.
        config([
            'services.legacy_payments.mirror' => true,
            'services.legacy_payments.url' => 'https://payments.komiut.com',
            'brands' => ['komiut' => ['name' => 'Komiut', 'hosts' => ['api.komiut.com', 'payments.komiut.com']]],
        ]);
        Http::fake();
        Log::spy();

        (new MirrorConfirmationToLegacy($this->payload(), '4'))->handle();

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $m, array $c): bool => str_contains($m, 'refused')
                && $c['target'] === 'https://payments.komiut.com');
    }

    #[Test]
    public function it_posts_to_a_hostname_that_still_resolves_to_mumbai(): void
    {
        config([
            'services.legacy_payments.mirror' => true,
            'services.legacy_payments.url' => 'https://legacy-payments.komiut.com',
            'brands' => ['komiut' => ['name' => 'Komiut', 'hosts' => ['api.komiut.com', 'payments.komiut.com']]],
        ]);
        Http::fake(['*' => Http::response(['C2BPaymentConfirmationResult' => 'Success'], 200)]);

        (new MirrorConfirmationToLegacy($this->payload(), '4'))->handle();

        Http::assertSent(function ($request): bool {
            // The SAME path shape legacy already answers, and the body verbatim --
            // legacy dedupes on TransID, so the copy has to carry it.
            return $request->url() === 'https://legacy-payments.komiut.com/api/confirmation/4'
                && $request['TransID'] === 'MIRROR001'
                && $request['BusinessShortCode'] === '7100466';
        });
    }

    #[Test]
    public function it_sends_nothing_at_all_while_the_mirror_is_switched_off(): void
    {
        // Off is the default, and it stays off until the DNS flip. A mirror
        // running before the cutover would double-write legacy from both sides.
        config([
            'services.legacy_payments.mirror' => false,
            'services.legacy_payments.url' => 'https://legacy-payments.komiut.com',
        ]);
        Http::fake();

        (new MirrorConfirmationToLegacy($this->payload(), '4'))->handle();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_confirmation_queues_the_mirror_without_delaying_the_ack(): void
    {
        // Queued, not inline: Safaricom retries anything slow, and the ack is the
        // receipt rather than the outcome.
        //
        // Asserted through Bus, not Queue: QueueTestCase::setUp() calls
        // Bus::fake(), so a dispatch is captured by the fake BUS and never
        // reaches the queue at all -- Queue::assertPushed sees nothing and reads
        // as "the controller never dispatched", which is not what happened.
        $response = $this->postJson(self::URL, $this->payload());

        $response->assertOk();
        Bus::assertDispatched(MirrorConfirmationToLegacy::class, function (MirrorConfirmationToLegacy $job): bool {
            // The setting id from the URL has to travel with the copy, or the
            // mirror lands on a different path at the other end.
            return $job->settingId === '4'
                && $job->fields['TransID'] === 'MIRROR001';
        });
    }

    #[Test]
    public function the_mirror_is_queued_even_when_recording_the_payment_fails(): void
    {
        // Legacy's copy matters MOST when ours went wrong -- it is the ledger we
        // would roll back to. A payload with no TransID fails C2bPaymentRecorder
        // ("missing TransID") and must still be mirrored.
        $broken = $this->payload();
        unset($broken['TransID']);

        $this->postJson(self::URL, $broken)->assertOk();

        Bus::assertDispatched(MirrorConfirmationToLegacy::class);
    }

    #[Test]
    public function it_retries_rather_than_dropping_the_copy(): void
    {
        // The failure mode this whole job exists to avoid: legacy's own relay is
        // timeout(1)/connectTimeout(1), no retry, catch-and-ignore, and the Co-op
        // one has been posting to a dead ngrok tunnel since June. Silence is how
        // both went unnoticed.
        $job = new MirrorConfirmationToLegacy($this->payload(), '4');

        $this->assertSame(5, $job->tries);
        $this->assertSame([10, 30, 120, 300], $job->backoff);
    }

    #[Test]
    public function a_queue_outage_never_turns_a_received_payment_into_a_failed_ack(): void
    {
        // If dispatch throws, Safaricom must still get its Success. A 500 here
        // makes Safaricom retry a payment we have already accepted and written.
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('redis is gone'));
        Log::spy();

        $this->postJson(self::URL, $this->payload())->assertOk();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $m, array $c): bool => str_contains($m, 'could not queue the legacy mirror'));
    }
}
