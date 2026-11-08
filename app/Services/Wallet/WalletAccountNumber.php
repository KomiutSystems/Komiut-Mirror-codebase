<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Models\Wallet;
use RuntimeException;

/**
 * The code a passenger's top-up arrives under.
 *
 * THE ALPHABET IS THE WHOLE POINT. This value gets read off a phone screen and
 * typed into an M-Pesa prompt, often on a moving matatu, so 0/O and 1/I/L are
 * excluded -- a passenger who types O for 0 does not get a polite error, they
 * send money to a paybill with an account number that matches nobody.
 *
 * `normalise()` is deliberately forgiving in the other direction: people insert
 * spaces and dashes, and lower case arrives from some handsets. A top-up that
 * failed to match because somebody typed "w-3f 8kq" instead of "W3F8KQ" is real
 * money sitting unattributed, so the parsing side accepts what the printing
 * side would never produce.
 */
final class WalletAccountNumber
{
    /** No 0/O, no 1/I/L. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const PREFIX = 'W';

    private const BODY_LENGTH = 6;

    /** How many times to retry on a collision before giving up. */
    private const ATTEMPTS = 12;

    /**
     * This wallet's code, minting one if it has none.
     *
     * Assigned on demand rather than backfilled: a passenger who never tops up
     * never needs one.
     */
    public static function forWallet(Wallet $wallet): string
    {
        $existing = $wallet->account_no;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $candidate = self::mint();

            // Checked rather than trusted to collision-resistance: the unique
            // index is the real guarantee, and this keeps the common case from
            // surfacing a database error to a passenger.
            if (Wallet::where('account_no', $candidate)->exists()) {
                continue;
            }

            $wallet->account_no = $candidate;
            $wallet->save();

            return $candidate;
        }

        throw new RuntimeException('Could not mint a unique wallet account number.');
    }

    /** 31^6 is about 887 million, which is plenty against a six-figure user base. */
    private static function mint(): string
    {
        $body = '';

        for ($i = 0; $i < self::BODY_LENGTH; $i++) {
            // random_int, not rand: this is a value an attacker would like to
            // guess in order to pay into somebody else's wallet.
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::PREFIX.$body;
    }

    /**
     * What a human typed, turned into what was minted -- or null if it could
     * never be one of ours.
     *
     * Returning null for a non-wallet reference is load-bearing: every fare
     * payment on a paybill also carries a BillRefNumber, and treating one of
     * those as a wallet code would credit a wallet with a SACCO's fare.
     */
    public static function normalise(?string $raw): ?string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $raw) ?? '');

        if ($value === '' || ! str_starts_with($value, self::PREFIX)) {
            return null;
        }

        $body = substr($value, strlen(self::PREFIX));

        if (strlen($body) !== self::BODY_LENGTH) {
            return null;
        }

        // Every character must be from the minting alphabet. This is what makes
        // a fare reference that happens to begin with W -- a plate, a route
        // code -- fail to parse rather than be hunted for in the wallets table.
        foreach (str_split($body) as $char) {
            if (! str_contains(self::ALPHABET, $char)) {
                return null;
            }
        }

        return self::PREFIX.$body;
    }

    /** The wallet this reference belongs to, or null. */
    public static function resolve(?string $raw): ?Wallet
    {
        $code = self::normalise($raw);

        return $code === null ? null : Wallet::where('account_no', $code)->first();
    }
}
