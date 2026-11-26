<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\Team\TeamCard;
use App\Models\User;
use App\Services\Team\TeamBoard;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

final class TeamBoardTest extends QueueTestCase
{
    private const API = '/api/v1/super/team/';

    private function teamMember(): User
    {
        $user = $this->makeUser([]);
        $user->forceFill(['type' => UserType::Superadmin->value])->save();

        return $user->fresh();
    }

    /** @return list<int> the ids in one column, in board order */
    private function column(string $status): array
    {
        return array_column($this->getJson(self::API.'board')->assertOk()->json("columns.{$status}"), 'id');
    }

    private function card(string $title, string $status = 'todo'): int
    {
        return (int) $this->postJson(self::API.'cards', ['title' => $title, 'status' => $status])->assertCreated()->json('card.id');
    }

    #[Test]
    public function only_the_team_may_open_the_workspace(): void
    {
        $this->getJson(self::API.'board')->assertUnauthorized();

        Sanctum::actingAs($this->makeUser([], $this->makeSacco()));
        $this->getJson(self::API.'board')->assertForbidden();
        $this->postJson(self::API.'cards', ['title' => 'x'])->assertForbidden();

        $inactive = $this->teamMember();
        $inactive->forceFill(['status' => false])->save();
        Sanctum::actingAs($inactive->fresh());
        $this->getJson(self::API.'board')->assertForbidden();
    }

    #[Test]
    public function members_are_the_active_team(): void
    {
        $me = $this->teamMember();
        $other = $this->teamMember();
        $gone = $this->teamMember();
        $gone->forceFill(['status' => false])->save();
        $outsider = $this->makeUser([]);
        Sanctum::actingAs($me);

        $ids = array_column($this->getJson(self::API.'members')->assertOk()->json('members'), 'id');

        $this->assertContains($me->id, $ids);
        $this->assertContains($other->id, $ids);
        $this->assertNotContains($gone->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    #[Test]
    public function a_card_is_made_edited_and_shaped_as_the_console_reads_it(): void
    {
        $me = $this->teamMember();
        $sacco = $this->makeSacco();
        Sanctum::actingAs($me);

        $card = $this->postJson(self::API.'cards', [
            'title' => 'Lydia sends the keys',
            'priority' => 'high',
            'due_on' => '2026-11-30',
            'assignee_id' => $me->id,
            'link' => ['type' => 'sacco', 'id' => $sacco->id],
        ])->assertCreated()->json('card');

        $this->assertSame('todo', $card['status']);
        $this->assertSame('manual', $card['source']);
        $this->assertSame($me->id, $card['assignee']['id']);
        $this->assertSame($me->id, $card['created_by']['id']);
        $this->assertSame(['type' => 'sacco', 'id' => $sacco->id, 'label' => $sacco->name, 'sacco_id' => $sacco->id, 'brand' => null], $card['link']);
        $this->assertSame('2026-11-30', $card['due_on']);
        $this->assertSame(0, $card['comments_count']);

        $patched = $this->patchJson(self::API.'cards/'.$card['id'], ['status' => 'waiting', 'link' => null, 'due_on' => null])
            ->assertOk()->json('card');
        $this->assertSame('waiting', $patched['status']);
        $this->assertNull($patched['link']);
        $this->assertNull($patched['due_on']);
        $this->assertSame('Lydia sends the keys', $patched['title']);
    }

    #[Test]
    public function only_a_team_member_can_be_assigned_and_only_real_things_linked(): void
    {
        Sanctum::actingAs($this->teamMember());

        $this->postJson(self::API.'cards', ['title' => 'x', 'assignee_id' => $this->makeUser([])->id])->assertUnprocessable();
        $this->postJson(self::API.'cards', ['title' => 'x', 'link' => ['type' => 'sacco', 'id' => 999999]])->assertUnprocessable();
        $this->postJson(self::API.'cards', ['title' => 'x', 'link' => ['type' => 'bus', 'id' => 1]])->assertUnprocessable();
        $this->postJson(self::API.'cards', ['title' => '   '])->assertUnprocessable();
    }

    #[Test]
    public function a_move_lands_directly_above_its_neighbour_or_at_the_bottom(): void
    {
        Sanctum::actingAs($this->teamMember());
        $a = $this->card('A');
        $b = $this->card('B');
        $c = $this->card('C');
        $this->assertSame([$a, $b, $c], $this->column('todo'));

        $this->postJson(self::API."cards/{$c}/move", ['status' => 'todo', 'before_id' => $a])->assertOk();
        $this->assertSame([$c, $a, $b], $this->column('todo'));

        $this->postJson(self::API."cards/{$c}/move", ['status' => 'todo', 'before_id' => $b])->assertOk();
        $this->assertSame([$a, $c, $b], $this->column('todo'));

        $this->postJson(self::API."cards/{$a}/move", ['status' => 'doing', 'before_id' => null])->assertOk();
        $this->assertSame([$c, $b], $this->column('todo'));
        $this->assertSame([$a], $this->column('doing'));

        // The neighbour must be in the target column.
        $this->postJson(self::API."cards/{$b}/move", ['status' => 'doing', 'before_id' => $c])->assertUnprocessable();
    }

    #[Test]
    public function the_order_survives_when_the_gap_between_neighbours_runs_out(): void
    {
        // Each drop directly above "bottom" halves the gap; ~30 halvings
        // exhaust it and force a renumber. Through the service: 80 HTTP calls
        // would trip the API's own rate limit.
        $board = app(TeamBoard::class);
        $make = fn (string $t) => $board->create(['title' => $t], null);
        $top = $make('top');
        $bottom = $make('bottom');

        $expected = [$top->id];
        for ($i = 0; $i < 40; $i++) {
            $card = $make("n{$i}");
            $board->move($card, 'todo', $bottom->id);
            $expected[] = $card->id;
        }
        $expected[] = $bottom->id;

        Sanctum::actingAs($this->teamMember());
        $this->assertSame($expected, $this->column('todo'));
    }

    #[Test]
    public function done_shows_only_the_last_fortnight_and_leaving_done_clears_the_clock(): void
    {
        Sanctum::actingAs($this->teamMember());
        $old = $this->card('old', 'done');
        $recent = $this->card('recent', 'done');
        TeamCard::whereKey($old)->update(['done_at' => now()->subDays(15)]);

        $this->assertSame([$recent], $this->column('done'));
        $this->assertSame(14, $this->getJson(self::API.'board')->json('done_days'));

        $this->postJson(self::API."cards/{$recent}/move", ['status' => 'doing', 'before_id' => null])->assertOk();
        $this->assertNull(TeamCard::find($recent)->done_at);
    }

    #[Test]
    public function the_board_filters_by_assignee_and_searches_titles_and_link_labels(): void
    {
        $me = $this->teamMember();
        $other = $this->teamMember();
        $world = $this->makeWorld();
        Sanctum::actingAs($me);

        $mine = (int) $this->postJson(self::API.'cards', ['title' => 'Call NICCO', 'assignee_id' => $me->id])->json('card.id');
        $theirs = (int) $this->postJson(self::API.'cards', ['title' => 'Other', 'assignee_id' => $other->id])->json('card.id');
        $bus = (int) $this->postJson(self::API.'cards', ['title' => 'Bus problem', 'link' => ['type' => 'vehicle', 'id' => $world['vehicle']->id]])->json('card.id');

        $this->assertSame([$mine], array_column($this->getJson(self::API.'board?assignee=me')->json('columns.todo'), 'id'));
        $this->assertSame([$theirs], array_column($this->getJson(self::API.'board?assignee='.$other->id)->json('columns.todo'), 'id'));
        $this->assertSame([$mine], array_column($this->getJson(self::API.'board?q=nicco')->json('columns.todo'), 'id'));
        $this->assertSame([$bus], array_column($this->getJson(self::API.'board?q='.urlencode($world['vehicle']->plate))->json('columns.todo'), 'id'));
    }

    #[Test]
    public function comments_are_listed_oldest_first_and_go_with_the_card(): void
    {
        $me = $this->teamMember();
        Sanctum::actingAs($me);
        $id = $this->card('x');

        $this->postJson(self::API."cards/{$id}/comments", ['body' => 'first'])->assertCreated()
            ->assertJsonPath('comment.author.id', $me->id);
        $this->postJson(self::API."cards/{$id}/comments", ['body' => 'second'])->assertCreated();

        $this->assertSame(['first', 'second'], array_column($this->getJson(self::API."cards/{$id}/comments")->json('comments'), 'body'));
        $this->assertSame(2, collect($this->getJson(self::API.'board')->json('columns.todo'))->firstWhere('id', $id)['comments_count']);

        $this->deleteJson(self::API."cards/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('team_card_comments', ['card_id' => $id]);
    }

    #[Test]
    public function every_write_is_audited_by_who_made_it(): void
    {
        $me = $this->teamMember();
        Sanctum::actingAs($me);
        $id = $this->card('x');
        $this->patchJson(self::API."cards/{$id}", ['title' => 'y'])->assertOk();
        $this->postJson(self::API."cards/{$id}/move", ['status' => 'doing', 'before_id' => null])->assertOk();
        $this->postJson(self::API."cards/{$id}/comments", ['body' => 'c'])->assertCreated();
        $this->deleteJson(self::API."cards/{$id}")->assertNoContent();

        $actions = AuditLog::where('subject_type', 'team_card')->where('subject_id', (string) $id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['team.card.created', 'team.card.updated', 'team.card.moved', 'team.card.commented', 'team.card.deleted'], $actions);
        $this->assertSame([(string) $me->id], AuditLog::where('subject_id', (string) $id)->where('subject_type', 'team_card')->distinct()->pluck('actor_id')->all());
    }

    #[Test]
    public function the_platform_opens_one_card_per_problem_and_closes_it_itself(): void
    {
        $board = app(TeamBoard::class);
        $sacco = $this->makeSacco();

        $first = $board->openAuto('head_office', 'sacco', $sacco->id, 'NICCO has buses waiting for keys');
        $again = $board->openAuto('head_office', 'sacco', $sacco->id, 'NICCO has buses waiting for keys');
        $this->assertSame($first->id, $again->id);
        $this->assertSame('todo', $first->status);
        $this->assertNull($first->assignee_id);

        $closed = $board->closeAuto('head_office', 'sacco', $sacco->id, 'keys saved.');
        $this->assertSame('done', $closed->status);
        $this->assertStringContainsString('keys saved', $closed->comments()->latest('id')->first()->body);

        // Once closed, the same problem coming back is a new card.
        $reopened = $board->openAuto('head_office', 'sacco', $sacco->id, 'again');
        $this->assertNotSame($first->id, $reopened->id);
    }

    #[Test]
    public function a_card_someone_parked_in_waiting_is_left_there_with_a_comment(): void
    {
        $board = app(TeamBoard::class);
        $sacco = $this->makeSacco();
        $card = $board->openAuto('directory_claim', 'sacco', $sacco->id, 'Review claim');
        $board->move($card, 'waiting', null);

        $board->closeAuto('directory_claim', 'sacco', $sacco->id, 'claim approved.');

        $card->refresh();
        $this->assertSame('waiting', $card->status);
        $this->assertSame(1, $card->comments()->count());
    }

    #[Test]
    public function the_database_refuses_a_second_open_automatic_card(): void
    {
        $sacco = $this->makeSacco();
        app(TeamBoard::class)->openAuto('fraud', 'sacco', $sacco->id, 'Flag');

        $this->expectException(UniqueConstraintViolationException::class);
        TeamCard::create(['title' => 'dup', 'source' => 'fraud', 'link_type' => 'sacco', 'link_id' => $sacco->id, 'status' => 'doing']);
    }

    #[Test]
    public function a_card_held_by_someone_who_left_can_still_be_edited(): void
    {
        // The console resends the whole form, assignee included, on every save.
        Sanctum::actingAs($me = $this->teamMember());
        $left = $this->teamMember();
        $id = (int) $this->postJson(self::API.'cards', ['title' => 'x', 'assignee_id' => $left->id])->json('card.id');
        $left->forceFill(['status' => false])->save();

        $this->patchJson(self::API."cards/{$id}", ['title' => 'x', 'assignee_id' => $left->id, 'due_on' => '2026-12-01'])->assertOk();

        // Handing new work to them is still refused.
        $other = (int) $this->postJson(self::API.'cards', ['title' => 'y'])->json('card.id');
        $this->patchJson(self::API."cards/{$other}", ['assignee_id' => $left->id])->assertUnprocessable();
    }

    #[Test]
    public function an_automatic_card_stays_on_what_raised_it(): void
    {
        $sacco = $this->makeSacco();
        $card = app(TeamBoard::class)->openAuto('head_office', 'sacco', $sacco->id, 'Keys missing');
        Sanctum::actingAs($this->teamMember());

        // The console resends the same link with every save: fine.
        $this->patchJson(self::API."cards/{$card->id}", ['title' => 'Keys missing', 'link' => ['type' => 'sacco', 'id' => $sacco->id]])->assertOk();
        // Moving it to another SACCO, or off it, would orphan the auto-close.
        $this->patchJson(self::API."cards/{$card->id}", ['link' => ['type' => 'sacco', 'id' => $this->makeSacco()->id]])->assertUnprocessable();
        $this->patchJson(self::API."cards/{$card->id}", ['link' => null])->assertUnprocessable();
        $this->assertSame($sacco->id, $card->fresh()->link_id);
    }

    #[Test]
    public function reopening_an_old_automatic_card_while_a_newer_one_is_open_is_refused_cleanly(): void
    {
        $board = app(TeamBoard::class);
        $sacco = $this->makeSacco();
        $old = $board->openAuto('fraud', 'sacco', $sacco->id, 'Flag');
        $board->closeAuto('fraud', 'sacco', $sacco->id, 'resolved.');
        $board->openAuto('fraud', 'sacco', $sacco->id, 'Flag again');
        Sanctum::actingAs($this->teamMember());

        $this->postJson(self::API."cards/{$old->id}/move", ['status' => 'todo', 'before_id' => null])->assertUnprocessable();
        $this->patchJson(self::API."cards/{$old->id}", ['status' => 'doing'])->assertUnprocessable();
        $this->assertSame('done', $old->fresh()->status);
    }

    #[Test]
    public function an_email_thread_can_no_longer_be_linked(): void
    {
        // The team inbox is gone; its link type went with it.
        Sanctum::actingAs($this->teamMember());

        $this->postJson(self::API.'cards', ['title' => 'x', 'link' => ['type' => 'thread', 'id' => 1]])->assertUnprocessable();
    }
}
