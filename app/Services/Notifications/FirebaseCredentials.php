<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where a brand's Firebase service-account key comes from.
 *
 * FROM SSM, NOT FROM GIT. Until 2026-10-07 the komiut key sat in this
 * repository (storage/app/json/komiut-firebase-adminsdk-*.json), and the
 * repository is public. Google disables service-account keys it finds exposed,
 * and from then on every push failed at the token request with
 * `invalid_grant: Invalid JWT Signature` -- crews and passengers got no booking
 * or payment notifications, and nothing but a WARNING said so.
 *
 * The key now arrives as `KOMIUT_FCM_CREDENTIALS_JSON` (SAFIRI_... for the other
 * brand): the JSON Firebase hands out, base64-encoded so it survives being one
 * line of .env, stored as a SecureString under /komiut/prod/ and rendered onto
 * the box by Docker/prod/render-env.sh like every other secret. Raw JSON is
 * accepted too. A file on the local disk (`..._FCM_CREDENTIALS`) still works
 * for a developer machine, and is only consulted when no JSON is set.
 */
final class FirebaseCredentials
{
    /**
     * The key for a brand, as Google\Client::setAuthConfig() takes it: the
     * decoded key, or a path. Null when the brand has no Firebase project or
     * its key cannot be read -- logged, never thrown, because a push is never
     * worth failing the caller for.
     *
     * @return array{project_id: string, credentials: array<string, mixed>|string, source: string}|null
     */
    public static function forBrand(string $brand): ?array
    {
        $projectId = config("services.fcm.{$brand}.project_id");
        $json = config("services.fcm.{$brand}.credentials_json");

        if (filled($json)) {
            $key = self::decode((string) $json);
            if ($key === null) {
                Log::warning('fcm: credentials_json is not a service-account key', ['brand' => $brand]);

                return null;
            }

            return [
                'project_id' => (string) ($key['project_id'] ?? $projectId),
                'credentials' => $key,
                'source' => 'credentials_json',
            ];
        }

        $file = config("services.fcm.{$brand}.credentials");
        if (! $file || ! $projectId) {
            return null;
        }

        // disk('local') EXPLICITLY: production runs FILESYSTEM_DISK=s3, and
        // resolving a path against the default disk threw before Google was
        // ever asked. See FcmSender's history.
        try {
            $path = Storage::disk('local')->path($file);
        } catch (Throwable $e) {
            Log::warning('fcm: could not resolve credentials path', [
                'brand' => $brand,
                'file' => $file,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return is_file($path)
            ? ['project_id' => (string) $projectId, 'credentials' => $path, 'source' => 'file']
            : null;
    }

    /**
     * A cache identity for the key's access token. Built from the key's own id,
     * so a rotated key never reuses a token minted for the one it replaced.
     *
     * @param  array<string, mixed>|string  $credentials
     */
    public static function cacheKey(array|string $credentials): string
    {
        $identity = is_array($credentials)
            ? ($credentials['client_email'] ?? '').'|'.($credentials['private_key_id'] ?? '')
            : $credentials;

        return 'fcm_token_'.md5($identity);
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $value): ?array
    {
        $raw = trim($value);
        if (! str_starts_with($raw, '{')) {
            $raw = (string) base64_decode($raw, true);
        }

        $key = json_decode($raw, true);

        return is_array($key)
            && ($key['type'] ?? null) === 'service_account'
            && ! empty($key['private_key'])
            && ! empty($key['client_email'])
            ? $key
            : null;
    }
}
