<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeatBooking extends Model
{
    use HasFactory;
    protected $fillable = ["seat_id","booking_id", "paid","status"];
    public function booking(){
        return $this->belongsTo(Booking::class);
    }
    public function seat(){
        return $this->belongsTo(SeatArrangement::class);
    }
}
