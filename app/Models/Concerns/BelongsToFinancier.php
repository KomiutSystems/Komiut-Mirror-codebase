<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\FinancierScope;

/**
 * Marks a model as readable through the financier boundary: when the caller is
 * a bank user, every query is constrained to the vehicles their own bank
 * financed (see FinancierScope for the exemptions and the fail-closed rule).
 *
 * Models that own the `financier` column need nothing more. Models that reach
 * a financed vehicle through a relation declare the path in `$financierVia`:
 *
 *     protected ?string $financierVia = 'vehicle';               // Summary, Transaction
 *     protected ?string $financierVia = 'transaction.vehicle';   // Mpesa
 *
 * Unlike BelongsToSacco / BelongsToBrand this trait is a no-op for every
 * non-bank caller, so adding it to a model cannot change what a SACCO admin,
 * a driver or a webhook sees.
 */
trait BelongsToFinancier
{
    public static function bootBelongsToFinancier(): void
    {
        static::addGlobalScope(new FinancierScope());
    }

    /**
     * The relation path to a model carrying `financier`, or null when this
     * model has the column directly.
     */
    public function getFinancierVia(): ?string
    {
        return property_exists($this, 'financierVia') ? $this->financierVia : null;
    }
}
