<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Models\Sacco;
use App\Models\Team\TeamCard;
use App\Models\Team\TeamCardComment;
use App\Models\Team\TeamThread;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Platform\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to the ops board goes through here, so ordering, the done
 * clock, the audit trail and the one-open-automatic-card rule each live in
 * one place -- whether a person or the platform is making the change.
 */
final class TeamBoard
{
    /** Gap between neighbours in a freshly ordered column. */
    private const GAP = 1024.0;

    /** Below this the midpoint can no longer separate two neighbours. */
    private const MIN_GAP = 1e-6;

    /**
     * Who the audit trail names for the platform's own board work. Card work
     * runs after the commit of whatever request caused it -- a SACCO admin
     * saving keys, a driver signing in -- and must not be logged as theirs.
     */
    private const PLATFORM = ['type' => 'system', 'id' => null, 'label' => 'automatic cards'];

    /** Set while openAuto / closeAuto run: the actor their writes are audited as. */
    private ?array $actor = null;

    /**
     * @param  array{title:string, description?:?string, status?:string, priority?:string, due_on?:?string,
     *               assignee_id?:?int, link?:?array{type:string,id:int}}  $data  already validated
     */
    public function create(array $data, ?User $by, string $source = 'manual'): TeamCard
    {
        return DB::transaction(function () use ($data, $by, $source): TeamCard {
            $status = $data['status'] ?? 'todo';
            $link = $data['link'] ?? null;
            $thread = null;

            if ($link !== null && $link['type'] === 'thread') {
                $thread = TeamThread::query()->lockForUpdate()->find($link['id']);
                if ($thread?->card_id !== null) {
                    throw ValidationException::withMessages(['link' => 'This email already has a card (#'.$thread->card_id.').']);
                }
                $source = 'inbox';
            }

            $card = TeamCard::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $status,
                'priority' => $data['priority'] ?? 'normal',
                'due_on' => $data['due_on'] ?? null,
                'assignee_id' => $data['assignee_id'] ?? null,
                'link_type' => $link['type'] ?? null,
                'link_id' => $link['id'] ?? null,
                'source' => $source,
                'created_by' => $by?->id,
                'position' => $this->bottomOf($status),
                'done_at' => $status === 'done' ? now() : null,
            ]);

            $thread?->forceFill(['card_id' => $card->id])->save();

            $this->audit('team.card.created', $card, ['source' => $source, 'status' => $status]);

            return $card;
        });
    }

    /** @param  array<string, mixed>  $data  already validated; only the keys present change */
    public function update(TeamCard $card, array $data): TeamCard
    {
        return DB::transaction(function () use ($card, $data): TeamCard {
            $card = TeamCard::query()->lockForUpdate()->findOrFail($card->id);

            foreach (['title', 'description', 'priority', 'due_on', 'assignee_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $card->{$field} = $data[$field];
                }
            }

            if (array_key_exists('link', $data)) {
                $this->relink($card, $data['link']);
            }

            if (array_key_exists('status', $data) && $data['status'] !== $card->status) {
                $this->placeIn($card, $data['status'], null);
            }

            $changed = array_keys($card->getDirty());
            $card->save();

            if ($changed !== []) {
                // Field names, not values: a title can carry a person's name.
                $this->audit('team.card.updated', $card, ['fields' => $changed]);
            }

            return $card;
        });
    }

    /**
     * Put the card in $status directly above $beforeId (null = at the bottom).
     * A neighbour, not an index: the person may be looking at a filtered board.
     */
    public function move(TeamCard $card, string $status, ?int $beforeId): TeamCard
    {
        return DB::transaction(function () use ($card, $status, $beforeId): TeamCard {
            $card = TeamCard::query()->lockForUpdate()->findOrFail($card->id);
            $from = $card->status;

            $this->placeIn($card, $status, $beforeId);
            $card->save();

            $this->audit('team.card.moved', $card, ['from' => $from, 'to' => $status, 'before_id' => $beforeId]);

            return $card;
        });
    }

    public function delete(TeamCard $card): void
    {
        DB::transaction(function () use ($card): void {
            $this->audit('team.card.deleted', $card, ['source' => $card->source, 'status' => $card->status]);
            $card->delete(); // comments cascade; a thread's card_id is nulled
        });
    }

    public function comment(TeamCard $card, ?User $by, string $body): TeamCardComment
    {
        $comment = TeamCardComment::create(['card_id' => $card->id, 'author_id' => $by?->id, 'body' => $body]);
        $card->touch();

        // A comment with no author is the platform's, whoever is signed in.
        $this->audit('team.card.commented', $card, ['comment_id' => (int) $comment->id, 'system' => $by === null], $by === null ? self::PLATFORM : null);

        return $comment;
    }

    /**
     * The platform raising a card: unassigned, in To do, linked. One open card
     * per (source, linked thing) -- if one is open, that card is returned
     * untouched. The partial unique index team_cards_one_open_auto makes this
     * hold even when two workers raise the same thing at once.
     */
    public function openAuto(string $source, string $linkType, int $linkId, string $title, ?string $description = null, string $priority = 'normal'): TeamCard
    {
        $open = fn () => TeamCard::query()
            ->where('source', $source)->where('link_type', $linkType)->where('link_id', $linkId)
            ->where('status', '!=', 'done')->first();

        if ($existing = $open()) {
            return $existing;
        }

        try {
            return $this->asPlatform(fn () => DB::transaction(fn () => $this->create([
                'title' => mb_strimwidth($title, 0, 200, '…'),
                'description' => $description,
                'priority' => $priority,
                'link' => ['type' => $linkType, 'id' => $linkId],
            ], null, $source)));
        } catch (UniqueConstraintViolationException) {
            return $open() ?? throw new \RuntimeException("Automatic card for {$source} {$linkType}#{$linkId} vanished");
        }
    }

    /**
     * The platform closing its own card because the problem went away: moved
     * to Done with a comment saying why -- unless a person parked it in
     * Waiting, which is a decision to leave to them: it only gets the comment.
     */
    public function closeAuto(string $source, string $linkType, int $linkId, string $why): ?TeamCard
    {
        $card = TeamCard::query()
            ->where('source', $source)->where('link_type', $linkType)->where('link_id', $linkId)
            ->where('status', '!=', 'done')->first();

        if ($card === null) {
            return null;
        }

        if ($card->status === 'waiting') {
            $this->comment($card, null, 'Resolved on the platform: '.$why.' Left in Waiting because someone parked it there.');

            return $card;
        }

        return $this->asPlatform(function () use ($card, $why): TeamCard {
            $this->move($card, 'done', null);
            $this->comment($card, null, 'Closed itself: '.$why);

            return $card->refresh();
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function asPlatform(callable $work): mixed
    {
        $outer = $this->actor;
        $this->actor = self::PLATFORM;
        try {
            return $work();
        } finally {
            $this->actor = $outer;
        }
    }

    /** Does the thing a link points at exist? */
    public static function linkExists(string $type, int $id): bool
    {
        return match ($type) {
            'sacco' => Sacco::withoutGlobalScopes()->whereKey($id)->exists(),
            'vehicle', 'till' => Vehicle::withoutGlobalScopes()->whereKey($id)->exists(),
            'thread' => TeamThread::query()->whereKey($id)->exists(),
            default => false,
        };
    }

    /** @param  array{type:string,id:int}|null  $link */
    private function relink(TeamCard $card, ?array $link): void
    {
        // Unhook a thread that pointed here; hook up a new one.
        if ($card->link_type === 'thread' && ($link === null || $link['type'] !== 'thread' || $link['id'] !== $card->link_id)) {
            TeamThread::query()->where('id', $card->link_id)->where('card_id', $card->id)->update(['card_id' => null]);
        }

        if ($link !== null && $link['type'] === 'thread') {
            $thread = TeamThread::query()->lockForUpdate()->findOrFail($link['id']);
            if ($thread->card_id !== null && $thread->card_id !== $card->id) {
                throw ValidationException::withMessages(['link' => 'This email already has a card (#'.$thread->card_id.').']);
            }
            $thread->forceFill(['card_id' => $card->id])->save();
        }

        $card->link_type = $link['type'] ?? null;
        $card->link_id = $link['id'] ?? null;
    }

    /** Give $card a position in $status above $beforeId, and keep done_at honest. */
    private function placeIn(TeamCard $card, string $status, ?int $beforeId): void
    {
        $card->position = $this->positionFor($card, $status, $beforeId);

        if ($status === 'done' && $card->status !== 'done') {
            $card->done_at = now();
        } elseif ($status !== 'done') {
            $card->done_at = null;
        }

        $card->status = $status;
    }

    private function positionFor(TeamCard $card, string $status, ?int $beforeId): float
    {
        // To the bottom needs only the column's last position, never a
        // renumbering -- so no reason to read and lock the whole column. Done
        // only grows (every automatic close lands there), and this is the
        // path those closes take, on request paths and the till job.
        if ($beforeId === null) {
            return ((float) TeamCard::query()->where('status', $status)->where('id', '!=', $card->id)->max('position')) + self::GAP;
        }

        // The column, locked, in order, without the card being moved.
        $column = TeamCard::query()
            ->where('status', $status)->where('id', '!=', $card->id)
            ->orderBy('position')->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'position']);

        $index = $column->search(fn (TeamCard $c) => (int) $c->id === $beforeId);
        if ($index === false) {
            throw ValidationException::withMessages(['before_id' => 'That card is not in the '.$status.' column.']);
        }

        $before = (float) $column[$index]->position;
        $above = $index > 0 ? (float) $column[$index - 1]->position : null;

        if ($above === null) {
            return $before - self::GAP;
        }

        if ($before - $above > self::MIN_GAP) {
            return ($above + $before) / 2;
        }

        // The gap has run out: renumber the column, then take the midpoint.
        foreach ($column->values() as $i => $c) {
            TeamCard::query()->whereKey($c->id)->update(['position' => ($i + 1) * self::GAP]);
        }

        return ($index * self::GAP + ($index + 1) * self::GAP) / 2;
    }

    private function bottomOf(string $status): float
    {
        return ((float) TeamCard::query()->where('status', $status)->max('position')) + self::GAP;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type:string, id:?string, label:?string}|null  $actor  null: the signed-in person
     */
    private function audit(string $action, TeamCard $card, array $data, ?array $actor = null): void
    {
        AuditLogger::record($action, ['card_id' => (int) $card->id] + $data, $actor ?? $this->actor, subject: ['type' => 'team_card', 'id' => (string) $card->id]);
    }
}
