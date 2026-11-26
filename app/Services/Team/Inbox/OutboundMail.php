<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use App\Models\Team\TeamMessage;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * One outgoing team email, built and handed to the mail server.
 *
 * Plain text only, from the mailbox's own address and name, with the
 * threading headers a customer's mail client needs to keep the conversation
 * together: our own Message-ID (the one stored on the message, so their
 * answer's In-Reply-To finds it), In-Reply-To the message being answered,
 * and the References chain. With plus-addressing on, Reply-To carries the
 * thread's token as a second way back.
 */
final class OutboundMail
{
    public function send(TeamMessage $message): void
    {
        $thread = $message->thread;
        $mailbox = $thread?->mailbox;
        if ($thread === null || $mailbox === null) {
            throw new RuntimeException('Team message '.$message->id.' has no thread or mailbox.');
        }

        $to = array_values(array_filter((array) $message->to, fn ($a) => Addresses::valid($a)));
        $cc = array_values(array_filter((array) $message->cc, fn ($a) => Addresses::valid($a)));
        if ($to === []) {
            throw new RuntimeException('Team message '.$message->id.' has no valid recipient.');
        }

        $subject = Addresses::line($thread->subject, 500);
        if ($message->in_reply_to !== null && ! preg_match('/^re:/i', $subject)) {
            $subject = 'Re: '.$subject;
        }

        $mailer = config('team_inbox.mailer') ?: config('mail.default');

        Mail::mailer($mailer)->raw($message->body, function (Message $mail) use ($message, $thread, $mailbox, $to, $cc, $subject): void {
            $mail->from($mailbox->address, Addresses::line($mailbox->name, 120));
            $mail->to($to);
            if ($cc !== []) {
                $mail->cc($cc);
            }
            $mail->subject($subject !== '' ? $subject : '(no subject)');

            if (config('team_inbox.reply_to_plus_addressing')) {
                $mail->replyTo(Addresses::plus($mailbox->address, $thread->reply_token), Addresses::line($mailbox->name, 120));
            }

            $headers = $mail->getSymfonyMessage()->getHeaders();
            $headers->addIdHeader('Message-ID', $message->message_id_header);
            if (self::idOk($message->in_reply_to)) {
                $headers->addIdHeader('In-Reply-To', $message->in_reply_to);
            }
            // A sender's malformed id must not stop the reply going out: it is
            // left out of the chain instead.
            $references = array_values(array_filter(preg_split('/\s+/', trim((string) $message->references), -1, PREG_SPLIT_NO_EMPTY) ?: [], self::idOk(...)));
            if ($references !== []) {
                $headers->addIdHeader('References', $references);
            }
        });
    }

    /** A Message-ID the header encoder accepts: left@right, no spaces or brackets. */
    private static function idOk(?string $id): bool
    {
        return $id !== null && preg_match('/^[^\s<>@"]+@[^\s<>@"]+$/', $id) === 1;
    }
}
