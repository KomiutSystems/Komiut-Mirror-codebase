<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super;

use App\Http\Controllers\Controller;
use App\Models\ApplicationLog;
use App\Models\RequestLog;
use App\Services\Sql\LikeSql;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The super-admin LOGS console. Surfaces every log an operator would otherwise
 * SSH into a box to read:
 *   - HTTP request logs   (request_logs, written by LogHttpRequests)
 *   - Application logs     (application_logs, written by the 'database' channel)
 *   - Server logs          (nginx / php-fpm / framework files, tailed on demand)
 *
 * Cross-brand: no BrandScope applies to the super admin, so these feeds show
 * every brand's activity. Gated by `View Platform Logs` at the route.
 */
class LogsController extends Controller
{
    /** GET logs/http?status=&path=&user_id=&from=&to= — the HTTP request feed, newest first. */
    public function http(Request $request): JsonResponse
    {
        $query = RequestLog::query()
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (int) $request->input('status')))
            ->when($request->filled('path'), fn (Builder $q) => $q->where('path', LikeSql::op(), $request->input('path').'%'))
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', (int) $request->input('user_id')))
            ->when($request->filled('brand'), fn (Builder $q) => $q->where('brand', $request->input('brand')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->input('to')))
            ->orderByDesc('id');

        $page = $query->paginate($this->pageSize($request));

        return $this->paginated($page, array_map(
            fn (RequestLog $row) => [
                'id' => $row->id,
                'method' => $row->method,
                'path' => $row->path,
                'status' => (int) $row->status,
                'durationMs' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
                'userId' => $row->user_id !== null ? (int) $row->user_id : null,
                'brand' => $row->brand,
                'ip' => $row->ip,
                'userAgent' => $row->user_agent,
                'createdAt' => optional($row->created_at)->toIso8601String(),
            ],
            $page->items(),
        ));
    }

    /** GET logs/application?level=&channel=&q=&from=&to= — the framework/application feed, newest first. */
    public function application(Request $request): JsonResponse
    {
        $query = ApplicationLog::query()
            ->when($request->filled('level'), fn (Builder $q) => $q->where('level', strtolower((string) $request->input('level'))))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->input('channel')))
            ->when($request->filled('q'), fn (Builder $q) => $q->where('message', LikeSql::op(), '%'.$request->input('q').'%'))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->input('to')))
            ->orderByDesc('id');

        $page = $query->paginate($this->pageSize($request));

        return $this->paginated($page, array_map(
            fn (ApplicationLog $row) => [
                'id' => $row->id,
                'level' => $row->level,
                'channel' => $row->channel,
                'message' => $row->message,
                'context' => $row->context,
                'createdAt' => optional($row->created_at)->toIso8601String(),
            ],
            $page->items(),
        ));
    }

    /**
     * GET logs/server?source=framework|nginx_access|nginx_error|php_fpm&lines=N
     * Tails the configured file, newest line first. An empty/unreadable/unknown
     * source returns items:[] with a `note`, never a 500.
     */
    public function server(Request $request): JsonResponse
    {
        $sources = (array) config('platform.logs.sources', []);
        $maxLines = (int) config('platform.logs.max_lines', 1000);

        $source = (string) $request->input('source', 'framework');
        $requested = (int) $request->input('lines', 200);
        $lines = max(1, min($requested > 0 ? $requested : 200, $maxLines));

        if (! array_key_exists($source, $sources)) {
            return $this->serverPayload([], $source, 'Unknown log source.');
        }

        $path = (string) ($sources[$source] ?? '');

        if ($path === '') {
            return $this->serverPayload([], $source, 'This log source is not configured on this server.');
        }

        if (! is_file($path) || ! is_readable($path)) {
            return $this->serverPayload([], $source, 'Log file is not present or not readable on this server.');
        }

        $tail = $this->tail($path, $lines); // oldest -> newest
        $items = array_reverse($tail);      // newest first, matching the DB feeds

        return $this->serverPayload($items, $source, null, $path, $lines);
    }

    /** GET logs/sources — which server-log sources are configured/available (for the UI tabs). */
    public function sources(): JsonResponse
    {
        $sources = (array) config('platform.logs.sources', []);

        $items = [];
        foreach ($sources as $key => $path) {
            $path = (string) $path;
            $configured = $path !== '';
            $items[] = [
                'source' => $key,
                'configured' => $configured,
                'available' => $configured && is_file($path) && is_readable($path),
            ];
        }

        return response()->json([
            'message' => [
                'items' => $items,
                'count' => count($items),
                'maxLines' => (int) config('platform.logs.max_lines', 1000),
            ],
        ]);
    }

    private function pageSize(Request $request): int
    {
        return min(max((int) $request->input('per_page', 25), 1), 100);
    }

    /**
     * Wraps paginated Eloquent results in the shared response envelope.
     *
     * @param  LengthAwarePaginator  $page
     * @param  array<int, array<string, mixed>>  $items
     */
    private function paginated($page, array $items): JsonResponse
    {
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

    /**
     * @param  array<int, string>  $items
     */
    private function serverPayload(array $items, string $source, ?string $note, ?string $path = null, ?int $lines = null): JsonResponse
    {
        return response()->json([
            'message' => [
                'items' => $items,
                'count' => count($items),
                'source' => $source,
                'path' => $path,
                'lines' => $lines,
                'note' => $note,
            ],
        ]);
    }

    /**
     * Read the last $lines lines of a file without loading the whole thing into
     * memory. Seeks from the end in chunks — safe for multi-gigabyte log files.
     *
     * @return array<int, string> oldest -> newest
     */
    private function tail(string $path, int $lines): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $buffer = '';
            $chunkSize = 4096;
            $newlineCount = 0;

            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);
            if ($position === false) {
                return [];
            }

            // Walk backwards a chunk at a time, prepending, until we've seen
            // enough line breaks or reached the start of the file.
            while ($position > 0 && $newlineCount <= $lines) {
                $read = (int) min($chunkSize, $position);
                $position -= $read;
                fseek($handle, $position, SEEK_SET);
                $chunk = fread($handle, $read);
                if ($chunk === false) {
                    break;
                }
                $buffer = $chunk.$buffer;
                $newlineCount += substr_count($chunk, "\n");
            }
        } finally {
            fclose($handle);
        }

        $all = preg_split('/\r\n|\r|\n/', rtrim($buffer, "\r\n")) ?: [];

        return array_slice($all, -$lines);
    }
}
