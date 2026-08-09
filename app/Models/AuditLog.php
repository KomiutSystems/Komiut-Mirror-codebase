<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail. Written BEFORE a money/PII/privilege notification via
 * App\Services\Platform\AuditLogger, so an incident survives a dismissed alert.
 * Never updated; only created_at is kept. Cross-brand (no BrandScope) — the
 * super-admin console reads every brand.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'action', 'actor_type', 'actor_id', 'actor_label',
        'subject_type', 'subject_id', 'brand',
        'sacco_id', 'data', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
    ];
}
