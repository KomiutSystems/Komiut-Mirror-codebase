<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A SACCO has many M-Pesa connections, and exactly one of them is its default.
 *
 * NICCO alone collects through ~25 head-office Daraja apps (KDQ's 5142602,
 * Moses 5339502, Mr Mburu-Coop 3020809, ...), each linked to the buses whose
 * tills sit under it. The schema already allowed that -- vehicles carry
 * mpesa_payment_setting_id -- but nothing named a connection, and "the SACCO's
 * connection" was whichever row happened to have the lowest id
 * (MpesaCredentialResolver::settingFor). Giving SACCOs their other connections
 * to see and manage would have quietly changed that fallback.
 *
 * So the fallback becomes explicit: `is_default`, set here on exactly the row
 * the resolver picks today (lowest id per SACCO). No bus changes the
 * credentials it pays and registers with. `name` is the label a SACCO knows a
 * connection by ("Mr Mburu-Coop"), since a short code alone tells nobody which
 * account it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_payment_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('mpesa_payment_settings', 'name')) {
                $table->string('name', 100)->nullable();
            }
            if (! Schema::hasColumn('mpesa_payment_settings', 'is_default')) {
                $table->boolean('is_default')->default(false);
            }
        });

        DB::statement(
            'UPDATE mpesa_payment_settings SET is_default = true
             WHERE sacco_id IS NOT NULL
               AND id = (SELECT MIN(x.id) FROM mpesa_payment_settings x WHERE x.sacco_id = mpesa_payment_settings.sacco_id)'
        );
    }

    public function down(): void
    {
        Schema::table('mpesa_payment_settings', function (Blueprint $table): void {
            $table->dropColumn(['name', 'is_default']);
        });
    }
};
