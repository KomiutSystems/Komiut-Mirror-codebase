<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use App\Models\Team\TeamMessage;
use App\Models\Team\TeamMessageAttachment;
use App\Models\Team\TeamThread;
use App\Services\Team\TeamWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;

/**
 * Threads and messages in the shape the console reads
 * (KomiutWebApp docs/TEAM-WORKSPACE-API.md, section 4).
 */
final class InboxPresenter
{
    /** Named once, in routes/team-inbox-public.php. */
    public const DOWNLOAD_ROUTE = 'team-inbox.attachment';

    /**
     * What a thread list needs loaded, for one caller: assignee, SACCO and
     * that caller's read row -- three queries for the whole page.
     */
    public static function withListRelations(Builder $query, int $userId): Builder
    {
        return $query->with([
            'assignee',
            'sacco:id,name,brand',
            'reads' => fn ($r) => $r->where('user_id', $userId),
        ]);
    }

    /** @return array<string, mixed> */
    public static function thread(TeamThread $thread, int $userId): array
    {
        $thread->loadMissing(['assignee', 'sacco:id,name,brand', 'reads' => fn ($r) => $r->where('user_id', $userId)]);

        $read = $thread->reads->firstWhere('user_id', $userId);
        $unread = $thread->last_message_at !== null
            && ($read === null || $read->read_at->lessThan($thread->last_message_at));

        return [
            'id' => (int) $thread->id,
            'mailbox_id' => (int) $thread->mailbox_id,
            'subject' => $thread->subject,
            'snippet' => $thread->snippet,
            'from' => ['name' => $thread->from_name, 'email' => $thread->from_email],
            'status' => $thread->status,
            'assignee' => TeamWorkspace::member($thread->assignee),
            'unread' => $unread,
            'message_count' => (int) $thread->message_count,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'sacco' => $thread->sacco !== null
                ? ['id' => (int) $thread->sacco->id, 'name' => (string) $thread->sacco->name, 'brand' => TeamWorkspace::brand($thread->sacco->brand)]
                : null,
            'card_id' => $thread->card_id,
        ];
    }

    /** @return array<string, mixed> with author and attachments loaded */
    public static function message(TeamMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'direction' => $message->direction,
            'from' => ['name' => $message->from_name, 'email' => $message->from_email],
            'to' => array_values((array) ($message->to ?? [])),
            'cc' => array_values((array) ($message->cc ?? [])),
            'body' => $message->body,
            'author' => TeamWorkspace::member($message->author),
            'attachments' => $message->attachments->map(fn (TeamMessageAttachment $a) => [
                'id' => (int) $a->id,
                'name' => $a->name,
                'size' => (int) $a->size,
                'url' => self::downloadUrl($a),
            ])->values()->all(),
            // Outgoing mail only: whether it has gone, or why it has not.
            'sent_at' => $message->direction === 'out' ? $message->sent_at?->toIso8601String() : null,
            'send_error' => $message->direction === 'out' ? $message->send_error : null,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * A short-lived signed link: a browser following it cannot send the
     * console's bearer token, so the signature is the permission. Signed over
     * the path only (relative) so the proxy's scheme and host do not break it,
     * then made absolute for the console, which lives on another origin.
     */
    public static function downloadUrl(TeamMessageAttachment $attachment): string
    {
        return url(URL::temporarySignedRoute(
            self::DOWNLOAD_ROUTE,
            now()->addMinutes((int) config('team_inbox.download_url_minutes', 10)),
            ['attachment' => $attachment->id],
            absolute: false,
        ));
    }
}
