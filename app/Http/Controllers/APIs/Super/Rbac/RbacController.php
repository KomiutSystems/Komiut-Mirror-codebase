<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Rbac;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\User;
use App\Services\Super\Access\AccessChangeRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * RBAC reads + the one privileged mutation (role → permissions) for the
 * super-admin console. The permission-sync mutation deliberately does NOT
 * reimplement the audit-then-notify path: App\Services\Super\Access\
 * AccessChangeRecorder::recordPermissionSync already IS that path — it's what
 * App\Http\Controllers\APIs\Dashboard\Settings\RolesController::saveRole calls
 * for the existing dashboard "save role" route — so this route calls the same
 * recorder rather than emitting a second, possibly-drifting copy of the event.
 */
class RbacController extends Controller
{
    /** The static brand catalog with a couple of cheap per-brand counts. */
    public function brands(): JsonResponse
    {
        $brands = [];
        foreach ((array) config('brands', []) as $key => $definition) {
            $hosts = array_values(array_filter((array) ($definition['hosts'] ?? [])));

            $brands[] = [
                'brand' => $key,
                'name' => $definition['name'] ?? $key,
                'hosts' => $hosts,
                'app_key_set' => ! empty($definition['app_key']),
                'saccos' => Sacco::where('brand', $key)->count(),
                'users' => User::whereHas('sacco', fn ($q) => $q->where('brand', $key))->count(),
                // No cheap source for either yet — null rather than a fabricated value.
                'tls_expires_at' => null,
                'bank_partner' => null,
                'status' => 'active',
            ];
        }

        return response()->json($brands);
    }

    /**
     * Every Spatie role with its permission list. NOTE: this codebase has no
     * permission "group" concept (unlike RolesController::permissions(), which
     * derives a pseudo-group from the verb prefix for its own UI) — `group` is
     * always null here rather than inventing one.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::with('permissions:id,name')->orderBy('name')->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'permissions' => $role->permissions
                    ->map(fn (Permission $p) => ['name' => $p->name, 'group' => null])
                    ->values(),
            ])->values();

        return response()->json($roles);
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::orderBy('name')->get()
            ->map(fn (Permission $p) => ['name' => $p->name])
            ->values();

        return response()->json($permissions);
    }

    /**
     * Privileged mutation: audit-first, then the (never-throttled) critical
     * alert — both handled by AccessChangeRecorder::recordPermissionSync, the
     * same method the dashboard's own role-save route already calls.
     */
    public function updatePermissions(Request $request, Role $role, AccessChangeRecorder $recorder): JsonResponse
    {
        $data = $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $permsBefore = $role->permissions()->pluck('name')->all();

        // Guard 'web' explicitly — see RolesController::saveRole for why: the
        // sanctum guard on this request would otherwise fail to resolve names.
        $role->syncPermissions(
            Permission::where('guard_name', 'web')->whereIn('name', $data['permissions'] ?? [])->get()
        );

        $recorder->recordPermissionSync($role, $permsBefore, $request->user());

        return response()->json(['role' => [
            'name' => $role->name,
            'permissions' => $role->permissions()->pluck('name')->values(),
        ]]);
    }
}
