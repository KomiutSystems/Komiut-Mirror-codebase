<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Team;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Team\TeamCard;
use App\Models\Team\TeamThread;
use App\Models\Vehicle;
use App\Services\Sql\LikeSql;
use App\Services\Team\CardPresenter;
use App\Services\Team\TeamBoard;
use App\Services\Team\TeamWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The ops board: Safetek's own work, across both brands.
 *
 * Sits behind the team-workspace gate (TeamWorkspace), never behind
 * isSuperAdmin() directly, so widening who is on the team is one change.
 */
class TeamBoardController extends Controller
{
    /** Done cards stay on the board this long. */
    private const DONE_DAYS = 14;

    public function __construct(private readonly TeamBoard $board) {}

    public function members(): JsonResponse
    {
        $members = TeamWorkspace::members()->orderBy('firstname')->orderBy('lastname')->get()
            ->map(fn ($u) => TeamWorkspace::member($u))->values();

        return response()->json(['members' => $members]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'assignee' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $query = TeamCard::query()->with(['assignee', 'creator'])->withCount('comments')
            ->where(fn (Builder $q) => $q->where('status', '!=', 'done')
                ->orWhere('done_at', '>=', now()->subDays(self::DONE_DAYS)));

        if ($request->filled('assignee')) {
            $assignee = $request->input('assignee') === 'me' ? (int) $request->user()->id : (int) $request->input('assignee');
            $query->where('assignee_id', $assignee);
        }

        if (filled($q = trim((string) $request->input('q')))) {
            $this->search($query, $q);
        }

        $cards = $query->orderBy('position')->orderBy('id')->get();
        $shaped = collect(CardPresenter::many($cards));

        $columns = [];
        foreach (TeamCard::STATUSES as $status) {
            $columns[$status] = $shaped->where('status', $status)->values()->all();
        }

        return response()->json(['columns' => $columns, 'done_days' => self::DONE_DAYS]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);
        $card = $this->board->create($data, $request->user());

        return response()->json(['card' => CardPresenter::one($card)], 201);
    }

    public function update(Request $request, TeamCard $card): JsonResponse
    {
        $data = $this->validated($request, creating: false, card: $card);
        $card = $this->board->update($card, $data);

        return response()->json(['card' => CardPresenter::one($card)]);
    }

    public function move(Request $request, TeamCard $card): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(TeamCard::STATUSES)],
            'before_id' => ['present', 'nullable', 'integer'],
        ]);

        $card = $this->board->move($card, $data['status'], isset($data['before_id']) ? (int) $data['before_id'] : null);

        return response()->json(['card' => CardPresenter::one($card)]);
    }

    public function destroy(TeamCard $card): Response
    {
        $this->board->delete($card);

        return response()->noContent();
    }

    public function comments(TeamCard $card): JsonResponse
    {
        $comments = $card->comments()->with('author')->orderBy('id')->get()
            ->map(fn ($c) => $this->comment($c))->values();

        return response()->json(['comments' => $comments]);
    }

    public function addComment(Request $request, TeamCard $card): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $comment = $this->board->comment($card, $request->user(), trim($data['body']));

        return response()->json(['comment' => $this->comment($comment->load('author'))], 201);
    }

    /** @return array<string, mixed> */
    private function comment($c): array
    {
        return [
            'id' => (int) $c->id,
            'author' => TeamWorkspace::member($c->author),
            'body' => $c->body,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating, ?TeamCard $card = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(TeamCard::STATUSES)],
            'priority' => ['sometimes', Rule::in(TeamCard::PRIORITIES)],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
            'link' => ['sometimes', 'nullable', 'array'],
            'link.type' => ['required_with:link', Rule::in(TeamCard::LINK_TYPES)],
            'link.id' => ['required_with:link', 'integer', 'min:1'],
        ]);

        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
            if ($data['title'] === '') {
                throw ValidationException::withMessages(['title' => 'A card needs a title.']);
            }
        }

        // Checked only when the assignee CHANGES: the console sends the whole
        // form on every save, and a card still held by someone who has since
        // left must stay editable until it is handed on.
        if (($data['assignee_id'] ?? null) !== null
            && (int) $data['assignee_id'] !== (int) ($card?->assignee_id ?? 0)
            && ! TeamWorkspace::isMember((int) $data['assignee_id'])) {
            throw ValidationException::withMessages(['assignee_id' => 'Cards can only be assigned to the team.']);
        }

        if (array_key_exists('link', $data) && $data['link'] !== null) {
            $data['link'] = ['type' => $data['link']['type'], 'id' => (int) $data['link']['id']];
            if (! TeamBoard::linkExists($data['link']['type'], $data['link']['id'])) {
                throw ValidationException::withMessages(['link' => 'Nothing to link: that '.$data['link']['type'].' does not exist.']);
            }
        }

        return $data;
    }

    /** Title, description, or what the card links to (SACCO name, plate, till, email subject). */
    private function search(Builder $query, string $q): void
    {
        $like = '%'.addcslashes($q, '%_\\').'%';
        $op = LikeSql::op();

        $query->where(function (Builder $w) use ($like, $op): void {
            $w->where('title', $op, $like)
                ->orWhere('description', $op, $like)
                ->orWhere(fn (Builder $l) => $l->where('link_type', 'sacco')
                    ->whereIn('link_id', Sacco::withoutGlobalScopes()->select('id')->where('name', $op, $like)))
                ->orWhere(fn (Builder $l) => $l->whereIn('link_type', ['vehicle', 'till'])
                    ->whereIn('link_id', Vehicle::withoutGlobalScopes()->select('id')
                        ->where(fn ($v) => $v->where('plate', $op, $like)->orWhere('merchant_short_code', $op, $like))))
                ->orWhere(fn (Builder $l) => $l->where('link_type', 'thread')
                    ->whereIn('link_id', TeamThread::query()->select('id')->where('subject', $op, $like)));
        });
    }
}
