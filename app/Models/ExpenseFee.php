<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

class ExpenseFee extends Model
{
    use HasFactory, BelongsToSacco;

    /**
     * A platform catalogue a SACCO may extend.
     *
     * Every row in expense_fees carries sacco_id NULL — Fuel, Parking, Stage
     * Fee, Police/Fines and the rest are shared, not owned. A plain equality
     * scope therefore matched NOTHING for any tenant user, so the expense
     * category picker was empty for every SACCO admin on the platform while
     * looking perfectly well-scoped in the code. Shared rows are visible to
     * everyone; a row that does carry a sacco_id belongs to that SACCO alone.
     */
    protected bool $saccoIncludesShared = true;
    protected $fillable = ["name", "sacco_id", "type","status"];

    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }
}
