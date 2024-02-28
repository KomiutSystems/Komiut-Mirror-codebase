<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('direct_line_claims', function (Blueprint $table) {
            $table->foreignIdFor(Transaction::class)->nullabl()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('direct_line_claims', function (Blueprint $table) {
            $table->dropColumn('transaction_id');
        });
    }
};
