<?php

declare(strict_types=1);

namespace App\Services\Team;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Squares team_card_events with the audit trail and the cards themselves.
 *
 * The board audited every move before it wrote events, and a server still on
 * the previous build during a deploy keeps moving cards without them. This
 * adds only what is missing, so it can run at any time and any number of
 * times (the events migration, then team:square-card-events once a deploy
 * has rolled):
 *
 *   - a create or a move in the audit trail with no event for that column at
 *     that moment becomes one; a reorder within a column is not a move;
 *   - a column change made in the card sheet was audited by field name only
 *     (team.card.updated, fields: status). When a later move says the card
 *     came FROM a column the replay never put it in, that edit is where it
 *     went there: an event at the edit, by the editor;
 *   - finally a card whose last event is not where the card is now gets one
 *     that puts it there, by whoever last edited its column if anyone did.
 *
 * The holder at a reconstructed moment is not recorded anywhere; the card's
 * holder now stands in for it.
 */
final class CardEventsBackfill
{
    /** An event and an audit row this close together are the same moment. */
    private const SAME_MOMENT_SECONDS = 2;

    /** @return int events added */
    public function run(): int
    {
        $cards = DB::table('team_cards')->get(['id', 'status', 'assignee_id', 'created_at', 'updated_at', 'done_at'])->keyBy('id');
        if ($cards->isEmpty()) {
            return 0;
        }

        $existing = DB::table('team_card_events')->orderBy('at')->orderBy('id')
            ->get(['card_id', 'to_status', 'at'])->groupBy('card_id');

        $audit = DB::table('audit_logs')
            ->whereIn('action', ['team.card.created', 'team.card.moved', 'team.card.updated'])
            ->where('subject_type', 'team_card')
            ->orderBy('created_at')->orderBy('id')
            ->get(['action', 'actor_type', 'actor_id', 'subject_id', 'data', 'created_at'])
            ->groupBy(fn ($r) => (int) $r->subject_id);

        $add = [];
        foreach ($cards as $cardId => $card) {
            $have = ($existing->get($cardId) ?? collect())->map(fn ($e) => ['to' => $e->to_status, 'at' => CarbonImmutable::parse($e->at, 'UTC')])->values()->all();
            $derived = $this->derive(($audit->get($cardId) ?? collect()), $card);

            foreach ($derived as $e) {
                if (! $this->already($have, $e['to'], $e['at'])) {
                    $add[] = $e + ['card_id' => (int) $cardId];
                    $have[] = ['to' => $e['to'], 'at' => $e['at']];
                }
            }

            // Where the card is now must be where its events leave it.
            usort($have, fn ($a, $b) => $a['at'] <=> $b['at']);
            $last = end($have) ?: null;
            if ($last === null || $last['to'] !== $card->status) {
                $edit = $this->lastStatusEdit($audit->get($cardId) ?? collect());
                $at = $card->status === 'done' && $card->done_at
                    ? CarbonImmutable::parse($card->done_at, 'UTC')
                    : ($edit['at'] ?? CarbonImmutable::parse($card->updated_at ?? $card->created_at, 'UTC'));
                // Never before the last move it follows.
                if ($last !== null && $at->lessThan($last['at'])) {
                    $at = $last['at']->addSecond();
                }
                $add[] = ['card_id' => (int) $cardId, 'actor_id' => $edit['actor'] ?? null, 'from' => $last['to'] ?? null, 'to' => $card->status, 'at' => $at];
            }
        }

        foreach (array_chunk($add, 500) as $chunk) {
            DB::table('team_card_events')->insert(array_map(fn (array $e) => [
                'card_id' => $e['card_id'],
                'actor_id' => $e['actor_id'],
                'from_status' => $e['from'],
                'to_status' => $e['to'],
                'assignee_id' => $cards[$e['card_id']]->assignee_id,
                'at' => $e['at']->format('Y-m-d H:i:s'),
            ], $chunk));
        }

        return count($add);
    }

    /**
     * The card's column changes as the audit trail tells them.
     *
     * @param  Collection<int, object>  $rows  oldest first
     * @return list<array{actor_id:?int, from:?string, to:string, at:CarbonImmutable}>
     */
    private function derive(Collection $rows, object $card): array
    {
        $out = [];
        $last = null;
        $pendingEdit = null; // the latest sheet edit of the column since the last known move

        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $at = CarbonImmutable::parse($row->created_at, 'UTC');
            $actor = $row->actor_type === 'user' && ctype_digit((string) $row->actor_id) ? (int) $row->actor_id : null;

            if ($row->action === 'team.card.updated') {
                if (in_array('status', (array) ($data['fields'] ?? []), true)) {
                    $pendingEdit = ['actor' => $actor, 'at' => $at];
                }

                continue;
            }

            if ($row->action === 'team.card.created') {
                $to = is_string($data['status'] ?? null) ? $data['status'] : 'todo';
                $out[] = ['actor_id' => $actor, 'from' => null, 'to' => $to, 'at' => $at];
                $last = $to;
                $pendingEdit = null;

                continue;
            }

            // team.card.moved
            $from = is_string($data['from'] ?? null) ? $data['from'] : $last;
            $to = is_string($data['to'] ?? null) ? $data['to'] : null;
            if ($to === null) {
                continue;
            }

            // The card was somewhere the replay never put it: a sheet edit did.
            if ($from !== null && $from !== $last) {
                $out[] = [
                    'actor_id' => $pendingEdit['actor'] ?? null,
                    'from' => $last,
                    'to' => $from,
                    'at' => $pendingEdit['at'] ?? $at->subSecond(),
                ];
                $last = $from;
            }
            $pendingEdit = null;

            if ($to === $from) {
                continue; // a reorder within a column is not a move
            }

            $out[] = ['actor_id' => $actor, 'from' => $from, 'to' => $to, 'at' => $at];
            $last = $to;
        }

        if ($out === []) {
            // Audited before the board audited creates, or not at all.
            $out[] = ['actor_id' => null, 'from' => null, 'to' => 'todo', 'at' => CarbonImmutable::parse($card->created_at, 'UTC')];
        }

        return $out;
    }

    /** @param  list<array{to:string, at:CarbonImmutable}>  $have */
    private function already(array $have, string $to, CarbonImmutable $at): bool
    {
        foreach ($have as $e) {
            if ($e['to'] === $to && abs($e['at']->diffInSeconds($at, false)) <= self::SAME_MOMENT_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{actor:?int, at:CarbonImmutable}|null
     */
    private function lastStatusEdit(Collection $rows): ?array
    {
        $edit = null;
        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            if ($row->action === 'team.card.updated' && in_array('status', (array) ($data['fields'] ?? []), true)) {
                $edit = [
                    'actor' => $row->actor_type === 'user' && ctype_digit((string) $row->actor_id) ? (int) $row->actor_id : null,
                    'at' => CarbonImmutable::parse($row->created_at, 'UTC'),
                ];
            }
        }

        return $edit;
    }
}
