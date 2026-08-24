<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which bank financed a matatu — `vehicles.financier`, and now also
 * `users.financier` for the bank staff allowed to read that bank's fleet.
 *
 * The backing values are legacy production data and are NOT free to rename:
 * 829 vehicles carry 'NCBA' and 54 carry 'coop-bank' spelled exactly like
 * this. They are the same strings SendBankCollectionsStatement maps its
 * partner keys onto and the ones ImportLegacyVehicles copies across verbatim.
 *
 * This is the authorization axis for the bank dashboards, and `brand` is NOT a
 * substitute for it. Brand says which portal shows a vehicle; financier says
 * who banks it. The two correlate but do not agree — brand komiut is 840
 * vehicles while financier NCBA is 829, so scoping NCBA by brand would show
 * them 11 buses they do not finance. The case that settles it is NICCO MOVERS:
 * one SACCO holding 126 NCBA vehicles and 54 Co-op ones. No filter keyed on
 * sacco_id or brand can separate those two banks' money, and the two banks
 * reconcile against it separately.
 */
enum Financier: string
{
    case Ncba = 'NCBA';
    case Coop = 'coop-bank';

    /**
     * Resolve a stored or submitted value, treating blank as "not set".
     *
     * tryFrom rather than from, everywhere: this is an authorization key read
     * off a legacy free-text column, so an unrecognised value must degrade to
     * null — which every caller here reads as "deny" — and must never throw. A
     * ValueError would turn a deliberately fail-closed scope into a 500, which
     * is the one outcome worse than showing nothing.
     */
    public static function tryParse(?string $value): ?self
    {
        $value = trim((string) $value);

        return $value === '' ? null : self::tryFrom($value);
    }

    /**
     * The backing values, for validation allow-lists.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** How the bank is named to a human — report headers, exports. */
    public function label(): string
    {
        return match ($this) {
            self::Ncba => 'NCBA Bank',
            self::Coop => 'Co-operative Bank',
        };
    }
}
