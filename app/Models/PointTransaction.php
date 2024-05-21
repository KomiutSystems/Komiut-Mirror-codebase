<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointTransaction extends Model
{
    use HasFactory;
    protected $fillable = ["mpesa_booking_callback_id", "mpesa_qrcode_payment_id", "points", "trans_date"];

    public function mpesa_booking_callback(){
        return $this->belongsTo(MpesaBookingCallback::class);
    }
    public function mpesa_qrcode_payment(){
        return $this->belongsTo(MpesaQrcodePayment::class);
    }
}
