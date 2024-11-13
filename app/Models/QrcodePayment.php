<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrcodePayment extends Model
{
    use HasFactory;
    protected $fillable = ["vehicle_id","seat_arrangement_id","user_id","amount","status"];

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
    public function seat_arrangement(){
        return $this->belongsTo(SeatArrangement::class);
    }
    public function user(){
        return $this->belongsTo(User::class);
    }

    public function mpesa_qrcode_payment(){
        return $this->hasOne(MpesaQrcodePayment::class);
    }
}
