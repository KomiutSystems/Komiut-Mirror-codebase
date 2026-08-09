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
        // NOTE: there is deliberately NO Gate::before superadmin bypass.
        //
        // Superadmin-ness (users.type) grants cross-SACCO VISIBILITY -- SaccoScope
        // checks isSuperAdmin() -- but it does NOT grant platform CAPABILITIES.
        // Those still require the permission, so the platform console's gates mean
        // something even for a superadmin. Tests\Feature\Super\AccessEventsTest
        // asserts exactly this: a superadmin without 'View Platform Notifications'
        // clears the `super` middleware and is still refused by the route gate.
        //
        // A blanket Gate::before would silently delete that second layer.
    }
}
