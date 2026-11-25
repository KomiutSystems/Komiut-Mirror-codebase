<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment notification refused because it did not come from its sender.
 *
 * Kept whole, so that if a real sender posts from an address not yet on its
 * list, the payment is not lost: `payments:replay-refused` records it once a
 * person has confirmed the sender is genuine. The body never holds a bank
 * password: CallbackSourceAllowlist strips credential fields before saving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refused_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('ip', 64)->nullable();
            $table->string('method', 10);
            $table->string('host', 255)->nullable();
            $table->string('path', 512);
            $table->string('content_type', 255)->nullable();
            $table->text('body')->nullable();
            // NCBA signs in on confirmation_new: whether THIS post carried the
            // right username and password. A replay signs it back in only then,
            // so replaying a forged row cannot skip the password check.
            $table->boolean('credentials_ok')->nullable();
            // Set only once a replay has verifiably recorded the payment; a
            // failed replay leaves it null (and says why) so it can be retried.
            $table->timestamp('replayed_at')->nullable();
            $table->unsignedSmallInteger('replay_status')->nullable();
            $table->string('replay_note', 500)->nullable();
            $table->timestamps();
            $table->index(['created_at']);
            $table->index(['ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refused_callbacks');
    }
};
