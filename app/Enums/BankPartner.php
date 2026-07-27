<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The bank a brand hands its driver account-opening leads to.
 *
 * One partner per brand, agreed commercially — NCBA for Komiut, Co-operative
 * Bank for 2Safiri. The driver is asked whether they want an account, never
 * which bank, so this is always derived from the active brand and never read
 * from a request body.
 *
 * The backing value is what is stored on `driver_bank_leads.bank`.
 */
enum BankPartner: string
{
    case Ncba = 'ncba';
    case Coop = 'coop';

    /**
     * The partner serving this brand.
     *
     * Every brand is mapped explicitly and an unmapped one throws. A default
     * would be worse than a failure here: adding a white-label would silently
     * route its drivers' personal details to whichever bank happened to be the
     * fallback, and nobody would notice until that bank called them. Sending a
     * new brand's leads to the wrong partner is not recoverable by an apology.
     *
     * Adding a brand is therefore a deliberate act: map it, once a banking
     * agreement actually exists.
     */
    public static function forBrand(string $brand): self
    {
        return match (strtolower(trim($brand))) {
            'komiut', 'testing' => self::Ncba,
            '2safiri', 'safiri' => self::Coop,
            default => throw new \InvalidArgumentException(
                "No bank partner is mapped for brand [{$brand}]. Map it in " . self::class . '::forBrand().'
            ),
        };
    }
}
