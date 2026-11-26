<?php

declare(strict_types=1);

namespace App\Models\Team;

use App\Models\Sacco;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One email conversation in a shared mailbox. */
class TeamThread extends Model
{
    protected $fillable = [
        'mailbox_id', 'subject', 'snippet', 'from_name', 'from_email', 'status', 'assignee_id',
        'sacco_id', 'sacco_set_by_staff', 'card_id', 'message_count', 'last_message_at',
        'first_inbound_at', 'first_reply_at', 'reply_token',
    ];

    protected $casts = [
        'mailbox_id' => 'integer',
        'assignee_id' => 'integer',
        'sacco_id' => 'integer',
        'card_id' => 'integer',
        'sacco_set_by_staff' => 'boolean',
        'message_count' => 'integer',
        'last_message_at' => 'datetime',
        'first_inbound_at' => 'datetime',
        'first_reply_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $thread): void {
            $thread->reply_token ??= Str::lower(Str::random(32));
        });
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(TeamMailbox::class, 'mailbox_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** Unscoped: the team works across every SACCO of both brands. */
    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class, 'sacco_id')->withoutGlobalScopes();
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(TeamCard::class, 'card_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TeamMessage::class, 'thread_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(TeamThreadRead::class, 'thread_id');
    }
}
