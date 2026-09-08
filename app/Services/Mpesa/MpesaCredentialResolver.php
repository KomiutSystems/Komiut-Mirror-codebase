<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\Booking;
use App\Models\Vehicle;

/**
 * Resolves the Daraja credentials that initiated a booking's STK push — the same
 * precedence the push itself uses: the vehicle's own M-Pesa settings first, then
 * the SACCO's. The query must go to the SAME merchant that started the push,
 * with the SAME shortcode + passkey, or Safaricom won't recognise it.
 */
class MpesaCredentialResolver
{
    /**
     * Credentials for REGISTERING a vehicle's till, same precedence as a push:
     * the vehicle's own settings first, then its SACCO's.
     *
     * DELIBERATELY DOES NOT REQUIRE pass_key. The passkey signs an STK push and
     * is not used by c2b/v2/registerurl, so demanding it here would refuse to
     * register a perfectly valid till for a SACCO that only ever takes till
     * payments and has no STK credentials at all. It is passed through when
     * present and empty when not, because DarajaClient only reaches for it on
     * the push path.
     */
    public static function registrarFor(Vehicle $vehicle): ?DarajaClient
    {
        $setting = $vehicle->mpesa_payment_setting ?? $vehicle->sacco?->mpesa_payment;

        if (! $setting || ! $setting->consumer_key || ! $setting->consumer_secret) {
            return null;
        }

        return new DarajaClient(
            (string) $setting->consumer_key,
            (string) $setting->consumer_secret,
            (string) ($setting->business_short_code ?? ''),
            (string) ($setting->pass_key ?? ''),
            (bool) $setting->is_live,
        );
    }

    /** The settings row those credentials came from — the id the callback URL is keyed on. */
    public static function settingFor(Vehicle $vehicle): ?\App\Models\MpesaPaymentSetting
    {
        return $vehicle->mpesa_payment_setting ?? $vehicle->sacco?->mpesa_payment;
    }

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
