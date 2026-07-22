<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Brands\BrandContext;
use App\Brands\BrandRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the white-label brand for a request and activates it before auth.
 *
 * Resolution order:
 *   1. `X-App-Key` header — how the mobile apps identify themselves. Each build
 *      carries its own key.
 *   2. Hostname — how the web dashboard identifies itself.
 *
 * An unknown host/key FAILS CLOSED with a 404. Falling back to a default brand
 * would serve one brand's data from another's database, so there is no default.
 *
 * This must run BEFORE authentication: the users table lives inside the brand
 * database, so we cannot authenticate until we know which database to use.
 */
final class ResolveBrand
{
    public function __construct(
        private readonly BrandRegistry $registry,
        private readonly BrandContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $brand = $this->registry->resolveByAppKey($request->header('X-App-Key'))
            ?? $this->registry->resolveByHost($request->getHost());

        if ($brand === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->context->apply($brand);

        return $next($request);
    }
}
