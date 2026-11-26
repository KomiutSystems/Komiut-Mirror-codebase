<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Enums\UserType;
use App\Models\Team\TeamCard;
use App\Models\User;
use App\Services\Team\CardEventsBackfill;
use App\Services\Team\TeamBoard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * GET super/team/performance: who ships, how fast, how steadily -- with the
 * definitions the console shows (TeamPerformance).
 */
final class TeamPerformanceTest extends QueueTestCase
{
    private const URL = '/api/v1/super/team/performance';

    private TeamBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->board = app(TeamBoard::class);
        // Midday Thursday 26 Nov 2026, Nairobi.
        $this->at('2026-11-26 12:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Set the clock to a Nairobi wall time. */
    private function at(string $nairobi): void
    {
        Carbon::setTestNow(Carbon::parse($nairobi, 'Africa/Nairobi')->utc());
        Cache::flush();
    }

    private function member(string $name = 'Wanjiru'): User
    {
        $u = $this->makeUser([]);
        $u->forceFill(['type' => UserType::Superadmin->value, 'firstname' => $name])->save();

        return $u->fresh();
    }

    /** Do it as $who, the way a request would. */
    private function as(User $who, callable $work): mixed
    {
        Auth::setUser($who);
        try {
            return $work();
        } finally {
            Auth::forgetGuards();
        }
    }

    private function report(int $days = 30): array
    {
        Cache::flush();
        Sanctum::actingAs($this->member('Viewer'));

        return $this->getJson(self::URL.'?days='.$days)->assertOk()->json();
    }

    private function memberRow(array $report, User $u): array
    {
        return collect($report['members'])->firstWhere('member.id', $u->id);
    }

    #[Test]
    public function only_the_team_may_see_it_and_only_for_7_30_or_90_days(): void
    {
        Sanctum::actingAs($this->makeUser([], $this->makeSacco()));
        $this->getJson(self::URL)->assertForbidden();

        Sanctum::actingAs($this->member());
        $this->getJson(self::URL.'?days=14')->assertUnprocessable();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('period.days', 30)
            ->assertJsonPath('period', ['from' => '2026-10-28', 'to' => '2026-11-26', 'days' => 30]);
    }

    #[Test]
    public function a_card_counts_for_whoever_held_it_when_it_reached_done(): void
    {
        $amina = $this->member('Amina');
        $brian = $this->member('Brian');

        $card = $this->as($amina, fn () => $this->board->create(['title' => 'Chase keys', 'assignee_id' => $amina->id], $amina));
        $this->as($amina, fn () => $this->board->move($card, 'done', null));
        // Handed to Brian afterwards: the shipping stays Amina's.
        $this->as($brian, fn () => $this->board->update($card->fresh(), ['assignee_id' => $brian->id]));

        $r = $this->report();
        $this->assertSame(1, $this->memberRow($r, $amina)['shipped']);
        $this->assertSame(0, $this->memberRow($r, $brian)['shipped']);
        $this->assertSame(1, $r['team']['shipped']);
        $this->assertSame($amina->id, $r['cycle'][0]['member_id']);
    }

    #[Test]
    public function what_the_platform_closed_by_itself_is_nobodys(): void
    {
        $sacco = $this->makeSacco();
        $this->board->openAuto('directory_claim', 'sacco', $sacco->id, 'Review claim');
        $this->board->closeAuto('directory_claim', 'sacco', $sacco->id, 'approved.');

        $r = $this->report();
        $this->assertSame(0, $r['team']['shipped'], 'not the team\'s work');
        $this->assertSame(1, $r['team']['platform_resolved']);
        $this->assertNull($r['cycle'][0]['member_id']);
        $this->assertSame(0, array_sum(array_column($r['members'], 'actions')), 'platform actions are nobody\'s');
    }

    #[Test]
    public function cycle_time_runs_from_the_first_in_progress_else_from_creation(): void
    {
        $me = $this->member();

        $worked = $this->as($me, fn () => $this->board->create(['title' => 'Worked', 'assignee_id' => $me->id], $me));
        $direct = $this->as($me, fn () => $this->board->create(['title' => 'Direct', 'assignee_id' => $me->id], $me));

        $this->at('2026-11-26 13:00');
        $this->as($me, fn () => $this->board->move($worked, 'doing', null));   // the clock starts here
        $this->at('2026-11-26 14:00');
        $this->as($me, fn () => $this->board->move($worked->fresh(), 'waiting', null));
        $this->as($me, fn () => $this->board->move($worked->fresh(), 'doing', null)); // a second start does not reset it
        $this->at('2026-11-26 16:00');
        $this->as($me, fn () => $this->board->move($worked->fresh(), 'done', null));
        $this->as($me, fn () => $this->board->move($direct, 'done', null));       // never in progress: from creation

        $r = $this->report();
        $cycles = collect($r['cycle'])->keyBy('card_id');
        $this->assertEquals(3.0, $cycles[$worked->id]['cycle_hours']);
        $this->assertEquals(4.0, $cycles[$direct->id]['cycle_hours']);
        $this->assertEquals(3.5, $r['team']['cycle_median_hours']);
        $this->assertEquals(3.5, $this->memberRow($r, $me)['cycle_median_hours']);
    }

    #[Test]
    public function a_card_reopened_and_shipped_again_counts_once_and_one_reopened_for_good_not_at_all(): void
    {
        $me = $this->member();
        $twice = $this->as($me, fn () => $this->board->create(['title' => 'Twice', 'assignee_id' => $me->id], $me));
        $undone = $this->as($me, fn () => $this->board->create(['title' => 'Undone', 'assignee_id' => $me->id], $me));

        $this->as($me, fn () => $this->board->move($twice, 'done', null));
        $this->as($me, fn () => $this->board->move($undone, 'done', null));
        $this->at('2026-11-26 15:00');
        $this->as($me, fn () => $this->board->move($twice->fresh(), 'doing', null));
        $this->as($me, fn () => $this->board->move($undone->fresh(), 'doing', null));
        $this->at('2026-11-26 17:00');
        $this->as($me, fn () => $this->board->move($twice->fresh(), 'done', null));

        $r = $this->report();
        $this->assertSame(1, $r['team']['shipped']);
        $this->assertSame([$twice->id], array_column($r['cycle'], 'card_id'));
        $this->assertSame(1, $this->memberRow($r, $me)['wip'], 'the reopened card is work in progress again');
    }

    #[Test]
    public function the_previous_figures_are_the_equal_period_just_before(): void
    {
        $me = $this->member();

        // Last week's 7-day window is 13-19 Nov; this one is 20-26 Nov.
        $this->at('2026-11-15 10:00');
        $old = $this->as($me, fn () => $this->board->create(['title' => 'Old', 'assignee_id' => $me->id, 'due_on' => '2026-11-14'], $me));
        $this->as($me, fn () => $this->board->move($old, 'done', null));  // a day late
        $this->at('2026-11-01 10:00');
        $older = $this->as($me, fn () => $this->board->create(['title' => 'Older', 'assignee_id' => $me->id], $me));
        $this->as($me, fn () => $this->board->move($older, 'done', null)); // outside both windows

        $this->at('2026-11-26 09:00');
        foreach (['A', 'B'] as $t) {
            $c = $this->as($me, fn () => $this->board->create(['title' => $t, 'assignee_id' => $me->id, 'due_on' => '2026-11-26'], $me));
            $this->as($me, fn () => $this->board->move($c, 'done', null));  // on time
        }
        $this->at('2026-11-26 12:00');

        $r = $this->report(7);
        $this->assertSame(['from' => '2026-11-20', 'to' => '2026-11-26', 'days' => 7], $r['period']);
        $this->assertSame(2, $r['team']['shipped']);
        $this->assertSame(1, $r['team']['shipped_prev']);
        $this->assertEquals(1.0, $r['team']['on_time_rate']);
        $this->assertEquals(0.0, $r['team']['on_time_rate_prev']);
        $this->assertSame(1, $this->memberRow($r, $me)['shipped_prev']);
    }

    #[Test]
    public function wip_overdue_actions_and_activity_are_per_person(): void
    {
        $me = $this->member('Amina');
        $idle = $this->member('Idle');
        $late = $this->as($me, fn () => $this->board->create(['title' => 'Late', 'assignee_id' => $me->id, 'due_on' => '2026-11-20'], $me));
        $this->as($me, fn () => $this->board->create(['title' => 'Open', 'assignee_id' => $me->id], $me));
        $this->as($me, fn () => $this->board->comment($late, $me, 'on it'));
        $this->as($me, fn () => $this->board->move($late->fresh(), 'doing', null));

        $r = $this->report();
        $mine = $this->memberRow($r, $me);
        $this->assertSame(2, $mine['wip']);
        $this->assertSame(1, $mine['overdue']);
        $this->assertSame(4, $mine['actions'], '2 new cards + 1 comment + 1 move');

        // Everyone on the team is listed, idle or not.
        $this->assertSame(['shipped' => 0, 'wip' => 0, 'actions' => 0], array_intersect_key($this->memberRow($r, $idle), ['shipped' => 0, 'wip' => 0, 'actions' => 0]));
        $this->assertSame([['date' => '2026-11-26', 'member_id' => $me->id, 'shipped' => 0, 'actions' => 4]], $r['activity']);
    }

    #[Test]
    public function flow_counts_each_column_at_the_end_of_every_day(): void
    {
        $me = $this->member();
        $this->at('2026-11-24 10:00');
        $a = $this->as($me, fn () => $this->board->create(['title' => 'A'], $me));
        $b = $this->as($me, fn () => $this->board->create(['title' => 'B'], $me));
        $this->at('2026-11-25 10:00');
        $this->as($me, fn () => $this->board->move($a, 'doing', null));
        $this->at('2026-11-26 10:00');
        $this->as($me, fn () => $this->board->move($a->fresh(), 'done', null));
        $this->as($me, fn () => $this->board->move($b, 'waiting', null));
        $this->at('2026-11-26 12:00');

        $flow = collect($this->report(7)['flow'])->keyBy('date');
        $this->assertCount(7, $flow);
        $this->assertSame(['date' => '2026-11-23', 'todo' => 0, 'doing' => 0, 'waiting' => 0, 'done' => 0], $flow['2026-11-23']);
        $this->assertSame(['date' => '2026-11-24', 'todo' => 2, 'doing' => 0, 'waiting' => 0, 'done' => 0], $flow['2026-11-24']);
        $this->assertSame(['date' => '2026-11-25', 'todo' => 1, 'doing' => 1, 'waiting' => 0, 'done' => 0], $flow['2026-11-25']);
        $this->assertSame(['date' => '2026-11-26', 'todo' => 0, 'doing' => 0, 'waiting' => 1, 'done' => 1], $flow['2026-11-26']);
    }

    #[Test]
    public function done_cards_stay_on_the_board_for_30_days(): void
    {
        $me = $this->member();
        $recent = $this->as($me, fn () => $this->board->create(['title' => 'Recent', 'status' => 'done'], $me));
        $month = $this->as($me, fn () => $this->board->create(['title' => 'Month', 'status' => 'done'], $me));
        $old = $this->as($me, fn () => $this->board->create(['title' => 'Old', 'status' => 'done'], $me));
        TeamCard::whereKey($month->id)->update(['done_at' => now()->subDays(29)]);
        TeamCard::whereKey($old->id)->update(['done_at' => now()->subDays(31)]);

        Sanctum::actingAs($me);
        $board = $this->getJson('/api/v1/super/team/board')->assertOk();
        $this->assertSame(30, $board->json('done_days'));
        $this->assertEqualsCanonicalizing([$recent->id, $month->id], array_column($board->json('columns.done'), 'id'));
        $this->assertNotNull(TeamCard::find($old->id), 'older done cards leave the board, never the database');
    }

    #[Test]
    public function the_backfill_rebuilds_the_moves_from_the_audit_trail_and_squares_each_card(): void
    {
        // What production had before the events table: cards and audit rows.
        $me = $this->member();
        $moved = $this->as($me, fn () => $this->board->create(['title' => 'Moved', 'assignee_id' => $me->id], $me));
        $this->at('2026-11-26 13:00');
        $this->as($me, fn () => $this->board->move($moved, 'doing', null));
        $this->at('2026-11-26 15:00');
        $this->as($me, fn () => $this->board->move($moved->fresh(), 'done', null));
        // A column change through an edit: audited by field name only.
        $edited = $this->as($me, fn () => $this->board->create(['title' => 'Edited'], $me));
        $this->as($me, fn () => $this->board->update($edited, ['status' => 'waiting']));
        DB::table('team_card_events')->delete();

        $migration = require base_path('database/migrations/2026_11_27_110000_create_team_card_events.php');
        (new \ReflectionMethod($migration, 'backfill'))->invoke($migration);

        $events = DB::table('team_card_events')->orderBy('id')->get();
        $this->assertSame([[null, 'todo'], ['todo', 'doing'], ['doing', 'done']],
            $events->where('card_id', $moved->id)->map(fn ($e) => [$e->from_status, $e->to_status])->values()->all());
        $this->assertSame('waiting', $events->where('card_id', $edited->id)->last()->to_status, 'squared with where it is now');
        $this->assertSame($me->id, (int) $events->where('card_id', $moved->id)->first()->actor_id);

        $this->at('2026-11-26 16:00');
        $this->assertEquals(2.0, collect($this->report()['cycle'])->firstWhere('card_id', $moved->id)['cycle_hours']);
    }

    #[Test]
    public function a_reorder_is_not_an_action_and_a_column_change_from_the_card_sheet_is(): void
    {
        $me = $this->member();
        $a = $this->as($me, fn () => $this->board->create(['title' => 'A', 'assignee_id' => $me->id], $me));   // 1 action
        $b = $this->as($me, fn () => $this->board->create(['title' => 'B', 'assignee_id' => $me->id], $me));   // 2
        $this->as($me, fn () => $this->board->move($b, 'todo', $a->id));                                       // reorder: none
        $this->as($me, fn () => $this->board->update($a->fresh(), ['title' => 'A2']));                          // title edit: none
        $this->as($me, fn () => $this->board->update($a->fresh(), ['status' => 'done']));                       // 3: the sheet's move

        $r = $this->report();
        $this->assertSame(3, $this->memberRow($r, $me)['actions']);
        $this->assertSame(1, $this->memberRow($r, $me)['shipped']);
    }

    #[Test]
    public function a_card_a_person_finished_while_nobody_held_it_is_the_teams_but_not_the_platforms(): void
    {
        $me = $this->member();
        $card = $this->as($me, fn () => $this->board->create(['title' => 'Nobody\'s'], $me));
        $this->as($me, fn () => $this->board->move($card, 'done', null));

        $r = $this->report();
        $this->assertSame(1, $r['team']['shipped']);
        $this->assertSame(1, $r['team']['unassigned_shipped']);
        $this->assertSame(0, $r['team']['platform_resolved']);
        $this->assertSame(['member_id' => null, 'platform' => false], array_intersect_key($r['cycle'][0], ['member_id' => 0, 'platform' => 0]));
    }

    #[Test]
    public function a_done_card_cannot_be_deleted(): void
    {
        // Finished work is what the team is paid on.
        $me = $this->member();
        $card = $this->as($me, fn () => $this->board->create(['title' => 'Shipped', 'assignee_id' => $me->id], $me));
        $this->as($me, fn () => $this->board->move($card, 'done', null));
        Sanctum::actingAs($me);

        $this->deleteJson('/api/v1/super/team/cards/'.$card->id)->assertUnprocessable();
        $this->assertNotNull(TeamCard::find($card->id));
    }

    #[Test]
    public function squaring_skips_reorders_fills_sheet_edits_catches_unrecorded_moves_and_adds_nothing_twice(): void
    {
        $me = $this->member();
        $card = $this->as($me, fn () => $this->board->create(['title' => 'C', 'assignee_id' => $me->id], $me));
        $this->at('2026-11-26 13:00');
        $this->as($me, fn () => $this->board->update($card->fresh(), ['status' => 'doing']));   // sheet edit
        $this->at('2026-11-26 14:00');
        $this->as($me, fn () => $this->board->move($card->fresh(), 'doing', null));              // reorder
        $this->at('2026-11-26 15:00');
        $this->as($me, fn () => $this->board->move($card->fresh(), 'done', null));
        $events = fn () => DB::table('team_card_events')->where('card_id', $card->id)->orderBy('at')->orderBy('id')->get();
        // What the audit trail alone has to go on.
        DB::table('team_card_events')->delete();

        $backfill = app(CardEventsBackfill::class);
        $backfill->run();
        $this->assertSame([[null, 'todo'], ['todo', 'doing'], ['doing', 'done']],
            $events()->map(fn ($e) => [$e->from_status, $e->to_status])->all(), 'the sheet edit is found, the reorder is not a move');
        $this->assertSame('2026-11-26 10:00:00', (string) $events()[1]->at, 'the sheet edit is when it went to In progress (13:00 EAT)');
        $this->assertSame(0, $backfill->run(), 'running it again adds nothing');

        // A move by a server still on the previous build: no event at all.
        $this->at('2026-11-26 16:00');
        TeamCard::whereKey($card->id)->update(['status' => 'waiting', 'done_at' => null, 'updated_at' => now()]);
        $this->assertSame(1, $backfill->run());
        $this->assertSame('waiting', $events()->last()->to_status);
    }
}
