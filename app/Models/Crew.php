<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Crew extends Model
{
    use HasFactory;
    protected $fillable = ["firstname", "lastname","email","id_number","badge_number","phone","password",
    "image",'user_id', 'created_by',"status"];
    protected $hidden = [
        'password',
    ];
    public function user(){
        return $this->belongsTo(User::class);
    }
}
