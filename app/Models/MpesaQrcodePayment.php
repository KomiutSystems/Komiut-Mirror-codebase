<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaQrcodePayment extends Model
{
    use HasFactory;
    protected $fillable = ["transid","name","amount","points","phone","transdate","qrcode_payment_id","callback", "redeemed"];

    /**
     * The raw Daraja callback never leaves the server.
     *
     * It is kept for reconciliation and dispute work, and it is several
     * kilobytes of provider JSON that no screen renders. This relation is now
     * eager-loaded onto the QR payments listing, so without this every row on
     * that page would carry one.
     */
    protected $hidden = ["callback"];
    public function qrcode_payment(){
        return $this->belongsTo(QrcodePayment::class);
    }
}
