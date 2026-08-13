<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Partner;

use App\Http\Controllers\Controller;
use App\Http\Middleware\BankPartnerAuth;
use App\Models\BankTillRequest;
use App\Models\DriverBankLead;
use App\Services\Platform\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Partner bank — writing back
 *
 * The other half of the loop. Until now the partner portal was read-only: NCBA
 * pulled a lead list and whatever happened next happened in their systems,
 * invisible to us. These two endpoints let them tell us the outcome — an
 * account was opened, and here are the till credentials we asked for.
 *
 * A write endpoint on a shared-key portal is a different risk from a read one.
 * A leaked key used to leak a lead list; now it could inject money-routing
 * values. Hence the hard rule below, which is not negotiable:
 *
 *   THE PARTNER NEVER WRITES A VEHICLE'S till_number OR merchant_short_code.
 *
 * KDY 599G is the argument. Its merchant_short_code was wrong for a month; its
 * collections were invisible the entire time and the record looked perfectly
 * healthy — only the ABSENCE of payments gave it away. Tills the bank issues
 * land on the request as `issued_tills` and a human applies them from the
 * dashboard. tills:check-idle is the backstop, not the gate.
 *
 * Everything here is idempotent (banks retry), takes its brand from the partner
 * key rather than the body, and is audited: this is a third party changing our
 * records, so "who changed what, when" must be answerable later.
 */
class BankWriteBackController extends Controller
{
    /**
     * Confirm a driver's account was opened
     *
     * Idempotent: a retry with the same account number is accepted and changes
     * nothing, so a bank that did not see our 200 can safely send it again.
     */
    public function accountOpened(Request $request, int $lead): JsonResponse
    {
        $partner = $this->partner($request);

        $validator = Validator::make($request->all(), [
            'account_number' => 'required|string|max:40',
            'opened_at' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // Brand from the key, never the body: NCBA's key cannot reach a 2Safiri
        // driver even by guessing an id.
        $row = DriverBankLead::withoutGlobalScopes()
            ->where('id', $lead)
            ->where('brand', $partner['brand'])
            ->first();

        if ($row === null) {
            return response()->json(['error' => 'Lead not found.'], 404);
        }

        $account = trim((string) $request->input('account_number'));

        // A second, DIFFERENT account number is not a retry — it is either a
        // mistake or someone else's account, and silently overwriting would put
        // a driver's payouts somewhere new with no trace.
        if ($row->account_number !== null && $row->account_number !== '' && $row->account_number !== $account) {
            return response()->json([
                'error' => 'This lead already carries a different account number. It must be changed by Komiut, not through the portal.',
            ], 409);
        }

        $alreadyOpen = $row->account_opened_at !== null;

        $row->account_number = $account;
        $row->account_opened_at = $row->account_opened_at
            ?? ($request->filled('opened_at') ? $request->date('opened_at') : now());
        $row->status = 'opened';
        $row->save();

        if (! $alreadyOpen) {
            AuditLogger::record(
                action: 'partner.lead.account_opened',
                data: ['lead_id' => (int) $row->id, 'driver_id' => (int) $row->user_id, 'ip' => $request->ip()],
                actor: ['type' => 'partner', 'id' => $partner['key'], 'label' => $partner['label']],
                brand: $partner['brand'],
            );
        }

        return response()->json([
            'success' => 'Account recorded.',
            'lead' => ['id' => (int) $row->id, 'status' => $row->status,
                'account_opened_at' => optional($row->account_opened_at)->toIso8601String()],
        ]);
    }

    /**
     * Return the credentials for a till request
     *
     * The reply to the push-notification letter: the username, password and
     * secret key the callback will authenticate with, plus the tills the bank
     * actually opened.
     *
     * The tills are STAGED, not applied. See the class docblock.
     */
    public function tillCredentials(Request $request, int $tillRequest): JsonResponse
    {
        $partner = $this->partner($request);

        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:120',
            'password' => 'required|string|max:255',
            'secret_key' => 'required|string|max:255',
            'issued_tills' => 'nullable|array|max:200',
            'issued_tills.*.plate' => 'required_with:issued_tills|string|max:20',
            'issued_tills.*.till' => 'required_with:issued_tills|string|max:20',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $row = BankTillRequest::withoutGlobalScopes()
            ->where('id', $tillRequest)
            ->where('brand', $partner['brand'])
            ->first();

        if ($row === null) {
            return response()->json(['error' => 'Till request not found.'], 404);
        }

        // Assigned through the model so the encrypting cast runs exactly once.
        // Writing these with the query builder instead would store plaintext,
        // and writing an already-encrypted value through the model would encrypt
        // it twice — both mistakes this codebase has made before.
        $row->username = (string) $request->input('username');
        $row->password = (string) $request->input('password');
        $row->secret_key = (string) $request->input('secret_key');
        $row->issued_tills = $request->input('issued_tills') ?: $row->issued_tills;
        $row->credentials_received_at = $row->credentials_received_at ?? now();
        $row->status = BankTillRequest::STATUS_CREDENTIALS_RECEIVED;
        $row->save();

        AuditLogger::record(
            action: 'partner.till_request.credentials_received',
            // Never the credentials themselves, and never in an audit row: this
            // records THAT they arrived and how many tills came with them.
            data: [
                'till_request_id' => (int) $row->id,
                'sacco_id' => (int) $row->sacco_id,
                'issued_tills' => is_array($row->issued_tills) ? count($row->issued_tills) : 0,
                'ip' => $request->ip(),
            ],
            actor: ['type' => 'partner', 'id' => $partner['key'], 'label' => $partner['label']],
            brand: $partner['brand'],
        );

        return response()->json([
            'success' => 'Credentials received. The tills are staged for review before they go live.',
            'till_request' => ['id' => (int) $row->id, 'status' => $row->status],
        ]);
    }

    /** @return array{key: string, brand: string, label: string} */
    private function partner(Request $request): array
    {
        return $request->attributes->get(BankPartnerAuth::PARTNER);
    }
}
