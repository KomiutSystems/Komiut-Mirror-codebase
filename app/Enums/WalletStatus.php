<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of a passenger's wallet.
 *
 * The backing values are the wire contract -- the app's own
 * `WalletStatus.fromApi` matches on these exact strings and falls back to
 * `not_activated` for anything it does not recognise, so renaming one silently
 * degrades every client to "not activated" rather than failing loudly.
 */
enum WalletStatus: string
{
    /**
     * No wallet row exists yet. This is a DERIVED state, never stored: GET
     * /wallet reports it for a passenger who has never touched the wallet, so
     * that a plain read never writes a row. The row appears when the passenger
     * first does something with it (sets a PIN, or is credited).
     */
    case NotActivated = 'not_activated';

    case Active = 'active';

    /** Administratively held. Cannot pay; can still be credited and read. */
    case Frozen = 'frozen';

    /** Whether a fare may be taken from this wallet. Mirrors the app's `canPay`. */
    public function canPay(): bool
    {
        return $this === self::Active;
    }
}
