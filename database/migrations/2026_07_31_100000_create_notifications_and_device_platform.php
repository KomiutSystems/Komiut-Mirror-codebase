<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notification storage (Laravel's standard notifications table) plus the
 * device-token columns the mobile push flow needs.
 *
 * The app registers a device with just {token, platform} — it never sends the
 * device_id the original firebase_tokens schema made NOT NULL — so device_id
 * becomes nullable and a `platform` column is added (ANDROID|IOS|WEB).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('type');                       // notification class FQN (Laravel internal)
                $table->morphs('notifiable');                 // notifiable_type + notifiable_id
                $table->text('data');                         // JSON payload (our camelCase shape)
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                // "my unread notifications, newest first" — the list + badge query.
                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });
        }

        Schema::table('firebase_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('firebase_tokens', 'platform')) {
                $table->string('platform')->nullable()->after('firebase_token');
            }
        });

        // device_id was NOT NULL; the app doesn't send it. Relax it.
        Schema::table('firebase_tokens', function (Blueprint $table): void {
            $table->string('device_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('firebase_tokens', function (Blueprint $table): void {
            if (Schema::hasColumn('firebase_tokens', 'platform')) {
                $table->dropColumn('platform');
            }
        });
    }
};
