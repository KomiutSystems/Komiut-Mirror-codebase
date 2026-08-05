<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `suspended_at` / `suspension_reason` — the super-admin console's suspend
 * action on a user account. Distinct from `status` (the plain active/inactive
 * flag): a suspension always overrides `status` in the derived state shown to
 * the console (see App\Http\Controllers\APIs\Super\Users\UsersController),
 * and carries a reason an admin can read back later.
 *
 * Soft-delete (super/users/{id} DELETE) reuses these same two columns rather
 * than adding a third — it sets status=false + suspended_at + suspension_reason,
 * matching this codebase's "close, don't delete" convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->index()->after('last_active_at');
            $table->string('suspension_reason')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
