<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the bank may post on a bank webhook that carries no credential.
 *
 * NCBA signs in with a bank-issued username and password. Co-op sends
 * nothing: an HTTPS POST with a narration in it, and whatever is in that
 * narration becomes a bus's takings. Anyone who learns the URL -- it has
 * been in a Blade view, a bank contract and a Caddy log -- can write money
 * that never arrived into a SACCO's books. The one fact that separates the
 * bank from everyone else is where the POST comes from, so that is what is
 * checked here.
 *
 * `services.{bank}.source_ips` lists the bank's egress addresses (CIDR
 * allowed). Empty means the check is OFF and the route is as open as it was:
 * a list is switched on deliberately, with an address the bank confirmed,
 * because a wrong one refuses real payments. Refused posts are logged with
 * the source and the narration so nothing is lost while the list is wrong.
 *
 * The address is `$request->ip()`: the rightmost X-Forwarded-For entry that is
 * not a trusted proxy, which the ALB appends and a caller cannot forge.
 */
final class BankSourceAllowlist
{
    public function handle(Request $request, Closure $next, string $bank): Response
    {
        $allowed = (array) config("services.{$bank}.source_ips", []);

        if ($allowed === [] || IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return $next($request);
        }

        Log::warning('bank webhook refused: not from the bank', [
            'bank' => $bank,
            'ip' => $request->ip(),
            'path' => $request->path(),
            'narration' => $request->input('Narration'),
            'amount' => $request->input('Amount'),
        ]);

        return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
    }
}
