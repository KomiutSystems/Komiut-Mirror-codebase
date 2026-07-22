<?php

declare(strict_types=1);

namespace Tests\Feature\Brands;

use App\Brands\Brand;
use App\Brands\BrandRegistry;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * End-to-end behavior of the ResolveBrand middleware — the contract the mobile
 * apps wire against. Uses a throwaway route so it exercises the middleware, the
 * registry, and brand activation without depending on any real endpoint.
 */
final class ResolveBrandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A known two-brand catalogue, independent of env-driven config values.
        config(['brands' => [
            'komiut' => [
                'name' => 'Komiut',
                'connection' => 'komiut',
                'hosts' => ['komiut.co.ke', 'www.komiut.co.ke'],
                'app_key' => 'komiut-key-123',
                'features' => ['parcels' => true],
            ],
            'safiri' => [
                'name' => '2Safiri',
                'connection' => 'safiri',
                'hosts' => ['2safiri.co.ke'],
                'app_key' => 'safiri-key-456',
                'features' => ['parcels' => false],
            ],
        ]]);

        // Rebuild the registry singleton against the test catalogue.
        $this->app->forgetInstance(BrandRegistry::class);

        Route::middleware('brand')->get('/_brand-probe', function () {
            return response()->json([
                'brand' => app(Brand::class)->key,
                'connection' => DB::getDefaultConnection(),
                'context' => Context::get('brand'),
            ]);
        });
    }

    public function test_mobile_app_key_header_resolves_the_brand(): void
    {
        $this->withHeader('X-App-Key', 'safiri-key-456')
            ->getJson('/_brand-probe')
            ->assertOk()
            ->assertJson(['brand' => 'safiri', 'connection' => 'safiri', 'context' => 'safiri']);
    }

    public function test_hostname_resolves_the_brand_for_web(): void
    {
        $this->get('http://komiut.co.ke/_brand-probe')
            ->assertOk()
            ->assertJson(['brand' => 'komiut', 'connection' => 'komiut']);
    }

    public function test_app_key_takes_precedence_over_host(): void
    {
        // App key says safiri even though the host is komiut's — the key wins.
        $this->withHeader('X-App-Key', 'safiri-key-456')
            ->get('http://komiut.co.ke/_brand-probe')
            ->assertOk()
            ->assertJson(['brand' => 'safiri']);
    }

    public function test_unknown_app_key_fails_closed_with_404(): void
    {
        $this->withHeader('X-App-Key', 'not-a-real-key')
            ->getJson('/_brand-probe')
            ->assertNotFound();
    }

    public function test_unknown_host_fails_closed_with_404(): void
    {
        $this->get('http://evil.example.com/_brand-probe')
            ->assertNotFound();
    }

    public function test_no_brand_signal_at_all_fails_closed(): void
    {
        // getHost() defaults to 'localhost', which is not a configured host.
        $this->getJson('/_brand-probe')->assertNotFound();
    }
}
