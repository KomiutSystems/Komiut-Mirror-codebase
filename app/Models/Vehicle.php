<?php

namespace App\Models;

use App\Enums\Financier;
use App\Models\Concerns\BelongsToFinancier;
use App\Models\Scopes\FinancierScope;
use Illuminate\Support\Facades\Auth;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand, BelongsToFinancier;

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() for what this does and does
     * not permit — it exempts the TENANTLESS caller only; a user who has a
     * SACCO is still filtered to it.
     *
     * Which buses are running. The whole point of the passenger app is to find
     * a matatu, and it is not going to be one from a SACCO they are a member of.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    /*
     * Owns `financier`, so no $financierVia: this is the table every other
     * financier-scoped model reaches through. A bank user sees only the
     * vehicles their own bank financed; everyone else is unaffected.
     */
    protected $fillable = ["plate","fleet_no","till_number","merchant_short_code","sacco_id","user_id",'seat_id','mpesa_payment_setting_id','status','brand','financier','ncba_till','coop_till','till_registered_at','till_registered_url'];

    /**
     * Never serialized. follow_code is a column nothing reads any more (school
     * bus following is gone), but it stays in the table until the contract
     * migration drops it (database/contract/drop_school_bus_following.php) and
     * still holds the codes that were issued: it must not leak in the
     * meantime. Remove this with that migration.
     */
    protected $hidden = ['follow_code'];

    /**
     * A BANK is never told which bank finances a bus, nor shown the other
     * bank's collection-account column.
     *
     * Every bus a bank can read is one it finances (FinancierScope), so the
     * `financier` field tells it nothing except, by being there, that other
     * banks exist -- and the other bank's till column says so by its very
     * name. Hidden on the model rather than per endpoint: summaries,
     * transactions, M-Pesa and cash rows all embed the bus whole, and the next
     * screen to embed it would too.
     *
     * Auth::hasUser(), not Auth::user(): reading a model must never be what
     * triggers resolving the session.
     */
    protected static function booted(): void
    {
        static::retrieved(static function (Vehicle $vehicle): void {
            $viewer = Auth::hasUser() ? Auth::user() : null;
            if (! FinancierScope::confines($viewer)) {
                return;
            }

            $own = $viewer->currentFinancier();
            $vehicle->makeHidden(array_values(array_filter([
                'financier',
                'till_registration_status', 'till_registration_note', 'till_registration_attempted_at', 'till_registration_status_at',
                $own === Financier::Ncba ? null : 'ncba_till',
                $own === Financier::Coop ? null : 'coop_till',
            ])));
        });
    }

    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }
    public function seat(){
        return $this->belongsTo(Seat::class);
    }
    public function vehicle_user(){
        return $this->hasMany(VehicleUser::class);
    }
    public function mpesa_payment_setting(){
        return $this->belongsTo(MpesaPaymentSetting::class);
    }
}
