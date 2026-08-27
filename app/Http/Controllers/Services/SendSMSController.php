<?php

namespace App\Http\Controllers\Services;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outbound SMS, via Tenasms.
 *
 * This is the ONLY notification channel that actually reaches anyone today.
 * Push exists but is broken and, even repaired, has 6 device tokens across 4
 * users out of 6,808 — so every password reset, every booking confirmation and
 * every driver alert that lands does so as a text message. Treat it as load
 * bearing.
 *
 * THE CREDENTIALS USED TO BE IN THIS FILE. An API key and partner id sat in the
 * literal query string, committed, in a repository that is mirrored. Moving them
 * to config does NOT unpublish them — they are in the git history of every clone
 * — so the key they replaced has to be ROTATED with Tenasms to actually be
 * secured. This change is what makes rotating possible: the value now comes from
 * the environment, so a new key can be set without another deploy of source.
 *
 * There was also a hard-coded PHPSESSID cookie from whoever first captured this
 * request out of a browser. It was meaningless to the API and is gone.
 */
class SendSMSController extends Controller
{
    /** Tenasms occasionally hangs. Without this the caller hangs with it. */
    private const TIMEOUT_SECONDS = 15;

    /**
     * @return array<string, mixed>|null The provider's decoded reply, or null
     *                                   when the message could not be sent.
     */
    public function sendSMS($phone, $message)
    {
        $key = (string) config('services.tenasms.key');
        $partner = (string) config('services.tenasms.partner_id');

        if ($key === '' || $partner === '') {
            // Loud, but not fatal: a queued job that throws here would retry
            // forever against a gateway that is not configured, and the caller
            // is usually mid-flow in something more important than the text.
            Log::error('SMS not sent: Tenasms credentials are not configured.', [
                'phone' => substr((string) $phone, -4),
            ]);

            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(config('services.tenasms.url'), [
                    'apikey' => $key,
                    'partnerID' => $partner,
                    'shortcode' => config('services.tenasms.shortcode'),
                    'message' => $message,
                    'mobile' => $phone,
                ]);

            return $response->json();
        } catch (\Throwable $e) {
            // Never let the gateway take the caller down with it. The phone
            // number is logged by its last four digits only — the whole point
            // of a log line here is "did the text go", not who it went to.
            Log::error('SMS gateway failed.', [
                'phone' => substr((string) $phone, -4),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
