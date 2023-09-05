<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Route extends Model
{
    use HasFactory;
    protected $fillable = ['name','from_id','to_id','status'];

    public function from()
    {
        return $this->belongsTo(Place::class, 'from_id');
    }

    public function to()
    {
        return $this->belongsTo(Place::class, 'to_id');
    }




}
