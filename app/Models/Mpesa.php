<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFinancier;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mpesa extends Model
{
    use BelongsToFinancier, BelongsToSacco, HasFactory;

    /**
     * Same path as $saccoVia, and fail-closed for the same reason: a payment
     * whose transaction never resolved a vehicle is financed by nobody, so it
     * belongs to neither bank rather than to both.
     */
    protected ?string $financierVia = 'transaction.vehicle';

    /**
     * Reaches sacco_id via the transaction that attributes this payment to a
     * vehicle. `mpesas` carries no vehicle_id of its own — a C2B confirmation
     * arrives knowing only a till/paybill, and the vehicle is resolved onto the
     * Transaction afterwards — so this is the only path to a tenant.
     *
     * Fails CLOSED on purpose: an M-Pesa row with no transaction, or one whose
     * transaction never resolved a vehicle, belongs to nobody and is therefore
     * invisible to every SACCO. Unattributed money is a reconciliation problem
     * for the super console (Super\Payments\PaymentsController), not something
     * to hand to whichever SACCO happens to be looking.
     */
    protected $saccoVia = 'transaction.vehicle';

    /*
     * NO BelongsToBrand — deliberately, and it must stay that way until the
     * write path is fixed first.
     *
     * Unlike SaccoScope (which bails when there is no authenticated user, so
     * webhooks are exempt), BrandScope keys on Context and applies to
     * UNAUTHENTICATED requests too. Every C2B confirmation route runs under
     * `brand.route`, which sets that Context — and each of those handlers dedupes
     * with a bare `Mpesa::where('TransID', $id)->first()`:
     *
     *   App\Services\Mpesa\C2bPaymentRecorder::attempt()          (NCBA REST, live)
     *   App\Http\Controllers\APIs\CoopRestPaymentsController      (Co-op, live)
     *   App\Http\Controllers\APIs\server::CBAMpesaNotificationRequest()
     *       (dormant — the legacy SOAP handler has no caller today, but it
     *        carries the same pattern and would reintroduce the fault if revived)
     *
     * No AUTHENTICATED route reads Mpesa by TransID, which is why SaccoScope
     * above is safe to add on its own.
     *
     * Scoping that lookup through `transaction.vehicle` hides an existing row
     * whose transaction has no vehicle, so the handler builds a `new Mpesa`,
     * `save()` violates the unique index on TransID, C2bPaymentRecorder's own
     * try/catch swallows it, and a payment that really arrived is recorded as
     * failed. C2bPaymentRecorder already calls withoutGlobalScopes() for exactly
     * this reason on the Transaction and Summary lookups a few lines below;
     * those three Mpesa lookups need the same treatment before brand scoping
     * can be added here.
     */

    protected $fillable = ['TransID', 'MSISDN', 'TransAmount', 'OrgAccountBalance', 'TransTime', 'FirstName', 'MiddleName', 'LastName', 'ThirdPartyTransID',
        'InvoiceNumber', 'BillRefNumber', 'BusinessShortCode', 'TransactionType'];

    public function transaction()
    {
        return $this->hasOne(Transaction::class);
    }
}
