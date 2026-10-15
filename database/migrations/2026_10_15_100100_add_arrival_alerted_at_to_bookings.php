<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a passenger was told their bus is arriving (ArrivalAlerts), so they are
 * told once. A nullable column on a small table -- instant, no rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'arrival_alerted_at')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->timestamp('arrival_alerted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'arrival_alerted_at')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->dropColumn('arrival_alerted_at');
            });
        }
    }
};
