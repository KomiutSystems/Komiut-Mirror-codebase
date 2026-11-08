<?php

declare(strict_types=1);

namespace App\Services\Wallet\Payout;

/**
 * What a payout rail did.
 *
 * THREE OUTCOMES, NOT TWO, and the third is the one that matters. A rail can
 * fail (the money did not go), succeed (it did), or be UNKNOWN -- the request
 * timed out, or the rail is asynchronous and has only acknowledged it. Treating
 * unknown as failure is how a SACCO gets paid twice; treating it as success is
 * how one never gets paid at all. So it is its own state and the settlement
 * stays payable-but-flagged until something authoritative says otherwise.
 */
final class PayoutResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly bool $unknown,
        public readonly ?string $reference,
        public readonly ?string $error,
    ) {}

    public static function sent(string $reference): self
    {
        return new self(true, false, $reference, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, false, null, $error);
    }

    /**
     * Accepted but not confirmed -- an async rail, or a timeout.
     *
     * MUST NOT be retried blindly: the money may already be moving. The
     * reference is kept so the outcome can be matched when it arrives.
     */
    public static function pending(?string $reference, string $note): self
    {
        return new self(false, true, $reference, $note);
    }
}
