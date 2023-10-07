<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Point extends Model
{
    use HasFactory;
    protected $fillable = ["user_id", "name","phone",'start_date','end_date','points',"sacco_id", 'status'];
}
