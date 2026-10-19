<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stage a stage manager (the Queue Supervisor role) works at. Their app
 * shows the buses at that stage and what each made on its trip. One stage per
 * person, chosen in the app from their SACCO's stages. Not terminus_users:
 * that table is being retired (see AvailableTermini). Nullable, small, instant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'stage_terminus_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('stage_terminus_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'stage_terminus_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('stage_terminus_id');
            });
        }
    }
};
