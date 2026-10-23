<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LostItemStatus;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Brand-scoped only. Who may read a row is decided explicitly in the
 * controllers — the passenger by user_id, the crew by today's vehicle — because
 * a passenger has no SACCO and SaccoScope would simply not apply to them.
 */
class LostItemReport extends Model
{
    use BelongsToBrand, HasFactory;

    protected $fillable = [
        'user_id', 'vehicle_id', 'booking_id', 'sacco_id', 'brand',
        'category', 'description', 'contact_phone', 'trip_date', 'route_label', 'status',
        'found_where', 'found_place_name', 'found_note', 'found_photo_path',
        'found_by', 'found_at', 'keep_until', 'crew_notified_at',
    ];

    protected $casts = [
        'status' => LostItemStatus::class,
        'trip_date' => 'date',
        'keep_until' => 'date',
        'found_at' => 'datetime',
        'crew_notified_at' => 'datetime',
    ];

    /** Never serialise the passenger's number; only the SACCO office may see it. */
    protected $hidden = ['contact_phone'];

    /** How long a found item is held before it goes to the SACCO office. */
    public const KEEP_DAYS = 14;

    /** An unanswered report is closed after this long. */
    public const UNANSWERED_DAYS = 14;

    /** The most open reports one passenger may hold at once. */
    public const MAX_OPEN = 3;

    public function publicId(): string
    {
        return 'lf_'.$this->id;
    }

    public function reference(): string
    {
        return 'LF-'.$this->id;
    }

    /** `lf_2041` and `LF-2041` and `2041` all resolve to 2041. */
    public static function parseId(string $given): ?int
    {
        if (preg_match('/^(?:lf_|LF-)?(\d+)$/i', trim($given), $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LostItemEvent::class, 'report_id');
    }

    /** Appends to the timeline the app renders. */
    public function recordEvent(LostItemStatus $status, ?string $note = null, ?int $actorId = null): void
    {
        $this->events()->create([
            'status' => $status->value,
            'note' => $note,
            'actor_id' => $actorId,
        ]);
    }
}
