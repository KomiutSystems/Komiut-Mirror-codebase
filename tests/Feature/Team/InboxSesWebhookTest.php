<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Models\Team\TeamMailbox;
use App\Models\Team\TeamMessage;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * POST /api/v1/team-inbox/ses: only a genuine SNS message, for one of our
 * topics, gets an email into the inbox.
 */
final class InboxSesWebhookTest extends QueueTestCase
{
    private const URL = '/api/v1/team-inbox/ses';

    private const TOPIC = 'arn:aws:sns:eu-central-1:123456789012:team-inbox';

    private const CERT_URL = 'https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-test.pem';

    /** @var \OpenSSLAsymmetricKey */
    private $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $this->key), null, $this->key, 1);
        openssl_x509_export($cert, $pem);

        Http::preventStrayRequests();
        Http::fake([
            self::CERT_URL => Http::response($pem),
            'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription*' => Http::response('<ConfirmSubscriptionResponse/>'),
        ]);

        config([
            'team_inbox.inbound.enabled' => true,
            'team_inbox.inbound.sns_topic_arns' => [self::TOPIC],
        ]);

        TeamMailbox::create(['address' => 'support@komiut.test', 'name' => 'Komiut Support']);
    }

    private function email(string $messageId = 'sns-1@mail.example.test'): string
    {
        return "From: Lydia <lydia@example.test>\r\nTo: support@komiut.test\r\nSubject: Till down\r\n"
            ."Message-ID: <{$messageId}>\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nThe till is down.\r\n";
    }

    /** The SES "Received" notification SNS carries, with the email inline. */
    private function sesReceived(string $raw, string $encoding = 'BASE64'): string
    {
        return json_encode([
            'notificationType' => 'Received',
            'mail' => ['messageId' => 'ses-abc', 'destination' => ['support@komiut.test'], 'source' => 'lydia@example.test'],
            'receipt' => [
                'recipients' => ['support@komiut.test'],
                'action' => ['type' => 'SNS', 'topicArn' => self::TOPIC, 'encoding' => $encoding],
            ],
            'content' => $encoding === 'BASE64' ? base64_encode($raw) : $raw,
        ]);
    }

    /**
     * An SNS message, signed the way SNS signs.
     *
     * @param  array<string, string>  $override  applied before signing
     * @param  array<string, string>  $tamper  applied after signing
     * @return array<string, string>
     */
    private function sns(string $message, array $override = [], array $tamper = [], string $version = '2', $key = null): array
    {
        $fields = $override + [
            'Type' => 'Notification',
            'MessageId' => 'sns-'.uniqid(),
            'TopicArn' => self::TOPIC,
            'Subject' => 'Amazon SES Email Receipt Notification',
            'Message' => $message,
            'Timestamp' => '2026-11-26T10:00:00.000Z',
            'SignatureVersion' => $version,
            'SigningCertURL' => self::CERT_URL,
            'UnsubscribeURL' => 'https://sns.eu-central-1.amazonaws.com/?Action=Unsubscribe',
        ];

        $keys = $fields['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $string = '';
        foreach ($keys as $k) {
            if (isset($fields[$k])) {
                $string .= $k."\n".$fields[$k]."\n";
            }
        }

        openssl_sign($string, $signature, $key ?? $this->key, $version === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);
        $fields['Signature'] = base64_encode($signature);

        return $tamper + $fields;
    }

    /** @param  array<string, mixed>|string  $body */
    private function deliver(array|string $body): TestResponse
    {
        return $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'], is_string($body) ? $body : json_encode($body));
    }

    #[Test]
    public function it_is_not_there_until_inbound_is_switched_on(): void
    {
        config(['team_inbox.inbound.enabled' => false]);

        $this->deliver($this->sns($this->sesReceived($this->email())))->assertNotFound();
        $this->assertSame(0, TeamMessage::count());
    }

    #[Test]
    public function a_signed_notification_files_the_email_once(): void
    {
        $sns = $this->sns($this->sesReceived($this->email()));

        $this->deliver($sns)->assertOk();
        // SNS delivering it again.
        $this->deliver($sns)->assertOk();

        $this->assertSame(1, TeamMessage::count());
        $this->assertSame('The till is down.', TeamMessage::first()->body);
        Http::assertSentCount(1); // the certificate, fetched once and cached
    }

    #[Test]
    public function signature_version_1_sha1_and_utf8_content_are_accepted(): void
    {
        $this->deliver($this->sns($this->sesReceived($this->email('v1@x.test'), 'UTF8'), version: '1'))->assertOk();

        $this->assertSame('v1@x.test', TeamMessage::first()?->message_id_header);
    }

    #[Test]
    public function a_forged_or_altered_message_is_refused(): void
    {
        $genuine = $this->sesReceived($this->email('real@x.test'));
        $forged = $this->sesReceived($this->email('forged@x.test'));

        // Body swapped after signing.
        $this->deliver($this->sns($genuine, tamper: ['Message' => $forged]))->assertForbidden();
        // Signed by someone else's key.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->deliver($this->sns($forged, key: $other))->assertForbidden();
        // Signed as SHA1 but claiming version 2.
        $this->deliver($this->sns($forged, version: '1', tamper: ['SignatureVersion' => '2']))->assertForbidden();
        // An unknown signature version.
        $this->deliver($this->sns($forged, tamper: ['SignatureVersion' => '3']))->assertForbidden();
        // Garbage signature.
        $this->deliver($this->sns($forged, tamper: ['Signature' => '!!!']))->assertForbidden();

        $this->assertSame(0, TeamMessage::count());
    }

    #[Test]
    public function the_certificate_must_come_from_sns_over_https(): void
    {
        foreach ([
            'http://sns.eu-central-1.amazonaws.com/SimpleNotificationService-test.pem',
            'https://sns.eu-central-1.amazonaws.com.evil.test/SimpleNotificationService-test.pem',
            'https://evil.test/sns.eu-central-1.amazonaws.com/cert.pem',
            'https://sns.eu-central-1.amazonaws.com@evil.test/cert.pem',
            'https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-test.txt',
            'https://s3.amazonaws.com/cert.pem',
        ] as $url) {
            $this->deliver($this->sns($this->sesReceived($this->email()), ['SigningCertURL' => $url]))
                ->assertForbidden();
        }

        Http::assertNothingSent();
        $this->assertSame(0, TeamMessage::count());
    }

    #[Test]
    public function a_topic_not_on_the_list_is_refused_before_anything_is_fetched(): void
    {
        $this->deliver($this->sns($this->sesReceived($this->email()), ['TopicArn' => 'arn:aws:sns:eu-central-1:999999999999:theirs']))
            ->assertForbidden();

        $this->deliver($this->sns('{}', [
            'Type' => 'SubscriptionConfirmation',
            'TopicArn' => 'arn:aws:sns:eu-central-1:999999999999:theirs',
            'SubscribeURL' => 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&Token=t',
            'Token' => 't',
            'Subject' => null,
        ]))->assertForbidden();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_subscription_to_our_topic_is_confirmed(): void
    {
        $subscribe = 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.self::TOPIC.'&Token=tok';

        $this->deliver($this->sns('You have chosen to subscribe', [
            'Type' => 'SubscriptionConfirmation',
            'SubscribeURL' => $subscribe,
            'Token' => 'tok',
            'Subject' => null,
        ]))->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === $subscribe);
    }

    #[Test]
    public function a_subscribe_url_that_is_not_sns_is_never_visited(): void
    {
        $this->deliver($this->sns('x', [
            'Type' => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://evil.test/?Action=ConfirmSubscription',
            'Token' => 'tok',
            'Subject' => null,
        ]))->assertStatus(502);

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.test'));
    }

    #[Test]
    public function other_notifications_and_bad_bodies_file_nothing(): void
    {
        // An SES setup test notice on the topic: accepted, not mail.
        $this->deliver($this->sns(json_encode(['notificationType' => 'AmazonSnsSubscriptionSucceeded'])))->assertOk();
        // An S3-action receipt: no inline content (documented limitation).
        $this->deliver($this->sns(json_encode(['notificationType' => 'Received', 'receipt' => ['action' => ['type' => 'S3']]])))->assertOk();

        $this->deliver('not json')->assertStatus(400);
        $this->deliver(['Type' => 'Notification'])->assertStatus(400);
        $this->deliver(str_repeat('x', 300 * 1024 + 1))->assertStatus(413);

        $this->assertSame(0, TeamMessage::count());
    }

    #[Test]
    public function it_is_rate_limited_per_caller(): void
    {
        config(['team_inbox.inbound.enabled' => false]);

        for ($i = 0; $i < 120; $i++) {
            $this->deliver('x')->assertNotFound();
        }

        $this->deliver('x')->assertStatus(429);
    }
}
