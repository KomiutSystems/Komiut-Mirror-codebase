<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who ended a trip: the driver from the bus, or an admin from the dashboard or
 * the management app (QueuesAPIController::endTrip). A nullable column on a
 * table of a few thousand rows -- instant, no rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queues', 'ended_by')) {
            Schema::table('queues', function (Blueprint $table): void {
                $table->unsignedBigInteger('ended_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('queues', 'ended_by')) {
            Schema::table('queues', function (Blueprint $table): void {
                $table->dropColumn('ended_by');
            });
        }
    }
};
