<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Super\Access\AdminRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Admin actions on a user account: suspend / restore / password-reset / delete.
 * Every mutation is audit-first (App\Services\Platform\AuditLogger), matching
 * this domain's convention that a privileged change is reconstructable even if
 * the derived notification is dismissed.
 */
class UserActionsController extends Controller
{
    public function suspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string']);

        $user->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => $data['reason'],
        ])->save();

        $audit = AuditLogger::record(
            'access.user.suspended',
            ['targetUserId' => $user->id, 'reason' => $data['reason']],
            null,
            ['type' => 'user', 'id' => (string) $user->id],
        );

        if (AdminRoles::holdsAdminRole($user)) {
            app(PlatformNotifier::class)->dispatch(new PlatformEvent(
                event: 'access.user.suspended',
                severity: 'high',
                class: 'alert',
                title: 'Admin user suspended',
                summary: 'User #'.$user->id.' was suspended.',
                brand: $this->brand(),
                actor: $this->actorArray($request),
                subject: ['type' => 'user', 'id' => (string) $user->id],
                data: ['targetUserId' => $user->id],
                windowMinutes: 0,
                auditId: $audit->id,
            ));
        }

        return response()->json(['success' => true, 'user' => UsersController::rowFor($user->refresh())]);
    }

    public function restore(Request $request, User $user): JsonResponse
    {
        $user->forceFill([
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        AuditLogger::record(
            'access.user.restored',
            ['targetUserId' => $user->id],
            null,
            ['type' => 'user', 'id' => (string) $user->id],
        );

        return response()->json(['success' => true, 'user' => UsersController::rowFor($user->refresh())]);
    }

    public function passwordReset(Request $request, User $user): JsonResponse
    {
        $temporaryPassword = Str::random(16);

        // User::$casts has 'password' => 'hashed' — assigning the plain value
        // lets Eloquent hash it once; hashing it here first would double-hash.
        $user->forceFill(['password' => $temporaryPassword])->save();

        AuditLogger::record(
            'access.user.password_reset_by_admin',
            ['targetUserId' => $user->id],
            null,
            ['type' => 'user', 'id' => (string) $user->id],
        );

        return response()->json([
            'success' => true,
            'user' => UsersController::rowFor($user->refresh()),
            'temporary_password' => $temporaryPassword,
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->isSuperAdmin()) {
            return response()->json(['error' => 'Cannot delete a Super Admin.'], 422);
        }

        $data = $request->validate(['reason' => 'required|string']);

        // Soft semantics: close the account rather than hard-deleting the row —
        // matches this codebase's "close, don't delete" convention (see
        // App\Services\Driver\VehicleAssignment).
        $user->forceFill([
            'status' => false,
            'suspended_at' => now(),
            'suspension_reason' => $data['reason'],
        ])->save();

        AuditLogger::record(
            'access.user.deleted',
            ['targetUserId' => $user->id, 'reason' => $data['reason']],
            null,
            ['type' => 'user', 'id' => (string) $user->id],
        );

        return response()->json(['success' => true, 'user' => UsersController::rowFor($user->refresh())]);
    }

    private function brand(): ?string
    {
        return Context::has('brand') ? (string) Context::get('brand') : null;
    }

    /** @return array{type:string,id:?string,label:?string} */
    private function actorArray(Request $request): array
    {
        $actor = $request->user();
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
