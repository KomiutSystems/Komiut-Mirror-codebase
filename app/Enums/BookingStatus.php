<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A booking's lifecycle state, named.
 *
 * The table stores three unrelated booleans — `status`, `paid`, `boarded` — and
 * every screen re-derived meaning from them independently. So the driver app and
 * the dashboard could describe the same row differently, and a cancelled
 * booking had no name at all: CheckPassengerPayments sets `status = 0` on
 * anything still unpaid after two minutes and releases the seat, which is a
 * FAILED booking, but nothing said so.
 *
 * Order matters below. A row can satisfy several conditions at once (a boarded
 * booking is also paid), so `of()` returns the first that matches, most
 * advanced first.
 */
enum BookingStatus: string
{
    /** Cancelled by the unpaid sweep; the seat has been released. */
    case Failed = 'failed';

    /** Passenger is aboard. */
    case Boarded = 'boarded';

    /** Paid for and holding a seat. */
    case Confirmed = 'confirmed';

    /** Seat held, payment not yet received. */
    case Reserved = 'reserved';

    public function label(): string
    {
        return match ($this) {
            self::Failed => 'Failed',
            self::Boarded => 'Boarded',
            self::Confirmed => 'Confirmed',
            self::Reserved => 'Reserved',
        };
    }

    /** Derive the state of one booking from its three flags. */
    public static function of(bool $status, bool $paid, bool $boarded): self
    {
        return match (true) {
            ! $status => self::Failed,
            $boarded => self::Boarded,
            $paid => self::Confirmed,
            default => self::Reserved,
        };
    }
}
