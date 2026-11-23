<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\FirebaseToken;
use App\Services\Notifications\FcmSender;
use App\Services\Notifications\FirebaseCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A device Firebase says is gone is removed, and nothing else is.
 *
 * Until now nothing was ever removed: 1,055 sends on 2026-11-22 alone went to
 * devices Firebase answered 404 UNREGISTERED for, every day, for good.
 */
final class DeadPushTokensArePrunedTest extends QueueTestCase
{
    private const SEND = 'https://fcm.googleapis.com/v1/projects/komiut/messages:send';

    protected function setUp(): void
    {
        parent::setUp();
        $key = [
            'type' => 'service_account', 'project_id' => 'komiut', 'private_key_id' => 'k1',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nnot-a-real-key\n-----END PRIVATE KEY-----\n",
            'client_email' => 'firebase-adminsdk-test@komiut.iam.gserviceaccount.com', 'client_id' => '1',
        ];
        config(['services.fcm.komiut' => ['project_id' => 'komiut', 'credentials_json' => base64_encode((string) json_encode($key))]]);
        // The OAuth token, as if Google had already issued one.
        Cache::put(FirebaseCredentials::cacheKey(FirebaseCredentials::forBrand('komiut')['credentials']), 'oauth-1', 600);
    }

    private function devices(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        FirebaseToken::create(['user_id' => $a->id, 'device_id' => 'p1', 'firebase_token' => 'tok-dead']);
        FirebaseToken::create(['user_id' => $b->id, 'device_id' => 'p2', 'firebase_token' => 'tok-dead']);
        FirebaseToken::create(['user_id' => $a->id, 'device_id' => 'p3', 'firebase_token' => 'tok-alive']);
    }

    private function fcmAnswers(int $status, string $errorCode, string $message): void
    {
        Http::fake([self::SEND => Http::response(['error' => [
            'code' => $status, 'message' => $message, 'status' => $status === 404 ? 'NOT_FOUND' : 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $errorCode]],
        ]], $status)]);
    }

    #[Test]
    public function an_unregistered_device_is_removed_for_everyone_who_had_it(): void
    {
        $this->devices();
        $this->fcmAnswers(404, 'UNREGISTERED', 'NotRegistered');

        $this->assertFalse(app(FcmSender::class)->send('tok-dead', 'Hi', 'There', []));

        $this->assertSame(0, FirebaseToken::where('firebase_token', 'tok-dead')->count());
        $this->assertSame(1, FirebaseToken::where('firebase_token', 'tok-alive')->count());
    }

    #[Test]
    public function a_bad_payload_another_brands_token_or_an_outage_removes_nothing(): void
    {
        $this->devices();
        $error = fn (int $code, string $errorCode, string $message) => ['error' => [
            'code' => $code, 'message' => $message,
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $errorCode]],
        ]];
        Http::fake([self::SEND => Http::sequence()
            ->push($error(400, 'INVALID_ARGUMENT', 'Invalid value at message.data'), 400)
            ->push($error(403, 'SENDER_ID_MISMATCH', 'SenderId mismatch'), 403)
            ->push('upstream', 503)]);

        foreach ([1, 2, 3] as $n) {
            $this->assertFalse(app(FcmSender::class)->send('tok-dead', 'Hi', 'There', []));
            $this->assertSame(2, FirebaseToken::where('firebase_token', 'tok-dead')->count(), "after answer {$n}");
        }
        Http::assertSentCount(3);
    }

    #[Test]
    public function a_delivered_push_removes_nothing(): void
    {
        $this->devices();
        Http::fake([self::SEND => Http::response(['name' => 'projects/komiut/messages/1'])]);

        $this->assertTrue(app(FcmSender::class)->send('tok-alive', 'Hi', 'There', []));
        $this->assertSame(3, FirebaseToken::count());
    }
}
