<?php

namespace App\Models;

use App\Enums\SaccoClaimStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;

class Sacco extends Model
{
    use HasFactory, BelongsToBrand;
    // Most rows are directory entries with no account behind them — see
    // SaccoClaimStatus and App\Services\Sacco\SaccoDirectory.
    protected $fillable = ["name","email","slogan","phone", "status", "rotates_drivers", "brand", "claim_status", "source", "verified_at"];
    protected $hidden = ["paybill", "passkey", "consumer_key", "consumer_secret"];
    protected $casts = [
        "rotates_drivers" => "boolean",
        "claim_status" => SaccoClaimStatus::class,
        "verified_at" => "datetime",
    ];
    public function mpesa_payment(){
        return $this->hasOne(MpesaPaymentSetting::class);
    }
}
