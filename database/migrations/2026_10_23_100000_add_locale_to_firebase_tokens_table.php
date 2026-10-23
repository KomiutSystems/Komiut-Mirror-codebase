<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language of the phone holding this token, so a push can be written in it.
 * Per token, not per user: one person may carry an English phone and a Swahili
 * one, and each should read its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('firebase_tokens', 'locale')) {
            return;
        }

        Schema::table('firebase_tokens', function (Blueprint $table): void {
            $table->string('locale', 8)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('firebase_tokens', 'locale')) {
            return;
        }

        Schema::table('firebase_tokens', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
