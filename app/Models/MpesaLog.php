<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaLog extends Model
{
    use HasFactory;
    protected $fillable = ["trans_id", "logs", "ip_address"];
}
