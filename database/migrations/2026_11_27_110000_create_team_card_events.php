<?php

declare(strict_types=1);

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
 * (2026-11-26), then squared with each card's current column.
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

    private function backfill(): void
    {
        $cards = DB::table('team_cards')->get(['id', 'status', 'assignee_id', 'created_at', 'updated_at', 'done_at'])->keyBy('id');
        if ($cards->isEmpty()) {
            return;
        }

        $rows = DB::table('audit_logs')
            ->whereIn('action', ['team.card.created', 'team.card.moved'])
            ->where('subject_type', 'team_card')
            ->orderBy('created_at')->orderBy('id')
            ->get(['action', 'actor_type', 'actor_id', 'subject_id', 'data', 'created_at']);

        $events = [];
        $last = [];
        foreach ($rows as $row) {
            $cardId = (int) $row->subject_id;
            $card = $cards->get($cardId);
            if ($card === null) {
                continue; // deleted since
            }
            $data = json_decode((string) $row->data, true) ?: [];
            $to = $row->action === 'team.card.created' ? ($data['status'] ?? 'todo') : ($data['to'] ?? null);
            if (! is_string($to)) {
                continue;
            }
            $from = $row->action === 'team.card.created' ? null : ($data['from'] ?? $last[$cardId] ?? null);

            $events[] = [
                'card_id' => $cardId,
                'actor_id' => $row->actor_type === 'user' && ctype_digit((string) $row->actor_id) ? (int) $row->actor_id : null,
                'from_status' => $from,
                'to_status' => $to,
                // Not recorded then; the card's holder now is the best there is.
                'assignee_id' => $card->assignee_id,
                'at' => $row->created_at,
            ];
            $last[$cardId] = $to;
        }

        // A column change made through an edit was audited without the column:
        // square every card with where it is now.
        foreach ($cards as $card) {
            if (! isset($last[$card->id])) {
                $events[] = ['card_id' => $card->id, 'actor_id' => null, 'from_status' => null, 'to_status' => 'todo', 'assignee_id' => $card->assignee_id, 'at' => $card->created_at];
                $last[$card->id] = 'todo';
            }
            if ($last[$card->id] !== $card->status) {
                $events[] = [
                    'card_id' => $card->id, 'actor_id' => null, 'from_status' => $last[$card->id], 'to_status' => $card->status,
                    'assignee_id' => $card->assignee_id, 'at' => $card->status === 'done' && $card->done_at ? $card->done_at : $card->updated_at,
                ];
            }
        }

        foreach (array_chunk($events, 500) as $chunk) {
            DB::table('team_card_events')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_card_events');
    }
};
