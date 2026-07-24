<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing plan catalog. A "subscription type" is a plan a SACCO is put on; the
 * invoice for a period is base_fee + per_vehicle_fee × active vehicles (capped
 * at vehicle_cap when set). Rates are edited by superadmins; SACCOs only read.
 *
 * Columns beyond the original name/description are added here in place — we are
 * still on local and the billing schema ships fresh to the new environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('billing_cycle')->default('monthly');   // monthly|quarterly|annually
            $table->decimal('base_fee', 12, 2)->default(0);        // flat fee per period
            $table->decimal('per_vehicle_fee', 12, 2)->default(0); // × active vehicles
            $table->unsignedInteger('vehicle_cap')->nullable();    // stop charging past N vehicles
            $table->string('currency', 3)->default('KES');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('status_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_types');
    }
};
