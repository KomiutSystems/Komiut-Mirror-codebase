<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

class Point extends Model
{
    use HasFactory, BelongsToSacco;
    protected $fillable = ["user_id", "name","phone",'start_date','end_date','points','redeemed',"sacco_id", 'status'];

    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }
}
