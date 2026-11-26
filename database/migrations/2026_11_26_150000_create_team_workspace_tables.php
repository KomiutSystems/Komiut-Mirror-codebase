<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safetek's own team workspace in the super console: the ops board (cards,
 * comments, KPI targets) and the shared inbox (mailboxes, threads, messages).
 * One team for both brands, so none of these tables carries a brand scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('todo');      // todo | doing | waiting | done
            $table->string('priority', 16)->default('normal');  // urgent | high | normal | low
            $table->date('due_on')->nullable();
            // Board order within a column: gaps of 1024, a move takes the
            // midpoint between its neighbours, renumbered when a gap runs out.
            $table->double('position')->default(0);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('link_type', 16)->nullable();          // sacco | vehicle | till | thread
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('source', 32)->default('manual');     // manual | till_registration | head_office | directory_claim | fraud | inbox
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'position']);
            $table->index(['assignee_id', 'status']);
            $table->index(['link_type', 'link_id']);
        });

        // One open automatic card per (source, linked thing): the platform must
        // never stack a second card while one is still being worked.
        DB::statement("CREATE UNIQUE INDEX team_cards_one_open_auto ON team_cards (source, link_type, link_id) WHERE source NOT IN ('manual', 'inbox') AND status <> 'done'");

        Schema::create('team_card_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('card_id')->constrained('team_cards')->cascadeOnDelete();
            // Null for the platform's own comments ("closed itself: …").
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['card_id', 'id']);
        });

        Schema::create('team_mailboxes', function (Blueprint $table): void {
            $table->id();
            $table->string('address', 255)->unique();
            $table->string('name', 120);
            $table->string('brand', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('team_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('team_mailboxes')->cascadeOnDelete();
            $table->string('subject', 500)->default('');
            $table->string('snippet', 200)->default('');
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->string('status', 8)->default('open');        // open | closed
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sacco_id')->nullable()->constrained('saccos')->nullOnDelete();
            // A SACCO a person chose is never overwritten by the automatic match.
            $table->boolean('sacco_set_by_staff')->default(false);
            $table->foreignId('card_id')->nullable()->constrained('team_cards')->nullOnDelete();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            // For the first-reply KPI: the first inbound message and the first
            // reply after it.
            $table->timestamp('first_inbound_at')->nullable();
            $table->timestamp('first_reply_at')->nullable();
            // Carried in the Reply-To of our replies, so an answer that lost its
            // threading headers still files into this thread.
            $table->string('reply_token', 40)->unique();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index(['assignee_id', 'status']);
            $table->index(['mailbox_id', 'status']);
            $table->index('first_inbound_at');
        });

        Schema::create('team_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('team_threads')->cascadeOnDelete();
            $table->string('direction', 4);                       // in | out | note
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            // Plain text only: inbound HTML is stripped before it is stored and
            // the console never renders HTML.
            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // RFC 5322 threading: our own Message-ID for outgoing mail, the
            // sender's for incoming.
            $table->string('message_id_header', 500)->nullable();
            $table->string('in_reply_to', 500)->nullable();
            $table->text('references')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('send_error')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'id']);
            $table->index('message_id_header');
        });

        Schema::create('team_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('team_messages')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('mime', 150)->default('application/octet-stream');
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            // In the database, not on a server's disk: two hosts serve the API
            // and neither can read the other's files. Size-capped on the way in.
            $table->binary('content');
            $table->timestamps();

            $table->index('message_id');
        });

        Schema::create('team_thread_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('team_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['user_id', 'thread_id']);
        });

        Schema::create('team_kpi_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40);
            $table->string('period', 8);                          // week | month
            $table->decimal('target', 16, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'period']);
        });

        // A KPI that is a level, not a sum over a period (the share of tills
        // registered), has no "previous period" in the data itself. A nightly
        // snapshot gives it one.
        Schema::create('team_kpi_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40);
            $table->date('date');
            $table->decimal('value', 16, 2);
            $table->timestamps();

            $table->unique(['key', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_kpi_snapshots');
        Schema::dropIfExists('team_kpi_targets');
        Schema::dropIfExists('team_thread_reads');
        Schema::dropIfExists('team_message_attachments');
        Schema::dropIfExists('team_messages');
        Schema::dropIfExists('team_threads');
        Schema::dropIfExists('team_mailboxes');
        Schema::dropIfExists('team_card_comments');
        Schema::dropIfExists('team_cards');
    }
};
