<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Super\Fraud\PartnerAccessOrigins;
use App\Services\Super\Fraud\PartnerAuthBurst;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a partner bank by the key on its shared link.
 *
 * The key is the identity: it selects the partner, and the partner fixes the
 * brand whose leads are readable. NCBA's key cannot return 2Safiri drivers even
 * if the request asks for them, because the brand is never taken from input.
 *
 * Fails closed. A partner with no configured password is not a partner with a
 * blank password — an unset env var must not turn into an open door, which is
 * the classic way a "temporarily unconfigured" portal ships wide open.
 *
 * Comparison is constant-time so a wrong key leaks nothing through timing.
 */
class BankPartnerAuth
{
    public const PARTNER = 'bank_partner';

    public function handle(Request $request, Closure $next): Response
    {
        $presented = (string) ($request->header('X-Partner-Key') ?? $request->input('partner_key', ''));

        if ($presented === '') {
            return response()->json(['error' => 'A partner key is required.'], 401);
        }

        $partner = $this->resolve($presented);

        if ($partner === null) {
            // A rejected key is the only visible sign of someone guessing their
            // way into a bank's driver list. Bucketed by source IP — the key
            // does not resolve to a partner, and is never stored.
            app(PartnerAuthBurst::class)->recordFailure($request->ip());

            return response()->json(['error' => 'Invalid partner key.'], 401);
        }

        $request->attributes->set(self::PARTNER, $partner);

        // A leaked shared key surfaces first as a login from a new place: flag a
        // successful auth from an IP not seen for this partner in 30 days.
        app(PartnerAccessOrigins::class)->record($partner, $request->ip());

        return $next($request);
    }

    /**
     * The partner whose configured password matches, or null.
     *
     * Every candidate is compared even after a match so the work done does not
     * depend on which partner presented the key.
     *
     * @return array{key: string, brand: string, label: string}|null
     */
    private function resolve(string $presented): ?array
    {
        $matched = null;

        foreach ((array) config('bank_portal.partners') as $key => $partner) {
            $password = (string) ($partner['password'] ?? '');

            if ($password === '') {
                continue; // unconfigured partner — never matchable
            }

            if (hash_equals($password, $presented)) {
                $matched = [
                    'key' => (string) $key,
                    'brand' => (string) $partner['brand'],
                    'label' => (string) ($partner['label'] ?? $key),
                ];
            }
        }

        return $matched;
    }
}
