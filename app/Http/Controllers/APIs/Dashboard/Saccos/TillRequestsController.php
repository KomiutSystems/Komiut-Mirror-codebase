<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Enums\BankPartner;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\BankTillRequest;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Services\Platform\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * @group Bank — till request letters
 *
 * The NCBA "REQUEST FOR API PUSH NOTIFICATION SERVICE" letter, held as data so
 * the UI can render and edit it. Nothing here produces a document: the fields
 * are the blanks in the letter, and signing happens on paper because two
 * authorised signatories are required and neither of them is this API.
 *
 * One request per SACCO, because that is how the credentials work —
 * `mpesa_payment_settings` holds one Daraja set per SACCO and vehicles differ
 * only by their own till. The letter agrees: "NCBA Till No(s)" is plural
 * against the single aggregator paybill 880100.
 *
 * The bank replies through the partner portal (BankWriteBackController), which
 * stages the tills it opened. `apply()` here is the human step that puts them
 * on the vehicles — deliberately separated, because a wrong
 * merchant_short_code is invisible until someone notices a month of missing
 * money, which is exactly what KDY 599G cost.
 */
class TillRequestsController extends Controller
{
    use PaginatesResults;

    private const PERMISSION = 'Manage Bank Till Requests';

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /** The letters raised so far. */
    public function index(Request $request): JsonResponse
    {
        if (($deny = $this->deny($request)) !== null) {
            return $deny;
        }

        $perPage = $this->perPage($request);
        $query = BankTillRequest::with('sacco:id,name')->orderByDesc('id');
        $meta = $this->pageMeta($query, $request, $perPage);
        $rows = $query->skip(($meta['current_page'] - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'till_requests' => $rows->map(fn ($r) => $this->payload($r))->all(),
            'meta' => $meta,
        ]);
    }

    /**
     * Draft a letter
     *
     * Everything is editable except the bank, which is derived from the brand —
     * the same rule the driver lead list follows. Sending a SACCO's till numbers
     * to the wrong bank is not recoverable by an apology.
     */
    public function store(Request $request): JsonResponse
    {
        if (($deny = $this->deny($request)) !== null) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'sacco_id' => 'required|integer',
            'subject' => 'required|string|max:160',
            'letter_date' => 'nullable|date',
            'paybill' => 'nullable|string|max:20',
            'till_numbers' => 'nullable|array|max:500',
            'till_numbers.*' => 'string|max:20',
            'buygoods_numbers' => 'nullable|array|max:500',
            'buygoods_numbers.*' => 'string|max:20',
            'request_format' => 'nullable|in:json,xml',
            'endpoint_url' => 'required|url|max:255',
            'signatories' => 'nullable|array|max:4',
            'signatories.*.name' => 'required_with:signatories|string|max:120',
            'signatories.*.title' => 'nullable|string|max:120',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // Global scopes stay on: find() is the tenant boundary.
        $sacco = Sacco::find((int) $request->input('sacco_id'));
        if ($sacco === null) {
            return response()->json(['error' => 'SACCO not found.'], 404);
        }

        $brand = (string) Context::get('brand', '');
        $row = BankTillRequest::create([
            'sacco_id' => $sacco->id,
            'bank' => BankPartner::forBrand($brand)->value,
            'subject' => (string) $request->input('subject'),
            'letter_date' => $request->input('letter_date') ?: now()->toDateString(),
            'paybill' => (string) ($request->input('paybill') ?: '880100'),
            'till_numbers' => $request->input('till_numbers', []),
            'buygoods_numbers' => $request->input('buygoods_numbers', []),
            'request_format' => (string) ($request->input('request_format') ?: 'json'),
            'endpoint_url' => (string) $request->input('endpoint_url'),
            'signatories' => $request->input('signatories', []),
            'user_id' => $request->user()->id,
            'status' => BankTillRequest::STATUS_DRAFT,
        ]);

        return response()->json(['till_request' => $this->payload($row->fresh('sacco'))], 201);
    }

    /**
     * Edit the letter, or mark it sent
     *
     * Refuses once the bank has replied. Editing the till list under credentials
     * that were issued against the old list would leave the letter describing a
     * request nobody made.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (($deny = $this->deny($request)) !== null) {
            return $deny;
        }

        $row = BankTillRequest::find($id);
        if ($row === null) {
            return response()->json(['error' => 'Till request not found.'], 404);
        }
        if ($row->hasCredentials()) {
            return response()->json(['error' => 'The bank has already replied to this request; it can no longer be edited.'], 409);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'sometimes|string|max:160',
            'letter_date' => 'sometimes|nullable|date',
            'paybill' => 'sometimes|string|max:20',
            'till_numbers' => 'sometimes|array|max:500',
            'till_numbers.*' => 'string|max:20',
            'buygoods_numbers' => 'sometimes|array|max:500',
            'buygoods_numbers.*' => 'string|max:20',
            'request_format' => 'sometimes|in:json,xml',
            'endpoint_url' => 'sometimes|url|max:255',
            'signatories' => 'sometimes|array|max:4',
            'status' => 'sometimes|in:draft,sent',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $row->fill($validator->validated())->save();

        return response()->json(['till_request' => $this->payload($row->fresh('sacco'))]);
    }

    /**
     * Put the bank's tills onto the vehicles
     *
     * The human step. The partner portal stages what the bank opened; this is
     * where it becomes real, and it is separate on purpose.
     *
     * A wrong merchant_short_code does not fail — it succeeds silently and the
     * bus's collections simply never arrive, while the record looks correct.
     * KDY 599G ran that way for a month and cost roughly Ksh 20,800 a day of
     * invisible takings. So the numbers a third party sends us do not reach a
     * vehicle without somebody here saying yes.
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        if (($deny = $this->deny($request)) !== null) {
            return $deny;
        }

        $row = BankTillRequest::find($id);
        if ($row === null) {
            return response()->json(['error' => 'Till request not found.'], 404);
        }

        $issued = $row->issued_tills;
        if (! is_array($issued) || $issued === []) {
            return response()->json(['error' => 'The bank has not sent any tills for this request yet.'], 422);
        }

        $applied = [];
        $skipped = [];

        DB::transaction(function () use ($issued, $row, &$applied, &$skipped): void {
            foreach ($issued as $entry) {
                $plate = trim((string) ($entry['plate'] ?? ''));
                $till = trim((string) ($entry['till'] ?? ''));
                if ($plate === '' || $till === '') {
                    continue;
                }

                // Scoped find: a till can only land on a vehicle of the SACCO
                // this letter was raised for, whatever plate the bank sent back.
                $vehicle = Vehicle::withoutGlobalScopes()
                    ->where('sacco_id', $row->sacco_id)
                    ->whereRaw('UPPER(REPLACE(plate, \' \', \'\')) = ?', [strtoupper(str_replace(' ', '', $plate))])
                    ->first();

                if ($vehicle === null) {
                    $skipped[] = ['plate' => $plate, 'reason' => 'no such vehicle in this SACCO'];

                    continue;
                }

                $applied[] = [
                    'plate' => $vehicle->plate,
                    'from' => $vehicle->merchant_short_code,
                    'to' => $till,
                ];

                $vehicle->forceFill(['till_number' => $till, 'merchant_short_code' => $till])->save();
            }

            $row->applied_at = now();
            $row->applied_by = auth()->id();
            $row->status = BankTillRequest::STATUS_APPLIED;
            $row->save();
        });

        // Money routing changed. The before/after is the whole point of the
        // record: if collections stop, this is the first thing to look at.
        AuditLogger::record(
            action: 'bank.till_request.applied',
            data: [
                'till_request_id' => (int) $row->id,
                'applied' => $applied,
                'skipped' => $skipped,
            ],
            saccoId: (int) $row->sacco_id,
        );

        return response()->json([
            'success' => count($applied).' till(s) applied.',
            'applied' => $applied,
            'skipped' => $skipped,
        ]);
    }

    private function deny(Request $request): ?JsonResponse
    {
        return $request->user()->can(self::PERMISSION)
            ? null
            : response()->json(['error' => 'You do not have permission to manage bank till requests.'], 403);
    }

    /**
     * Hand-shaped. The credential columns are $hidden on the model, and nothing
     * here reads them back — they exist to be sent to Daraja, not displayed.
     *
     * @return array<string, mixed>
     */
    private function payload(BankTillRequest $r): array
    {
        return [
            'id' => (int) $r->id,
            'sacco' => ['id' => (int) $r->sacco_id, 'name' => $r->sacco?->name],
            'bank' => $r->bank?->value,
            'letter_date' => optional($r->letter_date)->toDateString(),
            'subject' => $r->subject,
            'paybill' => $r->paybill,
            'till_numbers' => $r->till_numbers ?? [],
            'buygoods_numbers' => $r->buygoods_numbers ?? [],
            'request_format' => $r->request_format,
            'endpoint_url' => $r->endpoint_url,
            'signatories' => $r->signatories ?? [],
            'status' => $r->status,
            // Whether the bank has replied — never the credentials themselves.
            'has_credentials' => $r->hasCredentials(),
            'issued_tills' => $r->issued_tills ?? [],
            'applied_at' => optional($r->applied_at)->toIso8601String(),
        ];
    }
}
