<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sacco extends Model
{
    use HasFactory;
    protected $fillable = ["name","slogan","phone", "status"];

    public function mpesa_payment(){
        return $this->hasOne(MpesaPaymentSetting::class);
    }
}
