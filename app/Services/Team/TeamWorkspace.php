<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is on Safetek's team, stated once.
 *
 * The gate and the member list are the same rule on purpose: everyone who may
 * open the workspace can be assigned work in it, and nobody else. Today that
 * is the super admins and Safetek Staff (Roles::SAFETEK_STAFF). No controller
 * asks isSuperAdmin() itself.
 */
final class TeamWorkspace
{
    /** The gate every /super/team route sits behind. */
    public const GATE = 'team-workspace';

    public static function allows(?User $user): bool
    {
        return $user instanceof User
            && (bool) $user->status
            && self::members()->whereKey($user->getKey())->exists();
    }

    /** @return Builder<User> active team members */
    public static function members(): Builder
    {
        return User::query()
            ->where('status', true)
            ->where(function (Builder $q): void {
                $q->where('type', UserType::Superadmin->value)
                    ->orWhereHas('roles', fn (Builder $r) => $r->whereIn('name', [Roles::SUPER_ADMIN, Roles::SAFETEK_STAFF]));
            });
    }

    public static function isMember(int $userId): bool
    {
        return self::members()->whereKey($userId)->exists();
    }

    /** @return array{id:int,name:string,email:?string}|null */
    public static function member(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: ($user->email ?? 'User '.$user->id),
            'email' => $user->email,
        ];
    }

    /** Our brand keys, as the console names them. */
    public static function brand(?string $brand): ?string
    {
        return match ($brand) {
            'komiut' => 'komiut',
            'safiri' => '2safiri',
            default => null,
        };
    }
}
