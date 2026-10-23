<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a report's timeline. Reached only through its report. */
class LostItemEvent extends Model
{
    use HasFactory;

    protected $fillable = ['report_id', 'status', 'note', 'actor_id'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(LostItemReport::class, 'report_id');
    }
}
