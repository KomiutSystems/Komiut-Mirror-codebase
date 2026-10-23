<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Queues\QueueTestCase;

/**
 * GET /api/up decides whether the ASG keeps a server. It must answer from PHP
 * alone: a dependency in it turns one Redis outage into every target failing
 * at once and the whole fleet being replaced.
 */
final class HealthProbeTest extends QueueTestCase
{
    #[Test]
    public function the_health_probe_answers_while_redis_is_down(): void
    {
        // The api group's rate limiter counts every request in the cache
        // store, which is Redis in production. Make that store unreachable.
        $down = new class extends ArrayStore
        {
            public function get($key): mixed
            {
                throw new RuntimeException('Connection refused [tcp://redis:6379]');
            }

            public function add($key, $value, $seconds): bool
            {
                throw new RuntimeException('Connection refused [tcp://redis:6379]');
            }

            public function increment($key, $value = 1): int|bool
            {
                throw new RuntimeException('Connection refused [tcp://redis:6379]');
            }
        };
        $this->app->instance(RateLimiter::class, new RateLimiter(new Repository($down)));

        $this->getJson('/api/up')->assertOk()->assertJson(['status' => 'ok']);
    }
}
