<?php

declare(strict_types=1);

namespace App\Services\Super\Access;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\User;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The access/privilege domain emitter. Controllers that change roles/permissions
 * or handle auth hand the before/after here; this class computes the diff, writes
 * the audit row (privilege changes are audit-first) and dispatches the platform
 * event. All payloads are PII-free — accounts are referenced by id only.
 */
class AccessChangeRecorder
{
    /** Direct permissions whose grant is worth a super-admin alert. */
    private const SENSITIVE_PERMISSIONS = [
        'Edit Loyalty',
        'Add Payment Settings',
        'Edit Payment Settings',
        'Edit Vehicles',
        'Add Sacco Members',
        'Edit Sacco Members',
    ];

    public function __construct(private readonly PlatformNotifier $notifier) {}

    /**
     * Called AFTER a user's roles are synced. Detects a Super Admin grant/revoke
     * (audit-first, never throttled) and any newly-gained sensitive permission.
     *
     * @param  array<int, string>  $rolesBefore
     * @param  array<int, string>  $permsBefore
     */
    public function recordRoleSync(User $user, array $rolesBefore, array $permsBefore, ?User $actor): void
    {
        // Spatie caches roles/permissions per request; after a sync the cached set
        // is stale. Invalidate + drop the loaded relations so the "after" read is
        // the real post-sync state (otherwise the diff comes back empty).
        $this->flush($user);

        $rolesAfter = $user->getRoleNames()->all();
        $permsAfter = $user->getAllPermissions()->pluck('name')->all();

        $hadSuper = in_array(Roles::SUPER_ADMIN, $rolesBefore, true);
        $hasSuper = in_array(Roles::SUPER_ADMIN, $rolesAfter, true);
        if ($hadSuper !== $hasSuper) {
            $this->superAdminChanged($user, $hasSuper ? 'granted' : 'revoked', $actor);
        }

        $gained = array_values(array_diff($permsAfter, $permsBefore));
        foreach ($gained as $permission) {
            if (in_array($permission, self::SENSITIVE_PERMISSIONS, true)) {
                $this->sensitivePermissionGranted($user, $permission, $actor);
            }
        }
    }

    /**
     * Called AFTER a role's permissions are synced. Emits when the set changed.
     *
     * @param  array<int, string>  $permsBefore
     */
    public function recordPermissionSync(Role $role, array $permsBefore, ?User $actor): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Read via a fresh relationship query, not the cached relation.
        $permsAfter = $role->permissions()->pluck('name')->all();

        $added = array_values(array_diff($permsAfter, $permsBefore));
        $removed = array_values(array_diff($permsBefore, $permsAfter));
        if ($added === [] && $removed === []) {
            return;
        }

        $brand = $this->brand();
        $actorArr = $this->actorArray($actor);
        $subject = ['type' => 'role', 'id' => (string) $role->id];
        $data = [
            'role' => $role->name,
            'added' => $added,
            'removed' => $removed,
            'affectedUserCount' => $role->users()->count(),
            'changedBy' => $actor?->id,
        ];

        $audit = AuditLogger::record('access.role.permissions_changed', $data, $actorArr, $subject, $brand);

        $this->notifier->dispatch(new PlatformEvent(
            event: 'access.role.permissions_changed',
            severity: 'critical',
            class: 'alert',
            title: 'Role permissions changed: '.$role->name,
            summary: $role->name.': +'.count($added).' / -'.count($removed).' permissions ('.$data['affectedUserCount'].' users).',
            brand: $brand,
            actor: $actorArr,
            subject: $subject,
            data: $data,
            dedupeKey: 'access:role_perms:'.$role->id,
            windowMinutes: 0,
            auditId: $audit->id,
        ));
    }

    /**
     * Called AFTER a user's `financier` is set, changed or cleared.
     *
     * Role syncs were already recorded here; this column was not, and it is the
     * one that decides WHICH BANK'S MONEY an account can read. FinancierScope
     * keys on nothing else — flip 'NCBA' to 'coop-bank' on user 6272 and that
     * account stops seeing 829 vehicles and starts seeing 54, with no role
     * change, no permission change and, until now, no trace anywhere. A role
     * grant left a record while the more powerful edit left none.
     *
     * `saccoIdBefore` is recorded alongside because provisioning a bank viewer
     * NULLs it, and that clearing is the part a reviewer most needs to see: it
     * is what stops SaccoScope and FinancierScope intersecting (see
     * BankAccessController for the 703-vehicle version of that story), and it
     * is also what lifts the SACCO wall on every model FinancierScope does not
     * cover. Both halves of the change belong in one row.
     *
     * Audit-first and never throttled, matching superAdminChanged(): this is a
     * privilege boundary move, and the immutable row must exist even if the
     * derived alert is dismissed or deduped away.
     */
    public function recordFinancierChange(
        User $user,
        ?string $financierBefore,
        ?int $saccoIdBefore,
        ?User $actor,
    ): void {
        $financierAfter = $user->financier;
        // Same reason as the caller's cast: `sacco_id` carries no model cast, so
        // compare and record a normalised int rather than whatever the driver
        // returned. A string/int mismatch here would report a no-op as a change.
        $saccoIdAfter = $user->sacco_id === null ? null : (int) $user->sacco_id;

        // Nothing moved — a re-provision of an account that already reads for
        // this bank. Emitting would train the reviewer to ignore the event.
        if ($financierBefore === $financierAfter && $saccoIdBefore === $saccoIdAfter) {
            return;
        }

        $brand = $this->brand();
        $actorArr = $this->actorArray($actor);
        $subject = ['type' => 'user', 'id' => (string) $user->id];
        $data = [
            'targetUserId' => $user->id,
            'financierBefore' => $financierBefore,
            'financierAfter' => $financierAfter,
            'saccoIdBefore' => $saccoIdBefore,
            'saccoIdAfter' => $saccoIdAfter,
            'changedBy' => $actor?->id,
        ];

        $audit = AuditLogger::record('access.financier.changed', $data, $actorArr, $subject, $brand);

        $this->notifier->dispatch(new PlatformEvent(
            event: 'access.financier.changed',
            severity: 'critical',
            class: 'alert',
            title: 'Bank access changed',
            summary: 'User #' . $user->id . ' now reads for ' . ($financierAfter ?? 'no bank')
                . ' (was ' . ($financierBefore ?? 'no bank') . ').',
            brand: $brand,
            actor: $actorArr,
            subject: $subject,
            data: $data,
            dedupeKey: 'access:financier:' . $user->id,
            windowMinutes: 0,
            auditId: $audit->id,
        ));
    }

    /**
     * Called on a failed dashboard login. Counts failures per admin account within
     * the burst window; emits once when the count first crosses the threshold.
     */
    public function recordFailedLogin(?string $identifier, ?string $ip): void
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return;
        }

        // No user → no admin role to protect, and keying the counter on the raw
        // identifier would leak PII into the cache. Skip.
        $user = $this->resolveUser($identifier);
        if ($user === null || ! AdminRoles::holdsAdminRole($user)) {
            return;
        }

        $threshold = Thresholds::get($this->brand(), 'access_login_burst');
        $count = (int) ($threshold['count'] ?? 5);
        $window = (int) ($threshold['window_minutes'] ?? 15);
        $windowSeconds = max(1, $window * 60);

        // Per-account counter (an IP-rotating attacker still crosses it) and a
        // per-account set of the source IPs seen in this window.
        $countKey = 'access:login_fail:count:'.$user->id;
        $ipsKey = 'access:login_fail:ips:'.$user->id;

        Cache::add($countKey, 0, $windowSeconds); // seed with a TTL so it self-resets
        $attempts = (int) Cache::increment($countKey);

        $ips = (array) Cache::get($ipsKey, []);
        if ($ip !== null && $ip !== '' && ! in_array($ip, $ips, true)) {
            $ips[] = $ip;
            Cache::put($ipsKey, $ips, $windowSeconds);
        }

        // Emit only at the crossing, not on every subsequent failure.
        if ($attempts !== $count) {
            return;
        }

        $data = [
            'userId' => $user->id,
            'attemptCount' => $attempts,
            'sourceIps' => array_values($ips),
        ];

        $this->notifier->dispatch(new PlatformEvent(
            event: 'access.login.failed_burst',
            severity: 'high',
            class: 'alert',
            title: 'Admin login failure burst',
            summary: $attempts.' failed logins for admin user #'.$user->id.'.',
            brand: $this->brand(),
            subject: ['type' => 'user', 'id' => (string) $user->id],
            data: $data,
            dedupeKey: 'access:login_burst:'.$user->id,
            windowMinutes: $window,
        ));
    }

    /** Called on a password reset. Emits when the target holds an admin role. */
    public function recordPrivilegedPasswordReset(User $user, ?string $ip): void
    {
        $this->flush($user);
        if (! AdminRoles::holdsAdminRole($user)) {
            return;
        }

        $data = [
            'userId' => $user->id,
            'roles' => $user->getRoleNames()->values()->all(),
            'requestedIp' => $ip,
        ];

        $this->notifier->dispatch(new PlatformEvent(
            event: 'access.password_reset.privileged',
            severity: 'high',
            class: 'alert',
            title: 'Privileged account password reset',
            summary: 'Password reset for admin user #'.$user->id.'.',
            brand: $this->brand(),
            subject: ['type' => 'user', 'id' => (string) $user->id],
            data: $data,
            dedupeKey: 'access:pwreset:'.$user->id,
            windowMinutes: 60,
        ));
    }

    /**
     * Called on a successful login. A dashboard (type=admin) account that signs in
     * with zero permissions is a review signal. Passengers/drivers are excluded by
     * type, so this never fires on mobile logins.
     */
    public function recordDashboardLogin(User $user): void
    {
        if ($user->type !== UserType::Admin) {
            return;
        }

        $this->flush($user);
        if ($user->getAllPermissions()->isNotEmpty()) {
            return;
        }

        $this->notifier->dispatch(new PlatformEvent(
            event: 'access.no_permission_login',
            severity: 'normal',
            class: 'review',
            title: 'Zero-permission dashboard login',
            summary: 'Dashboard user #'.$user->id.' signed in with no permissions.',
            brand: $this->brand(),
            subject: ['type' => 'user', 'id' => (string) $user->id],
            data: ['userId' => $user->id],
            dedupeKey: 'access:nopermlogin:'.$user->id,
            windowMinutes: 1440,
        ));
    }

    private function superAdminChanged(User $user, string $action, ?User $actor): void
    {
        $brand = $this->brand();
        $actorArr = $this->actorArray($actor);
        $subject = ['type' => 'user', 'id' => (string) $user->id];
        $data = [
            'targetUserId' => $user->id,
            'action' => $action,
            'changedBy' => $actor?->id,
        ];

        // Audit-first: the immutable row precedes the (never-throttled) alert.
        $audit = AuditLogger::record('access.super_admin.changed', $data, $actorArr, $subject, $brand);

        $this->notifier->dispatch(new PlatformEvent(
            event: 'access.super_admin.changed',
            severity: 'critical',
            class: 'alert',
            title: 'Super Admin role '.$action,
            summary: 'Super Admin role '.$action.' for user #'.$user->id.'.',
            brand: $brand,
            actor: $actorArr,
            subject: $subject,
            data: $data,
            dedupeKey: 'access:super_admin:'.$user->id,
            windowMinutes: 0,
            auditId: $audit->id,
        ));
    }

    private function sensitivePermissionGranted(User $user, string $permission, ?User $actor): void
    {
        $brand = $this->brand();
        $data = [
            'targetUserId' => $user->id,
            'permission' => $permission,
            'saccoId' => $user->currentSaccoId(),
            'grantedBy' => $actor?->id,
        ];

        $this->notifier->dispatch(new PlatformEvent(
            event: 'access.sensitive_permission.granted',
            severity: 'high',
            class: 'alert',
            title: 'Sensitive permission granted',
            summary: "'".$permission."' granted to user #".$user->id.'.',
            brand: $brand,
            actor: $this->actorArray($actor),
            subject: ['type' => 'user', 'id' => (string) $user->id],
            data: $data,
            dedupeKey: 'access:sensitive:'.$user->id.':'.$permission,
            windowMinutes: 0,
        ));
    }

    private function resolveUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::where('email', $identifier)->first();
        }

        // Login submits `07…` while accounts are stored `2547…`; try both forms.
        $candidates = array_values(array_unique(array_filter([
            $identifier,
            '254'.ltrim($identifier, '0'),
        ])));

        return User::whereIn('phone', $candidates)->first();
    }

    /** Drop stale spatie caches/relations so a post-change read is accurate. */
    private function flush(User $user): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
    }

    private function brand(): ?string
    {
        return Context::has('brand') ? (string) Context::get('brand') : null;
    }

    /** @return array{type:string,id:?string,label:?string} */
    private function actorArray(?User $actor): array
    {
        if ($actor === null) {
            return ['type' => 'system', 'id' => null, 'label' => null];
        }

        return [
            'type' => 'user',
            'id' => (string) $actor->id,
            'label' => trim(($actor->firstname ?? '').' '.($actor->lastname ?? '')) ?: null,
        ];
    }
}
