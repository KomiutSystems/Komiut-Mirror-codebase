<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime overrides for the alert thresholds in config/platform.php.
 *
 * The Thresholds service was written expecting the /super console to retune a
 * detector "without a deploy", but config is read-only at runtime — so the
 * overrides need somewhere real to live. One row per (brand, key); the config
 * file stays the source of DEFAULTS and rows here only override it, which keeps
 * a wiped table equivalent to shipped behaviour rather than to no thresholds.
 *
 * `brand` is nullable: null means the override applies platform-wide. The
 * unique index covers (brand, key) so a brand cannot hold two values for one
 * threshold. Value is json because thresholds are a mix of scalars
 * (sacco_dormant_days => 14) and shapes (driver_login_burst => {count, window}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_thresholds', function (Blueprint $table): void {
            $table->id();
            $table->string('brand')->nullable()->index();
            $table->string('key');
            $table->json('value');
            $table->timestamps();

            $table->unique(['brand', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_thresholds');
    }
};
