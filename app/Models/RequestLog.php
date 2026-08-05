<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An HTTP request captured by App\Http\Middleware\LogHttpRequests. Append-only:
 * rows are written once in terminate() and only ever read or pruned. NOT
 * brand-scoped — the super-admin console sits above the brand boundary and
 * `brand` is a filterable column, not a wall.
 *
 * Rows are inserted with the query builder (see the middleware), so this model is
 * used only for reads; UPDATED_AT is disabled because there is no updated_at.
 */
class RequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'method', 'path', 'status', 'duration_ms', 'user_id', 'brand', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'status' => 'integer',
        'duration_ms' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
