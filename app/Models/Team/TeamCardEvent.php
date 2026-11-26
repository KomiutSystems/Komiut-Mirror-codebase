<?php

declare(strict_types=1);

namespace App\Models\Team;

use Illuminate\Database\Eloquent\Model;

/** A card arriving in a column: who moved it, and who held it then. */
class TeamCardEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['card_id', 'actor_id', 'from_status', 'to_status', 'assignee_id', 'at'];

    protected $casts = [
        'card_id' => 'integer',
        'actor_id' => 'integer',
        'assignee_id' => 'integer',
        'at' => 'datetime',
    ];
}
