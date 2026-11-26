<?php

declare(strict_types=1);

use App\Services\Team\CardEventsBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every move of a board card between columns, with who moved it and who held
 * it at that moment -- what the team performance page measures (who shipped,
 * cycle time from first "In progress", the board's daily flow). The audit log
 * records the same moves for accountability, but an edit there names only the
 * fields that changed, never the column a card went to.
 *
 * Backfilled from the audit trail the board has written since it launched
 * (2026-11-26), then squared with each card's current column. A server still
 * on the previous build keeps moving cards without events until the deploy
 * rolls, so `team:square-card-events` runs the same squaring again after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // The foreign key to users takes SHARE ROW EXCLUSIVE on users: give
            // up rather than queue every users write behind a long transaction.
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('team_card_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('card_id')->constrained('team_cards')->cascadeOnDelete();
            // Null: the platform (automatic cards) or no one signed in.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 16)->nullable(); // null: the card was created
            $table->string('to_status', 16);
            // Who held the card at that moment: a card shipped counts for them.
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('at');

            $table->index(['card_id', 'at']);
            $table->index(['to_status', 'at']);
            $table->index('at');
        });

        $this->backfill();
    }

    /** From the audit trail, squared with each card (CardEventsBackfill; re-run after the deploy rolls). */
    private function backfill(): void
    {
        app(CardEventsBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('team_card_events');
    }
};
