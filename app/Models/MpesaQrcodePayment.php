<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaQrcodePayment extends Model
{
    use HasFactory;
    protected $fillable = ["transid","amount","phone","transdate","qrcode_payment_id","callback", "redeemed"];
    public function qrcode_payment(){
        return $this->belongsTo(QrcodePayment::class);
    }
}
