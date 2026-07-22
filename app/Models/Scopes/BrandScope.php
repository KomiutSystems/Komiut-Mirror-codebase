<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Context;

/**
 * Constrains brand-owned models to the brand of the current request.
 *
 * Unlike SaccoScope (keyed on the authenticated user), the brand is a property
 * of the request/app — set by ResolveBrand into Context — so passengers, drivers
 * and admins alike only ever see the operational data of the app they opened.
 *
 * When no brand is active (console commands, non-brand requests, queued jobs with
 * no brand), it does not scope — matching how those contexts already behave.
 */
final class BrandScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Context::has('brand')) {
            return;
        }

        $brand = (string) Context::get('brand');

        $via = method_exists($model, 'getBrandVia') ? $model->getBrandVia() : null;

        if ($via === null) {
            $builder->where($model->getTable() . '.brand', $brand);

            return;
        }

        // Reach the branded ancestor (a vehicle / sacco carrying the column).
        $builder->whereHas($via, static function (Builder $query) use ($brand): void {
            $query->where('brand', $brand);
        });
    }
}
