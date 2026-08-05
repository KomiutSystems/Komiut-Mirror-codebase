<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Access;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read side of the access domain: the immutable audit trail for privilege
 * changes (access.*). Cross-brand — a super admin sees every brand's rows, with
 * `brand` as an optional filter. Gated by `View Platform Notifications` at the
 * route. Response mirrors the notification feed envelope, camelCased.
 */
class AccessAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = AuditLog::query()
            ->where('action', 'LIKE', 'access.%')
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'LIKE', $request->input('action').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        $items = array_map(static fn (AuditLog $row): array => [
            'id' => $row->id,
            'action' => $row->action,
            'actorType' => $row->actor_type,
            'actorId' => $row->actor_id,
            'actorLabel' => $row->actor_label,
            'subjectType' => $row->subject_type,
            'subjectId' => $row->subject_id,
            'brand' => $row->brand,
            'data' => $row->data,
            'ip' => $row->ip,
            'createdAt' => optional($row->created_at)->toIso8601String(),
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
