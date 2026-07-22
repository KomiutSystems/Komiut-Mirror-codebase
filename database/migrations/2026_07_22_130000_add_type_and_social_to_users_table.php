<?php

declare(strict_types=1);

use App\Models\Gender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the account type + social-login columns, and relaxes the columns a
 * social passenger cannot supply at sign-up.
 *
 * A Google/Apple passenger arrives with only email + name + a provider id — no
 * phone, password, date of birth, or gender. Those become nullable so the
 * account can be created; a later profile-completion flow fills them in.
 *
 * NOTE: existing rows default to type 'passenger'. Real staff/driver rows need a
 * one-off data backfill to their correct type — out of scope for this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('type')->default('passenger')->index()->after('email');
            $table->string('provider')->nullable()->after('remember_token');
            $table->string('provider_id')->nullable()->after('provider');
            $table->index(['provider', 'provider_id']);
        });

        // Relaxed for social passengers (change() keeps the existing unique index
        // on phone — indexes are not touched by change()).
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->date('dob')->nullable()->change();
            $table->foreignIdFor(Gender::class)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'provider_id']);
            $table->dropColumn(['type', 'provider', 'provider_id']);
        });
    }
};
