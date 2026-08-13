<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message a SACCO sent to its crew. See the create_sacco_announcements
 * migration for why this is a row rather than a fire-and-forget loop.
 *
 * SACCO-scoped, so a SACCO admin listing announcements can only ever see their
 * own — the same boundary every other dashboard resource sits behind.
 */
class SaccoAnnouncement extends Model
{
    use BelongsToBrand, BelongsToSacco, HasFactory;

    protected $fillable = ['sacco_id', 'user_id', 'vehicle_id', 'title', 'body', 'recipients', 'brand'];

    protected $casts = [
        'recipients' => 'integer',
    ];

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class);
    }

    /** Who sent it. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The one vehicle this was aimed at, or null when it went to the whole SACCO. */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
