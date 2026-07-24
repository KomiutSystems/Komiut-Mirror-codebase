<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;

class Sacco extends Model
{
    use HasFactory, BelongsToBrand;
    protected $fillable = ["name","email","slogan","phone", "status", "rotates_drivers", "brand"];
    protected $hidden = ["paybill", "passkey", "consumer_key", "consumer_secret"];
    protected $casts = ["rotates_drivers" => "boolean"];
    public function mpesa_payment(){
        return $this->hasOne(MpesaPaymentSetting::class);
    }
}
