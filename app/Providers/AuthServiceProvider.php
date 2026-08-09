<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        /*
         * A superadmin passes every authorization check.
         *
         * Superadmin-ness is a property of the ACCOUNT (users.type), but
         * permissions come from spatie roles, so the two could disagree: a user
         * with type = superadmin but without the 'Super Admin' role held no
         * permissions at all and was refused everywhere. Nothing surfaced that
         * while reads were unguarded; now that they are, it would be a lockout
         * of the only account that can administer the platform.
         *
         * This also makes the authorization layer agree with SaccoScope, which
         * already bypasses on isSuperAdmin() rather than on a role.
         *
         * Returning null (not false) for everyone else leaves the normal gate
         * chain intact — a `before` callback that returned false would deny
         * every non-superadmin outright.
         */
        Gate::before(fn ($user) => $user instanceof User && $user->isSuperAdmin() ? true : null);
    }
}
