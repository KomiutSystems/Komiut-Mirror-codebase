<?php

declare(strict_types=1);

namespace Tests\Unit\Brands;

use App\Brands\Brand;
use App\Brands\Feature;
use PHPUnit\Framework\TestCase;

final class BrandTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'name' => 'Komiut',
            'connection' => 'komiut',
            'hosts' => ['portal.komiut.co.ke'],
            'app_key' => 'komiut-app-key',
            'features' => [
                'parcels' => true,
                'carpool' => false,
            ],
            'session' => [
                'cookie' => 'komiut_session',
                'domain' => '.komiut.co.ke',
            ],
        ];
    }

    public function test_from_config_maps_every_property(): void
    {
        $brand = Brand::fromConfig('komiut', $this->config());

        self::assertSame('komiut', $brand->key);
        self::assertSame('Komiut', $brand->name);
        self::assertSame('komiut_session', $brand->sessionCookie);
        self::assertSame('.komiut.co.ke', $brand->sessionDomain);
        self::assertSame(['parcels' => true, 'carpool' => false], $brand->features);
    }

    public function test_from_config_tolerates_a_minimal_definition(): void
    {
        $brand = Brand::fromConfig('safiri', ['connection' => 'safiri']);

        self::assertSame('safiri', $brand->key);
        self::assertSame('safiri', $brand->name);
        self::assertSame([], $brand->features);
        self::assertNull($brand->sessionCookie);
        self::assertNull($brand->sessionDomain);
    }

    public function test_has_reports_enabled_and_disabled_features(): void
    {
        $brand = Brand::fromConfig('komiut', $this->config());

        self::assertTrue($brand->has(Feature::Parcels));
        self::assertFalse($brand->has(Feature::Carpool));
    }

    public function test_has_defaults_to_false_for_unconfigured_features(): void
    {
        $brand = Brand::fromConfig('komiut', $this->config());

        self::assertFalse($brand->has(Feature::Wallet));
        self::assertFalse($brand->has(Feature::Bookings));
        self::assertFalse($brand->has(Feature::Loyalty));
    }

    public function test_every_feature_case_is_addressable_by_its_backing_value(): void
    {
        $features = [];

        foreach (Feature::cases() as $case) {
            $features[$case->value] = true;
        }

        $brand = Brand::fromConfig('komiut', ['features' => $features]);

        foreach (Feature::cases() as $case) {
            self::assertTrue($brand->has($case), "Feature {$case->value} should be enabled.");
        }
    }
}
