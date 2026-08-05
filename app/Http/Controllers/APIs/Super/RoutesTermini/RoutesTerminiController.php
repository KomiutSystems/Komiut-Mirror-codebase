<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\RoutesTermini;

use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Models\Route;
use App\Models\Terminus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin platform console — routes and termini reads.
 *
 * App\Models\Route carries no brand/sacco_id column of its own (only
 * from_id/to_id → Place, plus route_stages); the SACCO/brand ownership lives
 * on App\Models\SaccoRoute (sacco_routes: user_id, route_id, sacco_id, amount,
 * min_amount, status). Filtering by sacco_id or brand therefore goes through
 * that pivot (brand via a join to saccos) rather than a direct column — Route
 * itself is not ours to edit, so no whereHas() is available and the route row
 * never carries a real `brand` (emitted as null).
 *
 * Terminus is similar: no brand/sacco_id column, but sacco_termini
 * (terminus_id, sacco_id, user_id, status) links a terminus to the SACCOs
 * that use it, so the brand filter goes through that pivot joined to saccos.
 */
final class RoutesTerminiController extends Controller
{
    public function routes(Request $request): JsonResponse
    {
        $query = Route::query()->with(['from:id,name', 'to:id,name']);

        $query->when($request->filled('q'), fn ($q) => $q->where('name', 'LIKE', '%'.$request->input('q').'%'));
        $query->when($request->has('status'), fn ($q) => $q->where('status', $request->boolean('status')));

        $query->when($request->filled('sacco_id'), function ($q) use ($request): void {
            $routeIds = DB::table('sacco_routes')
                ->where('sacco_id', $request->input('sacco_id'))
                ->pluck('route_id');
            $q->whereIn('id', $routeIds);
        });

        $query->when($request->filled('brand'), function ($q) use ($request): void {
            $routeIds = DB::table('sacco_routes')
                ->join('saccos', 'saccos.id', '=', 'sacco_routes.sacco_id')
                ->where('saccos.brand', $request->input('brand'))
                ->pluck('sacco_routes.route_id');
            $q->whereIn('id', $routeIds);
        });

        $perPage = max(1, min((int) $request->input('per_page', 25), 100));
        $paginator = $query->orderByDesc('id')->paginate($perPage)->appends($request->query());

        return SlimPage::of($paginator, function (Route $route): array {
            return [
                'id' => $route->id,
                'name' => $route->name,
                'from' => $route->from !== null ? ['id' => $route->from->id, 'name' => $route->from->name] : null,
                'to' => $route->to !== null ? ['id' => $route->to->id, 'name' => $route->to->name] : null,
                'status' => (bool) $route->status,
                'brand' => null, // Route carries no brand column of its own — see class docblock.
                'created_at' => optional($route->created_at)->toIso8601String(),
            ];
        })->response();
    }

    public function termini(Request $request): JsonResponse
    {
        $query = Terminus::query()->with('place:id,name');

        $query->when($request->filled('q'), fn ($q) => $q->where('name', 'LIKE', '%'.$request->input('q').'%'));

        $query->when($request->filled('brand'), function ($q) use ($request): void {
            $terminusIds = DB::table('sacco_termini')
                ->join('saccos', 'saccos.id', '=', 'sacco_termini.sacco_id')
                ->where('saccos.brand', $request->input('brand'))
                ->pluck('sacco_termini.terminus_id');
            $q->whereIn('id', $terminusIds);
        });

        $perPage = max(1, min((int) $request->input('per_page', 25), 100));
        $paginator = $query->orderByDesc('id')->paginate($perPage)->appends($request->query());

        return SlimPage::of($paginator, function (Terminus $terminus): array {
            return [
                'id' => $terminus->id,
                'name' => $terminus->name,
                'place' => $terminus->place !== null ? ['id' => $terminus->place->id, 'name' => $terminus->place->name] : null,
                'status' => (bool) $terminus->status,
            ];
        })->response();
    }
}
