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
 */
final class InboundMail
{
    public function __construct(
        private readonly TeamInbox $inbox,
        private readonly AttachmentStore $attachments,
    ) {}

    /** @return TeamMessage|null the message filed; null when nothing new was filed */
    public function ingest(string $rawMime, ?string $toAddress = null): ?TeamMessage
    {
        try {
            $mail = Message::from($rawMime, false);
        } catch (Throwable $e) {
            Log::warning('team inbox: unreadable email dropped', ['error' => $e->getMessage(), 'bytes' => strlen($rawMime)]);

            return null;
        }

        $messageId = $this->messageId($mail, $rawMime);
        $parsed = $this->read($mail);

        return DB::transaction(function () use ($messageId, $parsed, $toAddress): ?TeamMessage {
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
            $body = $parsed['body'];
            if ($files['skipped'] !== []) {
                $body = rtrim($body)."\n\n[Attachments not kept: ".implode('; ', $files['skipped']).']';
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
                'sent_at' => $parsed['date'],
            ]);

            foreach ($files['keep'] as $file) {
                $this->attachments->store((int) $message->id, $file['name'], $file['mime'], $file['bytes']);
            }

            $this->inbox->matchSacco($thread->refresh(), $parsed['from_email']);

            return $message;
        });
    }

    /**
     * Everything filed from one email, decoded.
     *
     * @return array{subject:string, from_name:?string, from_email:?string, to:list<string>, cc:list<string>,
     *               delivered_to:list<string>, in_reply_to:list<string>, references:list<string>, body:string,
     *               date:?\DateTimeInterface, attachments:list<array{name:string, bytes:string}>}
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

        $date = null;
        try {
            $date = $mail->getHeader('Date')?->getDateTime();
        } catch (Throwable) {
            // An unparseable Date is the sender's problem, not a reason to lose the mail.
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
            'date' => $date,
            'attachments' => $attachments,
        ];
    }

    /**
     * Plain text: the text part as sent, else the HTML part reduced to text.
     * HTML itself is never stored (see HtmlToText).
     */
    private function body(IMessage $mail): string
    {
        $text = $mail->getTextContent();
        if ($text === null || trim($text) === '') {
            $html = $mail->getHtmlContent();
            $text = $html !== null ? HtmlToText::convert($html) : '';
        } else {
            $text = str_replace(["\r\n", "\r"], "\n", $text);
        }

        // Postgres text holds no NUL, and a broken charset must not fail the insert.
        $text = mb_scrub(str_replace("\0", '', $text), 'UTF-8');

        $max = (int) config('team_inbox.limits.body_max_chars');
        if (mb_strlen($text) > $max) {
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
