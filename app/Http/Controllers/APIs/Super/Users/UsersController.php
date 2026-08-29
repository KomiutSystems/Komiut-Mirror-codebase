<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Users;

use App\Http\Controllers\Controller;
use App\Models\CarbonCreditAccount;
use App\Models\LoyaltyAccount;
use App\Models\User;
use App\Services\Sql\LikeSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The super-admin user directory: search + filter across every brand. `User`
 * is not brand-scoped (BelongsToBrand doesn't apply to it), so cross-brand is
 * the natural default here — `?brand` filters via the user's SACCO, it is not
 * a tenancy wall.
 *
 * NOT SlimPage: this route needs a `summary` sibling key alongside data/total/
 * per_page/current_page/last_page, which SlimPage's fixed shape doesn't carry.
 * The envelope below is deliberately identical to SlimPage's otherwise, so the
 * frontend contract still holds for every key SlimPage itself returns.
 */
class UsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $base = User::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->input('q');
                $query->where(function ($w) use ($q) {
                    $w->where('firstname', LikeSql::op(), "%{$q}%")
                        ->orWhere('lastname', LikeSql::op(), "%{$q}%")
                        ->orWhere('email', LikeSql::op(), "%{$q}%")
                        ->orWhere('phone', LikeSql::op(), "%{$q}%");
                });
            })
            ->when($request->filled('brand'), fn ($query) => $query->whereHas(
                'sacco',
                fn ($s) => $s->where('brand', $request->input('brand'))
            ))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')));

        $summary = [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->whereNull('suspended_at')->where('status', true)->count(),
            'suspended' => (clone $base)->whereNotNull('suspended_at')->count(),
            'privileged' => (clone $base)
                ->where(function ($q) {
                    $q->whereIn('type', ['admin', 'superadmin'])->orWhereHas('roles');
                })->count(),
        ];

        $list = (clone $base)->with(['sacco:id,name,brand', 'roles:id,name']);

        match ($request->input('status')) {
            'suspended' => $list->whereNotNull('suspended_at'),
            'inactive' => $list->whereNull('suspended_at')->where('status', false),
            'active' => $list->whereNull('suspended_at')->where('status', true),
            default => null,
        };

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $list->orderByDesc('id')->paginate($perPage);

        // Two aggregates for the whole page, not a pair of queries per row.
        // Loyalty is a balance PER SACCO, so a passenger who rides two SACCOs
        // holds two rows and the console wants the sum; carbon credits are
        // platform-wide and already one row per passenger.
        //
        // SaccoScope and BrandScope both exempt a super admin, so these sum
        // across every SACCO and both brands, which is what "aggregate" has to
        // mean on a directory that is itself cross-brand.
        $ids = collect($paginator->items())->pluck('id');
        $loyalty = LoyaltyAccount::whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(balance) AS total')
            ->pluck('total', 'user_id');
        $carbon = CarbonCreditAccount::whereIn('user_id', $ids)->pluck('credits', 'user_id');

        return response()->json([
            'data' => collect($paginator->items())->map(fn (User $u) => self::rowFor(
                $u,
                (float) ($loyalty[$u->id] ?? 0),
                (int) ($carbon[$u->id] ?? 0),
            ))->values(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'summary' => $summary,
        ]);
    }

    /**
     * The row shape shared with UserActionsController's mutation responses, so
     * the list and the suspend/restore/password-reset/delete replies never drift.
     */
    /**
     * @param  float|null  $loyaltyPoints  NULL means not loaded, which the
     *   console renders as an em dash; zero means the passenger genuinely holds
     *   none. The mutation replies pass neither and invalidate the list instead,
     *   which refetches with both.
     */
    public static function rowFor(User $user, ?float $loyaltyPoints = null, ?int $carbonCredits = null): array
    {
        return [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'phone' => $user->phone,
            'type' => $user->type?->value,
            'brand' => $user->sacco?->brand,
            'sacco_id' => $user->sacco_id,
            'sacco' => $user->sacco ? ['id' => $user->sacco->id, 'name' => $user->sacco->name] : null,
            // ADDITIVE, and the reason it is worth adding: a bank viewer has no
            // SACCO by construction (see BankAccessController), so on this list
            // it is otherwise indistinguishable from a saccoless passenger —
            // and `financier` is what decides which bank's money it reads. The
            // console had no way to see the column at all before this.
            'financier' => $user->financier,
            'roles' => $user->roles->map(fn ($r) => ['name' => $r->name])->values(),
            'status' => self::statusOf($user),
            // `suspended_at` is NOT in User::$casts (out of scope to edit that
            // model here), so it arrives as a raw DB string — parse explicitly
            // rather than relying on optional()->toIso8601String().
            'suspended_at' => $user->suspended_at !== null ? Carbon::parse($user->suspended_at)->toIso8601String() : null,
            'suspension_reason' => $user->suspension_reason,
            'last_active_at' => optional($user->last_active_at)->toIso8601String(),
            'created_at' => optional($user->created_at)->toIso8601String(),
            'loyalty_points' => $loyaltyPoints,
            'carbon_credits' => $carbonCredits,
        ];
    }

    /** suspended_at overrides everything; otherwise status(bool) decides active/inactive. */
    public static function statusOf(User $user): string
    {
        if ($user->suspended_at !== null) {
            return 'suspended';
        }

        return $user->status ? 'active' : 'inactive';
    }
}
