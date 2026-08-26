<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Which SACCO is this request acting on?
 *
 * Several dashboard endpoints accepted a `sacco_id` in the payload and preferred
 * it over the caller's own:
 *
 *     $saccoId = $request->filled('sacco_id') ? (int) $request->sacco_id : auth()->user()->currentSaccoId();
 *
 * On a READ that is mostly harmless, because SaccoScope narrows the query
 * afterwards and the caller gets nothing. On a WRITE it is the opposite of
 * harmless, and the scope makes it worse rather than better: updateOrCreate does
 * a scoped SELECT that can never match the victim's existing row, concludes
 * nothing is there, and INSERTS one stamped with the victim's sacco_id. The
 * tenant boundary turns a hijack into a clean create.
 *
 * That is not theoretical. It was reachable on saccos/fares/add — the fare a
 * SACCO charges its passengers — and on saccos/loyalty/save, where setting
 * another SACCO's redemption_threshold to 0 mints free rides on their buses.
 *
 * The parameter is kept because it is genuinely useful to a superadmin operating
 * on a SACCO's behalf. For everyone else, naming another SACCO is REFUSED rather
 * than quietly rewritten to their own — a silent fallback would tell the caller
 * they had written to the SACCO they asked for when they had not.
 */
trait ResolvesTenant
{
    /**
     * The SACCO this request may act on, or null when the caller named one they
     * have no business touching. Callers should treat null as 403.
     */
    protected function resolveSaccoId(Request $request, string $key = 'sacco_id'): ?int
    {
        $user = $request->user() ?? auth()->user();

        if ($user === null) {
            return null;
        }

        $own = $user->currentSaccoId();

        if (! $request->filled($key)) {
            return $own === null ? null : (int) $own;
        }

        $requested = (int) $request->input($key);

        // The platform tier operates across SACCOs by design — that is what the
        // parameter is for.
        if ($user->isSuperAdmin()) {
            return $requested;
        }

        return ($own !== null && $requested === (int) $own) ? $requested : null;
    }

    /** The refusal that goes with a null from resolveSaccoId(). */
    protected function foreignSaccoDenied(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['error' => 'You can only act on your own SACCO.'], 403);
    }
}
