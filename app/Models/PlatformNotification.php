<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Sql\LikeSql;
use App\Support\PlatformConsole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A super-admin console event (the "notification centre" feed). CROSS-BRAND by
 * design — no BrandScope — because super admin sits above the brand boundary;
 * `brand` is a filterable column, not a tenancy wall.
 *
 * NOTE: distinct from App\Notifications\PlatformNotification, which is the
 * per-USER push/db notification. This model is the platform operator's feed.
 * Populate it only through App\Services\Platform\PlatformNotifier so dedup,
 * throttling, the audit link, and the Reverb broadcast stay consistent.
 */
class PlatformNotification extends Model
{
    protected $fillable = [
        'event', 'severity', 'delivery_class', 'brand', 'title', 'summary',
        'actor', 'subject', 'data', 'dedupe_key', 'count', 'audit_id',
        'first_occurred_at', 'occurred_at', 'read_at', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'actor' => 'array',
        'subject' => 'array',
        'data' => 'array',
        'count' => 'integer',
        'first_occurred_at' => 'datetime',
        'occurred_at' => 'datetime',
        'read_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function audit()
    {
        return $this->belongsTo(AuditLog::class, 'audit_id');
    }

    /**
     * Alert families Safetek Staff see. An ALLOWLIST, so a new family stays
     * hidden from them until someone decides otherwise: money (payment.*,
     * loyalty.*, payment details and tills), access and sign-in (access.*,
     * partner.*) and the daily digest carry figures, transaction ids and
     * addresses that are not theirs.
     */
    public const STAFF_EVENT_PREFIXES = ['sacco.', 'driver.', 'onboarding.', 'vehicle.plate.', 'platform.'];

    /** What the console may show THIS caller: everything to a Super Admin. */
    public function scopeVisibleOnConsole(Builder $query, ?User $user): Builder
    {
        if (! PlatformConsole::staffRequest($user)) {
            return $query;
        }

        return $query
            ->where('delivery_class', '!=', 'digest')
            ->where(function (Builder $q): void {
                foreach (self::STAFF_EVENT_PREFIXES as $prefix) {
                    $q->orWhere('event', LikeSql::op(), $prefix.'%');
                }
            });
    }
}
