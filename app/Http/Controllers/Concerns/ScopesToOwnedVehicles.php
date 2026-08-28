<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Auth\Roles;
use App\Models\Scopes\SaccoScope;
use App\Models\User;
use App\Models\VehicleUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Narrows a money read to the vehicles the caller actually OWNS.
 *
 * The Investor role carries View Summaries, View Transactions, View QRCode
 * Payments and View Expense And Fees, and every one of them resolved to a
 * SACCO-WIDE read, because SaccoScope was the only filter in the path and
 * SaccoScope's answer is "which SACCO?", not "which buses?". At NICCO MOVERS
 * (sacco_id 4) that put ~147 reporting vehicles and KES 2,619,683 of one day's
 * takings in front of someone who owns one or two buses. Twelve accounts hold
 * the role.
 *
 * Permission is not ownership: 'View Transactions' says what KIND of thing a
 * caller may read, never WHICH ROW. This trait supplies the row check.
 *
 * WHY A CONTROLLER CONCERN AND NOT A GLOBAL SCOPE. The three boundaries in this
 * codebase (SaccoScope, BrandScope, FinancierScope) are tenancy — they answer
 * "whose data is this?" and belong on the model, where the next endpoint picks
 * them up for free. Ownership is a different question: an investor IS inside
 * the tenant, and the SACCO's own staff must keep seeing every row in it. A
 * global scope would also have to narrow every incidental read an investor's
 * request makes — their own profile, a till lookup, a vehicle picker — and each
 * of those is a separate judgement. It is applied here, at the five money
 * listings the role can actually reach, and each caller is named below.
 *
 * The cost of that choice is the one FinancierScopeTest names: a controller
 * boundary is inherited by nothing. Any new endpoint reading money per vehicle
 * must call ownedVehicleIds() itself.
 *
 * Applied by:
 *   - Summaries\SummariesAPIController      (list, totals footer, CSV, PDF)
 *   - Transactions\TransactionsAPIController (list, both total tiles, export)
 *   - Transactions\MpesaAPIController        (payments list)
 *   - QRCode\QRCodeApiController             (QR payments list)
 *   - ExpenseAndFees\ExpenseAndFeesAPIController (expenses list)
 */
trait ScopesToOwnedVehicles
{
    /** @var array<int, int>|null */
    private ?array $ownedVehicleIdsCache = null;

    /** null is a real answer here, so "resolved" cannot be inferred from the cache. */
    private bool $ownedVehicleIdsResolved = false;

    /**
     * The vehicle ids this caller may see money for, or NULL when no ownership
     * filter applies and the caller must add none.
     *
     * The three results are NOT interchangeable:
     *
     *   null   not an investor-only caller. Apply NO extra filter.
     *   [1,2]  narrow to exactly these vehicle ids.
     *   []     an investor with no open assignment. Narrow to NOTHING.
     *
     * That last one is the point of the change. `whereIn($column, [])` compiles
     * to `0 = 1`, so an empty array fails CLOSED by itself — but only if the
     * caller passes it straight through. NEVER guard a call with
     * `if (count($ids) > 0)`. That is the exact shape of the bug this exists to
     * remove: a filter that could not work out what to narrow by, and so
     * narrowed by nothing and returned everything. SaccoScope was written that
     * way and handed a passenger account 5,033 summaries worth KES 78,223,947.
     *
     * @return array<int, int>|null
     */
    protected function ownedVehicleIds(): ?array
    {
        if ($this->ownedVehicleIdsResolved) {
            return $this->ownedVehicleIdsCache;
        }

        $this->ownedVehicleIdsResolved = true;

        if (! self::confines(Auth::user())) {
            return $this->ownedVehicleIdsCache = null;
        }

        /** @var User $user */
        $user = Auth::user();

        // Ownership lives in vehicle_users, NOT in vehicles.user_id: that column
        // records whoever last SAVED the row, and 168 of NICCO's 180 vehicles
        // point at the migration account. Keyed on it, one investor would
        // inherit the fleet and the other eleven would get nothing.
        //
        // An OPEN assignment is status = true AND end_date IS NULL — the same
        // pair App\Services\Driver\VehicleAssignment writes and closes on. The
        // SoftDeletes trait already excludes deleted rows.
        //
        // withoutGlobalScope(SaccoScope) is deliberate and NARROWS rather than
        // widens. vehicle_users.sacco_id is nullable and predates being
        // populated, so scoping on it would silently drop a real investor's
        // buses; and for a saccoless caller SaccoScope now denies the whole
        // table, which would read as "owns nothing" for the wrong reason. The
        // SACCO boundary is taken from the authoritative side instead —
        // whereHas('vehicle') runs Vehicle's own SaccoScope, BrandScope and
        // FinancierScope, so an assignment pointing outside the caller's SACCO,
        // brand or bank cannot survive the EXISTS, and neither can one pointing
        // at a vehicle that no longer exists.
        $this->ownedVehicleIdsCache = VehicleUser::query()
            ->withoutGlobalScope(SaccoScope::class)
            ->where('user_id', $user->id)
            ->where('status', true)
            ->whereNull('end_date')
            ->whereHas('vehicle')
            ->pluck('vehicle_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->ownedVehicleIdsCache;
    }

    /**
     * Is this caller confined to the buses they own?
     *
     * Shaped after FinancierScope::confines() so the two tiers are read the same
     * way, and false for everybody else — the filter only ever touches an
     * account holding the Investor role, which is what keeps the blast radius at
     * the twelve investor accounts rather than all 6,808 users.
     *
     * An investor who is ALSO SACCO staff keeps the fleet-wide view: Millicent
     * Gichimu at NICCO is an Investor and a SACCO Admin, and narrowing her to
     * her own buses would break the dashboard she runs the SACCO on.
     *
     * Bank Viewer is deliberately NOT a fleet-wide role. It means "a bank sees
     * the fleet it financed", which FinancierScope already confines; an investor
     * who is also bank staff gets both filters, and the intersection is right.
     *
     * Reached as `self::confines()` from inside the trait, which resolves to the
     * composing controller. Never call it as ScopesToOwnedVehicles::confines():
     * a static call directly on a trait is deprecated as of PHP 8.1. Callers
     * outside the trait should ask `ownedVehicleIds() !== null` instead, which
     * is the same question and is memoised.
     */
    protected static function confines(?Authenticatable $user): bool
    {
        if (! $user instanceof User || $user->isSuperAdmin()) {
            return false;
        }

        if (! $user->hasRole(Roles::INVESTOR)) {
            return false;
        }

        return ! $user->hasAnyRole(Roles::FULL_FLEET_VIEW);
    }
}
