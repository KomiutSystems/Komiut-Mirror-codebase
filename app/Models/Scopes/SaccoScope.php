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
 *   - the user is a superadmin (sees across SACCOs within the brand);
 *   - the user has no home SACCO (passengers/drivers on the mobile apps, who
 *     legitimately query vehicles/queues across SACCOs to book a ride).
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
            return;
        }

        $via = method_exists($model, 'getSaccoVia') ? $model->getSaccoVia() : null;

        if ($via === null) {
            // Usually `sacco_id`. Sacco itself overrides it to `id`: it does not
            // belong to a tenant, it IS one.
            $column = method_exists($model, 'getSaccoColumn') ? $model->getSaccoColumn() : 'sacco_id';

            $builder->where($model->getTable() . '.' . $column, $saccoId);

            return;
        }

        // Relation-reached models (Booking, Queue, Transaction, …) carry no
        // sacco_id of their own; scope them through the relation that does.
        $builder->whereHas($via, static function (Builder $query) use ($saccoId): void {
            $query->where('sacco_id', $saccoId);
        });
    }
}
