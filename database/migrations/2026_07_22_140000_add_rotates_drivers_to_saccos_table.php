<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a SACCO rotates which driver runs which vehicle.
 *
 * Drivers sign in each day with their phone + vehicle number plate. If the SACCO
 * rotates drivers, that session must expire daily so the next day's driver
 * re-authenticates against the current assignment. SACCOs that keep a fixed
 * driver per vehicle get a non-expiring session (no daily login).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saccos', function (Blueprint $table): void {
            $table->boolean('rotates_drivers')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('saccos', function (Blueprint $table): void {
            $table->dropColumn('rotates_drivers');
        });
    }
};
