<?php

namespace App\Models;

use App\Enums\SaccoClaimStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

class Sacco extends Model
{
    use HasFactory, BelongsToBrand, BelongsToSacco;

    /**
     * Sacco does not belong to a tenant — it IS one, so its tenant key is its own
     * primary key rather than a sacco_id column.
     *
     * This model was the one tenant table with no scope on it, and it leaked in
     * production: a SACCO Admin at NICCO opened the dashboard and was shown
     * "Bahima", because getSaccos returned all 49 SACCOs ordered by name and the
     * client took the first. Every other surface held — her vehicles, summaries
     * and transactions were correctly confined — so nobody's money was exposed,
     * but the whole SACCO directory was, and an admin landing in the wrong tenant
     * saw an empty dashboard and no explanation.
     *
     * Scoping it also closes SaccoAPIController::getSacco, which took an id
     * straight from the request and did findOrFail on it.
     *
     * The exemptions in SaccoScope are what keep this from breaking the rest:
     * passengers and drivers have no home SACCO, so currentSaccoId() is null and
     * they still browse every SACCO to book a ride; self-registration and the
     * claim flow run unauthenticated; super admins are never scoped; and the
     * cross-tenant maintenance paths (SaccoObserver's duplicate detection,
     * BrandAudit, BrandBackfill, DetectDormantSaccos, PlatformDailyDigest) all
     * already call withoutGlobalScopes() explicitly.
     */
    protected string $saccoColumn = 'id';
    // Most rows are directory entries with no account behind them — see
    // SaccoClaimStatus and App\Services\Sacco\SaccoDirectory.
    protected $fillable = ["name","email","slogan","phone", "status", "rotates_drivers", "brand", "claim_status", "source", "verified_at"];
    protected $hidden = ["paybill", "passkey", "consumer_key", "consumer_secret"];
    protected $casts = [
        "rotates_drivers" => "boolean",
        "claim_status" => SaccoClaimStatus::class,
        "verified_at" => "datetime",
    ];
    public function mpesa_payment(){
        return $this->hasOne(MpesaPaymentSetting::class);
    }
}
