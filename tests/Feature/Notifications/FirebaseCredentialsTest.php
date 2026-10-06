<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Services\Notifications\FcmSender;
use App\Services\Notifications\FirebaseCredentials;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Firebase key comes from SSM, not from a file in the repository.
 *
 * Until 2026-10-07 the komiut service-account key was committed to this
 * (public) repository. Google disabled it, and every push since failed at the
 * token request with `invalid_grant: Invalid JWT Signature`. The key now
 * arrives as KOMIUT_FCM_CREDENTIALS_JSON, base64 of the JSON Firebase hands
 * out, rendered from /komiut/prod/ like every other secret.
 */
final class FirebaseCredentialsTest extends TestCase
{
    /** A key-shaped array. Never a real key: nothing here reaches Google. */
    private function key(string $keyId = 'abc123def456'): array
    {
        return [
            'type' => 'service_account',
            'project_id' => 'komiut',
            'private_key_id' => $keyId,
            'private_key' => "-----BEGIN PRIVATE KEY-----\nnot-a-real-key\n-----END PRIVATE KEY-----\n",
            'client_email' => 'firebase-adminsdk-test@komiut.iam.gserviceaccount.com',
            'client_id' => '1',
        ];
    }

    #[Test]
    public function the_key_is_read_from_the_base64_setting(): void
    {
        config(['services.fcm.komiut' => [
            'project_id' => 'komiut',
            'credentials_json' => base64_encode(json_encode($this->key(), JSON_THROW_ON_ERROR)),
            'credentials' => null,
        ]]);

        $resolved = FirebaseCredentials::forBrand('komiut');

        $this->assertNotNull($resolved);
        $this->assertSame('credentials_json', $resolved['source']);
        $this->assertSame('komiut', $resolved['project_id']);
        $this->assertSame('firebase-adminsdk-test@komiut.iam.gserviceaccount.com', $resolved['credentials']['client_email']);
    }

    #[Test]
    public function raw_json_is_accepted_too(): void
    {
        config(['services.fcm.komiut.credentials_json' => json_encode($this->key(), JSON_THROW_ON_ERROR)]);

        $this->assertSame('credentials_json', FirebaseCredentials::forBrand('komiut')['source']);
    }

    #[Test]
    public function the_setting_wins_over_a_file_on_disk(): void
    {
        Storage::disk('local')->put('json/old-key.json', json_encode($this->key('old'), JSON_THROW_ON_ERROR));
        config(['services.fcm.komiut' => [
            'project_id' => 'komiut',
            'credentials_json' => base64_encode(json_encode($this->key('new'), JSON_THROW_ON_ERROR)),
            'credentials' => 'json/old-key.json',
        ]]);

        $this->assertSame('new', FirebaseCredentials::forBrand('komiut')['credentials']['private_key_id']);

        Storage::disk('local')->delete('json/old-key.json');
    }

    #[Test]
    public function something_that_is_not_a_service_account_key_is_refused_quietly(): void
    {
        config(['services.fcm.komiut' => [
            'project_id' => 'komiut',
            'credentials_json' => base64_encode('{"type":"authorized_user"}'),
            'credentials' => null,
        ]]);

        $this->assertNull(FirebaseCredentials::forBrand('komiut'));
        // And a push with it is a false, never an exception.
        $this->assertFalse((new FcmSender)->send('token', 'Title', 'Body', ['type' => 'system', 'referenceId' => '']));
    }

    #[Test]
    public function a_rotated_key_never_reuses_the_old_keys_token(): void
    {
        $this->assertNotSame(
            FirebaseCredentials::cacheKey($this->key('old')),
            FirebaseCredentials::cacheKey($this->key('new')),
        );
    }

    #[Test]
    public function the_check_command_fails_when_komiut_has_no_key(): void
    {
        config(['services.fcm.komiut' => ['project_id' => 'komiut', 'credentials_json' => null, 'credentials' => null]]);

        $this->artisan('fcm:check', ['--brand' => ['komiut']])
            ->expectsOutputToContain('no Firebase key configured')
            ->assertFailed();
    }

    #[Test]
    public function no_key_is_kept_in_the_repository(): void
    {
        $tracked = shell_exec('git -C '.escapeshellarg(base_path()).' ls-files storage/app 2>/dev/null') ?? '';

        $this->assertStringNotContainsString('.json', $tracked, 'storage/app is for runtime files, never a committed key');
    }
}
