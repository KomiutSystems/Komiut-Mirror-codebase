<?php

declare(strict_types=1);

namespace App\Models\Team;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An email in or out of a thread, or a team-only note (direction "note"). */
class TeamMessage extends Model
{
    public const DIRECTIONS = ['in', 'out', 'note'];

    protected $fillable = [
        'thread_id', 'direction', 'from_name', 'from_email', 'to', 'cc', 'body', 'author_id',
        'message_id_header', 'in_reply_to', 'references', 'sent_at', 'send_error',
    ];

    protected $casts = [
        'thread_id' => 'integer',
        'author_id' => 'integer',
        'to' => 'array',
        'cc' => 'array',
        'sent_at' => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(TeamThread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TeamMessageAttachment::class, 'message_id');
    }
}
