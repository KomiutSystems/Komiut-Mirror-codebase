<?php

declare(strict_types=1);

namespace App\Services\Wallet\Payout;

use App\Models\WalletSettlement;

/**
 * A way of getting money out of the float and into a SACCO's account.
 *
 * AN INTERFACE RATHER THAN ONE IMPLEMENTATION because there is currently NO
 * automated way to do this at all, and the shape of the eventual one is the
 * bank's decision, not ours. NCBA -- where the Komiut float sits for the komiut
 * brand -- exposes three collection calls and nothing that moves money out.
 * Safaricom B2B could work from a Komiut shortcode but needs a
 * SecurityCredential we do not hold.
 *
 * So the working implementation today is "a human pays it", which is not a rail
 * and deliberately does not implement this. A rail is only for money that
 * leaves by API, and every one of them must be idempotent on
 * WalletSettlement::$reference.
 */
interface WalletPayoutRail
{
    /** Stored on the settlement as `payout_rail`, so a paid row says how. */
    public function name(): string;

    /** Whether this rail has everything it needs to send money right now. */
    public function enabled(): bool;

    /**
     * Send one settlement.
     *
     * MUST be idempotent on $settlement->reference: a retry after a timeout is
     * the same request, never a second payment.
     */
    public function send(WalletSettlement $settlement): PayoutResult;
}
