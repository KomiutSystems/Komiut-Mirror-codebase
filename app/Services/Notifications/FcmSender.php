<?php

declare(strict_types=1);

namespace App\Services\Notifications;

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
