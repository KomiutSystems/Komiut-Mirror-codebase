<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use App\Jobs\Team\SendTeamInboxMessage;
use App\Models\Sacco;
use App\Models\Team\TeamMailbox;
use App\Models\Team\TeamMessage;
use App\Models\Team\TeamThread;
use App\Models\Team\TeamThreadRead;
use App\Models\User;
use App\Services\Platform\AuditLogger;
use App\Services\Team\TeamWorkspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every write to the shared inbox goes through here -- mail filed by
 * InboundMail, and replies, notes and new emails from the console -- so a
 * thread's counters, its read state and the audit trail each live in one
 * place.
 *
 * THE COUNTERS, stated once:
 * - message_count counts everything shown in the thread: in, out and notes.
 * - last_message_at is the latest of any of them -- a teammate's note is
 *   activity the others should see, so it moves the thread up and marks it
 *   unread for them.
 * - snippet is the start of the latest EMAIL, in or out, never a note: the
 *   list shows where the conversation with the customer stands, and a note
 *   is not part of that conversation.
 * - first_inbound_at is when we received the first inbound email.
 * - first_reply_at is when the first OUTGOING email after it was written in
 *   the console (created, not delivered): the first-reply KPI measures the
 *   team, not the mail queue. A reply that finally fails to send gives the
 *   time back (SendTeamInboxMessage::failed -> replyFailed()).
 *
 * READ STATE is per person: unread = no read row, or read before the latest
 * message. Writing a reply or note marks the thread read for its author --
 * nobody needs telling about their own message.
 */
final class TeamInbox
{
    /** Characters of the latest email shown in the thread list. */
    public const SNIPPET_CHARS = 120;

    public static function sendingEnabled(): bool
    {
        return (bool) config('team_inbox.send_enabled');
    }

    /**
     * File one message in a thread and keep the thread's counters true. The
     * thread row is locked, so two deliveries into one thread cannot lose a
     * count.
     *
     * @param  array<string, mixed>  $attributes  TeamMessage attributes, thread_id excluded
     */
    public function record(TeamThread $thread, array $attributes): TeamMessage
    {
        return DB::transaction(function () use ($thread, $attributes): TeamMessage {
            $thread = TeamThread::query()->lockForUpdate()->findOrFail($thread->id);
            $message = TeamMessage::create(['thread_id' => $thread->id] + $attributes);
            $at = $message->created_at;
            $direction = $message->direction;

            $thread->message_count = (int) $thread->message_count + 1;
            if ($thread->last_message_at === null || $at->greaterThan($thread->last_message_at)) {
                $thread->last_message_at = $at;
            }

            if ($direction !== 'note') {
                $thread->snippet = self::snippet($message->body);
            }

            if ($direction === 'in') {
                $thread->first_inbound_at ??= $at;
                // A customer writing again on a closed conversation needs an answer.
                $thread->status = 'open';
            }

            if ($direction === 'out' && $thread->first_inbound_at !== null && $thread->first_reply_at === null) {
                $thread->first_reply_at = $at;
            }

            $thread->save();

            return $message;
        });
    }

    /** The caller has seen everything in this thread up to $at (default now). */
    public function markRead(TeamThread $thread, int $userId, ?Carbon $at = null): void
    {
        $at ??= now();
        // Never earlier than the latest message: a clock a second behind the
        // row that was just written must not leave it unread.
        if ($thread->last_message_at !== null && $thread->last_message_at->greaterThan($at)) {
            $at = $thread->last_message_at;
        }

        TeamThreadRead::query()->upsert(
            [['thread_id' => $thread->id, 'user_id' => $userId, 'read_at' => $at]],
            ['user_id', 'thread_id'],
            ['read_at'],
        );
    }

    /**
     * The SACCO an address belongs to: a platform user's SACCO first (an admin
     * writing from their own account), else a SACCO whose contact email it
     * is -- only when exactly one SACCO has it, so a shared address files
     * nothing rather than the wrong SACCO.
     */
    public function saccoForEmail(?string $email): ?int
    {
        if (($email = Addresses::normalise($email)) === null) {
            return null;
        }

        // byEmail compares LOWER(email): the same rule the sign-in uses.
        $fromUser = User::query()->withoutGlobalScopes()->byEmail($email)->whereNotNull('sacco_id')->value('sacco_id');
        if ($fromUser !== null && Sacco::withoutGlobalScopes()->whereKey($fromUser)->exists()) {
            return (int) $fromUser;
        }

        // saccos is the directory (a few thousand rows), so LOWER() on it is a
        // short scan, once per new thread.
        $ids = Sacco::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /** Fill in the thread's SACCO from this address, unless a person chose it. */
    public function matchSacco(TeamThread $thread, ?string $email): void
    {
        if ($thread->sacco_set_by_staff || $thread->sacco_id !== null) {
            return;
        }

        if (($saccoId = $this->saccoForEmail($email)) !== null) {
            $thread->forceFill(['sacco_id' => $saccoId])->save();
        }
    }

    /**
     * Status, assignee and SACCO, as validated by the controller. Choosing a
     * SACCO -- or clearing it -- is a person's decision, which the automatic
     * match never overrides afterwards.
     *
     * @param  array{status?:string, assignee_id?:?int, sacco_id?:?int}  $data
     */
    public function update(TeamThread $thread, array $data): TeamThread
    {
        return DB::transaction(function () use ($thread, $data): TeamThread {
            $thread = TeamThread::query()->lockForUpdate()->findOrFail($thread->id);

            foreach (['status', 'assignee_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $thread->{$field} = $data[$field];
                }
            }

            if (array_key_exists('sacco_id', $data)) {
                $thread->sacco_id = $data['sacco_id'];
                $thread->sacco_set_by_staff = true;
            }

            $changed = array_keys($thread->getDirty());
            $thread->save();

            if ($changed !== []) {
                $this->audit('team.inbox.thread.updated', $thread, ['fields' => $changed]);
            }

            return $thread;
        });
    }

    /** An internal note: stored, shown to the team, never emailed to anyone. */
    public function note(TeamThread $thread, User $by, string $body): TeamMessage
    {
        $message = $this->record($thread, [
            'direction' => 'note',
            'body' => $body,
            'author_id' => $by->id,
            'from_name' => TeamWorkspace::member($by)['name'] ?? null,
            'from_email' => null,
        ]);

        $this->markRead($thread->refresh(), (int) $by->id, $message->created_at);
        $this->audit('team.inbox.note', $thread, ['message_id' => (int) $message->id]);

        return $message;
    }

    /**
     * A reply from the thread's mailbox, to whoever wrote last. Stored now and
     * sent on the queue; the message carries sent_at or send_error when the
     * job is done with it.
     *
     * @param  list<string>  $cc  validated addresses
     */
    public function reply(TeamThread $thread, User $by, string $body, array $cc = []): TeamMessage
    {
        $mailbox = $this->activeMailbox($thread->mailbox_id);
        $lastIn = $thread->messages()->where('direction', 'in')->latest('id')->first();
        $lastOut = $thread->messages()->where('direction', 'out')->latest('id')->first();

        // Answer whoever wrote last; on a thread we started, whoever we wrote to.
        $to = $lastIn?->from_email ?? ($lastOut?->to[0] ?? null) ?? $thread->from_email;
        if (Addresses::normalise($to) === null) {
            throw ValidationException::withMessages(['body' => 'This conversation has no address to reply to.']);
        }

        $cc = array_values(array_diff(array_unique($cc), [mb_strtolower((string) $to)]));
        $parent = $lastIn ?? $lastOut;

        $message = DB::transaction(function () use ($thread, $by, $body, $mailbox, $to, $cc, $parent): TeamMessage {
            $message = $this->record($thread, [
                'direction' => 'out',
                'from_name' => $mailbox->name,
                'from_email' => $mailbox->address,
                'to' => [mb_strtolower((string) $to)],
                'cc' => $cc,
                'body' => $body,
                'author_id' => $by->id,
                'message_id_header' => self::newMessageId($mailbox->address),
                'in_reply_to' => $parent?->message_id_header,
                'references' => self::references($parent),
            ]);

            $this->markRead($thread->refresh(), (int) $by->id, $message->created_at);

            return $message;
        });

        SendTeamInboxMessage::dispatch((int) $message->id);
        // Ids and counts only: no addresses, no body.
        $this->audit('team.inbox.reply_queued', $thread, ['message_id' => (int) $message->id, 'recipients' => 1 + count($cc)]);

        return $message;
    }

    /**
     * A new email from the console: a new thread in that mailbox, assigned to
     * whoever wrote it. The thread's "from" is the person written to -- the
     * list names the other side of the conversation, as it does for mail we
     * received.
     *
     * @param  list<string>  $to  validated addresses
     */
    public function compose(TeamMailbox $mailbox, User $by, array $to, string $subject, string $body): TeamThread
    {
        $mailbox = $this->activeMailbox($mailbox->id);
        $to = array_values(array_unique($to));

        [$thread, $message] = DB::transaction(function () use ($mailbox, $by, $to, $subject, $body): array {
            $thread = TeamThread::create([
                'mailbox_id' => $mailbox->id,
                'subject' => $subject,
                'from_name' => null,
                'from_email' => $to[0],
                'status' => 'open',
                'assignee_id' => $by->id,
            ]);

            $message = $this->record($thread, [
                'direction' => 'out',
                'from_name' => $mailbox->name,
                'from_email' => $mailbox->address,
                'to' => $to,
                'cc' => [],
                'body' => $body,
                'author_id' => $by->id,
                'message_id_header' => self::newMessageId($mailbox->address),
            ]);

            $thread->refresh();
            $this->matchSacco($thread, $to[0]);
            $this->markRead($thread, (int) $by->id, $message->created_at);

            return [$thread, $message];
        });

        SendTeamInboxMessage::dispatch((int) $message->id);
        $this->audit('team.inbox.compose_queued', $thread, ['message_id' => (int) $message->id, 'recipients' => count($to), 'mailbox_id' => (int) $mailbox->id]);

        return $thread->refresh();
    }

    /**
     * A reply that never went out does not count as answering the customer:
     * the first reply becomes the next one that did not fail, or none.
     */
    public function replyFailed(TeamMessage $message): void
    {
        DB::transaction(function () use ($message): void {
            $thread = TeamThread::query()->lockForUpdate()->find($message->thread_id);
            if ($thread === null || $thread->first_inbound_at === null) {
                return;
            }

            $next = TeamMessage::query()
                ->where('thread_id', $thread->id)->where('direction', 'out')->whereNull('send_error')
                ->where('created_at', '>=', $thread->first_inbound_at)
                ->orderBy('created_at')->orderBy('id')->value('created_at');

            $thread->forceFill(['first_reply_at' => $next])->save();
        });
    }

    private function activeMailbox(int $mailboxId): TeamMailbox
    {
        $mailbox = TeamMailbox::query()->find($mailboxId);
        if ($mailbox === null || ! $mailbox->is_active) {
            throw ValidationException::withMessages(['mailbox_id' => 'This mailbox is switched off, so nothing can be sent from it.']);
        }

        return $mailbox;
    }

    /** Our own Message-ID, bare (no angle brackets), on the mailbox's domain. */
    public static function newMessageId(string $mailboxAddress): string
    {
        return Str::uuid()->toString().'@'.Addresses::domain($mailboxAddress);
    }

    /**
     * The References chain for a message answering $parent: its chain plus
     * its own id, the most recent 20 (RFC 5322 lets the middle be trimmed;
     * the first and the latest are what clients thread on).
     */
    public static function references(?TeamMessage $parent): ?string
    {
        if ($parent?->message_id_header === null) {
            return null;
        }

        $chain = preg_split('/\s+/', trim((string) $parent->references), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chain[] = $parent->message_id_header;
        $chain = array_values(array_unique($chain));
        if (count($chain) > 20) {
            $chain = array_merge([$chain[0]], array_slice($chain, -19));
        }

        return implode(' ', $chain);
    }

    public static function snippet(string $body): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $body) ?? ''), 0, self::SNIPPET_CHARS);
    }

    /** @param  array<string, mixed>  $data  ids and counts only */
    private function audit(string $action, TeamThread $thread, array $data): void
    {
        AuditLogger::record($action, ['thread_id' => (int) $thread->id] + $data, subject: ['type' => 'team_thread', 'id' => (string) $thread->id]);
    }
}
