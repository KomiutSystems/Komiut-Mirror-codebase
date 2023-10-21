<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaPaymentSetting extends Model
{
    use HasFactory;
    protected $fillable = ["sacco_id","consumer_key",'consumer_secret',"pass_key","business_short_code","payment_mode", 'is_live', 'status'];

    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }
}
