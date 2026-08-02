<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypt on write, decrypt on read — but tolerate rows that predate encryption.
 *
 * These columns (M-Pesa consumer key/secret, passkey) already hold PLAINTEXT on
 * live boxes. Laravel's built-in `encrypted` cast calls decrypt() on read and
 * would throw DecryptException on every such row — and this is the STK-push
 * credential path, so that throw is a failed payment. This cast instead returns
 * the raw value when it isn't a valid ciphertext, so legacy plaintext keeps
 * working and every write re-stores it encrypted. Over time the table converges
 * to fully encrypted with no data migration and no risk to the payment path.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedLegacyString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value === '' ? '' : null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // A value written before this cast existed — return it as-is.
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }
}
