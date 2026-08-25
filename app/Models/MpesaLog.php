<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaLog extends Model
{
    use HasFactory;
    /**
     * "log", not "logs". The column created by create_mpesa_logs_table is
     * singular, so the plural spelling here mass-assigned an attribute that does
     * not exist: every MpesaLog::create() silently wrote a row with a NULL body,
     * losing the raw payload that is the whole point of the table. The existing
     * callers only escaped it by assigning properties directly instead.
     */
    protected $fillable = ['trans_id', 'log', 'ip_address'];
}
