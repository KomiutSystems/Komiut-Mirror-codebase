<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\Booking;
use App\Models\MpesaPaymentSetting;
use App\Models\Vehicle;

/**
 * Resolves the Daraja credentials that initiated a booking's STK push — the same
 * precedence the push itself uses: the vehicle's own M-Pesa settings first, then
 * the SACCO's. The query must go to the SAME merchant that started the push,
 * with the SAME shortcode + passkey, or Safaricom won't recognise it.
 *
 * RESOLVED WITHOUT THE TENANT SCOPE, ON PURPOSE. The caller on the push and poll
 * paths is a PASSENGER, who has no SACCO, and SaccoScope fails closed for them --
 * correctly, for anything they are shown. But a settings row is never shown to
 * them: it decides which merchant Safaricom bills on their behalf, and the model
 * hides the secrets from every JSON anyway. Read through the scoped relations,
 * `$vehicle->mpesa_payment_setting` was NULL for every passenger on the platform,
 * the push answered "No payments found for this sacco", and not one STK prompt
 * ever left Frankfurt (booking #7, 2026-09-12, the first real attempt).
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
        $setting = self::settingFor($vehicle);

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

    /**
     * The settings row a vehicle's payments run on — the id the callback URL is
     * keyed on. The vehicle's own row first, then its SACCO's; by id, unscoped.
     */
    public static function settingFor(Vehicle $vehicle): ?MpesaPaymentSetting
    {
        if ($vehicle->mpesa_payment_setting_id !== null) {
            $own = MpesaPaymentSetting::withoutGlobalScopes()->find($vehicle->mpesa_payment_setting_id);
            if ($own !== null) {
                return $own;
            }
        }

        if ($vehicle->sacco_id === null) {
            return null;
        }

        // Sacco::mpesa_payment() is a hasOne with no ordering; the lowest id is
        // what Postgres has been handing back and is the deterministic reading.
        return MpesaPaymentSetting::withoutGlobalScopes()
            ->where('sacco_id', $vehicle->sacco_id)
            ->orderBy('id')
            ->first();
    }

    /** The settings row for the vehicle a booking sits on, or null when there is none. */
    public static function settingForBooking(Booking $booking): ?MpesaPaymentSetting
    {
        $vehicle = $booking->queue?->vehicle;

        return $vehicle !== null ? self::settingFor($vehicle) : null;
    }

    public static function forBooking(Booking $booking): ?DarajaClient
    {
        $setting = self::settingForBooking($booking);

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
