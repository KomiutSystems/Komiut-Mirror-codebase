<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'route_id', 'queue_id', 'latitude', 'longitude',
        'heading', 'broadcasting', 'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'heading' => 'integer',
        'broadcasting' => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function queue()
    {
        return $this->belongsTo(Queue::class);
    }
}
