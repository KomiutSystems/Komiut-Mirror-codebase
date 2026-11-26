<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Models\Team\TeamCard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How the team works the board: who ships, how fast, how steadily
 * (GET super/team/performance; the console's /super/kpis page).
 *
 * The words the console shows are defined here, so keep them exact:
 *
 *   SHIPPED     a card that reached Done in the period and is still there --
 *               a card moved out of Done and back counts once, on its last
 *               Done (team_cards.done_at). It counts for whoever HELD it at
 *               that moment (its TeamCardEvent). An unassigned card the
 *               platform closed itself is nobody's: team.platform_resolved.
 *   CYCLE TIME  hours from the card's first move into In progress to that
 *               Done; from its creation if it never went through In progress.
 *   ON TIME     of shipped cards with a due date, the share done on or before
 *               it (Nairobi date); null when none had one.
 *   WIP/OVERDUE right now: cards held and not Done / held and past due.
 *   ACTIONS     moves, comments and new cards a person made (the audit trail:
 *               team.card.moved / commented / created). The platform's are
 *               nobody's.
 *   FLOW        cards in each column at the end of each day, rebuilt from
 *               the events; "done" there is every card in Done then.
 *
 * The team's figures leave out what the platform resolved by itself: closing
 * a problem that went away is not the team's work, nor its speed.
 *
 * Periods are calendar days in Africa/Nairobi ending today. One query per
 * section over team-sized tables (the board holds hundreds of cards, not
 * millions), cached briefly.
 */
final class TeamPerformance
{
    public const DAYS = [7, 30, 90];

    private const TZ = 'Africa/Nairobi';

    private const CACHE_SECONDS = 60;

    private const CYCLE_CAP = 1000;

    /** @return array<string, mixed> */
    public function report(int $days, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(self::TZ)->startOfDay();

        return Cache::remember("team:performance:{$days}:".$today->toDateString(), self::CACHE_SECONDS,
            fn () => $this->compute($days, $today));
    }

    /** @return array<string, mixed> */
    private function compute(int $days, CarbonImmutable $today): array
    {
        $from = $today->subDays($days - 1);
        $end = $today->addDay();                 // exclusive
        $prevFrom = $from->subDays($days);

        $members = TeamWorkspace::members()->orderBy('firstname')->orderBy('lastname')->get();
        $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();

        $shipped = $this->shipped($from, $end);
        $shippedPrev = $this->shipped($prevFrom, $from);
        $team = $shipped->where('platform', false);
        $teamPrev = $shippedPrev->where('platform', false);

        $actions = $this->actions($from, $end, $memberIds);
        $now = TeamCard::query()->where('status', '!=', 'done')->whereNotNull('assignee_id')->get(['assignee_id', 'due_on']);
        $todayDate = $today->toDateString();

        $perMember = $members->map(function (User $u) use ($team, $teamPrev, $now, $actions, $todayDate) {
            $id = (int) $u->id;
            $mine = $team->where('member_id', $id);
            $held = $now->where('assignee_id', $id);

            return [
                'member' => TeamWorkspace::member($u),
                'shipped' => $mine->count(),
                'shipped_prev' => $teamPrev->where('member_id', $id)->count(),
                'cycle_median_hours' => $this->median($mine->pluck('cycle_hours')),
                'on_time_rate' => $this->onTime($mine),
                'wip' => $held->count(),
                'overdue' => $held->filter(fn ($c) => $c->due_on !== null && $c->due_on->toDateString() < $todayDate)->count(),
                'actions' => (int) $actions->where('member_id', $id)->sum('n'),
            ];
        })->values();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $today->toDateString(), 'days' => $days],
            'team' => [
                'shipped' => $team->count(),
                'shipped_prev' => $teamPrev->count(),
                'cycle_median_hours' => $this->median($team->pluck('cycle_hours')),
                'cycle_median_hours_prev' => $this->median($teamPrev->pluck('cycle_hours')),
                'on_time_rate' => $this->onTime($team),
                'on_time_rate_prev' => $this->onTime($teamPrev),
                'wip' => $now->count(),
                'platform_resolved' => $shipped->where('platform', true)->count(),
                // Shipped by a person while nobody held it: the team's, no one's row.
                'unassigned_shipped' => $team->whereNull('member_id')->count(),
            ],
            'members' => $perMember->all(),
            'activity' => $this->activity($team, $actions),
            'cycle' => $shipped->sortByDesc('done_at')->take(self::CYCLE_CAP)->map(fn (array $c) => [
                'card_id' => $c['card_id'],
                'title' => $c['title'],
                'member_id' => $c['member_id'],
                // member_id null is either the platform's own close or a person's
                // while nobody held the card; this says which.
                'platform' => $c['platform'],
                'done_at' => $c['done_at']->toIso8601String(),
                'cycle_hours' => $c['cycle_hours'],
            ])->values()->all(),
            'flow' => $this->flow($from, $today),
        ];
    }

    /**
     * Cards whose last Done falls in [$from, $end), with who held them then
     * and their cycle time.
     *
     * @return Collection<int, array{card_id:int,title:string,member_id:?int,platform:bool,done_at:CarbonImmutable,due_on:?string,cycle_hours:float}>
     */
    private function shipped(CarbonImmutable $from, CarbonImmutable $end): Collection
    {
        $rows = DB::table('team_cards as c')
            ->where('c.status', 'done')
            ->where('c.done_at', '>=', $from->utc())
            ->where('c.done_at', '<', $end->utc())
            // The event that put it in Done this last time: who held it, who moved it.
            ->leftJoinSub(
                DB::table('team_card_events')->selectRaw('distinct on (card_id) card_id, assignee_id, actor_id')
                    ->where('to_status', 'done')->orderBy('card_id')->orderByDesc('at')->orderByDesc('id'),
                'd', 'd.card_id', '=', 'c.id')
            // When it first went into In progress, if it ever did.
            ->leftJoinSub(
                DB::table('team_card_events')->selectRaw('card_id, min(at) as started_at')->where('to_status', 'doing')->groupBy('card_id'),
                's', 's.card_id', '=', 'c.id')
            ->get(['c.id', 'c.title', 'c.source', 'c.created_at', 'c.done_at', 'c.due_on', 'c.assignee_id as card_assignee',
                'd.card_id as has_event', 'd.assignee_id as done_assignee', 'd.actor_id as done_actor', 's.started_at']);

        return $rows->map(function ($r) {
            $doneAt = CarbonImmutable::parse($r->done_at, 'UTC');
            $created = CarbonImmutable::parse($r->created_at, 'UTC');
            $started = $r->started_at !== null ? CarbonImmutable::parse($r->started_at, 'UTC') : null;
            // A start after the Done (a card reopened, worked, re-shipped is
            // still measured from its FIRST start) can only come from bad data.
            $start = $started !== null && $started->lessThanOrEqualTo($doneAt) ? $started : $created;

            $holder = $r->has_event !== null ? $r->done_assignee : $r->card_assignee;
            $byPlatform = $r->has_event !== null && $r->done_actor === null && in_array($r->source, TeamCard::AUTO_SOURCES, true);

            return [
                'card_id' => (int) $r->id,
                'title' => (string) $r->title,
                'member_id' => $holder !== null ? (int) $holder : null,
                'platform' => $holder === null && $byPlatform,
                'done_at' => $doneAt,
                'due_on' => $r->due_on,
                'cycle_hours' => round(max(0, $doneAt->diffInSeconds($start, true)) / 3600, 2),
            ];
        })->values();
    }

    /**
     * Moves, comments and new cards each member made, per Nairobi day.
     *
     * @param  list<int>  $memberIds
     * @return Collection<int, object{date:string,member_id:int,n:int}>
     */
    private function actions(CarbonImmutable $from, CarbonImmutable $end, array $memberIds): Collection
    {
        if ($memberIds === []) {
            return collect();
        }

        return DB::table('audit_logs')
            // Moves (also a column changed in the card sheet, audited as an
            // edit of the status), comments and new cards. A reorder within a
            // column is not a move.
            ->where(fn ($q) => $q->whereIn('action', ['team.card.moved', 'team.card.commented', 'team.card.created'])
                ->orWhere(fn ($u) => $u->where('action', 'team.card.updated')
                    ->whereRaw("jsonb_exists((data::jsonb) -> 'fields', 'status')")))
            ->whereRaw("not (action = 'team.card.moved' and coalesce(data->>'from', '') = coalesce(data->>'to', ''))")
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $end->utc())
            ->where('actor_type', 'user')
            ->whereIn('actor_id', array_map('strval', $memberIds))
            ->selectRaw("to_char((created_at at time zone 'UTC') at time zone ?, 'YYYY-MM-DD') as date, actor_id, count(*) as n", [self::TZ])
            ->groupByRaw('1, 2')
            ->get()
            ->map(fn ($r) => (object) ['date' => $r->date, 'member_id' => (int) $r->actor_id, 'n' => (int) $r->n]);
    }

    /**
     * One row per (day, member) with anything in it.
     *
     * @param  Collection<int, array<string, mixed>>  $team
     * @param  Collection<int, object{date:string,member_id:int,n:int}>  $actions
     * @return list<array{date:string,member_id:int,shipped:int,actions:int}>
     */
    private function activity(Collection $team, Collection $actions): array
    {
        $cells = [];
        foreach ($team as $c) {
            if ($c['member_id'] === null) {
                continue;
            }
            $key = $c['done_at']->setTimezone(self::TZ)->toDateString().'|'.$c['member_id'];
            $cells[$key] ??= ['shipped' => 0, 'actions' => 0];
            $cells[$key]['shipped']++;
        }
        foreach ($actions as $a) {
            $key = $a->date.'|'.$a->member_id;
            $cells[$key] ??= ['shipped' => 0, 'actions' => 0];
            $cells[$key]['actions'] += $a->n;
        }

        ksort($cells);
        $out = [];
        foreach ($cells as $key => $v) {
            [$date, $member] = explode('|', $key);
            $out[] = ['date' => $date, 'member_id' => (int) $member, 'shipped' => $v['shipped'], 'actions' => $v['actions']];
        }

        return $out;
    }

    /**
     * Cards in each column at the end of each day, replayed from the events.
     *
     * @return list<array{date:string,todo:int,doing:int,waiting:int,done:int}>
     */
    private function flow(CarbonImmutable $from, CarbonImmutable $today): array
    {
        $events = DB::table('team_card_events')
            ->where('at', '<', $today->addDay()->utc())
            ->orderBy('at')->orderBy('id')
            ->get(['card_id', 'to_status', 'at']);

        $where = [];                               // card => column, as of the replay
        $i = 0;
        $n = $events->count();
        $out = [];

        for ($day = $from; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $endOfDay = $day->addDay()->utc();
            while ($i < $n && CarbonImmutable::parse($events[$i]->at, 'UTC')->lessThan($endOfDay)) {
                $where[(int) $events[$i]->card_id] = $events[$i]->to_status;
                $i++;
            }

            $counts = array_count_values($where) + ['todo' => 0, 'doing' => 0, 'waiting' => 0, 'done' => 0];
            $out[] = ['date' => $day->toDateString(), 'todo' => $counts['todo'], 'doing' => $counts['doing'], 'waiting' => $counts['waiting'], 'done' => $counts['done']];
        }

        return $out;
    }

    /** @param  Collection<int, float>  $values */
    private function median(Collection $values): ?float
    {
        $sorted = $values->sort()->values();
        $n = $sorted->count();
        if ($n === 0) {
            return null;
        }

        return round($n % 2 ? (float) $sorted[intdiv($n, 2)] : ((float) $sorted[$n / 2 - 1] + (float) $sorted[$n / 2]) / 2, 2);
    }

    /** @param  Collection<int, array<string, mixed>>  $cards */
    private function onTime(Collection $cards): ?float
    {
        $dated = $cards->filter(fn (array $c) => $c['due_on'] !== null);
        if ($dated->isEmpty()) {
            return null;
        }

        $kept = $dated->filter(fn (array $c) => $c['done_at']->setTimezone(self::TZ)->toDateString() <= substr((string) $c['due_on'], 0, 10));

        return round($kept->count() / $dated->count(), 2);
    }
}
