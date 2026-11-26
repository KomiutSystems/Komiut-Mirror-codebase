<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\Booking;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\Vehicle;

/**
 * What a push was raised for: the bus, and the amount the prompt asked for.
 *
 * A rail that is settled by asking the bank (NCBA, Co-op) rather than by a
 * callback carrying the figures has to recover them from our own records: the
 * booking's server-set fare, or the QR payment's amount -- the same numbers
 * the push sent.
 */
final class StkPushSubject
{
    /** @return array{0: Vehicle|null, 1: float} */
    public static function of(MpesaStkCallback $push): array
    {
        if ($push->booking_id > 0) {
            $booking = Booking::withoutGlobalScopes()->with(['queue' => fn ($q) => $q->withoutGlobalScopes()])->find($push->booking_id);
            $vehicle = $booking?->queue?->vehicle_id ? Vehicle::withoutGlobalScopes()->find($booking->queue->vehicle_id) : null;

            return [$vehicle, (float) round((float) ($booking->amount ?? 0))];
        }

        $payment = QrcodePayment::withoutGlobalScopes()->find($push->qrcode_payment_id);

        return [$payment ? Vehicle::withoutGlobalScopes()->find($payment->vehicle_id) : null, (float) ($payment->amount ?? 0)];
    }
}
