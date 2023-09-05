<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mpesa extends Model
{
    use HasFactory;
    protected $fillable = ['TransID','MSISDN','TransAmount','TransTime','FirstName','MiddleName','LastName','ThirdPartyTransID',
    'InvoiceNumber','BillRefNumber','BusinessShortCode','TransactionType'];

    public function transaction(){
        return $this->hasOne(Transaction::class);
    }
}
