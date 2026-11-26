<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Team\Inbox;

use App\Http\Controllers\Controller;
use App\Services\Team\Inbox\Addresses;
use App\Services\Team\Inbox\InboundMail;
use App\Services\Team\Inbox\ReceiptVerdicts;
use App\Services\Team\Inbox\SnsMessage;
use App\Services\Team\Inbox\SnsSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/team-inbox/ses -- mail for the team inbox, from AWS SES receipt
 * rules through an SNS topic (HTTPS subscription, SES "SNS action" with the
 * email inline).
 *
 * Public by necessity: AWS calls it, with no token and no app key. What
 * makes a request genuine is SNS's signature and our topic list (SnsMessage);
 * everything else is refused before the body is read as mail. It answers 404
 * while config team_inbox.inbound.enabled is off, which it is until product
 * settles the inbound route.
 *
 * LIMITATION -- ONLY SMALL EMAILS. SES's SNS action carries the email inline
 * and SES bounces anything over 150 KB, so a customer's larger attachment
 * never reaches us this way. The fix is SES's S3 action (email stored in S3,
 * a notification naming the object), which needs the AWS SDK and an S3
 * reader this codebase does not have.
 * TODO(team-inbox): S3 action -- install aws/aws-sdk-php, read the object
 * named in receipt.action.{bucketName,objectKey}, hand it to
 * InboundMail::ingest() the same way.
 *
 * Filed synchronously: an email is one small parse and a few inserts, and a
 * failure answers 500 so SNS delivers it again (filing is idempotent by
 * Message-ID, so a retry of one that half-worked files nothing twice).
 *
 * THE STATUS CODE DECIDES WHETHER SNS TRIES AGAIN: it retries 5xx, timeouts
 * and connection errors, never a 4xx. So 4xx is kept for what will never
 * change (not SNS, not our topic, a bad signature) and anything that might
 * pass -- SNS's signing certificate not fetchable this minute -- is 503.
 *
 * SES's receipt verdicts (virus, spam, SPF/DKIM/DMARC) go to the ingestion
 * core with the email; see ReceiptVerdicts. Ops: set ScanEnabled=true on the
 * receipt rule, or every attachment is left out as "not scanned".
 */
class InboxSesController extends Controller
{
    public function __construct(private readonly InboundMail $inbound) {}

    public function __invoke(Request $request): Response
    {
        if (! config('team_inbox.inbound.enabled')) {
            abort(404);
        }

        $body = $request->getContent();
        if (strlen($body) > (int) config('team_inbox.inbound.max_request_bytes')) {
            return response('Too large', 413);
        }

        // SNS posts JSON as text/plain, so the body is read raw.
        $sns = SnsMessage::parse($body);
        if ($sns === null) {
            return response('Not an SNS message', 400);
        }

        if (! $sns->topicAllowed()) {
            Log::warning('team inbox: SNS message from a topic not on the list refused', ['topic' => $sns->topicArn()]);

            return response('Unknown topic', 403);
        }

        switch ($sns->verify()) {
            case SnsSignature::Unavailable:
                // Logged where the fetch failed; SNS will deliver it again.
                return response('Signing certificate unavailable, try again', 503);
            case SnsSignature::Invalid:
                Log::warning('team inbox: SNS message with a bad signature refused', ['topic' => $sns->topicArn()]);

                return response('Bad signature', 403);
            case SnsSignature::Valid:
                break;
        }

        return match ($sns->type()) {
            'SubscriptionConfirmation' => $this->confirm($sns),
            'Notification' => $this->notification($sns),
            default => response('', 200), // UnsubscribeConfirmation: nothing to do
        };
    }

    private function confirm(SnsMessage $sns): Response
    {
        $ok = $sns->confirmSubscription();
        Log::info('team inbox: SNS subscription '.($ok ? 'confirmed' : 'NOT confirmed'), ['topic' => $sns->topicArn()]);

        return $ok ? response('', 200) : response('Could not confirm', 502);
    }

    private function notification(SnsMessage $sns): Response
    {
        $ses = json_decode($sns->message(), true);
        if (! is_array($ses) || ($ses['notificationType'] ?? null) !== 'Received') {
            // Other SES notices on the topic (a test notification, a bounce)
            // are not mail for the inbox. 200, or SNS would retry them.
            return response('', 200);
        }

        $content = $ses['content'] ?? null;
        if (! is_string($content)) {
            // An S3-action receipt names an object instead (see the TODO above).
            Log::warning('team inbox: SES receipt without inline content skipped', [
                'action' => $ses['receipt']['action']['type'] ?? null,
                'ses_message_id' => $ses['mail']['messageId'] ?? null,
            ]);

            return response('', 200);
        }

        if (strtoupper((string) ($ses['receipt']['action']['encoding'] ?? 'UTF8')) === 'BASE64') {
            $content = base64_decode($content, true);
            if ($content === false) {
                return response('Bad content encoding', 400);
            }
        }

        $this->inbound->ingest($content, $this->deliveredTo($ses), ReceiptVerdicts::fromSes((array) ($ses['receipt'] ?? [])));

        return response('', 200);
    }

    /**
     * The envelope recipient the receipt rule matched: the first that is one
     * of our mailboxes, else the first. The headers decide when neither is.
     *
     * @param  array<string, mixed>  $ses
     */
    private function deliveredTo(array $ses): ?string
    {
        $recipients = array_values(array_filter((array) ($ses['receipt']['recipients'] ?? $ses['mail']['destination'] ?? []), 'is_string'));
        if ($recipients === []) {
            return null;
        }

        foreach ($recipients as $address) {
            if (Addresses::mailboxFor([$address]) !== null) {
                return $address;
            }
        }

        return $recipients[0];
    }
}
