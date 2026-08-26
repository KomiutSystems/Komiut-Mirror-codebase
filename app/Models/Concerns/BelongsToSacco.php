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

    /**
     * Whether a caller with NO SACCO of their own may still read this table.
     *
     * Default FALSE — deny. This is the counterpart to SaccoScope's fail-closed
     * branch, and the two must be read together: a passenger has sacco_id NULL,
     * so before that branch existed every tenant-owned table was fully readable
     * by every passenger on the platform.
     *
     * Say true here ONLY for a table a passenger must genuinely read across
     * SACCOs to use the product at all — which in practice means the book-a-ride
     * path: which SACCOs exist, which vehicles they run, which routes and
     * termini those serve, what the fare is, and the trip they are booking onto.
     * Those are catalogue and journey data.
     *
     * Never say true for takings, credentials, staff or member lists. If a row
     * would embarrass us in another SACCO's hands, this is not the mechanism for
     * reading it — a controller filtering by auth()->id() is.
     *
     * Opting in does NOT hand the table to everyone: it exempts only the
     * tenantless caller. A user who HAS a SACCO is still filtered to it, so a
     * SACCO admin never sees another SACCO's rows through this door.
     */
    public function allowsCrossTenantBrowsing(): bool
    {
        return property_exists($this, 'saccoCrossTenantBrowsing') ? $this->saccoCrossTenantBrowsing : false;
    }
}
