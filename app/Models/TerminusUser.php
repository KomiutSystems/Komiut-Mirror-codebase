<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TerminusUser extends Model
{
    use HasFactory;
    protected $fillable = ["user_id", "terminus_id", "status"];
    public function user(){
        return $this->belongsTo(User::class);
    }
    public function terminus(){
        return $this->belongsTo(Terminus::class);
    }
}
