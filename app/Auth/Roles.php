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

    // Roles carried over from the legacy system. Renamed away from the client
    // that happened to define them — every SACCO sees this list, so "Nicco
    // Managers" would be meaningless (and a small data leak) to the other 38.
    // The legacy name each one replaces is recorded in bundles().
    public const INVESTOR = 'Investor';                        // was: Investor

    public const QUEUE_SUPERVISOR = 'Queue Supervisor';        // was: Nicco Managers

    public const CASHLESS_ADMIN = 'Cashless Administrator';    // was: Nicco Administrator Cashless

    public const BANK_VIEWER = 'Bank Viewer';                  // was: CB Admin

    /**
     * Roles whose holder legitimately reads the WHOLE SACCO's money.
     *
     * Read by ScopesToOwnedVehicles to decide who is an INVESTOR and nothing
     * else. An investor is a SACCO member with money in the fleet, not staff of
     * it, so their money screens narrow to the buses they own — unless they also
     * hold one of these, in which case they are staff first and keep the
     * fleet-wide view. Millicent Gichimu at NICCO is an Investor and a SACCO
     * Admin; narrowing her to her own two buses would break the dashboard she
     * runs the SACCO on.
     *
     * Deliberately absent, and each for a reason:
     *   BOOKING_CLERK, DRIVER  no money permission in their bundle, so listing
     *   CONDUCTOR              them would widen nobody and only add risk.
     *   SUPPORT_AGENT          holds View Summaries, but reads it to answer a
     *                          passenger, not to run a fleet.
     *   BANK_VIEWER            not fleet-wide at all — it is one bank's financed
     *                          fleet, and FinancierScope already confines it.
     *   INVESTOR               the tier being narrowed.
     *
     * This list only ever WIDENS access, so anything uncertain belongs outside
     * it: a role wrongly omitted loses a view someone will report, while a role
     * wrongly included silently restores the leak.
     */
    public const FULL_FLEET_VIEW = [
        self::SUPER_ADMIN,
        self::SACCO_ADMIN,
        self::FLEET_MANAGER,
        self::OPERATIONS_MANAGER,
        self::FINANCE,
        self::CASHLESS_ADMIN,
        self::QUEUE_SUPERVISOR,
    ];

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
            // Legacy-derived tiers. Safe for a SACCO Admin to hand out: none
            // carries a PLATFORM_ONLY permission, so none can escalate beyond
            // the SACCO boundary.
            self::INVESTOR, self::QUEUE_SUPERVISOR,
            self::CASHLESS_ADMIN,

            // BANK_VIEWER is deliberately ABSENT. It is not a SACCO staff role:
            // it means "a financing bank sees the fleet it financed", which is a
            // financier-wide view that deliberately crosses SACCO boundaries
            // (Co-op's 54 vehicles all sit inside NICCO MOVERS, but NCBA's 829 do
            // not). Leaving it assignable let any of the 48 SACCO admins mint a
            // bank account for their own staff and read beyond their own SACCO —
            // a privilege-escalation path out of the tenant boundary every other
            // role on this list stays inside. Granting it stays superadmin-only.
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
                // Reaching the crew is a fleet job as much as an admin one.
                'Send Crew Announcements',
            ],
            self::OPERATIONS_MANAGER => [
                'View Dashboard',
                'View Routes', 'Add Routes', 'Edit Routes',
                'View Places', 'Add Places', 'Edit Places',
                'View Termini', 'Add Termini', 'Edit Termini',
                // Which termini THIS SACCO operates out of (the sacco_termini
                // link). The permission rows have existed in permissions.csv
                // since the beginning but were in no bundle at all, so only a
                // superadmin could hold them — which would have left the
                // attach/detach endpoints unreachable for the very role that
                // already owns termini, routes and queues. SACCO Admin picks
                // these up automatically (everything minus PLATFORM_ONLY).
                'View Termini Saccos', 'Add Termini Saccos', 'Edit Termini Saccos',
                'View Sacco Routes', 'Add Sacco Routes', 'Edit Sacco Routes',
                'View Fares', 'Add Fares', 'Edit Fares',
                'View Queues', 'Add Queues', 'Edit Queues',
                'View Queue Statuses', 'Add Queue Statuses', 'Edit Queue Statuses',
                'Get Queue Notifications', 'View Vehicle Locations',
                'View Passengers', 'View Summaries',
                'Send Crew Announcements',
            ],
            self::FINANCE => [
                'View Dashboard',
                'View Invoices',
                'View Transactions', 'Add Transactions', 'Edit Transactions',
                'View Transaction Cards', 'View QRCode Payments',
                'View Expense And Fees', 'Add Expense And Fees', 'Edit Expense And Fees',
                'View Summaries', 'Add Summaries',
                'View Points', 'View Redeemed Points', 'View Payment Settings',
                // The NCBA push-notification letter and the tills it opens.
                // Finance, not fleet: it carries the SACCO's Daraja credentials.
                'Manage Bank Till Requests',
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
            // The conductor works the stage: they load the matatu, take the
            // fares and close the trip. Two permissions were missing and both
            // broke real people.
            //
            //   Edit Queues     — without it queues/join, queues/exit and
            //                     trips/start all 403, so the entire shift
            //                     workflow was closed to the 206 crew migrated
            //                     from legacy, every one of whom holds this role.
            //   View Summaries  — without it "what has this bus taken today"
            //                     returns 403. The 19 owner-drivers on this role
            //                     could see individual payments but never their
            //                     own daily total.
            //
            // Neither crosses a SACCO boundary: SaccoScope confines both, and
            // the money endpoints were additionally pinned to the caller's own
            // SACCO, so this widens what a conductor may do with THEIR vehicle,
            // not whose data they can reach.
            self::CONDUCTOR => [
                'View Dashboard', 'View Routes', 'View Queues', 'Edit Queues',
                'View Passengers', 'Edit Passengers', 'View Vehicle Locations',
                'View Transactions', 'View Summaries',
            ],

            // ---------------------------------------------------------------
            // Carried over from the legacy system. These four had no
            // equivalent here, so the users holding them were migrated with NO
            // permissions rather than guessing a bundle. The lists below are
            // NOT invented — they are the exact `role_has_permissions` rows the
            // legacy roles carried, read out of komiut_latest_app.
            //
            // One deliberate subtraction: legacy "Nicco Administrator Cashless"
            // also held 'View Users', which is PLATFORM_ONLY here (it lists
            // every user on the platform, across all SACCOs). A SACCO-tier role
            // must not carry it, so it is dropped. Everything else is verbatim.
            //
            // Renamed away from the client that defined them: every SACCO sees
            // this list, so "Nicco Managers" would be meaningless to the other
            // 38 (and leaks a client name). Legacy name noted on each.
            // ---------------------------------------------------------------

            // was: Investor
            self::INVESTOR => [
                'View Summaries', 'View Transactions', 'View Transaction Cards',
                'View QRCode Payments', 'View Expense And Fees',
                'View Vehicles', 'Add Vehicles', 'View Vehicle Users',
                'View Points', 'View Direct Line Claims', 'Edit Parcels',
            ],
            // was: Nicco Managers
            self::QUEUE_SUPERVISOR => [
                'View Queues', 'Add Queues', 'Edit Queues',
                'View Queue Statuses', 'Add Queue Statuses', 'Edit Queue Statuses',
                'View Vehicles', 'View Vehicle Locations',
                'View Routes', 'View Places',
                'View Summaries', 'View Transactions', 'View QRCode Payments',
            ],
            // was: Nicco Administrator Cashless ('View Users' dropped, see above)
            self::CASHLESS_ADMIN => [
                'View Dashboard',
                'View Expense And Fees', 'Add Expense And Fees', 'Edit Expense And Fees',
                'View Expense And Fees Settings', 'Add Expense And Fees Settings', 'Edit Expense And Fees Settings',
                'View Queues', 'Add Queues', 'Edit Queues',
                'View Queue Statuses', 'Add Queue Statuses',
                'View Direct Line Claims', 'Add Direct Line Claims',
                'View Crews', 'View Passengers', 'View Places', 'View Routes',
                'View Sacco Routes', 'View Seat Settings', 'View Termini',
                'View Points', 'View Redeemed Points',
                'View Summaries', 'View Transactions', 'View Transaction Cards',
                'View QRCode Payments', 'View Vehicles', 'View Vehicle Locations',
                'View Vehicle Users',
            ],
            // was: CB Admin - read-only money visibility for a bank partner.
            //
            // 'View Vehicles' is money visibility too, not fleet administration:
            // a bank finances buses and has to be able to see WHICH buses those
            // are, or the collections it is shown are a total it cannot break
            // down. Vehicle carries BelongsToFinancier, so the list is already
            // confined to the fleet that bank financed -- NCBA reads its 829 and
            // none of Co-op's 54 -- and this is the only route the permission
            // gates. The write permissions are deliberately still absent.
            self::BANK_VIEWER => [
                'View Summaries', 'View Transactions', 'View QRCode Payments',
                'View Vehicles',
            ],
        ];
    }
}
