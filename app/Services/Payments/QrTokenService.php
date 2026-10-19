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
    /**
     * The keys a sticker may be signed with, the one new stickers are signed
     * with first.
     *
     * Stickers are printed and stuck on buses for years, so their key must
     * outlive any APP_KEY rotation: config('services.qr.signing_key') is that
     * key. Until it is set, APP_KEY signs, as it always has -- and APP_KEY
     * plus services.qr.previous_keys keep verifying, so setting or rotating
     * the signing key never voids a sticker already on a bus.
     *
     * @return list<string>  raw HMAC secrets
     */
    private function secrets(): array
    {
        $keys = array_merge(
            [(string) config('services.qr.signing_key'), (string) config('app.key')],
            array_map('trim', explode(',', (string) config('services.qr.previous_keys'))),
        );

        return array_values(array_unique(array_map(
            fn (string $k) => str_starts_with($k, 'base64:') ? (base64_decode(substr($k, 7)) ?: $k) : $k,
            array_filter($keys, fn ($k) => $k !== ''),
        )));
    }

    /**
     * @param  array<string, mixed>  $claims  e.g. ['vehicle_id' => 1, 'sacco_id' => 2, 'plate' => 'KDA123X']
     */
    public function generate(array $claims): string
    {
        $payload = $this->b64url(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = $this->b64url(hash_hmac('sha256', $payload, $this->secrets()[0], true));

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
        $valid = false;
        foreach ($this->secrets() as $secret) {
            $valid = hash_equals($this->b64url(hash_hmac('sha256', $payload, $secret, true)), $signature) || $valid;   // constant-time
        }
        if (! $valid) {
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
