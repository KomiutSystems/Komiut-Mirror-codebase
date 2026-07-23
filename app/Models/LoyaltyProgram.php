<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyProgram extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;

    protected ?string $brandVia = 'sacco';
    protected $saccoVia = 'sacco';

    protected $fillable = ['sacco_id', 'divisor', 'redemption_threshold', 'is_active'];

    protected $casts = [
        'divisor' => 'float',
        'redemption_threshold' => 'float',
        'is_active' => 'boolean',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }
}
