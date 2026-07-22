<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Brands\BrandContext;
use App\Brands\BrandRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the white-label brand from a `{brand}` URL segment and activates it.
 *
 * This exists for the payment CALLBACK routes (NCBA / Coop / Daraja webhooks).
 * Those requests arrive from the banks' / Safaricom's servers with no
 * `X-App-Key` header and not on a brand hostname, so ResolveBrand cannot
 * identify them. Instead each brand registers its own callback URL that carries
 * the brand key in the path — e.g. `/api/komiut/rest/mpesa/confirmation` — and
 * this middleware reads that segment to pick the database.
 *
 * An unknown brand key FAILS CLOSED with a 404, for the same reason as
 * ResolveBrand: falling back to a default would serve one brand's data from
 * another's database.
 */
final class ResolveBrandFromRoute
{
    public function __construct(
        private readonly BrandRegistry $registry,
        private readonly BrandContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->route('brand');

        $brand = is_string($key) ? $this->registry->get($key) : null;

        if ($brand === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->context->apply($brand);

        return $next($request);
    }
}
