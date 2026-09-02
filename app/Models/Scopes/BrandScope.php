<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Constrains brand-owned models to the brand of the current request.
 *
 * Unlike SaccoScope (keyed on the authenticated user), the brand is a property
 * of the request/app — set by ResolveBrand into Context — so passengers, drivers
 * and admins alike only ever see the operational data of the app they opened.
 *
 * THE EXCEPTIONS ARE OWNERSHIP BOUNDARIES, which brand must not cut across —
 * see boundedBySomethingTighter(). A super admin sits above every boundary; a
 * SACCO admin is already confined by SaccoScope to their own SACCO, which spans
 * brands when the SACCO does; and a bank is confined by FinancierScope to the
 * buses it financed, which is exactly the view the banks asked for. Everyone
 * else — passengers, drivers, unauthenticated callers — stays brand-scoped.
 *
 * When no brand is active (console commands, non-brand requests, queued jobs with
 * no brand), it does not scope — matching how those contexts already behave.
 */
final class BrandScope implements Scope
{
    /**
     * Is this caller already confined by an OWNERSHIP boundary, which brand must
     * not then cut across?
     *
     * Brand is a property of the app somebody opened. It is the right wall for a
     * passenger or a driver, who should only ever see the operational data of
     * the product in front of them. It is the wrong wall for anyone whose access
     * is defined by whose money it is:
     *
     *   - A SACCO admin administers a SACCO. NICCO runs 180 buses under two
     *     brands — 126 Komiut, 54 2Safiri — because 2Safiri carries the buses
     *     Co-op Bank financed. Brand-scoping their own finance officer showed
     *     him 126 of 180 and KES 892,585 of KES 1,430,420 on 2026-08-31, with
     *     nothing on screen saying so. SaccoScope already confines him, and to a
     *     tighter set than brand does.
     *
     *   - A bank sees the buses it financed, wherever they run. Co-op financed
     *     the 2Safiri fleet, so a Co-op viewer reaching the platform on the
     *     Komiut host would have been shown none of it. FinancierScope is that
     *     boundary and it is the one the banks asked for; brand cutting across
     *     it defeats the feature it was built alongside.
     *
     *   - A super admin sits above every boundary, as before.
     *
     * Everyone else — passengers, drivers, unauthenticated callers — stays
     * brand-scoped, which is the case this scope was written for.
     */
    private function boundedBySomethingTighter(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->isBankUser()
            || $user->currentSaccoId() !== null;
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (! Context::has('brand')) {
            return;
        }

        $user = Auth::user();
        if ($user instanceof User && $this->boundedBySomethingTighter($user)) {
            return;
        }

        $brand = (string) Context::get('brand');

        $via = method_exists($model, 'getBrandVia') ? $model->getBrandVia() : null;

        if ($via === null) {
            $builder->where($model->getTable().'.brand', $brand);

            return;
        }

        // Reach the branded ancestor (a vehicle / sacco carrying the column).
        $builder->whereHas($via, static function (Builder $query) use ($brand): void {
            $query->where('brand', $brand);
        });
    }
}
