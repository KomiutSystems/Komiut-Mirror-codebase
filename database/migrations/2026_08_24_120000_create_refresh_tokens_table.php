<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long-lived refresh tokens, deliberately NOT Sanctum personal access tokens.
 *
 * Sanctum has no refresh-token concept — it issues access tokens and that is
 * all. The obvious shortcut is to mint the refresh token as a second PAT with
 * an ability like `refresh`, but that is unsafe here: `auth:sanctum` does not
 * check abilities, so such a token would authenticate EVERY route in the API
 * exactly like an access token. A stolen 30-day refresh token would be a
 * 30-day master key.
 *
 * Keeping them in their own table means a refresh token cannot authenticate
 * anything at all. It is accepted by one endpoint, which exchanges it.
 *
 * Only the SHA-256 of the token is stored. The plaintext is returned once, at
 * issue, and is never recoverable from the database — same rule Sanctum applies
 * to its own tokens, and it matters more here because this credential outlives
 * the access token by weeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('refresh_tokens')) {
            return;
        }

        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // SHA-256 hex is always 64 chars. Unique so a rotation collision is
            // a database error rather than two accounts sharing a credential.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');

            // Rotation revokes rather than deletes: a refresh token presented
            // after it was already exchanged is the classic replay signal, and
            // it can only be recognised if the spent row is still here.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // The lookup every refresh does: this user's live tokens.
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
