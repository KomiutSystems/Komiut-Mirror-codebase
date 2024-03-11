<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseFee extends Model
{
    use HasFactory;
    protected $fillable = ["name", "sacco_id", "type","status"];

    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }
}
