<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Canonical RBAC definitions — the single source of truth for role names and
 * their permission bundles, consumed by RoleSeeder, SACCO registration and the
 * roles API. Always reference these constants, never string literals: a
 * 'Super Admin' vs 'Superadmin' drift silently breaks a role check (lockout or
 * privilege escalation).
 *
 * Roles are GLOBAL, not per-SACCO: a "Fleet Manager" carries the same
 * permissions in every SACCO, and SaccoScope confines each user to their own
 * SACCO's data. Per-SACCO custom roles are intentionally deferred — because the
 * UI/API gate on permissions (not role names), adding spatie "teams" later is
 * non-breaking. Until then role CRUD is superadmin-only (a created role would be
 * global), and SACCO admins get an assign-only surface.
 */
final class Roles
{
    // Platform tier
    public const SUPER_ADMIN = 'Super Admin';   // MUST match User::isSuperAdmin()

    // SACCO tier
    public const SACCO_ADMIN = 'SACCO Admin';
    public const FLEET_MANAGER = 'Fleet Manager';
    public const OPERATIONS_MANAGER = 'Operations Manager';
    public const FINANCE = 'Finance';
    public const BOOKING_CLERK = 'Booking Clerk';
    public const SUPPORT_AGENT = 'Support Agent';

    // Field tier (mostly the mobile apps)
    public const DRIVER = 'Driver';
    public const CONDUCTOR = 'Conductor';

    /** Permissions the new features added — ensured to exist by the seeder. */
    public const FEATURE_PERMISSIONS = [
        'View Fares', 'Add Fares', 'Edit Fares',
        'View Loyalty', 'Add Loyalty', 'Edit Loyalty',
        'View Invoices', 'Add Invoices', 'Edit Invoices',
        'View Billing Plans', 'Add Billing Plans', 'Edit Billing Plans',
    ];

    /** Platform-only permissions a SACCO-tier role must never receive. */
    public const PLATFORM_ONLY = [
        'Add Saccos', 'Edit Saccos',
        'View Permissions', 'Add Permissions', 'Edit Permissions',
        'View Roles', 'Add Roles', 'Edit Roles',
        'View Users', 'Add Users', 'Edit Users',
        'View Logs', 'Edit Logs', 'Addd Logs',
        'View Billing Plans', 'Add Billing Plans', 'Edit Billing Plans',
        // Super-admin platform console — never granted to a SACCO-tier role.
        'View Platform Notifications', 'Manage Platform Thresholds', 'View Platform Logs',
    ];

    /** Roles a SACCO admin may assign to their own staff (never platform roles). */
    public static function saccoAssignable(): array
    {
        return [
            self::SACCO_ADMIN, self::FLEET_MANAGER, self::OPERATIONS_MANAGER,
            self::FINANCE, self::BOOKING_CLERK, self::SUPPORT_AGENT,
            self::DRIVER, self::CONDUCTOR,
        ];
    }

    /**
     * Explicit permission bundles for the granular roles. SUPER_ADMIN (every
     * permission) and SACCO_ADMIN (every permission except PLATFORM_ONLY) are
     * resolved in the seeder against the live catalog, so they are not listed here.
     *
     * @return array<string, array<int, string>>
     */
    public static function bundles(): array
    {
        return [
            self::FLEET_MANAGER => [
                'View Dashboard',
                'View Vehicles', 'Add Vehicles', 'Edit Vehicles',
                'View Sacco Vehicles', 'Add Sacco Vehicles', 'Edit Sacco Vehicles',
                'View Vehicle Users', 'Add Vehicle Users', 'Edit Vehicle Users',
                'View Crews', 'Add Crews', 'Edit Crews',
                'View Sacco Members', 'Add Sacco Members', 'Edit Sacco Members',
                'View Vehicle Locations', 'View Routes', 'View Summaries',
            ],
            self::OPERATIONS_MANAGER => [
                'View Dashboard',
                'View Routes', 'Add Routes', 'Edit Routes',
                'View Places', 'Add Places', 'Edit Places',
                'View Termini', 'Add Termini', 'Edit Termini',
                'View Sacco Routes', 'Add Sacco Routes', 'Edit Sacco Routes',
                'View Fares', 'Add Fares', 'Edit Fares',
                'View Queues', 'Add Queues', 'Edit Queues',
                'View Queue Statuses', 'Add Queue Statuses', 'Edit Queue Statuses',
                'Get Queue Notifications', 'View Vehicle Locations',
                'View Passengers', 'View Summaries',
            ],
            self::FINANCE => [
                'View Dashboard',
                'View Invoices',
                'View Transactions', 'Add Transactions', 'Edit Transactions',
                'View Transaction Cards', 'View QRCode Payments',
                'View Expense And Fees', 'Add Expense And Fees', 'Edit Expense And Fees',
                'View Summaries', 'Add Summaries',
                'View Points', 'View Redeemed Points', 'View Payment Settings',
            ],
            self::BOOKING_CLERK => [
                'View Dashboard',
                'View Queues', 'Add Queues', 'Edit Queues', 'View Queue Statuses',
                'View Passengers', 'Add Passengers', 'Edit Passengers',
                'View Vehicle Locations', 'View Routes',
            ],
            self::SUPPORT_AGENT => [
                'View Dashboard', 'View Passengers',
                'View Direct Line Claims', 'View Summaries',
            ],
            self::DRIVER => [
                'View Dashboard', 'View Routes', 'View Queues', 'Edit Queues',
                'View Vehicles', 'View Vehicle Locations',
            ],
            self::CONDUCTOR => [
                'View Dashboard', 'View Routes', 'View Queues',
                'View Passengers', 'Edit Passengers', 'View Vehicle Locations', 'View Transactions',
            ],
        ];
    }
}
