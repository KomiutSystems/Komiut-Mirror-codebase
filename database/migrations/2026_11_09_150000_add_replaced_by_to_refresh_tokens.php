<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which row replaced this one, so a lost response can be told from a replay.
 *
 * Rotation is single-use: exchanging a refresh token revokes it. Presenting a
 * revoked token therefore looks like theft, and the chain is killed. That is
 * right for a thief and wrong for the commonest real cause -- the phone sent
 * POST auth/refresh on bad 3G, the server rotated and answered, and the
 * response never arrived. The client cannot tell "rotated, reply lost" from
 * "never arrived", so today it throws its refresh token away rather than risk
 * signing the passenger out everywhere. Its access token has already expired
 * (that is why it refreshed), so the passenger is signed out anyway.
 *
 * Google/Apple passengers feel this most: they have no password to fall back
 * on, and it reads to them as "the app logged me out".
 *
 * With a link from a spent row to its successor, TokenPair::rotate can
 * recognise a client retry -- revoked moments ago, successor never used -- and
 * answer it instead of revoking everything. Anything else stays a replay.
 *
 * Nullable: every row issued before this, and every chain head, has no
 * successor. nullOnDelete rather than cascade -- losing a successor row must
 * not delete the history that points at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('refresh_tokens') || Schema::hasColumn('refresh_tokens', 'replaced_by_id')) {
            return;
        }

        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->foreignId('replaced_by_id')->nullable()
                ->constrained('refresh_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('refresh_tokens') || ! Schema::hasColumn('refresh_tokens', 'replaced_by_id')) {
            return;
        }

        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaced_by_id');
        });
    }
};
