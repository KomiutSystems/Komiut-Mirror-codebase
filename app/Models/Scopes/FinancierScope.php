<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Enums\Financier;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Confines a bank user to the vehicles their own bank financed.
 *
 * NCBA and Co-operative Bank staff log in as ordinary users and must see only
 * the fleet they financed, and only money computed over that fleet. Neither of
 * the two boundaries already in the codebase can express that:
 *
 *   - SaccoScope keys on the SACCO. NICCO MOVERS holds 180 vehicles, 126 NCBA
 *     and 54 Co-op, so a SACCO-keyed filter shows each bank the other's buses.
 *   - BrandScope keys on the portal. brand komiut is 840 vehicles but financier
 *     NCBA is 829, so it would show NCBA 11 buses it does not finance — while
 *     testing green for Co-op, whose 54 vehicles happen to be exactly brand
 *     safiri. Right answer for one bank, wrong for the other.
 *
 * Two ways this scope deliberately differs from its siblings:
 *
 *   1. It FAILS CLOSED. SaccoScope returns early when it cannot resolve a
 *      sacco_id, which is right there (passengers and drivers legitimately
 *      query across SACCOs to book a ride). A bank user is saccoless for the
 *      same structural reason a bank is not a SACCO — so inheriting that shape
 *      would hand a bank whose financier failed to resolve the entire platform.
 *      An unresolvable financier therefore matches nothing at all.
 *   2. It applies to NOBODY ELSE. A SACCO admin, a driver, a conductor and an
 *      unauthenticated webhook all see exactly what they saw before. This scope
 *      only ever narrows a caller who is already a bank.
 *
 * A caller counts as a bank when they carry a financier OR hold the Bank Viewer
 * role (see User::isBankUser) — the role half is what makes the fail-closed
 * branch reachable, since a bank user whose column is unset would otherwise
 * look like an ordinary user and be waved through unscoped.
 *
 * Superadmins are exempt, matching SaccoScope and BrandScope: the platform role
 * sits above every tenant boundary, and financier becomes a column they can
 * filter on rather than a wall.
 */
final class FinancierScope implements Scope
{
    /**
     * What a fail-closed caller gets: valid SQL, matches nothing, and survives
     * being AND-ed with whatever filters the caller sent.
     */
    private const DENY_ALL = '1 = 0';

    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        $via = method_exists($model, 'getFinancierVia') ? $model->getFinancierVia() : null;

        if ($via === null) {
            self::constrainColumn($user, $builder, $model->getTable() . '.financier');

            return;
        }

        // Relation-reached models (Summary, Transaction, …) carry no financier
        // of their own; reach the vehicle that does.
        self::constrainVia($user, $builder, $via);
    }

    /**
     * Is this caller confined to a single bank's fleet?
     *
     * False for everyone who is not a bank user, so callers can use it to pick
     * a tier without also having to re-implement the exemptions.
     */
    public static function confines(?Authenticatable $user): bool
    {
        if (! $user instanceof User || $user->isSuperAdmin()) {
            return false;
        }

        return $user->isBankUser();
    }

    /**
     * Constrain a query that already reaches `vehicles` — either because it is
     * a Vehicle query or because it joins the table. Cheaper than the relation
     * form: a plain column predicate rather than a correlated EXISTS.
     */
    public static function constrainColumn(?Authenticatable $user, Builder $query, string $column): void
    {
        self::constrain($user, $query, static function (Financier $financier) use ($query, $column): void {
            $query->where($column, $financier->value);
        });
    }

    /** Constrain a query that reaches a vehicle through a relation path. */
    public static function constrainVia(?Authenticatable $user, Builder $query, string $relation): void
    {
        self::constrain($user, $query, static function (Financier $financier) use ($query, $relation): void {
            $query->whereHas($relation, static function (Builder $q) use ($financier): void {
                $q->where('financier', $financier->value);
            });
        });
    }

    /**
     * The one decision, shared by the global scope and by the controllers that
     * apply the same rule by hand: leave the query alone for a caller who is
     * not a bank, deny everything for a bank whose financier will not resolve,
     * and otherwise hand the resolved bank to the caller's own predicate.
     *
     * @param  callable(Financier): void  $confine
     */
    private static function constrain(?Authenticatable $user, Builder $query, callable $confine): void
    {
        if (! self::confines($user)) {
            return;
        }

        /** @var User $user */
        $financier = $user->currentFinancier();

        if ($financier === null) {
            // The fail-closed branch: a bank user whose financier is NULL, or
            // holds a value that is not one of the banks we know about. Showing
            // them everything is precisely the outcome this scope exists to
            // prevent, so they get nothing until the column is put right.
            $query->whereRaw(self::DENY_ALL);

            return;
        }

        $confine($financier);
    }
}
