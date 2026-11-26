<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Proves an HTTPS notification really came from Amazon SNS, for one of our
 * topics, before anything in it is believed.
 *
 * The endpoint is public: anyone can POST an "email" to it. What makes one
 * genuine is SNS's signature, checked the way AWS documents it -- the
 * signing certificate must come from SNS itself (https, a host
 * sns.<region>.amazonaws.com, a .pem path), the signature must verify against
 * the canonical string of the message's own fields (SHA1 for
 * SignatureVersion 1, SHA256 for 2), and the topic must be one we
 * subscribed. A message failing any of those is refused whole.
 *
 * Replays of a genuine message are not refused here: filing is keyed on the
 * email's Message-ID, so a replay files nothing.
 */
final class SnsMessage
{
    /** The fields SNS signs, in order, per message type. Subject only when present. */
    private const SIGNED = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /** @param  array<string, mixed>  $fields */
    private function __construct(public readonly array $fields) {}

    /** The decoded body, or null when it is not an SNS message at all. */
    public static function parse(string $body): ?self
    {
        $fields = json_decode($body, true);
        if (! is_array($fields) || ! isset(self::SIGNED[$fields['Type'] ?? null])) {
            return null;
        }

        foreach (['Message', 'MessageId', 'Timestamp', 'TopicArn', 'Type', 'Signature', 'SignatureVersion', 'SigningCertURL'] as $required) {
            if (! is_string($fields[$required] ?? null)) {
                return null;
            }
        }

        return new self($fields);
    }

    public function type(): string
    {
        return (string) $this->fields['Type'];
    }

    public function topicArn(): string
    {
        return (string) $this->fields['TopicArn'];
    }

    public function message(): string
    {
        return (string) $this->fields['Message'];
    }

    public function topicAllowed(): bool
    {
        return in_array($this->topicArn(), (array) config('team_inbox.inbound.sns_topic_arns'), true);
    }

    /**
     * Is this signed by SNS? Fetches (and caches) the signing certificate.
     * Unavailable when that fetch failed for a reason that may pass (see
     * SnsSignature): the caller answers 5xx so SNS tries again.
     */
    public function verify(): SnsSignature
    {
        $algorithm = match ((string) $this->fields['SignatureVersion']) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };
        $certUrl = (string) $this->fields['SigningCertURL'];

        if ($algorithm === null || ! self::isSnsUrl($certUrl, requirePem: true)) {
            return SnsSignature::Invalid;
        }

        $signature = base64_decode((string) $this->fields['Signature'], true);
        if ($signature === false) {
            return SnsSignature::Invalid;
        }

        $pem = $this->certificate($certUrl);
        if (! is_string($pem)) {
            return $pem;
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return SnsSignature::Invalid;
        }

        return openssl_verify($this->stringToSign(), $signature, $key, $algorithm) === 1
            ? SnsSignature::Valid
            : SnsSignature::Invalid;
    }

    /** The canonical "Key\nValue\n" string SNS signed. */
    public function stringToSign(): string
    {
        $out = '';
        foreach (self::SIGNED[$this->type()] as $key) {
            if (! isset($this->fields[$key])) {
                continue;
            }
            $out .= $key."\n".$this->fields[$key]."\n";
        }

        return $out;
    }

    /**
     * Is this an SNS endpoint: https, on sns.<region>.amazonaws.com (or the
     * China partition), and for a certificate a .pem file? Anything else --
     * plain http, a look-alike host, a userinfo trick -- is not.
     */
    public static function isSnsUrl(string $url, bool $requirePem = false): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $hostOk = preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', strtolower((string) ($parts['host'] ?? ''))) === 1;

        return $hostOk && (! $requirePem || str_ends_with((string) ($parts['path'] ?? ''), '.pem'));
    }

    /** Confirm a subscription by visiting the SubscribeURL SNS sent -- only ever an SNS URL. */
    public function confirmSubscription(): bool
    {
        $url = (string) ($this->fields['SubscribeURL'] ?? '');
        if (! self::isSnsUrl($url)) {
            return false;
        }

        try {
            return Http::timeout(10)->get($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The PEM at $url, or why there is none. Only a 4xx from SNS's own host
     * (other than 408/429) is final -- SNS saying that certificate does not
     * exist, which a genuine message never names. A timeout, a 5xx, a
     * throttle or a body that is not a certificate (a proxy's error page, a
     * cut-off download) is Unavailable, and nothing is cached for it.
     */
    private function certificate(string $url): string|SnsSignature
    {
        $key = 'team-inbox:sns-cert:'.hash('sha256', $url);

        $pem = Cache::get($key);
        if (is_string($pem)) {
            return $pem;
        }

        try {
            $response = Http::timeout(5)->get($url);
        } catch (Throwable $e) {
            Log::warning('team inbox: SNS signing certificate could not be fetched', ['url' => $url, 'error' => $e->getMessage()]);

            return SnsSignature::Unavailable;
        }

        $status = $response->status();
        if ($response->clientError() && ! in_array($status, [408, 429], true)) {
            Log::warning('team inbox: SNS signing certificate refused by SNS', ['url' => $url, 'status' => $status]);

            return SnsSignature::Invalid;
        }

        $pem = $response->successful() ? $response->body() : '';
        if (! str_contains($pem, '-----BEGIN CERTIFICATE-----') || openssl_x509_read($pem) === false) {
            Log::warning('team inbox: SNS signing certificate unavailable', ['url' => $url, 'status' => $status]);

            return SnsSignature::Unavailable;
        }

        // SNS rotates its certificate rarely and names each by URL; a day
        // saves a fetch per email without holding a retired one for long.
        Cache::put($key, $pem, now()->addDay());

        return $pem;
    }
}
