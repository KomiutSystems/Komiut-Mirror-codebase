<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `activity_seen_at` — when this account last opened its Activity screen.
 *
 * WHY THIS IS ON THE SERVER AT ALL. The unread indicator could have been a flag
 * on the handset, and that is wrong here for three separate reasons. It is lost
 * on reinstall. It disagrees between a passenger's two devices. And matatu crews
 * and families share handsets — a device-local marker shows one person's unread
 * state to whoever picks the phone up next. Keyed to the user id, the marker
 * survives a reinstall, agrees across devices, and stays separate per person on
 * one phone. It also works with the socket down, which is the normal condition
 * on a moving matatu: the badge is a cold-start GET, not a live subscription.
 *
 * WHY A COLUMN ON `users` AND NOT A TABLE. A dedicated table would be the more
 * flexible shape, and it buys nothing today. There is ONE Activity screen, so
 * there is one thing to mark seen; a marker table would carry a `surface` column
 * that only ever holds one value. The column is also cheaper exactly where it
 * matters: Sanctum has already loaded the user row by the time the request
 * reaches the controller, so the badge endpoint — the one a passenger hits on
 * every cold start, on a bad network, which is the whole reason it exists —
 * costs two aggregate counts and no third lookup for the marker itself.
 * `users.last_active_at` is the precedent for a per-account timestamp here.
 *
 * WHAT WOULD FORCE THE TABLE, so the next person does not have to re-derive it:
 * the app is free to badge points and carbon apart, and if it ever also lets a
 * passenger read ONE of them without the other, then "seen" stops being one fact
 * about one screen and becomes per-surface. That is the day to move it to its
 * own table. Doing it now would be speculation; doing it then is a small
 * migration and a re-point of two queries.
 *
 * DELIBERATELY NOT INDEXED. It is only ever read by primary key, alongside the
 * user row itself. An index would be pure write cost — and worse than nothing:
 * `last_active_at` is indexed, which is what stops its every-request touch from
 * being a Postgres HOT update. This column is written whenever a passenger opens
 * a screen, and with no index on it that write stays HOT.
 *
 * NULLABLE, AND NULL MEANS "HAS NEVER LOOKED" — not "nothing new". Every account
 * that exists on the day this ships has never opened the screen, and their
 * points and carbon history is genuinely unseen, so it must be counted, not
 * hidden. A NOT NULL DEFAULT now() would have marked thousands of accounts as
 * having read a screen none of them has opened, silently zeroing every badge on
 * deploy; a DEFAULT of the epoch would have been a lie in the other direction.
 * NULL is the only value that says the thing that is true.
 *
 * PRECISION IS LOAD-BEARING. `timestamp` here is Postgres `timestamp(0)`, the
 * same precision Laravel gives `created_at` on loyalty_transactions and
 * carbon_credit_transactions. The unseen count compares this column against
 * those, so second-against-second is a like-for-like comparison and an entry
 * written in the same second as the mark compares EQUAL — which the count then
 * excludes with a strict `>`. Widening this column to microseconds would break
 * that: the marker would land mid-second, and a ledger row from earlier in the
 * same second would sit on a later whole second and start reading as unseen.
 * That is a badge that reappears the instant you look away from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('activity_seen_at')->nullable()->after('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('activity_seen_at');
        });
    }
};
