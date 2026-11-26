<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The team inbox is gone (removed from the console on 2026-11-26), so a board
 * card can no longer link to an email thread. Cards made from one keep their
 * title, comments and source ('inbox'); only the link goes, so every card
 * still loads. The inbox tables themselves are dropped in a later release, so
 * a server still running the previous build never meets a missing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('team_cards')->where('link_type', 'thread')->update(['link_type' => null, 'link_id' => null]);
    }

    public function down(): void
    {
        // The links are not restored: the inbox they pointed into is gone.
    }
};
