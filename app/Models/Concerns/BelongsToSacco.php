<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\SaccoScope;

/**
 * Marks a model as SACCO-owned: every query is automatically constrained to the
 * authenticated user's SACCO (see SaccoScope for the exemptions).
 *
 * Models with their own `sacco_id` column need nothing more. Models that reach
 * a SACCO through a relation declare the path in a `$saccoVia` property, e.g.
 *
 *     protected string $saccoVia = 'vehicle';        // Queue, Transaction, …
 *     protected string $saccoVia = 'queue.vehicle';  // Booking
 */
trait BelongsToSacco
{
    public static function bootBelongsToSacco(): void
    {
        static::addGlobalScope(new SaccoScope());
    }

    /**
     * The relation path to a model carrying `sacco_id`, or null when this model
     * has the column directly.
     */
    public function getSaccoVia(): ?string
    {
        return property_exists($this, 'saccoVia') ? $this->saccoVia : null;
    }
}
