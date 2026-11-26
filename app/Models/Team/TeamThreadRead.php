<?php

declare(strict_types=1);

namespace App\Models\Team;

use Illuminate\Database\Eloquent\Model;

/** When a team member last opened a thread: unread is per person. */
class TeamThreadRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['thread_id', 'user_id', 'read_at'];

    protected $casts = ['thread_id' => 'integer', 'user_id' => 'integer', 'read_at' => 'datetime'];
}
