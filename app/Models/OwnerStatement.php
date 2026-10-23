<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What was sent to an owner for one day, kept as sent. */
class OwnerStatement extends Model
{
    use HasFactory;

    protected $fillable = ['owner_id', 'date', 'totals', 'vehicles', 'generated_at', 'pushed_at'];

    protected $casts = [
        'date' => 'date',
        'totals' => 'array',
        'vehicles' => 'array',
        'generated_at' => 'datetime',
        'pushed_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
