<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

class SaccoTerminus extends Model
{
    use HasFactory, BelongsToSacco;
    protected $fillable = ["terminus_id","sacco_id","user_id", "geofence_radius"];
    public function user() {
        return $this->belongsTo(User::class);
    }
    public function terminus(){
        return $this->belongsTo(Terminus::class);
    }
    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }
}
