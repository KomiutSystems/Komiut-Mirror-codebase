<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an unguessable per-payment nonce to STK push records.
 *
 * The Daraja callback URL previously carried only `?booking_id=<sequential id>`
 * on an unauthenticated route, so anyone could POST a forged success payload
 * for a guessed booking and mark it paid. The callback is now keyed by this
 * nonce, and `processed_at` makes replays idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_stk_callbacks', function (Blueprint $table): void {
            $table->string('callback_nonce', 64)->nullable()->unique()->after('qrcode_payment_id');
            $table->timestamp('processed_at')->nullable()->after('callback');
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_stk_callbacks', function (Blueprint $table): void {
            $table->dropUnique(['callback_nonce']);
            $table->dropColumn(['callback_nonce', 'processed_at']);
        });
    }
};
