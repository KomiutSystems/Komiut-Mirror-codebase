<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Loyalty;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Services\Sql\LikeSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Loyalty
 *
 * Who holds points, and how many. READ ONLY, on purpose.
 *
 * THERE IS NO WRITE COUNTERPART TO THIS CONTROLLER AND THERE MUST NOT BE. Points
 * are money: 10 of them is a free ride on someone's bus. They are earned in
 * exactly one place — the EarnLoyaltyPoints listener, from the fare on a paid
 * booking, server-side — and spent in one other, by the passenger themselves on
 * their own token. No dashboard route can mint them, and a SACCO admin who could
 * would be able to issue free rides to whoever they liked, against their own
 * SACCO's revenue, with no payment behind it.
 *
 * The only loyalty write a SACCO admin has is the PROGRAMME — the earn divisor
 * and the redemption threshold, at saccos/loyalty/save. That sets the rate for
 * everyone prospectively; it cannot credit an individual.
 *
 * NOT CACHED, deliberately. A points balance is the kind of number a passenger
 * argues about at the door of a matatu, and a stale one is a support ticket
 * rather than a saved millisecond. The query is an indexed range scan
 * (loyalty_accounts_sacco_id_balance_index) over a table that grows with
 * ridership, and every earn and redeem would have to invalidate the cache — more
 * machinery, and a new way to be wrong, in exchange for very little. Index it
 * properly and read it live.
 */
class LoyaltyHoldersController extends Controller
{
    use PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Point holders in your SACCO
     *
     * One row per passenger, highest balance first. Scoped by LoyaltyAccount's
     * own SaccoScope, so a SACCO admin sees their own holders and nobody else's.
     *
     * @authenticated
     *
     * @queryParam search string Passenger name or phone. Example: Wanjiku
     * @queryParam min_balance number Only holders at or above this. Example: 1
     * @queryParam include_zero boolean Include spent-out accounts. Example: false
     */
    public function forSacco(Request $request): JsonResponse
    {
        $query = LoyaltyAccount::query()
            ->with(['user:id,firstname,lastname,phone'])
            ->when(! $request->boolean('include_zero'), fn ($q) => $q->where('balance', '>', 0))
            ->when($request->filled('min_balance'), fn ($q) => $q->where('balance', '>=', (float) $request->input('min_balance')))
            ->when(filled($request->search), fn ($q) => $q->whereHas('user', fn ($u) => $u
                ->where('firstname', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('lastname', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('phone', LikeSql::op(), '%'.$request->search.'%')))
            ->orderByDesc('balance');

        $__meta = $this->pageMeta($query, $request, 25);
        $page = max(1, (int) ($request->page ?: 1));
        $rows = $query->skip(($page - 1) * 25)->take(25)->get();

        return response()->json(array_merge([
            'holders' => $rows->map(fn (LoyaltyAccount $a) => [
                'user_id' => $a->user_id,
                'name' => $a->user ? trim(($a->user->firstname ?? '').' '.($a->user->lastname ?? '')) : null,
                'phone' => $a->user?->phone,
                'balance' => (float) $a->balance,
                'since' => optional($a->created_at)->toIso8601String(),
            ])->values(),
            // The programme's own numbers, so the UI can render "worth N free
            // rides" without a second call and without hardcoding the rule.
            'programme' => $this->programme(auth()->user()->currentSaccoId()),
        ], $__meta));
    }

    /**
     * Point holders across every SACCO (platform)
     *
     * One row per PERSON, not per account. A passenger can hold points with
     * several SACCOs — they ride several — so a row-per-account listing would
     * show the same person once per SACCO and invite exactly the miscount the
     * crews screen was making. The per-SACCO split is nested inside the person.
     *
     * @authenticated
     *
     * @queryParam sacco_id integer Only holders with points at this SACCO. Example: 4
     * @queryParam brand string Only this brand. Example: komiut
     * @queryParam min_total number Only people whose TOTAL is at least this. Example: 10
     * @queryParam search string Passenger name or phone. Example: 0712
     */
    public function forPlatform(Request $request): JsonResponse
    {
        // withoutGlobalScopes: a super admin is already exempt from SaccoScope,
        // but BrandScope is not keyed on the user — it keys on Context, which the
        // brand middleware sets from the request host. This endpoint is the
        // platform-wide view by definition, so brand is a FILTER here, never a
        // wall, and it is applied explicitly below.
        $base = LoyaltyAccount::withoutGlobalScopes()
            ->when($request->filled('sacco_id'), fn ($q) => $q->where('sacco_id', (int) $request->input('sacco_id')))
            ->when($request->filled('brand'), fn ($q) => $q->whereHas('sacco', fn ($s) => $s->where('brand', $request->input('brand'))))
            ->when(! $request->boolean('include_zero'), fn ($q) => $q->where('balance', '>', 0))
            ->when(filled($request->search), fn ($q) => $q->whereHas('user', fn ($u) => $u
                ->where('firstname', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('lastname', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('phone', LikeSql::op(), '%'.$request->search.'%')));

        // Page over PEOPLE, then fetch their accounts — so a person near the page
        // boundary cannot have half their SACCOs on one page and half on the next.
        $people = (clone $base)
            ->select('user_id')
            ->selectRaw('SUM(balance) as total_balance')
            ->selectRaw('COUNT(*) as sacco_count')
            ->groupBy('user_id')
            ->when($request->filled('min_total'), fn ($q) => $q->havingRaw('SUM(balance) >= ?', [(float) $request->input('min_total')]))
            ->orderByDesc('total_balance');

        $perPage = 25;
        $page = max(1, (int) ($request->page ?: 1));
        $rows = $people->skip(($page - 1) * $perPage)->take($perPage)->get();

        $userIds = $rows->pluck('user_id')->all();

        $accounts = LoyaltyAccount::withoutGlobalScopes()
            ->whereIn('user_id', $userIds)
            ->with(['sacco:id,name,brand'])
            ->get()
            ->groupBy('user_id');

        $users = DB::table('users')->whereIn('id', $userIds)
            ->get(['id', 'firstname', 'lastname', 'phone'])->keyBy('id');

        return response()->json([
            'holders' => $rows->map(function ($r) use ($accounts, $users) {
                $u = $users->get($r->user_id);

                return [
                    'user_id' => (int) $r->user_id,
                    'name' => $u ? trim(($u->firstname ?? '').' '.($u->lastname ?? '')) : null,
                    'phone' => $u?->phone,
                    'total_balance' => (float) $r->total_balance,
                    'sacco_count' => (int) $r->sacco_count,
                    'saccos' => ($accounts->get($r->user_id) ?? collect())
                        ->sortByDesc('balance')
                        ->map(fn (LoyaltyAccount $a) => [
                            'sacco_id' => $a->sacco_id,
                            'sacco' => $a->sacco?->name,
                            'brand' => $a->sacco?->brand,
                            'balance' => (float) $a->balance,
                        ])->values(),
                ];
            })->values(),
            'totals' => $this->platformTotals($base),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * One holder's points ledger
     *
     * Every movement on this person's balance with THIS SACCO, newest first,
     * with the balance as it stood after each one. The holders list answers
     * "who has points"; this answers "how did they get there" — a passenger at
     * a stage disputing a balance needs the second, not the first.
     *
     * RUNNING BALANCE IS COMPUTED FORWARDS AND SHOWN BACKWARDS. It has to be
     * accumulated oldest-first to mean anything, but a ledger is read newest-
     * first, so the rows are ordered ascending, walked, then reversed. Doing it
     * the other way — subtracting from the current balance as you descend — is
     * the same arithmetic only while no row is ever inserted out of order, and
     * gives silently wrong history the first time one is.
     *
     * READ ONLY, like everything else on this controller. Points are money and a
     * SACCO admin must not be able to mint them; see the class docblock.
     *
     * @authenticated
     *
     * @queryParam per_page integer Rows per page, max 100. Example: 50
     *
     * @response 200 {"holder": {"user_id": 12, "name": "Joyce Wanjiru", "phone": "0712345678", "balance": 40}, "entries": [{"id": 9, "type": "redeemed", "sign": "-", "value": 10, "balance_after": 40, "booking_id": 71, "at": "2026-08-28T09:12:00+00:00"}]}
     */
    public function history(Request $request, int $user): JsonResponse
    {
        $saccoId = auth()->user()->currentSaccoId();

        if ($saccoId === null) {
            // A saccoless caller has no tenant whose ledger this could be. The
            // holders list they can reach is their own; a per-person history is
            // not theirs to read.
            return response()->json(['error' => 'This is not available on your account.'], 403);
        }

        $account = LoyaltyAccount::query()
            ->with(['user:id,firstname,lastname,phone'])
            ->where('user_id', $user)
            ->first();

        if ($account === null) {
            // Scoped by LoyaltyAccount's own SaccoScope, so another SACCO's
            // holder is "not found" rather than forbidden — the distinction
            // would otherwise confirm that the person banks points elsewhere.
            return response()->json(['error' => 'That person holds no points with your SACCO.'], 404);
        }

        $perPage = $this->perPage($request, 25, 100);
        $page = max(1, (int) ($request->page ?: 1));

        $base = LoyaltyTransaction::query()->where('user_id', $user);

        $total = (clone $base)->count();

        // Ascending, so the running balance accumulates in the order the
        // movements actually happened.
        $rows = (clone $base)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $balance = 0.0;
        $entries = $rows->map(function (LoyaltyTransaction $t) use (&$balance): array {
            $signed = $t->type->isCredit() ? (float) $t->value : -(float) $t->value;
            $balance += $signed;

            return [
                'id' => (int) $t->id,
                'type' => $t->type->value,
                // The + / − the screen puts in front of the number, decided here
                // rather than by the client guessing from the type string.
                'sign' => $signed < 0 ? '-' : '+',
                'value' => abs((float) $t->value),
                'balance_after' => round($balance, 2),
                // What earned or spent it. Null for an adjustment with no ride.
                'booking_id' => $t->booking_id !== null ? (int) $t->booking_id : null,
                'at' => optional($t->created_at)->toIso8601String(),
            ];
        });

        // Read newest-first, paged after the walk.
        $entries = $entries->reverse()->values()->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'holder' => [
                'user_id' => (int) $account->user_id,
                'name' => $account->user
                    ? trim(($account->user->firstname ?? '').' '.($account->user->lastname ?? ''))
                    : null,
                'phone' => $account->user?->phone,
                // The authoritative figure, from the account itself. If it ever
                // disagrees with the last balance_after, the ledger and the
                // balance have drifted and that is worth seeing rather than
                // hiding behind a computed number.
                'balance' => (float) $account->balance,
            ],
            'entries' => $entries,
            'programme' => $this->programme($saccoId),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) max((int) ceil($total / max($perPage, 1)), 1),
        ]);
    }

    /**
     * Platform aggregates. Derived from the SAME filtered query as the rows, so
     * the header tiles and the table can never disagree — a mismatch between a
     * summary and the list beneath it is the kind of thing that gets read as
     * missing money.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $base
     * @return array<string, mixed>
     */
    private function platformTotals($base): array
    {
        $agg = (clone $base)
            ->selectRaw('COUNT(DISTINCT user_id) as holders')
            ->selectRaw('COUNT(*) as accounts')
            ->selectRaw('COALESCE(SUM(balance), 0) as points')
            ->first();

        return [
            'holders' => (int) ($agg->holders ?? 0),
            'accounts' => (int) ($agg->accounts ?? 0),
            'points' => (float) ($agg->points ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    private function programme(?int $saccoId): ?array
    {
        if ($saccoId === null) {
            return null;
        }

        $p = DB::table('loyalty_programs')->where('sacco_id', $saccoId)->first();

        return $p === null ? null : [
            'divisor' => (float) $p->divisor,
            'redemption_threshold' => (float) $p->redemption_threshold,
            'is_active' => (bool) $p->is_active,
        ];
    }
}
