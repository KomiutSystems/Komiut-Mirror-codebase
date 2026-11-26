<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The code Co-operative Bank files a bus's escrow account under for STK Push.
 * The push names no till and no account: the bank pays the money into whatever
 * account this code was set up for. Written exactly as the bank set it up
 * ("KDW 319J" with a space, "KDY688D" without) -- it is not the plate.
 * Empty means the bank has not set the bus up for STK, and no prompt is raised.
 *
 * Nullable and default-free: adding it rewrites nothing and takes no long lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('coop_operator_code', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('coop_operator_code');
        });
    }
};
