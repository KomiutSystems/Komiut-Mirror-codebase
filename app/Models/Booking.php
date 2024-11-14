<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;
    protected $fillable = ["name", "phone","passengers", "user_id","queue_id", 'from_id', 'to_id',"amount",
    'boarded','paid',"stk_response","start_time","stop_time",'created_by','status'];

    public function from(){
        return $this->belongsTo(Place::class, 'from_id');
    }
    public function to(){
        return $this->belongsTo(Place::class, 'to_id');
    }

    public function creator(){
        return $this->belongsTo(User::class, 'created_by');
    }
    public function user(){
        return $this->belongsTo(User::class);
    }

    public function queue(){
        return $this->belongsTo(Queue::class);
    }
    public function seats(){
        return $this->hasMany(SeatBooking::class);
    }
    public function mpesa_booking_callbacks(){
        return $this->hasMany(MpesaBookingCallback::class);
    }
}
