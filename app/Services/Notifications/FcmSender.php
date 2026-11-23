<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\FirebaseToken;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends an FCM push in the shape the mobile app actually reads.
 *
 * The pre-existing SendFCMMessageController emits data{payload, bookingid}, but
 * the app's push handler deep-links off data{type, referenceId} and shows a
 * banner only when a `notification` block is present — so this sends both a
 * `notification` block (title/body) AND data{type, referenceId, notificationId},
 * with type=trip + referenceId=bookingId opening the ticket screen.
 *
 * Best-effort by design (mirrors the C# service): if credentials are missing or
 * a send fails, it logs and returns false — a dead token or unconfigured brand
 * must never break the dispatch or, worse, a payment flow upstream.
 *
 * Per-brand: each brand has its own Firebase project, and its key comes from
 * SSM (see FirebaseCredentials). An unconfigured brand no-ops (no push) rather
 * than erroring.
 */
class FcmSender
{
    public function send(string $token, string $title, string $body, array $data): bool
    {
        $config = $this->brandConfig();
        if ($config === null) {
            Log::warning('fcm: no firebase config for brand, skipping push', ['brand' => $this->brand()]);

            return false;
        }

        $accessToken = $this->accessToken($config['credentials']);
        if ($accessToken === null) {
            return false;
        }

        try {
            $res = Http::withToken($accessToken)->timeout(15)->post(
                "https://fcm.googleapis.com/v1/projects/{$config['project_id']}/messages:send",
                [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => array_map('strval', $data), // FCM data values must be strings
                    ],
                ],
            );

            if ($res->failed()) {
                if (self::isDeadToken($res->status(), $res->json())) {
                    $this->forget($token);

                    return false;
                }

                Log::warning('fcm: send failed', ['status' => $res->status(), 'body' => $res->body()]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('fcm: send threw', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Firebase says this device is gone: the app was uninstalled, its data
     * cleared, or the token rotated. Only that answer -- 404 / UNREGISTERED.
     *
     * Deliberately narrow. INVALID_ARGUMENT is also how FCM rejects a bad
     * PAYLOAD, and SENDER_ID_MISMATCH is a live token from the other brand's
     * Firebase project; deleting on either would wipe working devices.
     *
     * @param  mixed  $body  the decoded error response
     */
    public static function isDeadToken(int $status, mixed $body): bool
    {
        if ($status === 404) {
            return true;
        }

        foreach ((array) data_get($body, 'error.details', []) as $detail) {
            if (data_get($detail, 'errorCode') === 'UNREGISTERED') {
                return true;
            }
        }

        return false;
    }

    /**
     * Stop sending to a device that no longer exists. Every user's row for it
     * goes: a token belongs to one install, whoever registered it. Without
     * this the list only ever grew -- 1,150 rows for 455 people by November,
     * and 1,055 sends a day to devices that were never coming back.
     */
    private function forget(string $token): void
    {
        $removed = FirebaseToken::where('firebase_token', $token)->delete();

        Log::info('fcm: device no longer registered, token removed', ['rows' => $removed]);
    }

    /**
     * OAuth token for the service account, cached ~55 min (Google tokens live 1h).
     * Keyed by the key's own id, so each brand -- and each rotation -- gets its
     * own token.
     *
     * @param  array<string, mixed>|string  $credentials  the decoded key, or a path to it
     */
    private function accessToken(array|string $credentials): ?string
    {
        try {
            return Cache::remember(FirebaseCredentials::cacheKey($credentials), now()->addMinutes(55), function () use ($credentials) {
                $client = new GoogleClient;
                $client->setAuthConfig($credentials);
                $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
                $client->refreshTokenWithAssertion();

                return $client->getAccessToken()['access_token'] ?? null;
            });
        } catch (Throwable $e) {
            Log::warning('fcm: could not obtain access token', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The brand's Firebase project and key -- from SSM, or a local file on a
     * developer machine. See FirebaseCredentials.
     *
     * @return array{project_id: string, credentials: array<string, mixed>|string}|null
     */
    private function brandConfig(): ?array
    {
        $resolved = FirebaseCredentials::forBrand($this->brand())
            ?? FirebaseCredentials::forBrand('default');

        return $resolved === null
            ? null
            : ['project_id' => $resolved['project_id'], 'credentials' => $resolved['credentials']];
    }

    private function brand(): string
    {
        return Context::has('brand')
            ? (string) Context::get('brand')
            : 'komiut';
    }
}
