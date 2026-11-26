<?php

declare(strict_types=1);

namespace App\Models\Team;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A piece of the team's work on the ops board. Either a person made it
 * (source manual / inbox) or the platform did (source = what raised it).
 */
class TeamCard extends Model
{
    public const STATUSES = ['todo', 'doing', 'waiting', 'done'];

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    public const LINK_TYPES = ['sacco', 'vehicle', 'till', 'thread'];

    /** Sources the platform raises and closes by itself. */
    public const AUTO_SOURCES = ['till_registration', 'head_office', 'directory_claim', 'fraud'];

    public const SOURCES = ['manual', 'inbox', ...self::AUTO_SOURCES];

    protected $fillable = [
        'title', 'description', 'status', 'priority', 'due_on', 'position',
        'assignee_id', 'link_type', 'link_id', 'source', 'created_by', 'done_at',
    ];

    protected $casts = [
        'due_on' => 'date:Y-m-d',
        'position' => 'float',
        'link_id' => 'integer',
        'assignee_id' => 'integer',
        'created_by' => 'integer',
        'done_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TeamCardComment::class, 'card_id');
    }

    /** The inbox thread this card was made from, if any. */
    public function thread(): HasOne
    {
        return $this->hasOne(TeamThread::class, 'card_id');
    }
}
