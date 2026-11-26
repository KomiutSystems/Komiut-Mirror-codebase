<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Team inbox read state by message, not by clock.
 *
 * Timestamps are stored to the second, so a read at 10:00:05.2 and an email
 * filed at 10:00:05.7 compare equal and the email never showed as unread.
 * Message ids only grow, so "unread" becomes: the thread's latest message id
 * is past the last one this person saw. read_at stays, as when they looked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_threads', function (Blueprint $table): void {
            // The newest team_messages.id in the thread; kept by TeamInbox::record().
            $table->unsignedBigInteger('last_message_id')->nullable();
        });

        Schema::table('team_thread_reads', function (Blueprint $table): void {
            // The newest message this person had in front of them.
            $table->unsignedBigInteger('last_read_message_id')->nullable();
        });

        // The tables are new and small; carry over anything already there.
        DB::statement('UPDATE team_threads t SET last_message_id = (SELECT MAX(m.id) FROM team_messages m WHERE m.thread_id = t.id)');
        DB::statement('UPDATE team_thread_reads r SET last_read_message_id = (
            SELECT MAX(m.id) FROM team_messages m WHERE m.thread_id = r.thread_id AND m.created_at <= r.read_at
        )');
    }

    public function down(): void
    {
        Schema::table('team_thread_reads', function (Blueprint $table): void {
            $table->dropColumn('last_read_message_id');
        });

        Schema::table('team_threads', function (Blueprint $table): void {
            $table->dropColumn('last_message_id');
        });
    }
};
