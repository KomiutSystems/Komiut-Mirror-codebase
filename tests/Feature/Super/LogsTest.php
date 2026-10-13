<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\ApplicationLog;
use App\Models\RequestLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The super-admin LOGS console: HTTP request logging via the global terminable
 * middleware, the DB application-log channel, and the safe server-log tail.
 */
final class LogsTest extends QueueTestCase
{
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        Permission::findOrCreate('View Platform Logs', 'web');
        $user->givePermissionTo('View Platform Logs');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    #[Test]
    public function a_request_is_recorded_and_returned_by_the_http_feed(): void
    {
        // A signed-in read is ROUTINE, so it is only sampled; record them all here.
        config(['logging.request_log_sample' => 1.0]);
        Sanctum::actingAs($this->superAdmin());

        // terminate() runs AFTER the response, so a self-referential call would
        // never see its own row. Fire one request first, THEN read the feed.
        $this->getJson('/api/v1/super/logs/sources')->assertOk();

        $this->assertGreaterThanOrEqual(1, RequestLog::count(), 'The middleware should have logged the first request.');

        $this->getJson('/api/v1/super/logs/http')
            ->assertOk()
            ->assertJsonPath('message.items.0.path', '/api/v1/super/logs/sources')
            ->assertJsonPath('message.items.0.method', 'GET')
            ->assertJsonPath('message.items.0.status', 200);
    }

    #[Test]
    public function the_load_balancer_health_check_is_never_recorded(): void
    {
        config(['logging.request_log_sample' => 1.0]);

        $this->getJson('/api/up')->assertOk();

        $this->assertSame(0, RequestLog::count());
    }

    #[Test]
    public function routine_reads_are_sampled_but_a_signed_in_users_failure_is_always_kept(): void
    {
        config(['logging.request_log_sample' => 0.0]);
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/logs/sources')->assertOk();
        $this->assertSame(0, RequestLog::count(), 'a routine read at a 0% sample leaves no row');

        // The feed is GET-only: a signed-in user's POST to it is a 405.
        $this->postJson('/api/v1/super/logs/http')->assertStatus(405);

        $this->assertSame(1, RequestLog::count());
        $row = RequestLog::first();
        $this->assertSame('POST', $row->method);
        $this->assertSame(405, (int) $row->status);
        $this->assertNotNull($row->user_id);
    }

    #[Test]
    public function logs_prune_keeps_the_windows_and_drops_what_is_older(): void
    {
        RequestLog::query()->insert([
            ['method' => 'GET', 'path' => '/old', 'status' => 200, 'created_at' => now()->subDays(15)],
            ['method' => 'GET', 'path' => '/new', 'status' => 200, 'created_at' => now()->subDays(13)],
        ]);
        DB::table('mpesa_logs')->insert([
            ['trans_id' => 'OLD1', 'log' => '{}', 'created_at' => now()->subDays(95), 'updated_at' => now()->subDays(95)],
            ['trans_id' => 'OLD2', 'log' => '{}', 'created_at' => now()->subDays(91), 'updated_at' => now()->subDays(91)],
            ['trans_id' => 'KEEP', 'log' => '{}', 'created_at' => now()->subDays(89), 'updated_at' => now()->subDays(89)],
            ['trans_id' => 'TODAY', 'log' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('logs:prune', ['--days' => 14, '--mpesa-days' => 90])->assertSuccessful();

        $this->assertSame(['/new'], RequestLog::pluck('path')->all());
        $this->assertSame(['KEEP', 'TODAY'], DB::table('mpesa_logs')->orderBy('id')->pluck('trans_id')->all());
    }

    #[Test]
    public function a_non_super_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->makeUser()); // ordinary user

        $this->getJson('/api/v1/super/logs/http')->assertStatus(403);
    }

    #[Test]
    public function the_application_feed_returns_database_channel_records(): void
    {
        Log::channel('database')->warning('disk almost full', ['free' => '2%']);

        $this->assertSame(1, ApplicationLog::count());

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/logs/application?level=warning')
            ->assertOk()
            ->assertJsonPath('message.items.0.message', 'disk almost full')
            ->assertJsonPath('message.items.0.level', 'warning')
            ->assertJsonPath('message.items.0.context.free', '2%');
    }

    #[Test]
    public function an_unconfigured_server_source_returns_items_not_a_500(): void
    {
        Sanctum::actingAs($this->superAdmin());

        // php_fpm defaults to '' in config/platform.php — an unconfigured source.
        $this->getJson('/api/v1/super/logs/server?source=php_fpm')
            ->assertOk()
            ->assertJsonPath('message.items', [])
            ->assertJsonPath('message.count', 0)
            ->assertJsonPath('message.source', 'php_fpm')
            ->assertJsonStructure(['message' => ['items', 'count', 'note']]);
    }

    #[Test]
    public function an_unknown_server_source_returns_items_not_a_500(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/logs/server?source=does_not_exist')
            ->assertOk()
            ->assertJsonPath('message.items', [])
            ->assertJsonPath('message.count', 0);
    }

    #[Test]
    public function the_server_feed_tails_a_readable_file_newest_first(): void
    {
        $path = sys_get_temp_dir().'/logs_test_'.uniqid().'.log';
        file_put_contents($path, "line one\nline two\nline three\n");
        config(['platform.logs.sources.framework' => $path]);

        Sanctum::actingAs($this->superAdmin());

        try {
            $this->getJson('/api/v1/super/logs/server?source=framework&lines=2')
                ->assertOk()
                ->assertJsonPath('message.count', 2)
                ->assertJsonPath('message.items.0', 'line three') // newest first
                ->assertJsonPath('message.items.1', 'line two');
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function the_sources_endpoint_lists_configured_sources(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/logs/sources')
            ->assertOk()
            ->assertJsonPath('message.items.0.source', 'framework')
            ->assertJsonStructure(['message' => ['items' => [['source', 'configured', 'available']], 'count', 'maxLines']]);
    }
}
