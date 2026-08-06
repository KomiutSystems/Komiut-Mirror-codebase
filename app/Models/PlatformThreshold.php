<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single alert-threshold override, layered over config/platform.php defaults.
 *
 * Deliberately NOT brand-scoped by BrandScope: thresholds are platform
 * configuration read by the super console, and `brand` here is the brand the
 * override APPLIES to (null = platform-wide) rather than the brand that owns
 * the row. Scoping it would hide platform-wide overrides from every request.
 */
final class PlatformThreshold extends Model
{
    protected $fillable = ['brand', 'key', 'value'];

    protected $casts = ['value' => 'array'];
}
