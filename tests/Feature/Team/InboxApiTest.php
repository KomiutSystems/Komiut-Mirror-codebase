<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Enums\UserType;
use App\Jobs\Team\SendTeamInboxMessage;
use App\Models\AuditLog;
use App\Models\Sacco;
use App\Models\Team\TeamMailbox;
use App\Models\Team\TeamMessage;
use App\Models\Team\TeamThread;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use App\Services\Team\Inbox\AttachmentStore;
use App\Services\Team\Inbox\InboundMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The inbox endpoints as the console reads them
 * (KomiutWebApp docs/TEAM-WORKSPACE-API.md, section 4).
 */
final class InboxApiTest extends QueueTestCase
{
    private const API = '/api/v1/super/team/inbox/';

    private const THREAD_KEYS = ['id', 'mailbox_id', 'subject', 'snippet', 'from', 'status', 'assignee', 'unread', 'message_count', 'last_message_at', 'sacco', 'card_id'];

    private TeamMailbox $support;

    private TeamMailbox $billing;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-26 09:00:00');
        $this->support = TeamMailbox::create(['address' => 'support@komiut.test', 'name' => 'Komiut Support', 'brand' => 'komiut']);
        $this->billing = TeamMailbox::create(['address' => 'billing@2safiri.test', 'name' => '2Safiri Billing', 'brand' => 'safiri']);
    }

    private function teamMember(): User
    {
        $user = $this->makeUser([]);
        $user->forceFill(['type' => UserType::Superadmin->value])->save();

        return $user->fresh();
    }

    /** An email received a minute after the last one. */
    private function receive(string $subject, string $from = 'lydia@example.test', string $to = 'support@komiut.test', string $body = 'Hello', array $headers = []): TeamThread
    {
        Carbon::setTestNow(now()->addMinute());

        $raw = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nMessage-ID: <".uniqid('m', true)."@x.test>\r\n";
        foreach ($headers as $k => $v) {
            $raw .= "{$k}: {$v}\r\n";
        }
        if (! isset($headers['Content-Type'])) {
            $raw .= "Content-Type: text/plain; charset=UTF-8\r\n";
        }
        $raw .= "\r\n{$body}\r\n";

        return app(InboundMail::class)->ingest($raw, $to)->thread->fresh();
    }

    #[Test]
    public function only_the_team_may_open_the_inbox(): void
    {
        $this->getJson(self::API.'mailboxes')->assertUnauthorized();

        Sanctum::actingAs($this->makeUser([], $this->makeSacco()));
        $this->getJson(self::API.'mailboxes')->assertForbidden();
        $this->getJson(self::API.'threads')->assertForbidden();
        $this->postJson(self::API.'compose', [])->assertForbidden();
    }

    #[Test]
    public function mailboxes_carry_open_and_unread_counts_for_the_caller(): void
    {
        $me = $this->teamMember();
        $other = $this->teamMember();

        $a = $this->receive('A');
        $b = $this->receive('B');
        $c = $this->receive('C', to: 'billing@2safiri.test');
        $closed = $this->receive('D');
        $closed->forceFill(['status' => 'closed'])->save();
        $a->forceFill(['assignee_id' => $me->id])->save();
        $c->forceFill(['assignee_id' => $other->id])->save();
        TeamMailbox::create(['address' => 'gone@komiut.test', 'name' => 'Gone', 'is_active' => false]);

        Sanctum::actingAs($me);
        $this->getJson(self::API.'threads/'.$a->id)->assertOk(); // read A

        $response = $this->getJson(self::API.'mailboxes')->assertOk();
        $this->assertSame([
            ['id' => $this->billing->id, 'address' => 'billing@2safiri.test', 'name' => '2Safiri Billing', 'brand' => '2safiri', 'open_count' => 1, 'unread_count' => 1],
            ['id' => $this->support->id, 'address' => 'support@komiut.test', 'name' => 'Komiut Support', 'brand' => 'komiut', 'open_count' => 2, 'unread_count' => 1],
        ], $response->json('mailboxes'));
        $this->assertSame(['unassigned' => 1, 'mine' => 1, 'open' => 3], $response->json('counts'));

        // Read state is per person.
        Sanctum::actingAs($other);
        $response = $this->getJson(self::API.'mailboxes')->assertOk();
        $this->assertSame(2, $response->json('mailboxes.1.unread_count'));
        $this->assertSame(['unassigned' => 1, 'mine' => 1, 'open' => 3], $response->json('counts'));
        $this->assertNotNull($b);
    }

    #[Test]
    public function threads_list_by_view_and_mailbox_latest_activity_first(): void
    {
        $me = $this->teamMember();
        $first = $this->receive('First', from: 'Lydia W <lydia@example.test>');
        $second = $this->receive('Second', to: 'billing@2safiri.test');
        $third = $this->receive('Third');
        $closed = $this->receive('Closed');
        $closed->forceFill(['status' => 'closed'])->save();
        $third->forceFill(['assignee_id' => $me->id])->save();

        Sanctum::actingAs($me);

        $list = $this->getJson(self::API.'threads')->assertOk();
        $this->assertSame([$third->id, $second->id, $first->id], array_column($list->json('threads'), 'id'));
        $this->assertSame(3, $list->json('total'));
        $this->assertSame(1, $list->json('page'));
        $this->assertSame(1, $list->json('last_page'));

        $shape = $list->json('threads.2');
        $this->assertSame(self::THREAD_KEYS, array_keys($shape));
        $this->assertSame(['name' => 'Lydia W', 'email' => 'lydia@example.test'], $shape['from']);
        $this->assertSame('First', $shape['subject']);
        $this->assertSame('Hello', $shape['snippet']);
        $this->assertTrue($shape['unread']);
        $this->assertSame(1, $shape['message_count']);
        $this->assertSame('open', $shape['status']);
        $this->assertNull($shape['assignee']);
        $this->assertNull($shape['sacco']);
        $this->assertNull($shape['card_id']);

        $ids = fn (string $query) => array_column($this->getJson(self::API.'threads?'.$query)->assertOk()->json('threads'), 'id');
        $this->assertSame([$third->id], $ids('view=mine'));
        $this->assertSame([$second->id, $first->id], $ids('view=unassigned'));
        $this->assertSame([$closed->id], $ids('view=closed'));
        $this->assertSame([$second->id], $ids('mailbox='.$this->billing->id));
        $this->assertSame([$third->id, $first->id], $ids('view=open&mailbox='.$this->support->id));

        $page2 = $this->getJson(self::API.'threads?per_page=2&page=2')->assertOk();
        $this->assertSame([$first->id], array_column($page2->json('threads'), 'id'));
        $this->assertSame(['total' => 3, 'page' => 2, 'last_page' => 2], ['total' => $page2->json('total'), 'page' => $page2->json('page'), 'last_page' => $page2->json('last_page')]);

        $this->getJson(self::API.'threads?per_page=101')->assertUnprocessable();
        $this->getJson(self::API.'threads?view=everything')->assertUnprocessable();
    }

    #[Test]
    public function search_covers_subject_sender_and_every_message_body(): void
    {
        $byNote = $this->receive('Keys', from: 'a@example.test');
        $bySubject = $this->receive('Refund for KDY 614G', from: 'b@example.test');
        $bySender = $this->receive('Hi', from: 'Mary Wambui <mary@nicco.test>');
        $byBody = $this->receive('Hello', from: 'c@example.test', body: 'my 50% discount');
        $this->receive('Unrelated', from: 'd@example.test');

        $me = $this->teamMember();
        Sanctum::actingAs($me);
        $this->postJson(self::API.'threads/'.$byNote->id.'/notes', ['body' => 'Called them about kdy 614g'])->assertCreated();

        $ids = fn (string $q) => collect($this->getJson(self::API.'threads?q='.urlencode($q))->assertOk()->json('threads'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$byNote->id, $bySubject->id])->sort()->values()->all(), $ids('KDY 614g'));
        $this->assertSame([$bySender->id], $ids('wambui'));
        $this->assertSame([$bySender->id], $ids('NICCO.test'));
        // % is a character to look for, not a wildcard.
        $this->assertSame([$byBody->id], $ids('50%'));
    }

    #[Test]
    public function opening_a_thread_marks_it_read_and_shows_its_messages_oldest_first(): void
    {
        $me = $this->teamMember();
        $other = $this->teamMember();
        $thread = $this->receive('Till down', body: 'First email');

        Sanctum::actingAs($me);
        Carbon::setTestNow(now()->addMinute());
        $this->postJson(self::API.'threads/'.$thread->id.'/notes', ['body' => 'Looking'])->assertCreated();
        $this->receive('Re: Till down', body: 'Second email', headers: ['In-Reply-To' => '<'.$thread->messages()->first()->message_id_header.'>']);

        $this->assertTrue($this->getJson(self::API.'threads')->json('threads.0.unread'));

        $show = $this->getJson(self::API.'threads/'.$thread->id)->assertOk();
        $this->assertSame(['thread', 'messages', 'context'], array_keys($show->json()));
        $this->assertSame(self::THREAD_KEYS, array_keys($show->json('thread')));
        $this->assertFalse($show->json('thread.unread'));
        $this->assertSame(3, $show->json('thread.message_count'));
        // The note is activity, not the conversation: the snippet is the latest email.
        $this->assertSame('Second email', $show->json('thread.snippet'));
        $this->assertNull($show->json('context'));

        $messages = $show->json('messages');
        $this->assertSame(['in', 'note', 'in'], array_column($messages, 'direction'));
        $this->assertSame(['id', 'direction', 'from', 'to', 'cc', 'body', 'author', 'attachments', 'sent_at', 'send_error', 'created_at'], array_keys($messages[0]));
        $this->assertSame(['name' => null, 'email' => 'lydia@example.test'], $messages[0]['from']);
        $this->assertSame(['support@komiut.test'], $messages[0]['to']);
        $this->assertSame([], $messages[0]['cc']);
        $this->assertSame('First email', $messages[0]['body']);
        $this->assertNull($messages[0]['author']);
        $this->assertSame($me->id, $messages[1]['author']['id']);
        $this->assertSame('Looking', $messages[1]['body']);

        $this->assertFalse($this->getJson(self::API.'threads')->json('threads.0.unread'));

        // Still unread for everyone else; and a new email makes it unread again.
        Sanctum::actingAs($other);
        $this->assertTrue($this->getJson(self::API.'threads')->json('threads.0.unread'));
        Sanctum::actingAs($me);
        $this->receive('Re: Till down', body: 'Third', headers: ['In-Reply-To' => '<'.$thread->messages()->first()->message_id_header.'>']);
        $this->assertTrue($this->getJson(self::API.'threads')->json('threads.0.unread'));
    }

    #[Test]
    public function a_members_own_note_leaves_the_thread_read_for_them_only(): void
    {
        $me = $this->teamMember();
        $other = $this->teamMember();
        $thread = $this->receive('Hi');

        foreach ([$me, $other] as $user) {
            Sanctum::actingAs($user);
            $this->getJson(self::API.'threads/'.$thread->id)->assertOk();
        }

        Carbon::setTestNow(now()->addMinute());
        Sanctum::actingAs($me);
        $this->postJson(self::API.'threads/'.$thread->id.'/notes', ['body' => 'Handled by phone'])->assertCreated();
        $this->assertFalse($this->getJson(self::API.'threads')->json('threads.0.unread'));

        Sanctum::actingAs($other);
        $this->assertTrue($this->getJson(self::API.'threads')->json('threads.0.unread'));
    }

    #[Test]
    public function a_note_is_stored_and_never_sent(): void
    {
        Mail::fake();
        $me = $this->teamMember();
        $thread = $this->receive('Hi');
        Sanctum::actingAs($me);

        // Notes work whether or not sending is switched on.
        config(['team_inbox.send_enabled' => false]);
        $note = $this->postJson(self::API.'threads/'.$thread->id.'/notes', ['body' => "  Internal only\r\n  "])->assertCreated()->json('message');

        $this->assertSame('note', $note['direction']);
        $this->assertSame('Internal only', $note['body']);
        $this->assertSame($me->id, $note['author']['id']);
        $this->assertNull($note['sent_at']);
        $this->assertSame([], $note['to']);

        config(['team_inbox.send_enabled' => true]);
        $this->postJson(self::API.'threads/'.$thread->id.'/notes', ['body' => 'Second'])->assertCreated();
        $this->postJson(self::API.'threads/'.$thread->id.'/notes', ['body' => '   '])->assertUnprocessable();

        Bus::assertNotDispatched(SendTeamInboxMessage::class);
        Mail::assertNothingOutgoing();
        // The send job refuses a note outright, should one ever reach it.
        app()->call([new SendTeamInboxMessage((int) $note['id']), 'handle']);
        Mail::assertNothingOutgoing();
        $this->assertNull(TeamMessage::find($note['id'])->sent_at);

        $this->assertNull($thread->fresh()->first_reply_at);
        $this->assertSame(1, AuditLog::where('action', 'team.inbox.note')->where('data->message_id', $note['id'])->count());
    }

    #[Test]
    public function status_assignee_and_sacco_are_changed_by_patch(): void
    {
        $me = $this->teamMember();
        $sacco = $this->makeSacco();
        $thread = $this->receive('Hi');
        Sanctum::actingAs($me);

        $patched = $this->patchJson(self::API.'threads/'.$thread->id, ['status' => 'closed', 'assignee_id' => $me->id, 'sacco_id' => $sacco->id])
            ->assertOk()->json('thread');

        $this->assertSame('closed', $patched['status']);
        $this->assertSame(['id' => $me->id, 'name' => $me->firstname.' '.$me->lastname, 'email' => $me->email], $patched['assignee']);
        $this->assertSame(['id' => $sacco->id, 'name' => $sacco->name, 'brand' => null], $patched['sacco']);
        $this->assertTrue($thread->fresh()->sacco_set_by_staff);

        $this->patchJson(self::API.'threads/'.$thread->id, ['assignee_id' => null, 'status' => 'open'])->assertOk()
            ->assertJsonPath('thread.assignee', null)->assertJsonPath('thread.status', 'open')
            ->assertJsonPath('thread.sacco.id', $sacco->id);

        $this->patchJson(self::API.'threads/'.$thread->id, ['assignee_id' => $this->makeUser([])->id])->assertUnprocessable();
        $this->patchJson(self::API.'threads/'.$thread->id, ['sacco_id' => 999999])->assertUnprocessable();
        $this->patchJson(self::API.'threads/'.$thread->id, ['status' => 'snoozed'])->assertUnprocessable();
        $this->patchJson(self::API.'threads/999999', ['status' => 'closed'])->assertNotFound();

        $audit = AuditLog::where('action', 'team.inbox.thread.updated')->orderBy('id')->first();
        $this->assertSame(['thread_id' => $thread->id, 'fields' => ['status', 'assignee_id', 'sacco_id', 'sacco_set_by_staff']], $audit->data);
    }

    #[Test]
    public function the_matched_saccos_current_problems_are_the_threads_context(): void
    {
        $me = $this->teamMember();
        $owner = $this->makeUser([]);
        $seat = $this->makeSeat();
        $sacco = $this->makeSacco();
        $admin = $this->makeUser([], $sacco);

        $bus = fn (array $attrs) => tap($this->makeVehicle($sacco, $owner, $seat), fn (Vehicle $v) => Vehicle::withoutGlobalScopes()->whereKey($v->id)->update($attrs))->fresh();
        $a = $bus(['plate' => 'KDY 614G', 'merchant_short_code' => '111', 'till_registration_status' => TillRegistrar::NEEDS_HEAD_OFFICE]);
        $bus(['plate' => 'KDY 700A', 'merchant_short_code' => '112', 'till_registration_status' => TillRegistrar::REGISTERED]);
        $bus(['plate' => 'KDY 701A', 'merchant_short_code' => '113', 'till_registration_status' => TillRegistrar::BAD_KEYS]);
        $bus(['plate' => 'KDY 702A', 'merchant_short_code' => '114', 'till_registration_status' => TillRegistrar::PENDING]);
        // The bank's: not ours to register, never an issue here.
        $bus(['plate' => 'KDY 703A', 'merchant_short_code' => '880100', 'till_registration_status' => null]);
        $bus(['plate' => 'KDY 704A', 'merchant_short_code' => '115', 'coop_till' => '01100000000000', 'till_registration_status' => null]);
        $bus(['plate' => 'KDY 705A', 'merchant_short_code' => '116', 'till_registration_status' => TillRegistrar::BANK_ISSUED]);
        // No till at all.
        $bus(['plate' => 'KDY 706A']);

        $thread = $this->receive('Help', from: $admin->email);
        $this->assertSame($sacco->id, $thread->sacco_id);
        Sanctum::actingAs($me);

        $context = $this->getJson(self::API.'threads/'.$thread->id)->assertOk()->json('context');
        $this->assertSame(['issues' => [
            ['label' => 'KDY 614G · needs a head office', 'tone' => 'danger'],
            ['label' => 'KDY 701A · M-Pesa keys rejected', 'tone' => 'danger'],
            ['label' => '1 till registering', 'tone' => 'info'],
        ]], $context);

        // Past a handful, they are summed.
        $bus(['plate' => 'KDY 800A', 'merchant_short_code' => '117', 'till_registration_status' => TillRegistrar::NEEDS_HEAD_OFFICE]);
        $bus(['plate' => 'KDY 801A', 'merchant_short_code' => '118', 'till_registration_status' => TillRegistrar::NEEDS_HEAD_OFFICE]);
        $bus(['plate' => 'KDY 802A', 'till_number' => '5551', 'till_registration_status' => null]);
        Sacco::withoutGlobalScopes()->whereKey($sacco->id)->update(['status' => 0]);

        $issues = $this->getJson(self::API.'threads/'.$thread->id)->assertOk()->json('context.issues');
        $this->assertSame([
            ['label' => 'SACCO is inactive', 'tone' => 'warning'],
            ['label' => '5 tills unregistered', 'tone' => 'danger'],
            ['label' => '3 · needs a head office', 'tone' => 'danger'],
            ['label' => '1 · M-Pesa keys rejected', 'tone' => 'danger'],
            ['label' => '1 · till not registered', 'tone' => 'warning'],
            ['label' => '1 till registering', 'tone' => 'info'],
        ], $issues);
        $this->assertNotNull($a);
    }

    #[Test]
    public function attachments_download_only_through_a_fresh_signed_link_and_never_inline(): void
    {
        $me = $this->teamMember();
        $pdf = "%PDF-1.4\n\0\xFF binary";
        $raw = "From: lydia@example.test\r\nTo: support@komiut.test\r\nSubject: Statement\r\nMessage-ID: <att@x.test>\r\n"
            ."Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n--B\r\nContent-Type: text/plain\r\n\r\nAttached\r\n"
            ."--B\r\nContent-Type: text/html; name=\"x.pdf\"\r\nContent-Disposition: attachment; filename=\"Taarifa ya\r\n Malipo \\\"Nov\\\".pdf\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode($pdf)."\r\n--B--\r\n";
        $thread = app(InboundMail::class)->ingest($raw, 'support@komiut.test')->thread;

        Sanctum::actingAs($me);
        $attachment = $this->getJson(self::API.'threads/'.$thread->id)->assertOk()->json('messages.0.attachments.0');
        $this->assertSame(['id', 'name', 'size', 'url'], array_keys($attachment));
        $this->assertSame(strlen($pdf), $attachment['size']);
        $this->assertStringStartsWith('http', $attachment['url']);
        $this->assertStringContainsString('/api/v1/team-inbox/attachments/'.$attachment['id'].'?expires=', $attachment['url']);

        // No bearer token on a download: the browser just follows the link.
        $this->app['auth']->forgetGuards();
        $download = $this->get($attachment['url'])->assertOk();
        $this->assertSame($pdf, $download->getContent());
        // Our type from the extension, never the sender's text/html.
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString("\n", $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $download->headers->get('Content-Security-Policy'));

        $this->get('/api/v1/team-inbox/attachments/'.$attachment['id'])->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $attachment['url']))->assertForbidden();
        $this->get(str_replace('/attachments/'.$attachment['id'].'?', '/attachments/'.($attachment['id'] + 1).'?', $attachment['url']))->assertForbidden();

        Carbon::setTestNow(now()->addMinutes(11));
        $this->get($attachment['url'])->assertForbidden();
    }

    #[Test]
    public function a_stored_type_outside_the_allowlist_is_served_as_bytes(): void
    {
        $thread = $this->receive('x');
        $message = $thread->messages()->first();
        $id = app(AttachmentStore::class)->store($message->id, 'note.html', 'text/html', '<script>alert(1)</script>');

        Sanctum::actingAs($this->teamMember());
        $url = $this->getJson(self::API.'threads/'.$thread->id)->assertOk()->json('messages.0.attachments.0.url');
        $this->assertStringContainsString('/attachments/'.$id.'?', $url);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    }
}
