<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Signed QR tokens for fare payment (mirrors the C# FareQrTokenService).
 *
 * The QR encodes  base64url(payload) . base64url(HMAC-SHA256(payload))  — a
 * tamper-proof, non-expiring per-vehicle code. Only the vehicle IDENTITY is
 * encoded, never the amount (fares vary), so a scan always resolves the right
 * till and a passenger can neither forge a code nor redirect a payment.
 */
class QrTokenService
{
    /** Raw HMAC secret derived from APP_KEY. */
    private function secret(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:')
            ? (base64_decode(substr($key, 7)) ?: $key)
            : $key;
    }

    /**
     * @param  array<string, mixed>  $claims  e.g. ['vehicle_id' => 1, 'sacco_id' => 2, 'plate' => 'KDA123X']
     */
    public function generate(array $claims): string
    {
        $payload = $this->b64url(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = $this->b64url(hash_hmac('sha256', $payload, $this->secret(), true));

        return $payload . '.' . $signature;
    }

    /**
     * @return array<string, mixed>|null  null when tampered or malformed
     */
    public function validate(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;
        $expected = $this->b64url(hash_hmac('sha256', $payload, $this->secret(), true));

        if (! hash_equals($expected, $signature)) {   // constant-time
            return null;
        }

        $claims = json_decode($this->b64urlDecode($payload), true);

        return is_array($claims) ? $claims : null;
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
