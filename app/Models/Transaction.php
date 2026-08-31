<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToFinancier;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use BelongsToBrand, BelongsToFinancier, BelongsToSacco, HasFactory;

    /** Reaches the financed vehicle the same way $saccoVia does. */
    protected ?string $financierVia = 'vehicle';

    /** Reaches brand via vehicle. */
    protected ?string $brandVia = 'vehicle';

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';

    protected $fillable = ['cash_id', 'mpesa_id', 'vehicle_id', 'amount', 'redeemed', 'summarized', 'points', 'trans_date'];

    /**
     * Only payments that were actually matched to a bus. THE SCOPE EVERY
     * TAKINGS FIGURE HAS TO CARRY.
     *
     * A row here with no vehicle_id is a payment C2bPaymentRecorder could not
     * attribute: the shortcode it arrived on belongs to no vehicle we know. It
     * is deliberately still written, because the row is the only record that the
     * money exists and the only thing a repair can work from — but it is not
     * takings, and summing it as takings is how a SACCO gets told it earned
     * money nobody collected.
     *
     * On this fleet that is not a rounding error. NICCO sweeps each till to the
     * bank at 03:00, and those sweeps arrive as C2B on nine collection
     * shortcodes that match no vehicle: 26 rows worth KES 483,268 on 2026-08-31,
     * and a comparable figure every day. An unscoped dashboard read
     * KES 1,667,039 against real takings of KES 1,183,771 — 41% of it the
     * SACCO's own money being counted a second time on its way to the bank.
     *
     * SACCO and investor views never saw this: $saccoVia reaches sacco_id
     * THROUGH the vehicle, so a null vehicle_id already failed their whereHas.
     * It is the unscoped reads — the superadmin dashboard and the platform
     * payments screen — that were wrong, which is exactly where nobody has a
     * conductor's book to check the number against.
     */
    public function scopeAttributed($query)
    {
        return $query->whereNotNull('vehicle_id');
    }

    public function cash()
    {
        return $this->belongsTo(Cash::class);
    }

    public function mpesa()
    {
        return $this->belongsTo(Mpesa::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function direct_line_claim()
    {
        return $this->hasOne(DirectLineClaim::class);
    }
}
