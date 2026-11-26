<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Models\Sacco;
use App\Models\Team\TeamMailbox;
use App\Models\Team\TeamMessage;
use App\Models\Team\TeamMessageAttachment;
use App\Models\Team\TeamThread;
use App\Services\Team\Inbox\AttachmentStore;
use App\Services\Team\Inbox\InboundMail;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The ingestion core: raw email in, a filed message out -- however it arrived.
 */
final class InboxIngestTest extends QueueTestCase
{
    private TeamMailbox $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = TeamMailbox::create(['address' => 'support@komiut.test', 'name' => 'Komiut Support', 'brand' => 'komiut']);
    }

    private function ingest(string $raw, ?string $to = 'support@komiut.test'): ?TeamMessage
    {
        return app(InboundMail::class)->ingest($raw, $to);
    }

    /** @param  array<string, string>  $headers */
    private function mail(array $headers = [], string $body = "Hello team,\nthe till is down.", string $contentType = 'text/plain; charset=UTF-8', string $encoding = '8bit'): string
    {
        $headers += [
            'From' => 'Lydia W <lydia@example.test>',
            'To' => 'support@komiut.test',
            'Subject' => 'Till not working',
            'Message-ID' => '<'.uniqid('m', true).'@mail.example.test>',
            'Date' => 'Thu, 26 Nov 2026 10:00:00 +0300',
            'MIME-Version' => '1.0',
            'Content-Type' => $contentType,
            'Content-Transfer-Encoding' => $encoding,
        ];

        $out = '';
        foreach ($headers as $name => $value) {
            $out .= "{$name}: {$value}\r\n";
        }

        return $out."\r\n".$body."\r\n";
    }

    /** @param  list<array{name:string, type:string, bytes:string}>  $files */
    private function withAttachments(array $files, array $headers = []): string
    {
        $parts = "--B1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nSee attached.\r\n";
        foreach ($files as $f) {
            $parts .= "--B1\r\nContent-Type: {$f['type']}; name=\"{$f['name']}\"\r\nContent-Disposition: attachment; filename=\"{$f['name']}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                .chunk_split(base64_encode($f['bytes']), 76, "\r\n");
        }
        $parts .= '--B1--';

        return $this->mail($headers, $parts, 'multipart/mixed; boundary="B1"', '7bit');
    }

    #[Test]
    public function a_new_email_opens_a_thread_in_the_mailbox_it_was_sent_to(): void
    {
        Carbon::setTestNow('2026-11-26 10:05:00');

        $message = $this->ingest($this->mail([
            'From' => '=?UTF-8?B?THlkaWEgV2FuasOpcg==?= <Lydia@Example.test>',
            'Subject' => '=?ISO-8859-1?Q?Caf=E9_till_=E9tait_down?=',
            'Cc' => 'Boss <boss@example.test>',
            'Message-ID' => '<first@mail.example.test>',
        ]));

        $this->assertNotNull($message);
        $thread = $message->thread;

        $this->assertSame($this->support->id, $thread->mailbox_id);
        $this->assertSame('Café till était down', $thread->subject);
        $this->assertSame('Lydia Wanjér', $thread->from_name);
        $this->assertSame('lydia@example.test', $thread->from_email);
        $this->assertSame('open', $thread->status);
        $this->assertSame(1, $thread->message_count);
        $this->assertSame('Hello team, the till is down.', $thread->snippet);
        $this->assertTrue($thread->first_inbound_at->equalTo(now()));
        $this->assertTrue($thread->last_message_at->equalTo(now()));
        $this->assertNull($thread->first_reply_at);
        $this->assertSame(32, strlen($thread->reply_token));

        $this->assertSame('in', $message->direction);
        $this->assertSame("Hello team,\nthe till is down.", $message->body);
        $this->assertSame('first@mail.example.test', $message->message_id_header);
        $this->assertSame(['support@komiut.test'], $message->to);
        $this->assertSame(['boss@example.test'], $message->cc);
    }

    #[Test]
    public function a_redelivered_email_files_nothing_new(): void
    {
        $raw = $this->mail(['Message-ID' => '<once@mail.example.test>']);

        $this->assertNotNull($this->ingest($raw));
        $this->assertNull($this->ingest($raw));
        $this->assertNull($this->ingest($raw, null));

        $this->assertSame(1, TeamMessage::count());
        $this->assertSame(1, TeamThread::count());
        $this->assertSame(1, TeamThread::first()->message_count);
    }

    #[Test]
    public function an_email_without_a_message_id_is_still_filed_once(): void
    {
        $raw = "From: a@example.test\r\nTo: support@komiut.test\r\nSubject: no id\r\n\r\nbody\r\n";

        $this->assertNotNull($this->ingest($raw));
        $this->assertNull($this->ingest($raw));
        $this->assertSame(1, TeamMessage::count());
        $this->assertStringEndsWith('@inbound.invalid', TeamMessage::first()->message_id_header);
    }

    #[Test]
    public function a_reply_files_into_its_thread_by_in_reply_to_or_references(): void
    {
        $first = $this->ingest($this->mail(['Message-ID' => '<q1@mail.example.test>']));
        $billing = TeamMailbox::create(['address' => 'billing@komiut.test', 'name' => 'Billing']);

        // Our reply in that thread, as the send path stores it.
        TeamMessage::create([
            'thread_id' => $first->thread_id, 'direction' => 'out', 'body' => 'We are on it',
            'from_email' => 'support@komiut.test', 'message_id_header' => 'ours-1@komiut.test',
        ]);

        // Answering our reply, and sent to another of our addresses: still the same conversation.
        $byInReplyTo = $this->ingest($this->mail([
            'To' => 'billing@komiut.test',
            'Subject' => 'Re: Till not working',
            'In-Reply-To' => '<ours-1@komiut.test>',
        ]), 'billing@komiut.test');
        $this->assertSame($first->thread_id, $byInReplyTo->thread_id);

        // A client that only kept References.
        $byReferences = $this->ingest($this->mail([
            'Subject' => 'Re: Re: Till not working',
            'References' => '<unknown@else.test> <q1@mail.example.test>',
        ]));
        $this->assertSame($first->thread_id, $byReferences->thread_id);
        $this->assertSame('unknown@else.test q1@mail.example.test', $byReferences->references);

        $this->assertSame(1, TeamThread::count());
        $this->assertSame(0, $billing->threads()->count());
        // Counted as filed: the reply above was written straight to the table.
        $this->assertSame(3, TeamThread::first()->message_count);
    }

    #[Test]
    public function a_reply_that_lost_its_headers_files_by_the_token_in_the_address(): void
    {
        $first = $this->ingest($this->mail());
        $token = $first->thread->reply_token;

        $reply = $this->ingest($this->mail([
            'To' => "Komiut Support <support+{$token}@komiut.test>",
            'Subject' => 'something else entirely',
        ]), "support+{$token}@komiut.test");

        $this->assertSame($first->thread_id, $reply->thread_id);

        // A plus-tag that is not a thread's token is just the mailbox.
        $other = $this->ingest($this->mail(['To' => 'support+newsletter@komiut.test']), 'support+newsletter@komiut.test');
        $this->assertNotSame($first->thread_id, $other->thread_id);
        $this->assertSame($this->support->id, $other->thread->mailbox_id);
    }

    #[Test]
    public function the_mailbox_is_found_from_delivered_to_when_the_envelope_is_unknown(): void
    {
        $message = $this->ingest($this->mail([
            'To' => 'all-staff@lists.example.test',
            'Delivered-To' => 'Support@Komiut.test',
        ]), null);

        $this->assertSame($this->support->id, $message?->thread->mailbox_id);
    }

    #[Test]
    public function mail_for_no_active_mailbox_of_ours_is_dropped(): void
    {
        $this->assertNull($this->ingest($this->mail(['To' => 'someone@elsewhere.test']), 'someone@elsewhere.test'));

        $this->support->forceFill(['is_active' => false])->save();
        $this->assertNull($this->ingest($this->mail()));

        $this->assertSame(0, TeamThread::count());
        $this->assertSame(0, TeamMessage::count());
    }

    #[Test]
    public function a_new_email_on_a_closed_thread_reopens_it(): void
    {
        $first = $this->ingest($this->mail(['Message-ID' => '<c1@mail.example.test>']));
        $first->thread->forceFill(['status' => 'closed'])->save();

        Carbon::setTestNow(now()->addHour());
        $this->ingest($this->mail(['In-Reply-To' => '<c1@mail.example.test>'], 'One more thing'));

        $thread = $first->thread->fresh();
        $this->assertSame('open', $thread->status);
        $this->assertSame(2, $thread->message_count);
        $this->assertSame('One more thing', $thread->snippet);
        // The first inbound stays the first.
        $this->assertTrue($thread->first_inbound_at->lessThan($thread->last_message_at));
    }

    #[Test]
    public function an_html_only_email_is_stored_as_plain_text(): void
    {
        $html = '<html><head><title>T</title><style>p{color:red}</style></head><body>'
            .'<script>alert("x")</script><p>Dear&nbsp;team,</p><p>Our <b>till</b> &amp; keys:<br>line two</p>'
            .'<ul><li>KDY 614G</li><li>KDY 615G</li></ul>'
            .'<p><a href="https://evil.example.test/login">Komiut login</a></p><!-- hidden --><img src="https://track.test/p.gif"></body></html>';

        $message = $this->ingest($this->mail([], $html, 'text/html; charset=UTF-8'));

        $this->assertSame(
            "Dear team,\n\nOur till & keys:\nline two\n\n- KDY 614G\n- KDY 615G\n\nKomiut login (https://evil.example.test/login)",
            $message->body,
        );
        $this->assertStringNotContainsString('<', $message->body);
        $this->assertStringNotContainsString('alert', $message->body);
        $this->assertStringNotContainsString('color:red', $message->body);
    }

    #[Test]
    public function the_text_part_wins_over_html_and_charsets_become_utf8(): void
    {
        $body = "--A\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nPay=E9 twice =\r\nfor one trip\r\n"
            ."--A\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>HTML version</p>\r\n--A--";

        $message = $this->ingest($this->mail([], $body, 'multipart/alternative; boundary="A"', '7bit'));
        $this->assertSame('Payé twice for one trip', $message->body);

        $b64 = $this->ingest($this->mail([], base64_encode('Malipo yangu — ¿dónde?'), 'text/plain; charset=UTF-8', 'base64'));
        $this->assertSame('Malipo yangu — ¿dónde?', $b64->body);
    }

    #[Test]
    public function attachments_are_kept_within_the_limits_and_the_rest_are_noted(): void
    {
        config([
            'team_inbox.limits.attachment_max_bytes' => 2000,
            'team_inbox.limits.attachments_total_max_bytes' => 3000,
        ]);

        $pdf = "%PDF-1.4\n\0\xFF\xFE binary".str_repeat('x', 1000);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $message = $this->ingest($this->withAttachments([
            ['name' => 'statement.pdf', 'type' => 'application/pdf', 'bytes' => $pdf],
            ['name' => 'setup.exe', 'type' => 'application/octet-stream', 'bytes' => "MZ\x90\0".str_repeat("\0", 100)],
            ['name' => 'invoice.pdf', 'type' => 'application/pdf', 'bytes' => "MZ\x90\0\x03\0\0\0\x04\0\0\0\xFF\xFF\0\0".str_repeat("\0", 200)],
            ['name' => 'page.html', 'type' => 'text/html', 'bytes' => '<script>x</script>'],
            ['name' => 'huge.pdf', 'type' => 'application/pdf', 'bytes' => '%PDF-1.4'.str_repeat('y', 2500)],
            ['name' => 'fake.png', 'type' => 'image/png', 'bytes' => '<svg onload=alert(1)></svg>'],
            ['name' => 'photo.png', 'type' => 'image/png', 'bytes' => $png],
            ['name' => 'second.pdf', 'type' => 'application/pdf', 'bytes' => '%PDF-1.4'.str_repeat('z', 1990)],
        ]));

        $kept = TeamMessageAttachment::query()->where('message_id', $message->id)->orderBy('id')->get();
        $this->assertSame(['statement.pdf', 'photo.png'], $kept->pluck('name')->all());

        $statement = $kept[0];
        $this->assertSame('application/pdf', $statement->mime);
        $this->assertSame(strlen($pdf), $statement->size);
        $this->assertSame(hash('sha256', $pdf), $statement->sha256);
        // Byte for byte, NULs and invalid UTF-8 included.
        $this->assertSame($pdf, app(AttachmentStore::class)->bytes($statement));
        $this->assertSame($png, app(AttachmentStore::class)->bytes($kept[1]));

        $this->assertStringStartsWith("See attached.\n\n[Attachments not kept: ", $message->body);
        $this->assertStringContainsString('setup.exe (104 bytes): file type not accepted', $message->body);
        $this->assertStringContainsString('invoice.pdf', $message->body);
        $this->assertStringContainsString('page.html', $message->body);
        $this->assertStringContainsString('huge.pdf (2 KB): larger than 2 KB', $message->body);
        $this->assertStringContainsString('fake.png', $message->body);
        $this->assertStringContainsString('second.pdf (2 KB): attachments over 3 KB in total', $message->body);
    }

    #[Test]
    public function the_sacco_is_matched_from_the_sender_unless_staff_chose_one(): void
    {
        $nicco = $this->makeSacco();
        $admin = $this->makeUser([], $nicco);
        $admin->forceFill(['email' => 'Admin@Nicco.test'])->save();

        $byUser = $this->ingest($this->mail(['From' => 'admin@NICCO.test']));
        $this->assertSame($nicco->id, $byUser->thread->sacco_id);

        $metro = $this->makeSacco();
        $metro->forceFill(['email' => 'office@metro.test'])->save();
        $byContact = $this->ingest($this->mail(['From' => 'Office@metro.test']));
        $this->assertSame($metro->id, $byContact->thread->sacco_id);

        // Two SACCOs on one contact address (unique only case-sensitively):
        // file neither rather than the wrong one.
        $a = $this->makeSacco();
        $b = $this->makeSacco();
        Sacco::withoutGlobalScopes()->whereKey($a->id)->update(['email' => 'shared@agent.test']);
        Sacco::withoutGlobalScopes()->whereKey($b->id)->update(['email' => 'Shared@Agent.test']);
        $this->assertNull($this->ingest($this->mail(['From' => 'shared@agent.test']))->thread->sacco_id);

        // Staff cleared the match on a thread: the next email does not refill it.
        $thread = $byUser->thread;
        $thread->forceFill(['sacco_id' => null, 'sacco_set_by_staff' => true])->save();
        $this->ingest($this->mail(['From' => 'admin@nicco.test', 'In-Reply-To' => '<'.$byUser->message_id_header.'>']));
        $this->assertNull($thread->fresh()->sacco_id);
    }

    #[Test]
    public function the_sync_command_applies_the_configured_mailboxes_and_never_deletes(): void
    {
        $old = TeamMailbox::create(['address' => 'old@komiut.test', 'name' => 'Old']);

        config(['team_inbox.mailboxes' => [
            ['address' => 'Support@Komiut.test', 'name' => 'Komiut Help', 'brand' => 'komiut'],
            ['address' => 'hello@2safiri.test', 'name' => '2Safiri', 'brand' => '2safiri'],
            ['address' => 'not an address', 'name' => 'x'],
            ['address' => 'x@komiut.test', 'name' => 'x', 'brand' => 'uber'],
        ]]);

        $this->artisan('team:inbox-sync-mailboxes', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('Komiut Support', $this->support->fresh()->name);

        $this->artisan('team:inbox-sync-mailboxes')->assertSuccessful();

        $this->assertSame('Komiut Help', $this->support->fresh()->name);
        $this->assertTrue($this->support->fresh()->is_active);
        $this->assertSame('safiri', TeamMailbox::where('address', 'hello@2safiri.test')->value('brand'));
        $this->assertFalse($old->fresh()->is_active);
        $this->assertNull(TeamMailbox::where('address', 'x@komiut.test')->first());
        $this->assertSame(3, TeamMailbox::count());
    }
}
