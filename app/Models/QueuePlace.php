<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QueuePlace extends Model
{
    use HasFactory;
    protected $fillable = ["queue_id", 'route_stage_id', 'status'];

    public function route_stage(){
        return $this->belongsTo(RouteStage::class);
    }
}
