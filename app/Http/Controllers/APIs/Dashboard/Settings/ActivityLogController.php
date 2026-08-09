<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A SACCO's own activity log.
 *
 * audit_logs already existed but was readable only through /super, so a SACCO
 * could not see its own history — including driver sign-ins, which are the
 * record that a vehicle was handed over.
 *
 * Scoping is explicit rather than via SaccoScope: AuditLog is not a tenant
 * model (most rows are platform-level and have no SACCO at all), so it carries
 * a nullable sacco_id and this controller filters on it. A row with no SACCO is
 * platform activity and is never shown here.
 */
class ActivityLogController extends Controller
{
    /** Actions a SACCO is allowed to see. Anything else is platform business. */
    private const VISIBLE = [
        'driver.login.succeeded',
        'driver.login.burst',
        'driver.login.suspicious_success',
        'sacco.member.role_synced',
        'sacco.member.added',
        'vehicle.payment_details.changed',
        'mpesa.settings.changed',
    ];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $saccoId = $request->user()->currentSaccoId();

        // A superadmin has no home SACCO; they may name one explicitly. Without
        // a SACCO there is nothing tenant-scoped to show, and falling back to
        // "everything" would quietly turn this into the platform log.
        if ($saccoId === null) {
            $saccoId = $request->filled('sacco') ? (int) $request->input('sacco') : null;
        }
        if ($saccoId === null) {
            return response()->json(['error' => 'No SACCO context.'], 422);
        }

        $query = AuditLog::query()
            ->where('sacco_id', $saccoId)
            ->whereIn('action', self::VISIBLE)
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<', $request->date('to')?->addDay()))
            ->orderByDesc('created_at');

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'activity' => collect($page->items())->map(fn (AuditLog $row) => [
                'id' => $row->id,
                'action' => $row->action,
                'description' => $this->describe($row),
                'actor' => [
                    'type' => $row->actor_type,
                    'id' => $row->actor_id,
                    'label' => $row->actor_label,
                ],
                'subject' => ['type' => $row->subject_type, 'id' => $row->subject_id],
                'data' => $row->data,
                'ip' => $row->ip,
                'occurred_at' => optional($row->created_at)->toIso8601String(),
            ]),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /** A readable line, so the screen does not have to know every action name. */
    private function describe(AuditLog $row): string
    {
        $who = $row->actor_label ?: ucfirst((string) $row->actor_type);
        $plate = $row->data['plate'] ?? null;

        return match ($row->action) {
            'driver.login.succeeded' => $plate
                ? "{$who} signed in and took {$plate}"
                : "{$who} signed in",
            'driver.login.burst' => "Repeated failed sign-ins".($plate ? " on {$plate}" : ''),
            'driver.login.suspicious_success' => "Sign-in succeeded after repeated failures".($plate ? " on {$plate}" : ''),
            'sacco.member.added' => "{$who} added a member",
            'sacco.member.role_synced' => "{$who} changed a member's roles",
            'vehicle.payment_details.changed' => "{$who} changed payment details".($plate ? " for {$plate}" : ''),
            'mpesa.settings.changed' => "{$who} changed the M-Pesa settings",
            default => $row->action,
        };
    }
}
