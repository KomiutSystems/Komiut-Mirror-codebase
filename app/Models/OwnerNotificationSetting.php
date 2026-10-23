<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** When an owner wants their statement, and which alerts they want. */
class OwnerNotificationSetting extends Model
{
    use HasFactory;

    /** Returned to an owner who has never saved. */
    public const DEFAULTS = [
        'statement_enabled' => true,
        'statement_time' => '21:00',
        'offline_alert_enabled' => true,
        'offline_after_minutes' => 30,
        'noon_alert_enabled' => true,
    ];

    protected $fillable = [
        'owner_id', 'statement_enabled', 'statement_time',
        'offline_alert_enabled', 'offline_after_minutes', 'noon_alert_enabled',
    ];

    protected $casts = [
        'statement_enabled' => 'boolean',
        'offline_alert_enabled' => 'boolean',
        'noon_alert_enabled' => 'boolean',
        'offline_after_minutes' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return array<string, mixed> */
    public static function forOwner(int $ownerId): array
    {
        $row = self::where('owner_id', $ownerId)->first();

        return $row === null ? self::DEFAULTS : [
            'statement_enabled' => $row->statement_enabled,
            'statement_time' => $row->statement_time,
            'offline_alert_enabled' => $row->offline_alert_enabled,
            'offline_after_minutes' => $row->offline_after_minutes,
            'noon_alert_enabled' => $row->noon_alert_enabled,
        ];
    }
}
