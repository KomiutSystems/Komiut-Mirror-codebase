<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Money;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Sql\LikeSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The money audit trail for the super-admin console: the immutable AuditLog rows
 * written before every money-integrity notification (payment-detail changes,
 * extreme loyalty config). Cross-brand, newest first, `brand`/`action` filters.
 * Gated by `View Platform Notifications` at the route.
 */
final class MoneyLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->where(function ($q): void {
                $q->where('action', LikeSql::op(), 'vehicle.payment%')
                    ->orWhere('action', LikeSql::op(), 'payment.%')
                    ->orWhere('action', LikeSql::op(), 'loyalty.%');
            })
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', LikeSql::op(), $request->input('action').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id'); // AuditLog has no updated_at and shares one now() per request

        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'message' => [
                'items' => array_map(fn (AuditLog $log): array => $this->present($log), $page->items()),
                'count' => count($page->items()),
                'pageNumber' => $page->currentPage(),
                'pageSize' => $page->perPage(),
                'totalCount' => $page->total(),
                'totalPages' => $page->lastPage(),
                'hasNextPage' => $page->hasMorePages(),
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function present(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'actorType' => $log->actor_type,
            'actorId' => $log->actor_id,
            'actorLabel' => $log->actor_label,
            'subjectType' => $log->subject_type,
            'subjectId' => $log->subject_id,
            'brand' => $log->brand,
            'data' => $log->data,
            'ip' => $log->ip,
            'createdAt' => $log->created_at?->toIso8601String(),
        ];
    }
}
