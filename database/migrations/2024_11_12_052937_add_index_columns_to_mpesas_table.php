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
        Schema::table('mpesas', function (Blueprint $table) {
            $table->index("TransID");
            $table->index("TransTime");
            $table->index(["FirstName", "MiddleName", "LastName"]);
            $table->index("BusinessShortCode");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesas', function (Blueprint $table) {
            //
            $table->dropIndex("TransID");
            $table->dropIndex("TransTime");
            $table->dropIndex(["FirstName", "MiddleName", "LastName"]);
            $table->dropIndex("BusinessShortCode");
        });
    }
};
