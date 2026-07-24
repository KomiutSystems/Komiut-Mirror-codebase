<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\Booking;

/**
 * Resolves the Daraja credentials that initiated a booking's STK push — the same
 * precedence the push itself uses: the vehicle's own M-Pesa settings first, then
 * the SACCO's. The query must go to the SAME merchant that started the push,
 * with the SAME shortcode + passkey, or Safaricom won't recognise it.
 */
class MpesaCredentialResolver
{
    public static function forBooking(Booking $booking): ?DarajaClient
    {
        $vehicle = $booking->queue?->vehicle;
        $setting = $vehicle?->mpesa_payment_setting ?? $vehicle?->sacco?->mpesa_payment;

        if (! $setting
            || ! $setting->consumer_key
            || ! $setting->consumer_secret
            || ! $setting->business_short_code
            || ! $setting->pass_key
        ) {
            return null;
        }

        return new DarajaClient(
            (string) $setting->consumer_key,
            (string) $setting->consumer_secret,
            (string) $setting->business_short_code,
            (string) $setting->pass_key,
            (bool) $setting->is_live,
        );
    }
}
