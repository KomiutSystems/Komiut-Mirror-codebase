<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Auth\Roles;
use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Cache\ScopedCache;
use App\Support\PlatformConsole;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Safetek Staff: the ops board and KPIs, the tenants and the alerts --
 * read-only but for directory claims and resolving alerts -- and nothing
 * else in the console.
 */
final class SafetekStaffTest extends QueueTestCase
{
    /** Console routes staff may reach: [method, uri without the v1/super/ prefix]. */
    private const OPEN = [
        ['GET', 'saccos'], ['GET', 'saccos/{id}'],
        ['GET', 'directory'], ['POST', 'directory/{id}/approve'], ['POST', 'directory/{id}/reject'],
        ['GET', 'vehicles'], ['GET', 'routes'], ['GET', 'termini'], ['GET', 'tenant/review-queue'],
        ['GET', 'notifications'], ['GET', 'notifications/unread-count'],
        ['POST', 'notifications/read-all'], ['POST', 'notifications/{id}/read'], ['POST', 'notifications/{id}/resolve'],
        ['GET', 'fraud/audit'],
    ];

    private function staff(): User
    {
        $u = $this->makeUser([]);
        $u->forceFill(['type' => UserType::Admin->value, 'sacco_id' => null])->save();
        $u->assignRole(Roles::SAFETEK_STAFF);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function superAdmin(): User
    {
        $u = $this->makeUser([]);
        $u->forceFill(['type' => UserType::Superadmin->value])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $u->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** @return list<Route> every route behind the console gate, under v1 */
    private function consoleRoutes(): array
    {
        return array_values(array_filter(Router::getRoutes()->getRoutes(), fn (Route $r) => in_array('super', $r->gatherMiddleware(), true)
            && str_starts_with($r->uri(), 'api/v1/super/')));
    }

    #[Test]
    public function every_console_route_checks_a_permission(): void
    {
        // The gate now lets staff in; a route without its own permission would
        // be open to them. Every one must carry one.
        $bare = array_map(fn (Route $r) => implode('|', $r->methods()).' '.$r->uri(),
            array_filter($this->consoleRoutes(), fn (Route $r) => ! collect($r->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'permission:') || str_starts_with($m, 'can:')))));

        $this->assertSame([], array_values($bare));
    }

    #[Test]
    public function staff_reach_their_slices_and_nothing_else_in_the_console(): void
    {
        $staff = $this->staff();
        $someone = $this->makeUser([]);
        $role = Role::findByName(Roles::SAFETEK_STAFF, 'web');
        // Real ids for bound models (a made-up one is a 404 before any
        // permission runs) and no rate limit (this calls every route).
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);
        Sanctum::actingAs($staff);
        $open = array_map(fn ($o) => $o[0].' api/v1/super/'.$o[1], self::OPEN);
        $refusedWrongly = [];
        $openedWrongly = [];

        foreach ($this->consoleRoutes() as $route) {
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $key = $method.' '.$route->uri();
            $uri = '/'.preg_replace(['/\{user\}/', '/\{role\}/', '/\{[^}]+\}/'], [(string) $someone->id, (string) $role->id, '999999'], $route->uri());
            $status = $this->json($method, $uri)->status();

            if (in_array($key, $open, true)) {
                if ($status === 403) {
                    $refusedWrongly[] = $key;
                }
            } elseif ($status !== 403) {
                $openedWrongly[] = $key.' -> '.$status;
            }
        }

        $this->assertSame([], $refusedWrongly, 'staff must reach these');
        $this->assertSame([], $openedWrongly, 'staff must be refused everything else');
    }

    #[Test]
    public function staff_see_every_tenant_in_the_console_but_none_outside_it(): void
    {
        $komiut = $this->makeSacco();
        $safiri = Sacco::withoutGlobalScopes()->create(['name' => 'Twosafiri Sacco', 'status' => 1, 'brand' => 'safiri']);
        Sanctum::actingAs($staff = $this->staff());

        $ids = collect($this->getJson('/api/v1/super/saccos?per_page=100')->assertOk()->json('data') ?? $this->getJson('/api/v1/super/saccos')->json('saccos'))->pluck('id');
        $this->assertContains($komiut->id, $ids);
        $this->assertContains($safiri->id, $ids);

        // Outside the console the account is an admin of no SACCO: nothing.
        // (Each production request starts with an empty context.)
        Context::flush();
        $this->assertFalse(PlatformConsole::seesAllTenants($staff), 'no console request, no cross-tenant view');
        Auth::setUser($staff);
        $this->assertSame(0, Vehicle::query()->count(), 'the SACCO scope fails closed for them');
    }

    #[Test]
    public function the_sacco_page_shows_staff_no_money(): void
    {
        $world = $this->makeWorld();

        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/super/saccos/'.$world['sacco']->id)->assertOk()->assertJsonPath('money.gross_volume_30d', null);

        Sanctum::actingAs($this->superAdmin());
        $this->assertIsNumeric($this->getJson('/api/v1/super/saccos/'.$world['sacco']->id)->assertOk()->json('money.gross_volume_30d'));
    }

    #[Test]
    public function staff_review_claims_and_resolve_alerts(): void
    {
        $pending = $this->makeSacco();
        $pending->forceFill(['claim_status' => SaccoClaimStatus::PendingReview])->save();
        $flag = PlatformNotification::create([
            'event' => 'driver.reassigned.rapid', 'severity' => 'high', 'delivery_class' => 'alert', 'title' => 'x', 'summary' => 'x',
            'first_occurred_at' => now(), 'occurred_at' => now(), 'count' => 1,
        ]);
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/super/directory/{$pending->id}/approve")->assertSuccessful();
        $this->postJson("/api/v1/super/notifications/{$flag->id}/resolve")->assertSuccessful();
        $this->assertNotNull($flag->fresh()->resolved_at);
    }

    #[Test]
    public function staff_are_on_the_team_and_a_sacco_admin_cannot_make_anyone_staff(): void
    {
        $staff = $this->staff();
        Sanctum::actingAs($staff);
        $this->assertContains($staff->id, array_column($this->getJson('/api/v1/super/team/members')->assertOk()->json('members'), 'id'));
        $this->getJson('/api/v1/super/team/board')->assertOk();
        $this->getJson('/api/v1/super/team/performance')->assertOk();

        $this->assertNotContains(Roles::SAFETEK_STAFF, Roles::saccoAssignable());
        $sacco = $this->makeSacco();
        $admin = $this->makeUser(['Edit Sacco Members'], $sacco);
        $member = $this->makeUser([], $sacco);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::SAFETEK_STAFF]])->assertForbidden();
    }

    #[Test]
    public function a_staff_request_never_shares_the_super_or_a_brands_cache(): void
    {
        $staff = $this->staff();
        Auth::setUser($staff);
        PlatformConsole::enterAsStaff();

        $partition = (new \ReflectionMethod(ScopedCache::class, 'segment'))->invoke(null);
        $this->assertSame('staff', $partition);
    }

    #[Test]
    public function a_switched_off_staff_account_is_refused(): void
    {
        $staff = $this->staff();
        $staff->forceFill(['status' => false])->save();
        Sanctum::actingAs($staff->fresh());

        $this->getJson('/api/v1/super/saccos')->assertForbidden();
    }
}
