<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Team\Inbox;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Team\TeamMailbox;
use App\Models\Team\TeamMessage;
use App\Models\Team\TeamThread;
use App\Services\Sql\LikeSql;
use App\Services\Team\Inbox\Addresses;
use App\Services\Team\Inbox\InboxPresenter;
use App\Services\Team\Inbox\TeamInbox;
use App\Services\Team\Inbox\ThreadContext;
use App\Services\Team\TeamWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The shared inbox in the super console: mailboxes, threads, replies, notes.
 *
 * Behind the team-workspace gate (TeamWorkspace) like the rest of
 * /super/team. Every write goes through TeamInbox, which keeps the thread
 * counters and the audit trail.
 */
class TeamInboxController extends Controller
{
    private const VIEWS = ['unassigned', 'mine', 'open', 'closed'];

    public function __construct(
        private readonly TeamInbox $inbox,
        private readonly ThreadContext $context,
    ) {}

    /** Active mailboxes with the caller's counts, and the view counts over open threads. */
    public function mailboxes(Request $request): JsonResponse
    {
        $me = (int) $request->user()->id;

        // Open threads per mailbox, and how many of them the caller has not
        // read since their latest message -- one grouped query.
        $perMailbox = TeamThread::query()
            ->leftJoin('team_thread_reads as r', fn ($j) => $j->on('r.thread_id', '=', 'team_threads.id')->where('r.user_id', '=', $me))
            ->where('team_threads.status', 'open')
            ->groupBy('team_threads.mailbox_id')
            // Same rule as TeamInbox::isUnread: by message id, not by clock.
            ->selectRaw('team_threads.mailbox_id, COUNT(*) AS open_count, COUNT(*) FILTER (WHERE team_threads.last_message_id IS NOT NULL AND (r.last_read_message_id IS NULL OR r.last_read_message_id < team_threads.last_message_id)) AS unread_count')
            ->get()->keyBy('mailbox_id');

        $mailboxes = TeamMailbox::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (TeamMailbox $m) => [
                'id' => (int) $m->id,
                'address' => $m->address,
                'name' => $m->name,
                'brand' => TeamWorkspace::brand($m->brand),
                'open_count' => (int) ($perMailbox[$m->id]->open_count ?? 0),
                'unread_count' => (int) ($perMailbox[$m->id]->unread_count ?? 0),
            ])->values();

        $counts = TeamThread::query()->where('status', 'open')
            ->selectRaw('COUNT(*) FILTER (WHERE assignee_id IS NULL) AS unassigned, COUNT(*) FILTER (WHERE assignee_id = ?) AS mine, COUNT(*) AS open', [$me])
            ->first();

        return response()->json([
            'mailboxes' => $mailboxes,
            'counts' => [
                'unassigned' => (int) $counts->unassigned,
                'mine' => (int) $counts->mine,
                'open' => (int) $counts->open,
            ],
        ]);
    }

    public function threads(Request $request): JsonResponse
    {
        $data = $request->validate([
            'view' => ['nullable', Rule::in(self::VIEWS)],
            'mailbox' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $me = (int) $request->user()->id;
        $perPage = (int) ($data['per_page'] ?? 30);
        $page = (int) ($data['page'] ?? 1);

        $query = TeamThread::query();

        match ($data['view'] ?? 'open') {
            'unassigned' => $query->where('status', 'open')->whereNull('assignee_id'),
            'mine' => $query->where('status', 'open')->where('assignee_id', $me),
            'closed' => $query->where('status', 'closed'),
            default => $query->where('status', 'open'),
        };

        if (isset($data['mailbox'])) {
            $query->where('mailbox_id', (int) $data['mailbox']);
        }

        if (filled($q = trim((string) ($data['q'] ?? '')))) {
            $this->search($query, $q);
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $threads = InboxPresenter::withListRelations($query, $me)
            ->orderByRaw('last_message_at DESC NULLS LAST')->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'threads' => $threads->map(fn (TeamThread $t) => InboxPresenter::thread($t, $me))->values(),
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }

    /** One thread, oldest message first. Opening it marks it read for the caller. */
    public function show(Request $request, TeamThread $thread): JsonResponse
    {
        $me = (int) $request->user()->id;

        $messages = $thread->messages()
            ->with(['author', 'attachments' => fn ($a) => $a->select(['id', 'message_id', 'name', 'mime', 'size'])->orderBy('id')])
            ->orderBy('id')
            ->get();

        // Read up to the newest message in this response -- one filed while
        // it was being built stays unread until it is actually shown.
        if (($lastShown = $messages->last()?->id) !== null) {
            $this->inbox->markRead($thread, $me, (int) $lastShown);
        }

        return response()->json([
            'thread' => InboxPresenter::thread($thread->fresh(), $me),
            'messages' => $messages->map(fn (TeamMessage $m) => InboxPresenter::message($m))->values(),
            'context' => $this->context->forSacco($thread->sacco_id),
        ]);
    }

    public function update(Request $request, TeamThread $thread): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'closed'])],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
            'sacco_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (($data['assignee_id'] ?? null) !== null) {
            $data['assignee_id'] = (int) $data['assignee_id'];
            if (! TeamWorkspace::isMember($data['assignee_id'])) {
                throw ValidationException::withMessages(['assignee_id' => 'Threads can only be assigned to the team.']);
            }
        }

        if (($data['sacco_id'] ?? null) !== null) {
            $data['sacco_id'] = (int) $data['sacco_id'];
            if (! Sacco::withoutGlobalScopes()->whereKey($data['sacco_id'])->exists()) {
                throw ValidationException::withMessages(['sacco_id' => 'That SACCO does not exist.']);
            }
        }

        $thread = $this->inbox->update($thread, $data);

        return response()->json(['thread' => InboxPresenter::thread($thread, (int) $request->user()->id)]);
    }

    public function reply(Request $request, TeamThread $thread): JsonResponse
    {
        if (! TeamInbox::sendingEnabled()) {
            return $this->sendingOff();
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:50000'],
            'cc' => ['sometimes', 'nullable', 'array', 'max:'.((int) config('team_inbox.max_recipients') - 1)],
            'cc.*' => ['string', 'max:254'],
        ]);

        $body = $this->body($data['body']);
        $cc = $this->recipients($data['cc'] ?? [], 'cc');

        if ($throttled = $this->throttle($request)) {
            return $throttled;
        }

        $message = $this->inbox->reply($thread, $request->user(), $body, $cc);

        return response()->json(['message' => InboxPresenter::message($message->load(['author', 'attachments']))], 201);
    }

    public function note(Request $request, TeamThread $thread): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        $message = $this->inbox->note($thread, $request->user(), $this->body($data['body']));

        return response()->json(['message' => InboxPresenter::message($message->load(['author', 'attachments']))], 201);
    }

    public function compose(Request $request): JsonResponse
    {
        if (! TeamInbox::sendingEnabled()) {
            return $this->sendingOff();
        }

        $data = $request->validate([
            'mailbox_id' => ['required', 'integer'],
            'to' => ['required', 'array', 'min:1', 'max:'.(int) config('team_inbox.max_recipients')],
            'to.*' => ['string', 'max:254'],
            'subject' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:50000'],
        ]);

        $mailbox = TeamMailbox::query()->find((int) $data['mailbox_id']);
        if ($mailbox === null) {
            throw ValidationException::withMessages(['mailbox_id' => 'No such mailbox.']);
        }

        $subject = Addresses::line($data['subject'], 500);
        if ($subject === '') {
            throw ValidationException::withMessages(['subject' => 'An email needs a subject.']);
        }

        $to = $this->recipients($data['to'], 'to');
        $body = $this->body($data['body']);

        if ($throttled = $this->throttle($request)) {
            return $throttled;
        }

        $thread = $this->inbox->compose($mailbox, $request->user(), $to, $subject, $body);

        return response()->json(['thread' => InboxPresenter::thread($thread, (int) $request->user()->id)], 201);
    }

    /** Before anything is validated or stored: the console shows this as is. */
    private function sendingOff(): JsonResponse
    {
        return response()->json(['error' => 'Sending is not switched on yet.'], 409);
    }

    /**
     * Replies and new emails per team member per minute (429 past it).
     * Counted per person, not per IP: the team shares one office connection.
     * Only a send that passed validation counts.
     */
    private function throttle(Request $request): ?JsonResponse
    {
        $key = 'team-inbox-send:'.$request->user()->id;
        if (! RateLimiter::attempt($key, (int) config('team_inbox.send_per_minute', 30), fn () => true, 60)) {
            return response()->json(['error' => 'Too many emails in a minute. Wait a moment and send again.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        return null;
    }

    /**
     * Addresses as typed: each must be one valid address, with nothing that
     * could break into another header; duplicates collapse.
     *
     * @param  list<mixed>  $raw
     * @return list<string>
     */
    private function recipients(array $raw, string $field): array
    {
        $out = [];
        foreach ($raw as $i => $address) {
            $normal = is_string($address) && ! preg_match('/[\r\n\0]/', $address) ? Addresses::normalise($address) : null;
            if ($normal === null) {
                throw ValidationException::withMessages(["{$field}.{$i}" => 'Not a valid email address.']);
            }
            $out[] = $normal;
        }

        return array_values(array_unique($out));
    }

    private function body(string $body): string
    {
        $body = trim(str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $body));
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write something first.']);
        }

        return $body;
    }

    /**
     * Subject, sender, and the text of any message in the thread.
     *
     * The body match is an ILIKE over team_messages: a scan of the inbox's
     * own messages, which is fine at a support team's volume (thousands of
     * emails, not millions) and keeps search exact. If the inbox grows past
     * that, a pg_trgm GIN index on team_messages.body is the next step -- not
     * a change to this query.
     */
    private function search(Builder $query, string $q): void
    {
        $like = '%'.addcslashes($q, '%_\\').'%';
        $op = LikeSql::op();

        $query->where(fn (Builder $w) => $w->where('subject', $op, $like)
            ->orWhere('from_email', $op, $like)
            ->orWhere('from_name', $op, $like)
            ->orWhereIn('id', DB::table('team_messages')->select('thread_id')->where('body', $op, $like)));
    }
}
