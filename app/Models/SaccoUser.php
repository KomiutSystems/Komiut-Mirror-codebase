<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SaccoUser extends Model
{
    use HasFactory, BelongsToSacco;

    protected $fillable = ["user_id","sacco_id","start_date","end_date","status",'created_by'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
