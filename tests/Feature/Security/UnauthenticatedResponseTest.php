<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An unauthenticated request must be told it is unauthenticated.
 *
 * The stock Authenticate middleware redirects to `route('login')` for anything
 * that does not ask for JSON. There is no login route here -- this service is
 * an API and App\Http\Kernel defines no `web` group -- so that call threw
 * RouteNotFoundException and the caller got 500 plus a stack trace in the log.
 *
 * It hid for a long time because the only clients anyone tested with (the
 * mobile app, the dashboard) send `Accept: application/json`, which
 * short-circuited the redirect. A browser, a curl smoke test or an uptime
 * monitor did not, and got a server error where 401 belonged.
 */
final class UnauthenticatedResponseTest extends TestCase
{
    use RefreshDatabase;

    /** Protected routes across three different groups. */
    public static function protectedRoutes(): array
    {
        return [
            'driver trip' => ['/api/v1/auth/driver/trip'],
            'driver home' => ['/api/v1/auth/driver/home'],
            'dashboard queues' => ['/api/v1/auth/queues'],
        ];
    }

    #[Test]
    #[DataProvider('protectedRoutes')]
    public function a_json_client_gets_401(string $uri): void
    {
        $this->getJson($uri)->assertStatus(401);
    }

    #[Test]
    #[DataProvider('protectedRoutes')]
    public function a_client_that_does_not_ask_for_json_also_gets_401(string $uri): void
    {
        // No Accept header at all -- the case that used to 500.
        $this->get($uri)->assertStatus(401);
    }
}
