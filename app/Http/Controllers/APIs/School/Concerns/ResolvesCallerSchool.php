<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\School\Concerns;

use App\Models\Sacco;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The school a school-admin request acts for.
 *
 * The caller's own SACCO must be a school, or the request is 403 -- decided
 * before any vehicle is looked at.
 *
 * A SUPERADMIN has no school of their own. On the list they pass `school` to
 * say whose buses they mean; without it their own SACCO is used, and if that
 * is not a school the answer is the same 403 anyone else gets.
 */
trait ResolvesCallerSchool
{
    protected function notASchool(): JsonResponse
    {
        return response()->json(['error' => 'This is for school accounts.'], 403);
    }

    /** The school the caller acts for on a list, or null (answer notASchool()). */
    protected function callerSchool(Request $request): ?Sacco
    {
        /** @var User $user */
        $user = $request->user();

        $id = $user->isSuperAdmin() && $request->filled('school')
            ? (int) $request->query('school')
            : $user->currentSaccoId();

        if ($id === null || $id <= 0) {
            return null;
        }

        $sacco = Sacco::withoutGlobalScopes()->find($id, ['id', 'name', 'kind']);

        return $sacco !== null && $sacco->isSchool() ? $sacco : null;
    }
}
