<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use App\Models\Team\TeamMessage;
use App\Models\Team\TeamThread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\IdHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;

/**
 * Files one received email into the shared inbox. Transport-agnostic: it
 * takes the raw RFC 5322 message and the address it was delivered to, so SES
 * via SNS today and whatever product settles on tomorrow call the same code.
 *
 * Parsing is zbateson/mail-mime-parser (multipart, quoted-printable/base64,
 * any charset to UTF-8, RFC 2047 encoded headers) -- not a hand-rolled parser:
 * real mail is too varied for one.
 *
 * WHERE IT GOES, in order:
 *   1. In-Reply-To / References naming a message we already hold -- a reply
 *      lands in its conversation whichever of our addresses it was sent to;
 *   2. the thread token in a recipient's plus-tag (support+TOKEN@…), for a
 *      client that dropped the threading headers;
 *   3. a new thread in the mailbox it was sent to (envelope recipient, then
 *      Delivered-To, X-Original-To, To, Cc). Mail for no active mailbox of
 *      ours is dropped with a log line.
 *
 * ONCE ONLY. Delivery is at-least-once (SNS retries, a re-run import), so the
 * Message-ID is the key: a message we already hold files nothing. A message
 * without one is keyed by a hash of its bytes, which a redelivery repeats.
 *
 * THE SENDER'S Date HEADER IS NOT KEPT. It is whatever the sender's clock or
 * spam kit wrote -- year 0000 included, which Postgres refuses, and a failed
 * insert would have SNS retry the same bytes until it gives up. The message's
 * created_at (when we received it) is the time the inbox runs on; sent_at is
 * for our own outgoing mail only.
 *
 * WHAT THE RECEIVING SERVICE SAID (ReceiptVerdicts, SES today): attachments
 * are left out unless it scanned them clean, and a sender that failed
 * SPF/DKIM/DMARC is flagged in the body and never linked to a SACCO -- a
 * forged From of a SACCO admin must not put that SACCO's context next to it.
 */
final class InboundMail
{
    public function __construct(
        private readonly TeamInbox $inbox,
        private readonly AttachmentStore $attachments,
    ) {}

    /** @return TeamMessage|null the message filed; null when nothing new was filed */
    public function ingest(string $rawMime, ?string $toAddress = null, ?ReceiptVerdicts $verdicts = null): ?TeamMessage
    {
        $verdicts ??= new ReceiptVerdicts;

        try {
            $mail = Message::from($rawMime, false);
        } catch (Throwable $e) {
            Log::warning('team inbox: unreadable email dropped', ['error' => $e->getMessage(), 'bytes' => strlen($rawMime)]);

            return null;
        }

        $messageId = $this->messageId($mail, $rawMime);
        $parsed = $this->read($mail);

        return DB::transaction(function () use ($messageId, $parsed, $toAddress, $verdicts): ?TeamMessage {
            // Two deliveries of one message at once (SNS retrying while the
            // first is still being filed) wait for each other here, so the
            // second sees the first's row instead of filing a twin.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['team-inbox:'.$messageId]);

            if (TeamMessage::query()->where('message_id_header', $messageId)->exists()) {
                Log::info('team inbox: already filed, redelivery ignored', ['message_id' => $messageId]);

                return null;
            }

            $recipients = array_values(array_filter([$toAddress, ...$parsed['delivered_to'], ...$parsed['to'], ...$parsed['cc']]));

            $thread = $this->threadByHeaders([...$parsed['in_reply_to'], ...array_reverse($parsed['references'])])
                ?? $this->threadByToken($recipients);

            if ($thread === null) {
                $mailbox = Addresses::mailboxFor($recipients);
                if ($mailbox === null) {
                    Log::warning('team inbox: mail for no active mailbox dropped', [
                        'message_id' => $messageId,
                        'delivered_to' => $toAddress,
                    ]);

                    return null;
                }

                $thread = TeamThread::create([
                    'mailbox_id' => $mailbox->id,
                    'subject' => Addresses::line($parsed['subject'], 500),
                    'from_name' => $parsed['from_name'],
                    'from_email' => $parsed['from_email'],
                    'status' => 'open',
                ]);
            }

            $files = $this->attachments->accept($parsed['attachments']);
            if (($refused = $verdicts->attachmentsRefused()) !== null && $parsed['attachments'] !== []) {
                Log::warning('team inbox: attachments left out on the mail service\'s verdict', [
                    'message_id' => $messageId,
                    'attachments' => count($parsed['attachments']),
                ] + $verdicts->toLog());

                $files = ['keep' => [], 'skipped' => array_map(
                    fn (array $f) => AttachmentStore::safeName($f['name']).' ('.AttachmentStore::human(strlen($f['bytes'])).'): '.$refused,
                    $parsed['attachments'],
                )];
            }

            $body = $parsed['body'];
            if ($files['skipped'] !== []) {
                $body = rtrim($body)."\n\n[Attachments not kept: ".implode('; ', $files['skipped']).']';
            }

            $warnings = [];
            if ($verdicts->senderFailed()) {
                $warnings[] = '[Sender not verified: this email failed the sender checks (SPF/DKIM/DMARC), so the From address may be forged. It has not been linked to a SACCO.]';
            } elseif ($verdicts->senderUnverified()) {
                // Common and usually harmless (the sender's domain publishes no
                // DMARC policy), but nothing proves the From, so no SACCO is
                // linked on its word.
                $warnings[] = '[Sender could not be verified (their domain publishes no DMARC policy), so it has not been linked to a SACCO automatically. Link it by hand if you know who it is.]';
            }
            if ($verdicts->spam()) {
                $warnings[] = '[Marked as spam by the mail service.]';
            }
            if ($warnings !== []) {
                $body = implode("\n", $warnings)."\n\n".ltrim($body);
            }

            $message = $this->inbox->record($thread, [
                'direction' => 'in',
                'from_name' => $parsed['from_name'],
                'from_email' => $parsed['from_email'],
                'to' => $parsed['to'],
                'cc' => $parsed['cc'],
                'body' => trim($body) === '' ? '(no text)' : $body,
                'message_id_header' => $messageId,
                'in_reply_to' => $parsed['in_reply_to'][0] ?? null,
                'references' => $parsed['references'] !== [] ? mb_substr(implode(' ', $parsed['references']), 0, 20000) : null,
            ]);

            foreach ($files['keep'] as $file) {
                $this->attachments->store((int) $message->id, $file['name'], $file['mime'], $file['bytes']);
            }

            if (! $verdicts->senderUnverified()) {
                $this->inbox->matchSacco($thread->refresh(), $parsed['from_email']);
            }

            return $message;
        });
    }

    /**
     * Everything filed from one email, decoded.
     *
     * @return array{subject:string, from_name:?string, from_email:?string, to:list<string>, cc:list<string>,
     *               delivered_to:list<string>, in_reply_to:list<string>, references:list<string>, body:string,
     *               attachments:list<array{name:string, bytes:string}>}
     */
    private function read(IMessage $mail): array
    {
        $from = $mail->getHeader('From');
        $fromEmail = $from instanceof AddressHeader ? Addresses::normalise($from->getEmail()) : null;
        $fromName = $from instanceof AddressHeader ? Addresses::line($from->getPersonName(), 255) : '';

        $delivered = [];
        foreach (['Delivered-To', 'X-Original-To'] as $name) {
            foreach ($mail->getAllHeadersByName($name) as $header) {
                $delivered[] = $header->getValue();
            }
        }

        $attachments = [];
        foreach ($mail->getAllAttachmentParts() as $part) {
            $attachments[] = [
                'name' => (string) ($part->getFilename() ?? ''),
                'bytes' => (string) $part->getBinaryContentStream()?->getContents(),
            ];
        }

        return [
            'subject' => (string) $mail->getSubject(),
            'from_name' => $fromName !== '' ? $fromName : null,
            'from_email' => $fromEmail,
            'to' => $this->addresses($mail, 'To'),
            'cc' => $this->addresses($mail, 'Cc'),
            'delivered_to' => array_values(array_filter(array_map(fn ($a) => Addresses::normalise($a), $delivered))),
            'in_reply_to' => $this->ids($mail, 'In-Reply-To'),
            'references' => $this->ids($mail, 'References'),
            'body' => $this->body($mail),
            'attachments' => $attachments,
        ];
    }

    /**
     * Plain text: the text part as sent, else the HTML part reduced to text.
     * HTML itself is never stored (see HtmlToText).
     */
    private function body(IMessage $mail): string
    {
        // Read a bounded slice, never the whole part: the stored body is cut at
        // body_max_chars anyway, and a 30 MB email parsed in full -- each HTML
        // clean-up step copying it -- would run the web request out of memory.
        $max = (int) config('team_inbox.limits.body_max_chars');
        $cut = false;
        $read = function ($stream, int $budget) use (&$cut): ?string {
            if ($stream === null) {
                return null;
            }
            // read() may return less than asked (decoders hand back chunks).
            $out = '';
            while (strlen($out) < $budget && ! $stream->eof()) {
                $chunk = $stream->read($budget - strlen($out));
                if ($chunk === '') {
                    break;
                }
                $out .= $chunk;
            }
            if (! $stream->eof() && $stream->read(1) !== '') {
                $cut = true;
            }

            return $out;
        };

        $text = $read($mail->getTextStream(), $max * 4);
        if ($text === null || trim($text) === '') {
            $html = $read($mail->getHtmlStream(), 2 * 1024 * 1024);
            $text = $html !== null ? HtmlToText::convert($html) : '';
        } else {
            $text = str_replace(["\r\n", "\r"], "\n", $text);
        }

        // Postgres text holds no NUL, and a broken charset must not fail the insert.
        $text = mb_scrub(str_replace("\0", '', $text), 'UTF-8');

        if ($cut || mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max)."\n\n[Message cut: longer than ".number_format($max).' characters.]';
        }

        return trim($text, "\n");
    }

    /** @return list<string> normalised addresses, at most 50 */
    private function addresses(IMessage $mail, string $name): array
    {
        $header = $mail->getHeader($name);
        if (! $header instanceof AddressHeader) {
            return [];
        }

        $out = [];
        foreach ($header->getAddresses() as $address) {
            if (($email = Addresses::normalise($address->getEmail())) !== null) {
                $out[] = $email;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 50);
    }

    /** @return list<string> bare ids (no angle brackets), at most 50 */
    private function ids(IMessage $mail, string $name): array
    {
        $header = $mail->getHeader($name);
        $ids = $header instanceof IdHeader ? $header->getIds() : [];

        $ids = array_map(fn ($id) => Addresses::line(trim((string) $id, '<> '), 500), $ids);

        return array_slice(array_values(array_unique(array_filter($ids))), -50);
    }

    private function messageId(IMessage $mail, string $raw): string
    {
        $id = Addresses::line(trim((string) $mail->getMessageId(), '<> '), 500);

        return $id !== '' && strlen($id) < 490 ? $id : 'sha256-'.hash('sha256', $raw).'@inbound.invalid';
    }

    /** @param  list<string>  $ids  most relevant first */
    private function threadByHeaders(array $ids): ?TeamThread
    {
        $ids = array_slice(array_values(array_unique(array_filter($ids))), 0, 30);
        if ($ids === []) {
            return null;
        }

        $found = TeamMessage::query()->whereIn('message_id_header', $ids)->pluck('thread_id', 'message_id_header');
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                return TeamThread::query()->find($found[$id]);
            }
        }

        return null;
    }

    /** @param  list<?string>  $recipients */
    private function threadByToken(array $recipients): ?TeamThread
    {
        foreach ($recipients as $address) {
            $tag = Addresses::tag((string) $address);
            if ($tag !== null && preg_match('/^[a-z0-9]{32}$/', $tag) === 1) {
                if ($thread = TeamThread::query()->where('reply_token', $tag)->first()) {
                    return $thread;
                }
            }
        }

        return null;
    }
}
