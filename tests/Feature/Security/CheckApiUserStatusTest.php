<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\CheckAPIUserStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Queues\QueueTestCase;

/**
 * CheckAPIUserStatus is the `user_status_api` group middleware. It runs BEFORE
 * the route-level auth on the routes it guards, so it is the first thing an
 * anonymous caller hits. It read `Auth::user()->status` with no null guard, so
 * that caller dereferenced null and got a 500 where 401 belonged.
 *
 * These lock in the fix (null user => clean 401) while proving the active /
 * inactive account logic for a present user is unchanged.
 */
final class CheckApiUserStatusTest extends QueueTestCase
{
    /** A route registered inside the `user_status_api` group. */
    private const PROTECTED_ROUTE = '/api/v1/auth/book_a_ride/queues';

    /** Run the middleware in isolation, recording whether $next was reached. */
    private function passThrough(Request $request, bool &$reached): Response
    {
        $reached = false;

        return (new CheckAPIUserStatus)->handle($request, function () use (&$reached) {
            $reached = true;

            return response('ok', 200);
        });
    }

    #[Test]
    public function an_unauthenticated_json_request_gets_401_not_500(): void
    {
        $this->getJson(self::PROTECTED_ROUTE)->assertStatus(401);
    }

    #[Test]
    public function an_unauthenticated_request_without_json_also_gets_401(): void
    {
        // No Accept header -- the browser / curl / uptime-monitor case.
        $this->get(self::PROTECTED_ROUTE)->assertStatus(401);
    }

    #[Test]
    public function a_null_user_is_refused_with_a_clean_401_json(): void
    {
        // No authenticated user: the exact null-deref the guard now catches.
        $response = $this->passThrough(Request::create(self::PROTECTED_ROUTE), $reached);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($reached, 'A request with no user must not reach the route.');
    }

    #[Test]
    public function an_active_user_passes_through(): void
    {
        $this->actingAs($this->makeUser());

        $response = $this->passThrough(Request::create(self::PROTECTED_ROUTE), $reached);

        $this->assertTrue($reached, 'An active user must reach the route.');
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function an_inactive_user_is_refused_with_403(): void
    {
        $this->actingAs($this->makeUser(status: false));

        $response = $this->passThrough(Request::create(self::PROTECTED_ROUTE), $reached);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($reached, 'An inactive user must not reach the route.');
    }

    #[Test]
    public function a_user_linked_to_an_inactive_sacco_is_refused_with_403(): void
    {
        $this->actingAs($this->makeUser(sacco: $this->makeSacco(active: false)));

        $response = $this->passThrough(Request::create(self::PROTECTED_ROUTE), $reached);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($reached, 'A user on an inactive SACCO must not reach the route.');
    }
}
