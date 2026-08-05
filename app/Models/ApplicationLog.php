<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A framework / application log record captured by the 'database' log channel
 * (App\Logging\DatabaseLogHandler). Append-only and read-only from the console's
 * perspective; UPDATED_AT is disabled because the table has no updated_at.
 */
class ApplicationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'level', 'channel', 'message', 'context', 'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
