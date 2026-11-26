<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PlatformConsole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /super platform console to super admins and Safetek Staff
 * (PlatformConsole). Returns 403 (not 404 / empty) so the console can tell "not
 * allowed" from "nothing to show". The tenant scopes' exemption keys off the
 * same PlatformConsole, so this and the data each sees stay in lockstep.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! PlatformConsole::admits($user)) {
            return response()->json(['error' => 'Super admin only.'], 403);
        }

        // Safetek Staff: every route here checks a permission (their role
        // holds only its read-only slices), and their view of every tenant
        // holds for this request alone.
        if (! $user->isSuperAdmin()) {
            PlatformConsole::enterAsStaff();
        }

        return $next($request);
    }
}
