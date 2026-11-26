<?php

declare(strict_types=1);

namespace App\Models\Team;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMessageAttachment extends Model
{
    protected $fillable = ['message_id', 'name', 'mime', 'size', 'sha256', 'content'];

    protected $casts = ['message_id' => 'integer', 'size' => 'integer'];

    /** The bytes are fetched only when someone downloads the file. */
    protected $hidden = ['content'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TeamMessage::class, 'message_id');
    }
}
