<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OldMpesaSync extends Model
{
    use HasFactory;
    protected $fillable = ["trans_id", "trans_date"];
}
