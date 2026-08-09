<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which SACCO an audit row belongs to.
 *
 * audit_logs already carried `brand`, which is the platform partition, but
 * nothing narrower — so every audit read was necessarily platform-wide and the
 * only endpoints over this table live under /super. A SACCO could not be shown
 * its own activity at all.
 *
 * Driver sign-ins are the first events a SACCO genuinely needs to see (who took
 * which vehicle out, and when), so the table needs a tenant column.
 *
 * Nullable on purpose: plenty of audited actions are platform-level and belong
 * to no SACCO. The index is (sacco_id, created_at) because the only read is
 * "this SACCO's activity, newest first".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('audit_logs', 'sacco_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('sacco_id')->nullable()->after('brand');
            $table->index(['sacco_id', 'created_at'], 'audit_logs_sacco_id_created_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('audit_logs', 'sacco_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_sacco_id_created_at_index');
            $table->dropColumn('sacco_id');
        });
    }
};
