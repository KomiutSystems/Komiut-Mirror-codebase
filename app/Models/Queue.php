<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Queue extends Model
{
    use HasFactory;
    protected $fillable = ["queue_number", "vehicle_id","terminus_id",
    "queue_status_id","route_id","user_id", 'amount','schedule_time','start_time','end_time', 'queue_type'];

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
    public function user(){
        return $this->belongsTo(User::class);
    }
    public function terminus(){
        return $this->belongsTo(Terminus::class);
    }
    public function queue_status(){
        return $this->belongsTo(QueueStatus::class);
    }
    public function route(){
        return $this->belongsTo(Route::class);
    }
    public function queue_places(){
        return $this->hasMany(QueuePlace::class);
    }

    public function bookings(){
        return $this->hasMany(Booking::class);
    }
}
