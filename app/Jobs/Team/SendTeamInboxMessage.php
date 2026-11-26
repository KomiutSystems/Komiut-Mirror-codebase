<?php

declare(strict_types=1);

namespace App\Jobs\Team;

use App\Models\Team\TeamMessage;
use App\Services\Platform\AuditLogger;
use App\Services\Team\Inbox\OutboundMail;
use App\Services\Team\Inbox\TeamInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Send one reply or new email written in the team inbox.
 *
 * Off the request because SMTP is slow and sometimes down; retried at
 * widening gaps for about an hour and a half, then the message says why it
 * did not go (send_error) for the console to show. The message is the
 * record either way: sent_at is set only once the mail server took it.
 */
final class SendTeamInboxMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Minutes to wait before each retry. */
    public const RETRY_MINUTES = [1, 5, 15, 60];

    public int $tries = 5;

    /** One chain per message: two would send it twice. */
    public int $uniqueFor = (1 + 5 + 15 + 60 + 30) * 60;

    public function __construct(public readonly int $messageId) {}

    public function uniqueId(): string
    {
        return 'team-inbox-send:'.$this->messageId;
    }

    public function backoff(): array
    {
        return array_map(fn (int $m) => $m * 60, self::RETRY_MINUTES);
    }

    public function handle(OutboundMail $mail): void
    {
        $message = TeamMessage::query()->with('thread.mailbox')->find($this->messageId);

        // Gone, already sent, or not an email at all: nothing to do. A note
        // can never reach here by design, and is refused again in case.
        if ($message === null || $message->direction !== 'out' || $message->sent_at !== null || $message->send_error !== null) {
            return;
        }

        if (! TeamInbox::sendingEnabled()) {
            $this->markUnsent($message, 'Sending was switched off before this went out.');

            return;
        }

        $mail->send($message);

        $message->forceFill(['sent_at' => now(), 'send_error' => null])->save();
        AuditLogger::record('team.inbox.sent', [
            'thread_id' => (int) $message->thread_id,
            'message_id' => (int) $message->id,
            'recipients' => count((array) $message->to) + count((array) $message->cc),
            'attempt' => $this->attempts(),
        ], subject: ['type' => 'team_thread', 'id' => (string) $message->thread_id]);
    }

    public function failed(?Throwable $e): void
    {
        $message = TeamMessage::query()->find($this->messageId);
        if ($message !== null && $message->sent_at === null) {
            $this->markUnsent($message, 'Could not be sent: '.mb_substr((string) $e?->getMessage(), 0, 300));
        }
    }

    private function markUnsent(TeamMessage $message, string $why): void
    {
        $message->forceFill(['send_error' => $why])->save();
        app(TeamInbox::class)->replyFailed($message);

        AuditLogger::record('team.inbox.send_failed', [
            'thread_id' => (int) $message->thread_id,
            'message_id' => (int) $message->id,
        ], subject: ['type' => 'team_thread', 'id' => (string) $message->thread_id]);
    }
}
