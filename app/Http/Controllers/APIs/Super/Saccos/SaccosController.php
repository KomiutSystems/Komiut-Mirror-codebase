<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Saccos;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Models\Sacco;
use App\Models\SaccoRoute;
use App\Models\SaccoUser;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Sql\LikeSql;
use Illuminate\Http\Request;

/**
 * The SACCO list for the super-admin console. Cross-brand: a super admin sees
 * every brand's rows (BrandScope is exempt for them — see
 * tests/Feature/Tenancy/BrandScopeTest.php::a_super_admin_sees_across_brands),
 * `brand` is an optional filter, never a wall. Gated by `View Platform
 * Notifications` at the route.
 */
class SaccosController extends Controller
{
    /** Filters: q (name/email/phone), brand, status (0|1), claim_status. */
    public function index(Request $request): SlimPage
    {
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = Sacco::query()
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = (string) $request->input('q');
                $q->where(function ($qq) use ($term): void {
                    $qq->where('name', LikeSql::op(), "%{$term}%")
                        ->orWhere('email', LikeSql::op(), "%{$term}%")
                        ->orWhere('phone', LikeSql::op(), "%{$term}%");
                });
            })
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (int) $request->input('status')))
            ->when($request->filled('claim_status'), fn ($q) => $q->where('claim_status', $request->input('claim_status')))
            ->orderByDesc('created_at');

        $page = $query->paginate($perPage);

        return SlimPage::of($page, fn (Sacco $sacco): array => $this->row($sacco));
    }

    /**
     * The list-row shape shared with the directory's action responses.
     * `members` counts current SaccoUser rows (the real membership table — see
     * SaccoMembersAPIController), not a raw sacco_id count; `drivers` is
     * type-filtered so it doesn't include SACCO admins.
     *
     * @return array<string,mixed>
     */
    private function row(Sacco $sacco): array
    {
        return [
            'id' => $sacco->id,
            'name' => $sacco->name,
            'brand' => $sacco->brand,
            'status' => $sacco->status,
            'claim_status' => $sacco->claim_status?->value,
            'email' => $sacco->email,
            'phone' => $sacco->phone,
            'created_at' => optional($sacco->created_at)->toIso8601String(),
            'members' => SaccoUser::where('sacco_id', $sacco->id)->whereNull('end_date')->count(),
            'drivers' => User::where('sacco_id', $sacco->id)->where('type', UserType::Driver)->count(),
            'vehicles' => Vehicle::where('sacco_id', $sacco->id)->count(),
            'routes' => SaccoRoute::where('sacco_id', $sacco->id)->distinct('route_id')->count('route_id'),
        ];
    }
}
