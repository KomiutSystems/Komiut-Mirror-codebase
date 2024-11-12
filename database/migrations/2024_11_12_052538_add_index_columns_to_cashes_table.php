<?php

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
        Schema::table('cashes', function (Blueprint $table) {
            $table->index("trans_id");
            $table->index(['firstname', 'lastname']);
            $table->index("vehicle_id");
            $table->index('trans_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cashes', function (Blueprint $table) {
            $table->dropIndex('trans_id');
            $table->dropIndex(["firstname", "lastname"]);
            $table->dropIndex("trans_date");
        });
    }
};
