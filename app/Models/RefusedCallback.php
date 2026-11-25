<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A payment notification refused for its source address. See CallbackSourceAllowlist. */
class RefusedCallback extends Model
{
    protected $fillable = ['source', 'ip', 'method', 'host', 'path', 'content_type', 'body', 'credentials_ok',
        'replayed_at', 'replay_status', 'replay_note'];

    protected $casts = ['replayed_at' => 'datetime', 'credentials_ok' => 'boolean'];
}
