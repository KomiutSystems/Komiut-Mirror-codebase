<?php

namespace Tests;

use App\Brands\BrandRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // The ResolveBrand middleware fails closed on an unknown host/app-key, and
        // feature tests hit brand-scoped routes as `localhost`. Register a default
        // test brand bound to the test host, on the sqlite test connection, so the
        // whole suite resolves a brand without every test having to send a header.
        // Tests that specifically exercise resolution override this catalogue.
        config(['brands' => [
            'testing' => [
                'name' => 'Testing',
                'connection' => config('database.default'),
                'hosts' => ['localhost', '127.0.0.1'],
                'app_key' => 'testing-app-key',
                'features' => ['parcels' => true, 'carpool' => true],
            ],
        ]]);

        $this->app->forgetInstance(BrandRegistry::class);
    }
}
