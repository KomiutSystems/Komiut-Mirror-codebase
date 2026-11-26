<?php

declare(strict_types=1);

namespace App\Services\Payments\PassengerPayments;

/**
 * One in-app payment as the passenger sees it: the contract row of
 * passenger/payments, plus what payment.updated needs to decide whether to
 * speak. Built only by PassengerPaymentFeed.
 */
final class PassengerPayment
{
    public const KIND_BOOKING = 'booking';

    public const KIND_QR_FARE = 'qr_fare';

    public const KINDS = [self::KIND_BOOKING, self::KIND_QR_FARE];

    public const METHOD_MPESA = 'mpesa';

    public const METHOD_POINTS = 'points';

    public const METHOD_WALLET = 'wallet';

    /**
     * @param  array<string, mixed>  $row  the response shape, in contract order
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly ?int $userId,
        public readonly PaymentState $state,
        public readonly array $row,
    ) {}

    /** "booking:12" / "qr_fare:4812" -- stable, and unique across both kinds. */
    public static function key(string $kind, int $id): string
    {
        return $kind.':'.$id;
    }

    /** @return array{0: string, 1: int}|null */
    public static function parseKey(string $key): ?array
    {
        if (! preg_match('/^(booking|qr_fare):([1-9][0-9]{0,18})$/', $key, $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    /**
     * Whether this state is ready to be announced. Not while an M-Pesa
     * settlement is half-written: every settlement flips the booking's or
     * fare's flag first, then writes the receipt, then closes the push. The
     * receipt's own save announces it (MpesaQrcodePayment / MpesaBookingCallback
     * booted). Announcing on the flag would tell the phone "paid" with no
     * M-Pesa code, and then leave it nothing new to say when the code arrived.
     */
    public function isAnnounceable(): bool
    {
        return ($this->row['_settling'] ?? false) !== true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter($this->row, fn (string $k) => ! str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
    }
}
