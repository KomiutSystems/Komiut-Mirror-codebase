<?php

declare(strict_types=1);

namespace App\Models\Team;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamCardComment extends Model
{
    protected $fillable = ['card_id', 'author_id', 'body'];

    protected $casts = ['card_id' => 'integer', 'author_id' => 'integer'];

    public function card(): BelongsTo
    {
        return $this->belongsTo(TeamCard::class, 'card_id');
    }

    /** Null for a comment the platform wrote. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
