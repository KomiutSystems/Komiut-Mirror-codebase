<?php

declare(strict_types=1);

namespace App\Services\Crew;

use App\Auth\Roles;
use App\Models\SaccoUser;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Services\Driver\VehicleAssignment;
use App\Services\Platform\AuditLogger;
use App\Services\Super\Access\AccessChangeRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * A SACCO deleting one of its crew (a driver or conductor).
 *
 * The SACCO's side of the person ends completely: off every bus of this
 * SACCO with their open trip cancelled, signed out of the apps, the crew roles
 * this SACCO gave them gone, their membership closed and their SACCO cleared.
 * The ACCOUNT stays, with every trip, payment and assignment it ever made: it
 * is the person's own, and they may join another SACCO tomorrow.
 *
 * One exception: an account nobody ever used, that only ever belonged to this
 * SACCO, is a typo or a duplicate from street onboarding -- that one is
 * soft-deleted so it stops appearing, and its phone and email are freed so
 * the right account can be made with them.
 */
final class CrewRemoval
{
    /**
     * Where an account leaves a trace by being used. Any row in any of these
     * means a person used it; the bank lead street onboarding always writes is
     * deliberately not one (it says the agent typed the number, not that
     * anyone used the account).
     */
    private const ACTIVITY = [
        ['personal_access_tokens', 'tokenable_id'], ['refresh_tokens', 'user_id'], ['firebase_tokens', 'user_id'],
        ['queues', 'user_id'], ['queue_logs', 'user_id'], ['crews', 'user_id'],
        ['cashes', 'user_id'], ['cash_submissions', 'user_id'], ['qrcode_payments', 'user_id'],
        ['bookings', 'user_id'], ['bookings', 'created_by'], ['parcels', 'created_by'],
        ['points', 'user_id'], ['loyalty_transactions', 'user_id'],
        ['carbon_credit_transactions', 'user_id'], ['carbon_credit_redemptions', 'user_id'],
        ['lost_item_reports', 'user_id'], ['vehicles', 'user_id'], ['sacco_vehicles', 'user_id'],
        ['terminus_users', 'user_id'], ['sacco_announcements', 'user_id'], ['vehicle_followers', 'user_id'],
        ['owner_statements', 'owner_id'], ['request_logs', 'user_id'], ['sessions', 'user_id'],
        // Any wallet at all, empty or not: it was opened for a person.
        ['wallets', 'user_id'], ['wallet_transactions', 'actor_id'],
    ];

    /** Crew roles: the only roles a crew delete may take away. */
    public const CREW_ROLES = [Roles::DRIVER, Roles::CONDUCTOR, Roles::QUEUE_SUPERVISOR];

    public function __construct(private readonly VehicleAssignment $assignments) {}

    /** @return array{deleted:true, closed_assignments:int, account_removed:bool} */
    public function remove(User $crew, User $by, int $saccoId): array
    {
        return DB::transaction(function () use ($crew, $by, $saccoId): array {
            $crew = User::query()->lockForUpdate()->findOrFail($crew->id);
            // Re-read under the lock: released and signed up elsewhere in the
            // moment since the caller looked, they are no longer ours to touch.
            if ((int) $crew->sacco_id !== $saccoId) {
                abort(404, 'That person is not in your SACCO.');
            }

            // Decided BEFORE anything below runs: signing them out deletes the
            // tokens that are part of the evidence.
            $unused = $this->neverUsed($crew, $saccoId);

            $stood = $this->assignments->standDown($crew, $saccoId);

            // Rows on this SACCO's buses that were closed already stay as they are.
            SaccoUser::withoutGlobalScopes()->where('user_id', $crew->id)->where('sacco_id', $saccoId)
                ->whereNull('end_date')->update(['end_date' => now(), 'status' => 0]);

            $rolesBefore = $crew->getRoleNames()->all();
            $permsBefore = $crew->getAllPermissions()->pluck('name')->all();
            $keep = array_values(array_diff($rolesBefore, Roles::saccoAssignable()));
            if ($keep !== $rolesBefore) {
                $crew->syncRoles(Role::where('guard_name', 'web')->whereIn('name', $keep)->get());
                app(AccessChangeRecorder::class)->recordRoleSync($crew, $rolesBefore, $permsBefore, $by);
            }

            // Signed out of every app now, not when a token next expires.
            $crew->tokens()->delete();
            if (Schema::hasTable('refresh_tokens')) {
                DB::table('refresh_tokens')->where('user_id', $crew->id)->delete();
            }

            $crew->forceFill(['sacco_id' => null])->save();

            if ($unused) {
                $crew->forceFill(['phone' => null, 'email' => null, 'status' => false])->save();
                $crew->delete();
            }

            AuditLogger::record('crew.deleted', [
                'user_id' => (int) $crew->id,
                'closed_assignments' => $stood['closed'],
                'queues_cancelled' => $stood['queues_cancelled'],
                'roles_removed' => array_values(array_intersect($rolesBefore, Roles::saccoAssignable())),
                'account_removed' => $unused,
            ], subject: ['type' => 'user', 'id' => (string) $crew->id], saccoId: $saccoId);

            return ['deleted' => true, 'closed_assignments' => $stood['closed'], 'account_removed' => $unused];
        });
    }

    /** Was this account never used, and only ever this SACCO's? */
    private function neverUsed(User $crew, int $saccoId): bool
    {
        if ($crew->last_active_at !== null) {
            return false;
        }

        foreach (self::ACTIVITY as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table)->where($column, $crew->id);
            if ($table === 'personal_access_tokens') {
                $query->where('tokenable_type', User::class);
            }
            if ($query->exists()) {
                return false;
            }
        }

        // Done anything anyone audited (signed in to the dashboard, acted).
        if (DB::table('audit_logs')->where('actor_type', 'user')->where('actor_id', (string) $crew->id)->exists()) {
            return false;
        }

        // Crewed a bus for more than a day. A conductor without a smartphone
        // leaves no other trace, and their name is on those days' takings. A
        // typo or duplicate is caught the day it was made; after that, it is
        // only ever released, never removed.
        $worked = VehicleUser::withoutGlobalScopes()->where('user_id', $crew->id)
            ->where(fn ($q) => $q->where('created_at', '<', now()->subDay())->orWhere('start_date', '<', now()->subDay()))
            ->exists();
        if ($worked) {
            return false;
        }

        $otherSacco = SaccoUser::withoutGlobalScopes()->where('user_id', $crew->id)->where('sacco_id', '!=', $saccoId)->exists();
        $otherBus = VehicleUser::withoutGlobalScopes()->where('user_id', $crew->id)
            ->whereNotIn('vehicle_id', Vehicle::withoutGlobalScopes()->select('id')->where('sacco_id', $saccoId))->exists();

        return ! $otherSacco && ! $otherBus;
    }
}
