<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft delete for accounts nobody ever used. A SACCO deleting a crew member
 * keeps the person's account and history -- except an account that was never
 * used and only ever belonged to that SACCO (a typo or a duplicate from street
 * onboarding), which disappears. Nullable and without a default, so adding it
 * rewrites nothing and every existing account stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
