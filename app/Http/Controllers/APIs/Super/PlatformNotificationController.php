<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformNotificationResource;
use App\Models\PlatformNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The super-admin notification centre feed. Cross-brand: a super admin sees every
 * brand's events (BrandScope doesn't apply to them, and this model isn't
 * brand-scoped anyway), with `brand` as an optional filter, never a wall.
 * Gated by `View Platform Notifications` at the route.
 */
class PlatformNotificationController extends Controller
{
    /** Paginated feed. Filters: class (alert|review|digest), severity, brand, event, unread_only. */
    public function index(Request $request): JsonResponse
    {
        $query = PlatformNotification::query()
            ->when($request->filled('class'), fn ($q) => $q->where('delivery_class', $request->input('class')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->input('severity')))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', 'LIKE', $request->input('event').'%'))
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->when($request->boolean('open_only'), fn ($q) => $q->whereNull('resolved_at'))
            ->orderByDesc('occurred_at');

        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'message' => [
                'items' => PlatformNotificationResource::collection($page->items()),
                'count' => count($page->items()),
                'unreadCount' => PlatformNotification::whereNull('read_at')->count(),
                'pageNumber' => $page->currentPage(),
                'pageSize' => $page->perPage(),
                'totalCount' => $page->total(),
                'totalPages' => $page->lastPage(),
                'hasNextPage' => $page->hasMorePages(),
            ],
        ]);
    }

    /** Cheap badge count — unread across all brands, optionally by class. */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = PlatformNotification::whereNull('read_at')
            ->when($request->filled('class'), fn ($q) => $q->where('delivery_class', $request->input('class')))
            ->count();

        return response()->json(['message' => ['count' => $count]]);
    }

    public function markRead(string $id): JsonResponse
    {
        PlatformNotification::whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        PlatformNotification::whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        PlatformNotification::whereKey($id)->whereNull('resolved_at')->update([
            'resolved_at' => now(),
            'resolved_by' => $request->user()?->id,
            'read_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }
}
