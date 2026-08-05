<?php

declare(strict_types=1);

/*
| Super-admin console — fraud / abuse / data-protection domain.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php.
|
| GET fraud/audit — the immutable trail behind this domain's alerts: the driver.*
| and partner.* audit rows (lead exports and any future erasure requests), newest
| first, filterable by brand and action. PII-free by construction (AuditLogger only
| writes counts/ids/hashes), so it is safe to browse.
*/

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('fraud/audit', function (Request $request) {
        $page = AuditLog::query()
            ->where(function ($q): void {
                $q->where('action', 'LIKE', 'partner.%')
                    ->orWhere('action', 'LIKE', 'driver.%');
            })
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'LIKE', $request->input('action').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        $items = array_map(static fn (AuditLog $log): array => [
            'id' => (int) $log->id,
            'action' => $log->action,
            'actorType' => $log->actor_type,
            'actorId' => $log->actor_id,
            'actorLabel' => $log->actor_label,
            'subjectType' => $log->subject_type,
            'subjectId' => $log->subject_id,
            'brand' => $log->brand,
            'data' => $log->data,
            'ip' => $log->ip,
            'userAgent' => $log->user_agent,
            'createdAt' => optional($log->created_at)->toIso8601String(),
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
    });
});
