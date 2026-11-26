<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use Aws\S3\S3Client;
use Throwable;

/**
 * The raw emails SES's receipt rule saves in S3 (the S3 action).
 *
 * The SNS notification names the object; this reads it -- but only from the
 * one bucket and prefix the rule writes to, whatever a notification says,
 * and only up to a size cap. Credentials come from the instance role
 * (komiut-prod-ec2 may read that bucket); no keys in config.
 */
class SesMailStore
{
    public function __construct(private ?S3Client $client = null) {}

    /** Is this an object the receipt rule could have written? */
    public function allowed(string $bucket, string $key): bool
    {
        $ourBucket = (string) config('team_inbox.inbound.s3_bucket');
        $prefix = (string) config('team_inbox.inbound.s3_prefix');

        return $ourBucket !== ''
            && $bucket === $ourBucket
            && str_starts_with($key, $prefix)
            && ! str_contains($key, '..');
    }

    /**
     * The raw email, or null when it cannot be read right now (the caller
     * answers 5xx so SNS delivers the notification again).
     *
     * @throws EmailTooLarge when the object is over the cap: retrying will not help.
     */
    public function fetch(string $bucket, string $key): ?string
    {
        try {
            $client = $this->client ??= new S3Client([
                'version' => 'latest',
                'region' => (string) config('team_inbox.inbound.s3_region'),
            ]);

            $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
            if ((int) ($head['ContentLength'] ?? 0) > (int) config('team_inbox.inbound.max_email_bytes')) {
                throw new EmailTooLarge($key);
            }

            return (string) $client->getObject(['Bucket' => $bucket, 'Key' => $key])['Body'];
        } catch (EmailTooLarge $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
