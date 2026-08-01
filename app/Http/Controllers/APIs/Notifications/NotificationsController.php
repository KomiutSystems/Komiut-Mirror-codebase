<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Notifications
 *
 * The authenticated user's in-app notifications. Read state is server-
 * authoritative; every action is scoped to the caller (a notification id that
 * isn't theirs simply isn't found).
 *
 * Responses are wrapped `{message:{...}}` with camelCase items to match the
 * app's existing NotificationModel — migrating the app is a path change only.
 */
class NotificationsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * List my notifications
     *
     * @authenticated
     *
     * @queryParam page integer Page number. Example: 1
     * @queryParam per_page integer Page size (max 100). Example: 20
     * @queryParam unread_only boolean Only unread. Example: false
     * @queryParam type string Filter by type. Example: trip
     *
     * @response 200 {"message": {"items": [{"id": "uuid", "title": "Booking confirmed", "message": "...", "type": "trip", "referenceId": "12", "isRead": false, "createdAt": "2026-07-31T10:00:00+00:00"}], "count": 1, "unreadCount": 1, "pageNumber": 1, "pageSize": 20, "totalCount": 1}}
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 20) ?: 20, 100);

        $query = $request->user()->notifications();

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }
        if ($request->filled('type')) {
            $query->where('data->type', $request->string('type'));
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'message' => [
                'items' => NotificationResource::collection($page->items()),
                'count' => $page->total(),
                'unreadCount' => $request->user()->unreadNotifications()->count(),
                'pageNumber' => $page->currentPage(),
                'pageSize' => $page->perPage(),
                'totalCount' => $page->total(),
                'totalPages' => $page->lastPage(),
                'hasNextPage' => $page->hasMorePages(),
            ],
        ]);
    }

    /**
     * Unread notification count
     *
     * @authenticated
     *
     * @response 200 {"message": {"count": 3}}
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'message' => ['count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    /**
     * Mark one notification read
     *
     * @authenticated
     *
     * @response 200 {"success": true}
     * @response 404 {"error": "Not found"}
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();

        if ($notification === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications read
     *
     * @authenticated
     *
     * @response 200 {"success": true}
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
