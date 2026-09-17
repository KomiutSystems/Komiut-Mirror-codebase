<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which bank financed a matatu — `vehicles.financier`, and now also
 * `users.financier` for the bank staff allowed to read that bank's fleet.
 *
 * The backing values are legacy production data and are NOT free to rename:
 * they are the strings SendBankCollectionsStatement maps its partner keys
 * onto. 'coop-bank' was set deliberately on legacy, bus by bus (55 today).
 * 'NCBA' WAS NOT: legacy stamped it on every Komiut-brand vehicle as the
 * default -- its code reads the column as a domain switch (komiut.com =>
 * NCBA, 2safiri.co.ke => coop-bank) -- so 829 rows carried it, 463 of them
 * Githurai tuktuks. Read literally by this scope, that showed NCBA's viewers
 * seven SACCOs' fleets. Cleared on 2026-09-17: 'NCBA' now marks only the
 * buses NCBA actually finances (NICCO MOVERS' 126, which is what the bank's
 * own viewer was confined to on legacy), and NULL means no bank does.
 *
 * This is the authorization axis for the bank dashboards, and `brand` is NOT a
 * substitute for it. Brand says which portal shows a vehicle; financier says
 * who banks it. The case that settles it is NICCO MOVERS: one SACCO holding
 * 126 NCBA vehicles and 54 Co-op ones. No filter keyed on sacco_id or brand
 * can separate those two banks' money, and the two banks reconcile against
 * it separately.
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
