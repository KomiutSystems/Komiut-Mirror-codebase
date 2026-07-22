<?php

declare(strict_types=1);

namespace Tests\Unit\Brands;

use App\Brands\Brand;
use App\Brands\BrandRegistry;
use PHPUnit\Framework\TestCase;

final class BrandRegistryTest extends TestCase
{
    private function registry(): BrandRegistry
    {
        return new BrandRegistry([
            'komiut' => [
                'name' => 'Komiut',
                'connection' => 'komiut',
                'hosts' => ['portal.komiut.co.ke', 'komiut.test'],
                'app_key' => 'komiut-app-key',
                'features' => ['parcels' => true],
                'session' => ['cookie' => 'komiut_session', 'domain' => '.komiut.co.ke'],
            ],
            'safiri' => [
                'name' => '2Safiri',
                'connection' => 'safiri',
                'hosts' => ['portal.2safiri.co.ke'],
                'app_key' => 'safiri-app-key',
                'features' => ['carpool' => true],
                'session' => ['cookie' => 'safiri_session', 'domain' => '.2safiri.co.ke'],
            ],
        ]);
    }

    public function test_all_returns_every_brand_keyed_by_brand_key(): void
    {
        $all = $this->registry()->all();

        self::assertSame(['komiut', 'safiri'], array_keys($all));
        self::assertContainsOnlyInstancesOf(Brand::class, $all);
    }

    public function test_get_returns_a_brand_by_key_and_null_otherwise(): void
    {
        $registry = $this->registry();

        self::assertSame('safiri', $registry->get('safiri')?->key);
        self::assertNull($registry->get('nairobi-express'));
    }

    public function test_resolves_by_host(): void
    {
        $registry = $this->registry();

        self::assertSame('komiut', $registry->resolveByHost('portal.komiut.co.ke')?->key);
        self::assertSame('komiut', $registry->resolveByHost('komiut.test')?->key);
        self::assertSame('safiri', $registry->resolveByHost('portal.2safiri.co.ke')?->key);
    }

    public function test_resolves_by_host_ignoring_case_and_port(): void
    {
        $registry = $this->registry();

        self::assertSame('komiut', $registry->resolveByHost('PORTAL.Komiut.CO.KE')?->key);
        self::assertSame('komiut', $registry->resolveByHost('komiut.test:8080')?->key);
    }

    public function test_unknown_host_fails_closed(): void
    {
        $registry = $this->registry();

        self::assertNull($registry->resolveByHost('evil.example.com'));
        self::assertNull($registry->resolveByHost('sub.portal.komiut.co.ke'));
        self::assertNull($registry->resolveByHost(''));
        self::assertNull($registry->resolveByHost(null));
    }

    public function test_resolves_by_app_key(): void
    {
        $registry = $this->registry();

        self::assertSame('komiut', $registry->resolveByAppKey('komiut-app-key')?->key);
        self::assertSame('safiri', $registry->resolveByAppKey('safiri-app-key')?->key);
    }

    public function test_unknown_or_empty_app_key_fails_closed(): void
    {
        $registry = $this->registry();

        self::assertNull($registry->resolveByAppKey('not-a-real-key'));
        self::assertNull($registry->resolveByAppKey('komiut-app-ke'));
        self::assertNull($registry->resolveByAppKey('KOMIUT-APP-KEY'));
        self::assertNull($registry->resolveByAppKey(''));
        self::assertNull($registry->resolveByAppKey(null));
    }

    public function test_brands_without_a_configured_app_key_never_match(): void
    {
        $registry = new BrandRegistry([
            'komiut' => [
                'name' => 'Komiut',
                'connection' => 'komiut',
                'hosts' => ['portal.komiut.co.ke'],
                'app_key' => null,
            ],
            'safiri' => [
                'name' => '2Safiri',
                'connection' => 'safiri',
                'hosts' => ['portal.2safiri.co.ke'],
                'app_key' => '',
            ],
        ]);

        self::assertNull($registry->resolveByAppKey(''));
        self::assertNull($registry->resolveByAppKey(null));
        self::assertNull($registry->resolveByAppKey('0'));
        self::assertSame('komiut', $registry->resolveByHost('portal.komiut.co.ke')?->key);
    }

    public function test_an_empty_registry_resolves_nothing(): void
    {
        $registry = new BrandRegistry([]);

        self::assertSame([], $registry->all());
        self::assertNull($registry->resolveByHost('portal.komiut.co.ke'));
        self::assertNull($registry->resolveByAppKey('komiut-app-key'));
    }
}
