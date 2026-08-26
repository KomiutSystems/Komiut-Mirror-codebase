<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedLegacyString;
use App\Enums\BankPartner;
use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The NCBA push-notification request letter, as data. See the
 * create_bank_till_requests migration for why it is per SACCO.
 *
 * SACCO-scoped as well as brand-scoped.
 *
 * This was brand-only, on the argument that the banking relationship belongs to
 * the brand rather than to the SACCO whose tills the letter lists. That framing
 * did not survive contact with where the endpoints actually live: the routes sit
 * in the SACCO dashboard group and 'Manage Bank Till Requests' is granted to
 * FINANCE, a SACCO-tier role. So the people reaching it are exactly the SACCO
 * staff the docblock assumed were not.
 *
 * With only a brand scope, `BankTillRequest::find($id)` resolved ANY SACCO's
 * letter in the brand. index() listed their till numbers, paybill, endpoint_url
 * and signatories; update() edited their draft; and apply() — which is scoped to
 * `$row->sacco_id` for the VEHICLE but not for the letter — rewrote
 * merchant_short_code on their buses. That column decides where a bus's money
 * lands, so a mis-scoped apply() is a money-routing change inside another tenant.
 *
 * The table has carried sacco_id since its first migration, so the scope needs
 * no relation path. The partner write-back (BankWriteBackController) is
 * unaffected: it authenticates a BANK, not a user, and already reads with
 * withoutGlobalScopes().
 *
 * The three credential columns are encrypted at rest and hidden from every JSON
 * response — same contract as MpesaPaymentSetting, and for the same reason:
 * they must only ever leave this system as an outbound call to the bank.
 */
class BankTillRequest extends Model
{
    use BelongsToBrand, BelongsToSacco, HasFactory;

    public const FORMATS = ['json', 'xml'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_CREDENTIALS_RECEIVED = 'credentials_received';

    public const STATUS_APPLIED = 'applied';

    protected $fillable = [
        'sacco_id', 'brand', 'bank', 'letter_date', 'subject', 'paybill',
        'till_numbers', 'buygoods_numbers', 'request_format', 'endpoint_url',
        'signatories', 'username', 'password', 'secret_key', 'issued_tills',
        'credentials_received_at', 'applied_at', 'applied_by', 'status', 'user_id',
    ];

    /** Never serialize the live credentials. */
    protected $hidden = ['username', 'password', 'secret_key'];

    protected $casts = [
        'bank' => BankPartner::class,
        'letter_date' => 'date',
        'till_numbers' => 'array',
        'buygoods_numbers' => 'array',
        'signatories' => 'array',
        'issued_tills' => 'array',
        'username' => EncryptedLegacyString::class,
        'password' => EncryptedLegacyString::class,
        'secret_key' => EncryptedLegacyString::class,
        'credentials_received_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class);
    }

    /** Who drafted the letter. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether the bank has sent back the credentials this request asked for. */
    public function hasCredentials(): bool
    {
        return $this->credentials_received_at !== null;
    }
}
