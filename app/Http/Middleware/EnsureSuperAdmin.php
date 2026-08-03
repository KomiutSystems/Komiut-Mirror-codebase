<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /super platform console to super admins only. Returns 403 (not 404 /
 * empty) so the console can tell "not allowed" from "nothing to show". The
 * cross-brand BrandScope exemption keys off the same isSuperAdmin(), so this and
 * the data a super admin sees stay in lockstep.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            return response()->json(['error' => 'Super admin only.'], 403);
        }

        return $next($request);
    }
}
