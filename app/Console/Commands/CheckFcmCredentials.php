<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\FirebaseCredentials;
use Google\Client as GoogleClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Can this box mint a Firebase token for each brand -- yes or no, and why.
 *
 * Push failures are best-effort by design, so a dead key shows up as nothing
 * but a WARNING per notification: on 2026-10-07 every push had been failing
 * with `invalid_grant: Invalid JWT Signature` for long enough that nobody
 * remembered pushes working. This asks Google for a token -- the step that was
 * failing -- and sends no message, so it is safe to run against production
 * after putting a new key in SSM.
 *
 * Prints which key is in use (source, account, key id), never the key itself.
 */
class CheckFcmCredentials extends Command
{
    protected $signature = 'fcm:check {--brand=* : Brands to check (default: komiut and safiri)}';

    protected $description = 'Check each brand\'s Firebase key can obtain a push token, without sending anything';

    public function handle(): int
    {
        $brands = $this->option('brand') ?: ['komiut', 'safiri'];
        $failed = false;

        foreach ($brands as $brand) {
            $resolved = FirebaseCredentials::forBrand($brand);
            if ($resolved === null) {
                $this->warn("{$brand}: no Firebase key configured (set ".strtoupper($brand).'_FCM_CREDENTIALS_JSON)');
                $failed = $failed || $brand === 'komiut';

                continue;
            }

            $key = $resolved['credentials'];
            $who = is_array($key)
                ? ($key['client_email'] ?? '?').' key '.substr((string) ($key['private_key_id'] ?? '?'), 0, 8).'…'
                : $key;

            try {
                $client = new GoogleClient;
                $client->setAuthConfig($key);
                $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
                $token = $client->fetchAccessTokenWithAssertion();

                if (! empty($token['access_token'])) {
                    $this->info("{$brand}: OK -- project {$resolved['project_id']}, {$resolved['source']}, {$who}");

                    continue;
                }

                $this->error("{$brand}: Google refused -- ".($token['error_description'] ?? $token['error'] ?? 'no token').' -- '.$who);
            } catch (Throwable $e) {
                $this->error("{$brand}: ".$e->getMessage().' -- '.$who);
            }

            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
