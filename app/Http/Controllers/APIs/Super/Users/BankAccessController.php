<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Users;

use App\Auth\Roles;
use App\Enums\Financier;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Super\Access\AccessChangeRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * The one write path for `users.financier` — turning an existing account into a
 * bank viewer.
 *
 * FinancierScope has been enforced on Vehicle, Summary, Transaction, Mpesa,
 * Cash, QrcodePayment and VehicleLocation since it was written, and it fails
 * closed. What was missing was any way to put a value in the column it reads.
 * `financier` is deliberately absent from User::$fillable, and no controller,
 * command or service set it, so all 6,808 accounts hold NULL — including
 * vriungu@co-opbank.co.ke (id 6272), the one live Bank Viewer, who therefore
 * matched the fail-closed branch and saw nothing at all. This endpoint exists
 * so that column can be set once, deliberately, by an account above every
 * tenant boundary, and never any other way.
 *
 * THE SHAPE IS THE WHOLE POINT. A bank viewer must have NO SACCO, and account
 * 6272 has sacco_id = 4. SaccoScope's exemptions live inside the branch that
 * only runs when sacco_id is NULL, so a bank user who keeps a SACCO gets both
 * boundaries AND-ed together. For Co-op that happens to be lossless — all 54
 * Co-op vehicles sit inside NICCO MOVERS — which is exactly what makes it
 * dangerous: it looks correct on the only account anyone has tested. Provision
 * an NCBA rep the same way and the intersection silently hides 703 of their 829
 * vehicles and KES 2,640,574 of collections. No error, no empty state, no
 * warning — just a smaller number on a bank's reconciliation. So sacco_id is
 * FORCED to NULL here, and an explicit request to keep one is refused rather
 * than quietly overridden: a caller who asked for that has the wrong model of
 * the boundary in their head, and silently doing the right thing would leave
 * them with it.
 *
 * Superadmins cannot be provisioned. FinancierScope exempts them by design
 * (the platform role sits above every tenant boundary), so setting the column
 * on one produces an account that reads as bank-scoped everywhere it is
 * displayed while actually seeing all 883 vehicles. A boundary that is visible
 * but absent is worse than no boundary.
 *
 * The account's `type` is deliberately left alone. Most of the 6,808 legacy
 * accounts carry no type at all, so refusing or rewriting it here would either
 * block the one provisioning this endpoint exists to perform or silently
 * rewrite an account's identity as a side effect of a bank grant.
 */
class BankAccessController extends Controller
{
    /**
     * Grant an existing account read access to one bank's financed fleet.
     *
     * POST /api/v1/super/users/{user}/bank-access  { "financier": "NCBA" }
     */
    public function provision(Request $request, User $user, AccessChangeRecorder $recorder): JsonResponse
    {
        // Checked BEFORE validation, on purpose. This is a refusal about the
        // SHAPE of the request, not a bad field value, and it must never be
        // masked by an unrelated validation error on the same payload.
        if ($this->asksToKeepSacco($request)) {
            return response()->json([
                'error' => 'A bank viewer cannot keep a SACCO. SaccoScope only exempts a bank user '
                    . 'when sacco_id is NULL, so an account with both is scoped by both: an NCBA rep '
                    . 'left in a SACCO silently loses 703 of 829 vehicles and KES 2,640,574 of '
                    . 'collections, with no error shown. Retry without sacco_id — it is cleared here.',
            ], 422);
        }

        $data = $request->validate([
            // An allow-list, not a string. The column is an authorization key
            // read back through Financier::tryParse, which returns null for
            // anything it does not recognise — and null on a bank user means
            // "deny everything". A typo accepted here is a bank staring at an
            // empty dashboard with nothing to explain why.
            'financier' => ['required', 'string', Rule::in(Financier::values())],
        ]);

        if ($user->isSuperAdmin()) {
            return response()->json([
                'error' => 'A Super Admin cannot be provisioned as a bank viewer. FinancierScope '
                    . 'exempts super admins, so the account would be labelled as one bank\'s while '
                    . 'still reading every SACCO and all 883 vehicles.',
            ], 422);
        }

        // Must already exist (RoleSeeder owns it). findOrCreate would happily
        // mint an EMPTY 'Bank Viewer' role on a mis-seeded environment, and the
        // resulting account would hold the role, satisfy isBankUser(), pass
        // every check here — and have no permission to read anything. That is
        // the silent-empty-dashboard failure again, one layer down.
        $role = Role::where('guard_name', 'web')->where('name', Roles::BANK_VIEWER)->first();

        if ($role === null) {
            return response()->json([
                'error' => 'The ' . Roles::BANK_VIEWER . ' role does not exist. Run the RoleSeeder before provisioning.',
            ], 409);
        }

        $financierBefore = $user->financier;
        // Cast explicitly: `sacco_id` is not in User::$casts, so what Eloquent
        // hands back is driver-dependent, and this file is strict_types — a
        // numeric string reaching the recorder's `?int` would be a TypeError
        // thrown AFTER the column was already written.
        $saccoIdBefore = $user->sacco_id === null ? null : (int) $user->sacco_id;
        $rolesBefore = $user->getRoleNames()->all();
        $permsBefore = $user->getAllPermissions()->pluck('name')->all();

        DB::transaction(function () use ($user, $data, $role): void {
            // forceFill because `financier` is NOT in User::$fillable and must
            // stay out of it: fillable would expose it to registration and
            // profile-update mass assignment, where the account being edited
            // could choose which bank's money it reads. This is the one place
            // allowed to reach past that, and it is behind the super gate.
            $user->forceFill([
                'financier' => $data['financier'],
                'sacco_id' => null,
            ])->save();

            // syncRoles/syncPermissions, NOT assignRole — the account is
            // REPLACED by a bank viewer rather than having bank access added to
            // whatever it already was.
            //
            // This is a consequence of nulling sacco_id, and it is easy to miss:
            // SaccoScope returns early when sacco_id is NULL, so clearing the
            // column REMOVES the SACCO wall on every model FinancierScope does
            // not cover — Queue, Booking, Route, Sacco, Point, Parcel, User.
            // FinancierScope confines the seven money/fleet models and nothing
            // else. Leave a SACCO Admin role on this account and you have not
            // built a bank viewer, you have built an account that reads every
            // SACCO in the brand for everything except the seven tables anyone
            // thought to scope. Direct permissions go the same way: they bypass
            // roles entirely, so a leftover 'View Passengers' grant would do
            // precisely that.
            //
            // The Bank Viewer bundle is three read permissions — View Summaries,
            // View Transactions, View QRCode Payments — and all three land on
            // financier-scoped models.
            $user->syncRoles([$role]);
            $user->syncPermissions([]);
        });

        // Two records, because two different things changed. recordRoleSync
        // already existed and covers the role half; the financier half had no
        // recorder at all until now, which left the more powerful of the two
        // edits — which bank's money this account reads — as the untraced one.
        $recorder->recordRoleSync($user, $rolesBefore, $permsBefore, $request->user());
        $recorder->recordFinancierChange($user, $financierBefore, $saccoIdBefore, $request->user());

        return response()->json([
            'success' => true,
            'user' => UsersController::rowFor($user->refresh()),
        ]);
    }

    /**
     * Did the caller ask for a bank viewer that keeps its SACCO?
     *
     * Two spellings of the same request. `sacco_id: null` is NOT one of them —
     * that agrees with what this endpoint does, so it passes.
     */
    private function asksToKeepSacco(Request $request): bool
    {
        if ($request->has('sacco_id') && $request->input('sacco_id') !== null) {
            return true;
        }

        return $request->boolean('keep_sacco');
    }
}
