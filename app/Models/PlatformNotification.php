<?php

declare(strict_types=1);

namespace App\Models;

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
}
