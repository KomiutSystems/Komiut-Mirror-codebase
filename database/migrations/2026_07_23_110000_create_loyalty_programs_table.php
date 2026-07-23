<?php

use App\Models\Sacco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-SACCO loyalty configuration. Earning is proportional to spend
 * (points = fare ÷ divisor); redeeming a free ride costs `redemption_threshold`
 * points. One program per SACCO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Sacco::class)->unique()->constrained()->cascadeOnDelete();
            $table->double('divisor')->default(100);            // KES per point earned
            $table->double('redemption_threshold')->default(0); // points to redeem a free ride
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_programs');
    }
};
