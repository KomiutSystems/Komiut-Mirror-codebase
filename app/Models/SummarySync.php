<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SummarySync extends Model
{
    use HasFactory;
    protected $fillable = ['sync_date', "status"];
}
