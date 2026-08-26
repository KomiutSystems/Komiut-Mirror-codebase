<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The SACCO table is itself tenant data.
 *
 * This is a regression test for a live leak: a SACCO Admin at NICCO opened the
 * dashboard and was shown another SACCO entirely ("Bahima"). Sacco was the one
 * tenant model with no global scope, so the directory endpoint returned all 49
 * SACCOs ordered by name and the client took the first one as the active tenant.
 * Everything downstream held — her vehicles, summaries and transactions were
 * correctly confined — so no money was exposed, but the directory was, and an
 * admin stranded in someone else's tenant saw an empty dashboard with no
 * explanation for it.
 */
final class SaccoDirectoryTenancyTest extends QueueTestCase
{
    /**
     * makeUser()'s first argument is a PERMISSIONS list, not attributes, so the
     * account type has to be set after the fact.
     */
    private function userOfType(UserType $type, ?Sacco $sacco): User
    {
        $user = $this->makeUser([], $sacco);
        $user->type = $type;
        $user->sacco_id = $sacco?->id;
        $user->save();

        return $user->fresh();
    }

    #[Test]
    public function a_sacco_admin_sees_only_their_own_sacco(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        Auth::login($this->userOfType(UserType::Admin, $mine['sacco']));

        $visible = Sacco::query()->pluck('id')->all();

        $this->assertSame([$mine['sacco']->id], $visible);
        $this->assertNotContains($theirs['sacco']->id, $visible);
    }

    #[Test]
    public function a_sacco_admin_cannot_open_another_saccos_record_by_id(): void
    {
        // The other half of the same bug: getSacco took an id from the request
        // and called findOrFail on it, with nothing between that id and the row.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        Auth::login($this->userOfType(UserType::Admin, $mine['sacco']));

        $this->assertNull(Sacco::find($theirs['sacco']->id));
        $this->assertNotNull(Sacco::find($mine['sacco']->id));
    }

    #[Test]
    public function a_passenger_still_browses_every_sacco(): void
    {
        // Passengers and drivers have no home SACCO, so currentSaccoId() is null
        // and the scope deliberately does not apply — they book across SACCOs.
        // Narrowing this would break booking, which is why the exemption exists.
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        Auth::login($this->userOfType(UserType::Passenger, null));

        $visible = Sacco::query()->pluck('id')->all();

        $this->assertContains($a['sacco']->id, $visible);
        $this->assertContains($b['sacco']->id, $visible);
    }

    #[Test]
    public function a_super_admin_still_sees_every_sacco(): void
    {
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        Auth::login($this->userOfType(UserType::Superadmin, $a['sacco']));

        $visible = Sacco::query()->pluck('id')->all();

        $this->assertContains($a['sacco']->id, $visible);
        $this->assertContains($b['sacco']->id, $visible);
    }

    #[Test]
    public function maintenance_paths_can_still_see_across_tenants(): void
    {
        // SaccoObserver's duplicate-name detection, BrandAudit, BrandBackfill and
        // DetectDormantSaccos all rely on withoutGlobalScopes(). If the escape
        // hatch stopped working, duplicate SACCOs would stop being detected —
        // silently, which is the worst way for that to fail.
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        Auth::login($this->userOfType(UserType::Admin, $a['sacco']));

        $all = Sacco::withoutGlobalScopes()->pluck('id')->all();

        $this->assertContains($a['sacco']->id, $all);
        $this->assertContains($b['sacco']->id, $all);
    }

    #[Test]
    public function an_unauthenticated_caller_is_not_scoped(): void
    {
        // Self-registration and the SACCO claim flow run before there is a user.
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        Auth::logout();

        $visible = Sacco::query()->pluck('id')->all();

        $this->assertContains($a['sacco']->id, $visible);
        $this->assertContains($b['sacco']->id, $visible);
    }
}
