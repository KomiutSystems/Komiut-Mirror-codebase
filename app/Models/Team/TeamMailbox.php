<?php

declare(strict_types=1);

namespace App\Models\Team;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shared address the team answers from (support@ …). */
class TeamMailbox extends Model
{
    protected $fillable = ['address', 'name', 'brand', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function threads(): HasMany
    {
        return $this->hasMany(TeamThread::class, 'mailbox_id');
    }
}
