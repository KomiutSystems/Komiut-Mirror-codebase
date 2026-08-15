<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformNotificationResource;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Services\Sql\LikeSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-lifecycle read endpoints for the super-admin console. Cross-brand: a
 * super admin sees every brand's rows (`brand` is an optional filter). Gated by
 * `View Platform Notifications` at the route.
 */
class TenantController extends Controller
{
    /**
     * The review queue: open (unresolved) review-class notifications — the
     * pending-SACCO + duplicate-suspect feed. Filters: brand, event.
     */
    public function reviewQueue(Request $request): JsonResponse
    {
        $query = PlatformNotification::query()
            ->where('delivery_class', 'review')
            ->whereNull('resolved_at')
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', LikeSql::op(), $request->input('event').'%'))
            ->orderByDesc('occurred_at');

        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'message' => [
                'items' => PlatformNotificationResource::collection($page->items()),
                'count' => count($page->items()),
                'pageNumber' => $page->currentPage(),
                'pageSize' => $page->perPage(),
                'totalCount' => $page->total(),
                'totalPages' => $page->lastPage(),
                'hasNextPage' => $page->hasMorePages(),
            ],
        ]);
    }

    /** The SACCO audit trail: append-only sacco.* audit rows. Filters: brand, action. */
    public function audit(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->where('action', LikeSql::op(), 'sacco.%')
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->orderByDesc('created_at');

        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));

        $items = array_map(static fn (AuditLog $log): array => [
            'id' => $log->id,
            'action' => $log->action,
            'brand' => $log->brand,
            'actor' => [
                'type' => $log->actor_type,
                'id' => $log->actor_id,
                'label' => $log->actor_label,
            ],
            'subject' => [
                'type' => $log->subject_type,
                'id' => $log->subject_id,
            ],
            'data' => $log->data,
            'occurredAt' => optional($log->created_at)->toIso8601String(),
        ], $page->items());

        return response()->json([
            'message' => [
                'items' => $items,
                'count' => count($items),
                'pageNumber' => $page->currentPage(),
                'pageSize' => $page->perPage(),
                'totalCount' => $page->total(),
                'totalPages' => $page->lastPage(),
                'hasNextPage' => $page->hasMorePages(),
            ],
        ]);
    }
}
