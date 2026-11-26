<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Enums\UserType;
use App\Jobs\Team\SendTeamInboxMessage;
use App\Models\AuditLog;
use App\Models\Team\TeamMailbox;
use App\Models\Team\TeamMessage;
use App\Models\Team\TeamThread;
use App\Models\User;
use App\Services\Team\Inbox\InboundMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Replies and new emails from the console: stored at once, sent on the queue
 * with the threading headers a customer's mail client needs.
 */
final class InboxSendTest extends QueueTestCase
{
    private const API = '/api/v1/super/team/inbox/';

    private TeamMailbox $support;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-26 09:00:00');
        config(['team_inbox.send_enabled' => true, 'team_inbox.mailer' => 'array']);
        $this->support = TeamMailbox::create(['address' => 'support@komiut.test', 'name' => 'Komiut Support', 'brand' => 'komiut']);

        $this->me = $this->makeUser([]);
        $this->me->forceFill(['type' => UserType::Superadmin->value])->save();
        Sanctum::actingAs($this->me->fresh());
    }

    private function receive(string $messageId = 'q1@mail.example.test', array $headers = []): TeamThread
    {
        $raw = "From: Lydia W <lydia@example.test>\r\nTo: support@komiut.test\r\nSubject: Till not working\r\nMessage-ID: <{$messageId}>\r\n";
        foreach ($headers as $k => $v) {
            $raw .= "{$k}: {$v}\r\n";
        }

        return app(InboundMail::class)->ingest($raw."\r\nThe till is down.\r\n", 'support@komiut.test')->thread->fresh();
    }

    /** Run the queued send for one message, as the worker would. */
    private function deliver(int $messageId): void
    {
        app()->call([new SendTeamInboxMessage($messageId), 'handle']);
    }

    /** @return Collection<int, Email> what the array mailer was handed */
    private function sent(): Collection
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage());
    }

    #[Test]
    public function nothing_is_stored_or_sent_while_sending_is_off(): void
    {
        config(['team_inbox.send_enabled' => false]);
        $thread = $this->receive();

        $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => 'Hi'])
            ->assertStatus(409)->assertExactJson(['error' => 'Sending is not switched on yet.']);
        $this->postJson(self::API.'compose', ['mailbox_id' => $this->support->id, 'to' => ['a@example.test'], 'subject' => 'x', 'body' => 'y'])
            ->assertStatus(409)->assertExactJson(['error' => 'Sending is not switched on yet.']);

        $this->assertSame(1, TeamMessage::count());
        $this->assertSame(1, TeamThread::count());
        Bus::assertNotDispatched(SendTeamInboxMessage::class);
    }

    #[Test]
    public function a_reply_is_stored_now_and_sent_on_the_queue_with_threading_headers(): void
    {
        config(['team_inbox.reply_to_plus_addressing' => true]);
        $thread = $this->receive('q1@mail.example.test', ['References' => '<older@mail.example.test>']);

        Carbon::setTestNow(now()->addMinutes(30));
        $message = $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => "We are on it.\nKomiut", 'cc' => ['Boss@Example.test', 'boss@example.test']])
            ->assertCreated()->json('message');

        $this->assertSame('out', $message['direction']);
        $this->assertSame(['name' => 'Komiut Support', 'email' => 'support@komiut.test'], $message['from']);
        $this->assertSame(['lydia@example.test'], $message['to']);
        $this->assertSame(['boss@example.test'], $message['cc']);
        $this->assertSame($this->me->id, $message['author']['id']);
        $this->assertNull($message['sent_at']);

        $stored = TeamMessage::findOrFail($message['id']);
        $this->assertStringEndsWith('@komiut.test', $stored->message_id_header);
        $this->assertSame('q1@mail.example.test', $stored->in_reply_to);
        $this->assertSame('older@mail.example.test q1@mail.example.test', $stored->references);

        Bus::assertDispatched(SendTeamInboxMessage::class, fn ($job) => $job->messageId === $message['id']);
        $this->assertCount(0, $this->sent());

        $this->deliver($message['id']);

        $email = $this->sent()->sole();
        $headers = $email->getHeaders();
        $this->assertSame('Komiut Support <support@komiut.test>', $headers->get('From')->getBodyAsString());
        $this->assertSame('lydia@example.test', $headers->get('To')->getBodyAsString());
        $this->assertSame('boss@example.test', $headers->get('Cc')->getBodyAsString());
        $this->assertSame('Re: Till not working', $email->getSubject());
        $this->assertSame('<'.$stored->message_id_header.'>', $headers->get('Message-ID')->getBodyAsString());
        $this->assertSame('<q1@mail.example.test>', $headers->get('In-Reply-To')->getBodyAsString());
        $this->assertSame('<older@mail.example.test> <q1@mail.example.test>', $headers->get('References')->getBodyAsString());
        $this->assertSame('Komiut Support <support+'.$thread->reply_token.'@komiut.test>', $headers->get('Reply-To')->getBodyAsString());
        $this->assertSame("We are on it.\nKomiut", $email->getTextBody());
        $this->assertNull($email->getHtmlBody());

        $stored->refresh();
        $this->assertNotNull($stored->sent_at);
        $this->assertNull($stored->send_error);

        // A second run (a duplicate job) sends nothing more.
        $this->deliver($message['id']);
        $this->assertCount(1, $this->sent());

        // The customer's answer to our reply files into the same thread.
        $answer = app(InboundMail::class)->ingest(
            "From: lydia@example.test\r\nTo: support@komiut.test\r\nSubject: Re: Re: Till not working\r\nMessage-ID: <a2@mail.example.test>\r\nIn-Reply-To: <{$stored->message_id_header}>\r\n\r\nThanks\r\n",
            'support@komiut.test',
        );
        $this->assertSame($thread->id, $answer->thread_id);

        // Audited without bodies or addresses.
        foreach (['team.inbox.reply_queued', 'team.inbox.sent'] as $action) {
            $audit = AuditLog::where('action', $action)->sole();
            $this->assertSame($thread->id, $audit->data['thread_id']);
            $this->assertSame(2, $audit->data['recipients']);
            $this->assertStringNotContainsString('@', json_encode($audit->data));
            $this->assertStringNotContainsString('on it', json_encode($audit->data));
        }
    }

    #[Test]
    public function without_plus_addressing_there_is_no_reply_to(): void
    {
        $thread = $this->receive();
        $id = $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => 'Hi'])->assertCreated()->json('message.id');
        $this->deliver($id);

        $this->assertNull($this->sent()->sole()->getHeaders()->get('Reply-To'));
    }

    #[Test]
    public function a_new_email_opens_a_thread_assigned_to_its_writer(): void
    {
        $sacco = $this->makeSacco();
        $sacco->forceFill(['email' => 'office@nicco.test'])->save();

        $thread = $this->postJson(self::API.'compose', [
            'mailbox_id' => $this->support->id,
            'to' => ['Office@nicco.test', 'second@nicco.test'],
            'subject' => "Your keys\r\nBcc: victim@example.test",
            'body' => 'Please send the keys.',
        ])->assertCreated()->json('thread');

        $this->assertSame($this->support->id, $thread['mailbox_id']);
        $this->assertSame('Your keys Bcc: victim@example.test', $thread['subject']);
        $this->assertSame(['name' => null, 'email' => 'office@nicco.test'], $thread['from']);
        $this->assertSame($this->me->id, $thread['assignee']['id']);
        $this->assertSame('open', $thread['status']);
        $this->assertFalse($thread['unread']);
        $this->assertSame(1, $thread['message_count']);
        $this->assertSame('Please send the keys.', $thread['snippet']);
        $this->assertSame($sacco->id, $thread['sacco']['id']);

        $message = TeamMessage::where('thread_id', $thread['id'])->sole();
        $this->assertSame(['office@nicco.test', 'second@nicco.test'], $message->to);
        $this->assertNull($message->in_reply_to);
        Bus::assertDispatched(SendTeamInboxMessage::class, fn ($job) => $job->messageId === $message->id);

        $this->deliver($message->id);
        $email = $this->sent()->sole();
        $this->assertSame('office@nicco.test, second@nicco.test', $email->getHeaders()->get('To')->getBodyAsString());
        $this->assertSame('Your keys Bcc: victim@example.test', $email->getSubject());
        $this->assertNull($email->getHeaders()->get('Bcc'));
        $this->assertNull($email->getHeaders()->get('In-Reply-To'));

        // No inbound yet, so no first reply either.
        $this->assertNull(TeamThread::find($thread['id'])->first_inbound_at);
        $this->assertNull(TeamThread::find($thread['id'])->first_reply_at);
        $this->assertSame(1, AuditLog::where('action', 'team.inbox.compose_queued')->count());
    }

    #[Test]
    public function first_reply_is_the_first_outgoing_email_after_the_first_inbound(): void
    {
        $thread = $this->receive();
        $receivedAt = now()->copy();

        Carbon::setTestNow(now()->addHours(2));
        $this->postJson(self::API.'threads/'.$thread->id.'/notes', ['body' => 'Not a reply'])->assertCreated();
        $this->assertNull($thread->fresh()->first_reply_at);

        Carbon::setTestNow(now()->addHour());
        $repliedAt = now()->copy();
        $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => 'One'])->assertCreated();

        Carbon::setTestNow(now()->addHour());
        $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => 'Two'])->assertCreated();

        $thread->refresh();
        $this->assertTrue($thread->first_inbound_at->equalTo($receivedAt));
        $this->assertTrue($thread->first_reply_at->equalTo($repliedAt));

        // A thread we started: the clock starts at their first answer.
        $composed = $this->postJson(self::API.'compose', ['mailbox_id' => $this->support->id, 'to' => ['x@example.test'], 'subject' => 'Hi', 'body' => 'Hello'])
            ->assertCreated()->json('thread.id');
        $ours = TeamMessage::where('thread_id', $composed)->sole();
        Carbon::setTestNow(now()->addHour());
        $answered = now()->copy();
        app(InboundMail::class)->ingest("From: x@example.test\r\nTo: support@komiut.test\r\nSubject: Re: Hi\r\nMessage-ID: <x1@x.test>\r\nIn-Reply-To: <{$ours->message_id_header}>\r\n\r\nYes\r\n", 'support@komiut.test');
        $this->assertTrue(TeamThread::find($composed)->first_inbound_at->equalTo($answered));
        $this->assertNull(TeamThread::find($composed)->first_reply_at);

        Carbon::setTestNow(now()->addMinutes(5));
        $this->postJson(self::API.'threads/'.$composed.'/reply', ['body' => 'Great'])->assertCreated()
            ->assertJsonPath('message.to', ['x@example.test']);
        $this->assertTrue(TeamThread::find($composed)->first_reply_at->equalTo(now()));
    }

    #[Test]
    public function a_reply_that_finally_fails_says_why_and_gives_the_first_reply_back(): void
    {
        $thread = $this->receive();
        Carbon::setTestNow(now()->addHour());
        $first = $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => 'One'])->assertCreated()->json('message.id');
        Carbon::setTestNow(now()->addHour());
        $secondAt = now()->copy();
        $second = $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => 'Two'])->assertCreated()->json('message.id');

        (new SendTeamInboxMessage($first))->failed(new RuntimeException('Connection to mail.privateemail.com timed out'));

        $failed = TeamMessage::find($first);
        $this->assertNull($failed->sent_at);
        $this->assertSame('Could not be sent: Connection to mail.privateemail.com timed out', $failed->send_error);
        $this->assertTrue($thread->fresh()->first_reply_at->equalTo($secondAt));

        $shown = collect($this->getJson(self::API.'threads/'.$thread->id)->assertOk()->json('messages'))->firstWhere('id', $first);
        $this->assertSame('Could not be sent: Connection to mail.privateemail.com timed out', $shown['send_error']);

        (new SendTeamInboxMessage($second))->failed(new RuntimeException('down'));
        $this->assertNull($thread->fresh()->first_reply_at);
        $this->assertSame(2, AuditLog::where('action', 'team.inbox.send_failed')->count());

        // And a failed message is never retried into a send.
        $this->deliver($first);
        $this->assertCount(0, $this->sent());
    }

    #[Test]
    public function a_reply_queued_before_sending_was_switched_off_is_not_sent(): void
    {
        $thread = $this->receive();
        $id = $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => 'Hi'])->assertCreated()->json('message.id');

        config(['team_inbox.send_enabled' => false]);
        $this->deliver($id);

        $this->assertCount(0, $this->sent());
        $this->assertSame('Sending was switched off before this went out.', TeamMessage::find($id)->send_error);
    }

    #[Test]
    public function recipients_are_validated(): void
    {
        $thread = $this->receive();
        $reply = fn (array $cc) => $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => 'Hi', 'cc' => $cc]);
        $compose = fn (array $to) => $this->postJson(self::API.'compose', ['mailbox_id' => $this->support->id, 'to' => $to, 'subject' => 's', 'body' => 'b']);

        $reply(["ok@example.test\r\nBcc: victim@example.test"])->assertUnprocessable()->assertJsonValidationErrors('cc.0');
        $reply(['not-an-address'])->assertUnprocessable();
        $reply(['a@example.test, b@example.test'])->assertUnprocessable();
        $reply(['"Evil" <a@example.test>'])->assertUnprocessable();
        $reply(array_map(fn ($i) => "c{$i}@example.test", range(1, 20)))->assertUnprocessable(); // 1 + 20 > 20

        $compose([])->assertUnprocessable();
        $compose(array_map(fn ($i) => "t{$i}@example.test", range(1, 21)))->assertUnprocessable();
        $compose(['ok@example.test', "x@example.test\nBcc: v@example.test"])->assertUnprocessable()->assertJsonValidationErrors('to.1');
        $this->postJson(self::API.'compose', ['mailbox_id' => 999999, 'to' => ['a@example.test'], 'subject' => 's', 'body' => 'b'])->assertUnprocessable();
        $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => ''])->assertUnprocessable();

        $this->support->forceFill(['is_active' => false])->save();
        $compose(['a@example.test'])->assertUnprocessable()->assertJsonValidationErrors('mailbox_id');

        $this->assertSame(1, TeamMessage::count());
        Bus::assertNotDispatched(SendTeamInboxMessage::class);
    }

    #[Test]
    public function sending_is_rate_limited_per_team_member(): void
    {
        config(['team_inbox.send_per_minute' => 2]);
        $thread = $this->receive();
        $reply = fn () => $this->postJson(self::API.'threads/'.$thread->id.'/reply', ['body' => 'Hi']);

        $reply()->assertCreated();
        $this->postJson(self::API.'compose', ['mailbox_id' => $this->support->id, 'to' => ['a@example.test'], 'subject' => 's', 'body' => 'b'])->assertCreated();
        $reply()->assertStatus(429)->assertHeader('Retry-After');
        // A refused send stores nothing.
        $this->assertSame(3, TeamMessage::count());

        // Someone else on the team has their own allowance.
        $other = $this->makeUser([]);
        $other->forceFill(['type' => UserType::Superadmin->value])->save();
        Sanctum::actingAs($other->fresh());
        $reply()->assertCreated();

        // Notes are not sends.
        Sanctum::actingAs($this->me);
        $this->postJson(self::API.'threads/'.$thread->id.'/notes', ['body' => 'still fine'])->assertCreated();
    }
}
