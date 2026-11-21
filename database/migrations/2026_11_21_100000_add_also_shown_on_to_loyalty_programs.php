<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a SACCO's loyalty card be shown to another brand's passengers without
 * moving its buses. A list of brand keys; null means only the brands its
 * vehicles run on, which is every programme today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            if (! Schema::hasColumn('loyalty_programs', 'also_shown_on')) {
                $table->jsonb('also_shown_on')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            if (Schema::hasColumn('loyalty_programs', 'also_shown_on')) {
                $table->dropColumn('also_shown_on');
            }
        });
    }
};
