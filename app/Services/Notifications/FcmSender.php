<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
 * Per-brand: each brand has its own Firebase project. Only komiut's
 * service-account file exists today; an unconfigured brand no-ops (no push)
 * rather than erroring.
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
     * Keyed by credentials path so each brand's token is cached separately.
     */
    private function accessToken(string $credentialsPath): ?string
    {
        try {
            return Cache::remember('fcm_token_' . md5($credentialsPath), now()->addMinutes(55), function () use ($credentialsPath) {
                $client = new GoogleClient();
                $client->setAuthConfig($credentialsPath);
                $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
                $client->refreshTokenWithAssertion();

                return $client->getAccessToken()['access_token'] ?? null;
            });
        } catch (Throwable $e) {
            Log::warning('fcm: could not obtain access token', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array{project_id: string, credentials: string}|null */
    private function brandConfig(): ?array
    {
        $file = config("services.fcm.{$this->brand()}.credentials")
            ?? config('services.fcm.default.credentials');
        $projectId = config("services.fcm.{$this->brand()}.project_id")
            ?? config('services.fcm.default.project_id');

        if (! $file || ! $projectId) {
            return null;
        }

        $path = Storage::path($file);

        return is_file($path) ? ['project_id' => $projectId, 'credentials' => $path] : null;
    }

    private function brand(): string
    {
        return \Illuminate\Support\Facades\Context::has('brand')
            ? (string) \Illuminate\Support\Facades\Context::get('brand')
            : 'komiut';
    }
}
