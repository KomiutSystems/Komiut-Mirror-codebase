<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Constrains every query on a tenant-owned model to the authenticated user's
 * SACCO, closing the cross-SACCO IDOR / unscoped-read holes by default.
 *
 * It intentionally does NOT apply when:
 *   - there is no authenticated user (webhooks, public callbacks, console);
 *   - the user is a superadmin (sees across SACCOs within the brand).
 *
 * A user with no home SACCO — a passenger, a driver not yet attached — is NOT a
 * third exemption. They are filtered to nothing by default, and see only the
 * tables that opt in via BelongsToSacco::allowsCrossTenantBrowsing(): the
 * book-a-ride catalogue and their own bookings. Treating "no SACCO" as "no
 * filter" is what made this scope leak every takings table to every passenger.
 *
 * The outer brand boundary is already enforced at the database-connection layer
 * by the ResolveBrand middleware, so this scope only ever narrows within a brand.
 */
final class SaccoScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return;
        }

        $saccoId = $user->currentSaccoId();

        if ($saccoId === null) {
            // FAIL CLOSED. This used to `return`, applying no filter at all, and
            // the tenant boundary was therefore inverted: the less a caller
            // belonged to, the more they saw. Verified in production before this
            // change — an ordinary passenger account (id 3, zero permissions,
            // sacco_id NULL) read 5,033 summaries worth KES 78,223,947 across 18
            // SACCOs, 1.3M transactions and all 895 vehicles. 6,388 accounts have
            // a NULL sacco_id, so that was every one of them.
            //
            // The early return was there for a real reason — passengers and
            // drivers browse across SACCOs to book a ride — but "do not narrow a
            // vehicle search" had become "do not narrow anything, including the
            // takings tables". The models that genuinely need cross-tenant
            // reading now say so themselves, one at a time, and everything else
            // is denied by default. FinancierScope already works this way.
            // A BANK is tenantless on purpose. Its whole job is to look across
            // SACCOs at the vehicles it financed, and FinancierScope — which
            // fails closed on a missing financier — is the boundary that holds
            // it. Scoping a bank by SACCO would hide the fleet it is owed money
            // on, so leave it to the scope that actually applies.
            if ($user->isBankUser()) {
                return;
            }

            if (method_exists($model, 'allowsCrossTenantBrowsing') && $model->allowsCrossTenantBrowsing()) {
                return;
            }

            $builder->whereRaw('1 = 0');

            return;
        }

        $via = method_exists($model, 'getSaccoVia') ? $model->getSaccoVia() : null;

        if ($via === null) {
            // Usually `sacco_id`. Sacco itself overrides it to `id`: it does not
            // belong to a tenant, it IS one.
            $column = $model->getTable() . '.'
                . (method_exists($model, 'getSaccoColumn') ? $model->getSaccoColumn() : 'sacco_id');

            $shared = method_exists($model, 'getSaccoIncludesShared') && $model->getSaccoIncludesShared();

            if ($shared) {
                // A platform catalogue the tenant may extend: rows with no SACCO
                // belong to everyone, rows with one belong to that SACCO only.
                $builder->where(static function (Builder $query) use ($column, $saccoId): void {
                    $query->whereNull($column)->orWhere($column, $saccoId);
                });

                return;
            }

            $builder->where($column, $saccoId);

            return;
        }

        // Relation-reached models (Booking, Queue, Transaction, …) carry no
        // sacco_id of their own; scope them through the relation that does.
        //
        // The column is QUALIFIED. Unqualified, it resolved outward to the parent
        // table whenever the related table had no sacco_id of its own — which is
        // how four models declaring $saccoVia = 'sacco' produced a correct filter
        // entirely by accident, since `saccos` has no sacco_id column. Add one
        // (an umbrella-SACCO field is a plausible change) and every such query
        // would silently rebind and start returning the wrong rows.
        $builder->whereHas($via, static function (Builder $query) use ($saccoId): void {
            $query->where($query->getModel()->getTable() . '.sacco_id', $saccoId);
        });
    }
}
