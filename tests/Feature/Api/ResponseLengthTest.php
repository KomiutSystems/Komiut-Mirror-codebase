<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * nginx compresses responses at or above gzip_min_length, and learns a
 * response's length only from Content-Length, which php-fpm never sends. So
 * the app says it: otherwise the tiny acknowledgements Safaricom, NCBA and
 * Co-op wait for would be compressed too.
 */
final class ResponseLengthTest extends QueueTestCase
{
    #[Test]
    public function the_health_probe_says_how_long_it_is(): void
    {
        $response = $this->get('/api/up')->assertOk();

        $this->assertSame((string) strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
    }

    #[Test]
    public function a_head_request_does_not_claim_an_empty_body(): void
    {
        $response = $this->call('HEAD', '/api/up');

        $this->assertNotSame('0', $response->headers->get('Content-Length'));
    }

    #[Test]
    public function a_safaricom_acknowledgement_says_how_long_it_is(): void
    {
        // From Safaricom's forwarder address, as MoneyIngestionNotThrottledTest does.
        $response = $this->call('POST', '/api/validation/3', [], [], [], ['REMOTE_ADDR' => '13.201.15.163']);

        $this->assertSame(200, $response->getStatusCode());
        $length = $response->headers->get('Content-Length');
        $this->assertSame((string) strlen((string) $response->getContent()), $length);
        $this->assertLessThan(1024, (int) $length, 'under nginx gzip_min_length, so it is never compressed');
    }
}
