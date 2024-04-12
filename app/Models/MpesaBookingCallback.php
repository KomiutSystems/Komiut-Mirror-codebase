<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaBookingCallback extends Model
{
    use HasFactory;
    protected $fillable = ["transid","amount","phone","transdate","booking_id","callback", "redeemed"];
    public function booking(){
        return $this->belongsTo(Booking::class);
    }
}
