<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * NOT '*'. Laravel turns '*' into 0.0.0.0/0 -- every address on earth is a
     * trusted proxy -- and Symfony then takes the LEFTMOST X-Forwarded-For
     * entry as the client. That is the entry the caller wrote, so with '*'
     * `$request->ip()` is whatever the caller says it is: the login throttle,
     * the driver-login lockout, the onboarding velocity check and the bank
     * source allowlist would all be keyed on a value a script chooses.
     *
     * What actually sits in front of PHP is the nginx container (REMOTE_ADDR,
     * a Docker address) with the ALB in front of it; both are on private
     * ranges, and the ALB APPENDS the address it saw to X-Forwarded-For. With
     * only the private ranges trusted, the rightmost entry that is not one of
     * them is the real client, and a forged prefix is ignored.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        '127.0.0.1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */

    protected $headers =
        Request::HEADER_FORWARDED |
        Request::HEADER_X_FORWARDED_PREFIX |
        Request::HEADER_X_FORWARDED_TRAEFIK |
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
