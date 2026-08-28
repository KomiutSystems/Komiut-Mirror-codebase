<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\SendSMSJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way a notification reaches a phone. Mirrors FcmSender: normalise,
 * hand off, never throw.
 *
 * Why this exists at all: SMS is the ONLY channel that actually reaches this
 * user base. Push has 6 device tokens registered across 4 users out of 6,808 —
 * 99.94% of accounts cannot receive a push, so any "we notified them" that only
 * went to FCM notified nobody. Every SMS in the codebase today is a bare
 * `dispatch(new SendSMSJob(...))` written out by hand at the call site
 * (AuthController:255, BookingsAPIController:166), which is why no notification
 * class can declare SMS as a channel. This is the seam that lets it.
 *
 * Delegates to SendSMSJob rather than calling SendSMSController inline so SMS
 * keeps the queue's retry/backoff and one dead gateway call cannot stall the
 * notification job mid-way through its other channels.
 */
class SmsSender
{
    public function send(?string $phone, string $message): bool
    {
        $msisdn = $this->normalise($phone);

        if ($msisdn === null) {
            // Not an error worth raising: plenty of rows carry junk or empty
            // phones. Log and move on rather than poisoning the queued job.
            Log::warning('sms: unusable recipient number, skipping', ['phone' => $phone]);

            return false;
        }

        if (trim($message) === '') {
            return false;
        }

        try {
            SendSMSJob::dispatch($msisdn, $message);

            return true;
        } catch (Throwable $e) {
            Log::warning('sms: could not enqueue', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Kenyan MSISDN in the 2547XXXXXXXX / 2541XXXXXXXX form the gateway expects.
     *
     * The four shapes that actually appear in this database — 0712345678,
     * 254712345678, +254712345678 and a bare 712345678 — all have to land on the
     * same string, because AuthController builds it one way ('254'.intval($phone),
     * which silently mangles a number already in 254… form) and the seed/import
     * data another. Anything that does not resolve to 12 digits starting 254 is
     * rejected instead of being sent to a wrong handset: an SMS containing a
     * booking or a reset password reaching a stranger is worse than no SMS.
     */
    private function normalise(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '254')) {
            $msisdn = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $msisdn = '254' . ltrim($digits, '0');
        } elseif (strlen($digits) === 9) {
            // Bare subscriber number, no national prefix.
            $msisdn = '254' . $digits;
        } else {
            return null;
        }

        return preg_match('/^254[17]\d{8}$/', $msisdn) === 1 ? $msisdn : null;
    }
}
