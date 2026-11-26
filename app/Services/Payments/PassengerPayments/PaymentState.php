<?php

declare(strict_types=1);

namespace App\Services\Payments\PassengerPayments;

use Carbon\CarbonInterface;

/**
 * Where one in-app payment stands, as PaymentStatus reads it.
 *
 * `attempt` is the id of the push the status came from (0 when none did). It
 * is part of what the phone is told about, so that "failed" on the first
 * prompt and "failed" on the retry are two changes rather than one.
 */
final class PaymentState
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?CarbonInterface $settledAt = null,
        public readonly int $attempt = 0,
    ) {}

    /**
     * What payment.updated dedupes on: a resent callback or a second poller
     * reads the same state and so the same fingerprint, and is not announced.
     * Paid and refunded drop the attempt -- a second prompt on a booking that
     * is already paid (the overpaid path) changes nothing the passenger sees.
     */
    public function fingerprint(): string
    {
        return in_array($this->status, [PaymentStatus::PAID, PaymentStatus::REFUNDED], true)
            ? $this->status
            : $this->status.':'.$this->attempt;
    }
}
