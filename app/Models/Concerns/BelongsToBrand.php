<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\BrandScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/**
 * Marks a model as brand-owned: every query is constrained to the current
 * request's brand (see BrandScope), and models that carry the `brand` column
 * directly are auto-stamped with it on creation.
 *
 * Models that own the column set `$brandVia = null` (the default); models that
 * reach the brand through a relation declare the path, e.g.
 *
 *     protected ?string $brandVia = 'vehicle';        // Queue, Transaction, …
 *     protected ?string $brandVia = 'queue.vehicle';  // Booking
 */
trait BelongsToBrand
{
    public static function bootBelongsToBrand(): void
    {
        static::addGlobalScope(new BrandScope());

        static::creating(function (Model $model): void {
            $via = method_exists($model, 'getBrandVia') ? $model->getBrandVia() : null;

            if ($via === null && Context::has('brand') && empty($model->getAttribute('brand'))) {
                $model->setAttribute('brand', (string) Context::get('brand'));
            }
        });
    }

    public function getBrandVia(): ?string
    {
        return property_exists($this, 'brandVia') ? $this->brandVia : null;
    }
}
