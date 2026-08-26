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

    /**
     * The column on THIS table that holds the SACCO id — `sacco_id` for every
     * model that belongs to a SACCO, but `id` for Sacco itself, which does not
     * belong to a tenant so much as IS one.
     *
     * That case is why this exists. Sacco was left unscoped because it fits
     * neither shape the trait originally supported, and the result was a live
     * leak: a SACCO admin could list all 49 SACCOs and open another one's record.
     * Naming the column makes "the tenant table" expressible instead of
     * exceptional.
     */
    public function getSaccoColumn(): string
    {
        return property_exists($this, 'saccoColumn') ? $this->saccoColumn : 'sacco_id';
    }

    /**
     * Whether rows with a NULL sacco are SHARED — visible to every tenant —
     * rather than simply unowned.
     *
     * Default false, because for most tables a null tenant means "not yet
     * attributed" and showing those to everyone is the leak the scope exists to
     * stop. ExpenseFee is the other kind: a platform catalogue (Fuel, Parking,
     * Stage Fee…) that a SACCO may extend with its own categories. Every row in
     * it carries sacco_id NULL, so a plain equality scope matched nothing and
     * quietly emptied the expense-category picker for every SACCO admin on the
     * platform — a whole feature switched off by a scope that looked correct.
     */
    public function getSaccoIncludesShared(): bool
    {
        return property_exists($this, 'saccoIncludesShared') ? $this->saccoIncludesShared : false;
    }
}
