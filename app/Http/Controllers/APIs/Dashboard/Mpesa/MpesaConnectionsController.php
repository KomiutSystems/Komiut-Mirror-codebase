<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Mpesa;

use App\Http\Controllers\Controller;
use App\Models\MpesaPaymentSetting;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * @group M-Pesa connections
 *
 * A SACCO's M-Pesa connections: one per Daraja app / head-office short code it
 * collects through, with the buses linked to each. NICCO alone has ~25, and
 * until now a SACCO admin could see and edit only one of them (mpesa/settings),
 * so onboarding a till under any other head office meant asking Komiut.
 *
 * SECRETS ARE WRITE-ONLY. The consumer key, consumer secret and passkey are
 * encrypted at rest and never leave this API: a response says only whether
 * each is set. A field sent blank on edit keeps its stored value. The legacy
 * payments page sent every key to the browser in hidden fields; this must not.
 *
 * A bus's connection is what BOTH registering its till (mpesa/tills/{id}/register)
 * and in-app payments (STK) use. A connection without a passkey can register
 * tills but cannot take app payments -- `can_take_app_payments` says which.
 */
class MpesaConnectionsController extends Controller
{
    private const MODES = [
        'buygoods' => 'CustomerBuyGoodsOnline',
        'paybill' => 'CustomerPayBillOnline',
        'CustomerBuyGoodsOnline' => 'CustomerBuyGoodsOnline',
        'CustomerPayBillOnline' => 'CustomerPayBillOnline',
    ];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * List the SACCO's connections
     *
     * Each with usage: how many buses are linked, how many of those are
     * registered with Safaricom, when a payment last arrived through it and how
     * many in the last 7 days. The default connection comes first.
     *
     * @authenticated
     *
     * @queryParam sacco_id integer Super admin only: the SACCO to list. Example: 4
     */
    public function index(Request $request): JsonResponse
    {
        $saccoId = $this->saccoId($request);
        if ($saccoId === null) {
            return response()->json(['error' => 'No SACCO context.'], 422);
        }

        $connections = MpesaPaymentSetting::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->orderByDesc('is_default')->orderBy('name')->orderBy('id')
            ->get();

        $usage = $this->usage($connections->pluck('id')->all());

        return response()->json([
            'connections' => $connections->map(fn (MpesaPaymentSetting $c) => $this->row($c, $usage[$c->id] ?? []))->values(),
            'count' => $connections->count(),
        ]);
    }

    /**
     * One connection, with the buses linked to it
     *
     * @authenticated
     */
    public function show(Request $request, int $connection): JsonResponse
    {
        $c = $this->find($request, $connection);
        if ($c instanceof JsonResponse) {
            return $c;
        }

        $vehicles = Vehicle::withoutGlobalScopes()
            ->where('mpesa_payment_setting_id', $c->id)
            ->orderBy('plate')
            ->get(['id', 'plate', 'till_number', 'merchant_short_code', 'till_registered_at', 'till_registered_url', 'status']);

        return response()->json([
            'connection' => $this->row($c, $this->usage([$c->id])[$c->id] ?? []),
            'vehicles' => $vehicles->map(fn (Vehicle $v) => [
                'id' => (int) $v->id,
                'plate' => $v->plate,
                'till_number' => $v->till_number,
                'merchant_short_code' => $v->merchant_short_code,
                'till_registered_at' => $v->till_registered_at === null ? null : Carbon::parse($v->till_registered_at, 'UTC')->toIso8601String(),
                'till_registered_url' => $v->till_registered_url,
                'status' => (bool) $v->status,
            ])->values(),
        ]);
    }

    /**
     * Add a connection
     *
     * @authenticated
     *
     * @bodyParam name string required How the SACCO knows this account. Example: Mr Mburu-Coop
     * @bodyParam business_short_code string required The head-office short code the Daraja app is live for. Example: 3020809
     * @bodyParam paybill string Optional paybill number. Example: 5557936
     * @bodyParam payment_mode string required buygoods or paybill. Example: buygoods
     * @bodyParam environment string live (default) or sandbox. Example: live
     * @bodyParam enabled boolean Defaults to true. Example: true
     * @bodyParam consumer_key string required Daraja consumer key. Example: aBc...
     * @bodyParam consumer_secret string required Daraja consumer secret. Example: xYz...
     * @bodyParam pass_key string The Lipa na M-Pesa Online passkey. Needed for in-app payments, not for registering tills. Example: bfb2...
     * @bodyParam make_default boolean Make this the SACCO's default connection. Example: false
     *
     * @response 201 {"success": "Connection added.", "connection": {"id": 37, "name": "Mr Mburu-Coop", "business_short_code": "3020809", "is_default": false, "credentials": {"consumer_key_set": true, "consumer_secret_set": true, "pass_key_set": true}}}
     * @response 409 {"error": "Short code 3020809 is already connected for this SACCO (connection #31)."}
     */
    public function store(Request $request): JsonResponse
    {
        if (($denied = $this->denyWrite($request)) !== null) {
            return $denied;
        }

        $saccoId = $this->saccoId($request);
        if ($saccoId === null) {
            return response()->json(['error' => 'No SACCO context.'], 422);
        }

        $this->aliasPassKey($request);
        $validator = Validator::make($request->all(), $this->rules(creating: true));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $shortCode = (string) intval($request->business_short_code);
        if (($clash = $this->shortCodeClash($shortCode, $saccoId)) !== null) {
            return $clash;
        }

        $c = DB::transaction(function () use ($request, $saccoId, $shortCode) {
            $c = new MpesaPaymentSetting;
            $c->sacco_id = $saccoId;
            $this->fill($c, $request, $shortCode);
            $c->save();

            if ($request->boolean('make_default')) {
                $this->makeDefault($c);
            }

            return $c->fresh();
        });

        return response()->json(['success' => 'Connection added.', 'connection' => $this->row($c, [])], 201);
    }

    /**
     * Edit a connection
     *
     * Any field may be omitted. A credential sent blank keeps its stored value.
     *
     * @authenticated
     */
    public function update(Request $request, int $connection): JsonResponse
    {
        if (($denied = $this->denyWrite($request)) !== null) {
            return $denied;
        }

        $c = $this->find($request, $connection);
        if ($c instanceof JsonResponse) {
            return $c;
        }

        $this->aliasPassKey($request);
        $validator = Validator::make($request->all(), $this->rules(creating: false));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // Turning the default off is done by making ANOTHER connection the
        // default; a SACCO with buses on the fallback must always have one.
        if ($request->has('make_default') && ! $request->boolean('make_default') && $c->is_default) {
            return response()->json(['error' => 'Make another connection the default instead; a SACCO always has one.'], 422);
        }

        $shortCode = $request->filled('business_short_code') ? (string) intval($request->business_short_code) : (string) $c->business_short_code;
        if ($shortCode !== (string) $c->business_short_code && ($clash = $this->shortCodeClash($shortCode, (int) $c->sacco_id, $c->id)) !== null) {
            return $clash;
        }

        DB::transaction(function () use ($request, $c, $shortCode): void {
            $this->fill($c, $request, $shortCode);
            $c->save();

            if ($request->boolean('make_default')) {
                $this->makeDefault($c);
            }
        });

        return response()->json(['success' => 'Connection saved.', 'connection' => $this->row($c->fresh(), $this->usage([$c->id])[$c->id] ?? [])]);
    }

    /**
     * Remove a connection
     *
     * Only one nothing depends on: not the default, no buses linked, and no
     * payment received through it in the last 90 days (tills registered to it
     * deliver to its URL). Otherwise disable it with `enabled: false`.
     *
     * @authenticated
     */
    public function destroy(Request $request, int $connection): JsonResponse
    {
        if (($denied = $this->denyWrite($request)) !== null) {
            return $denied;
        }

        $c = $this->find($request, $connection);
        if ($c instanceof JsonResponse) {
            return $c;
        }

        $reason = match (true) {
            $c->is_default => 'It is the SACCO\'s default connection.',
            Vehicle::withoutGlobalScopes()->where('mpesa_payment_setting_id', $c->id)->exists() => 'Buses are linked to it. Move them to another connection first.',
            DB::table('mpesas')->where('mpesa_setting_id', $c->id)->where('TransTime', '>=', now()->subDays(90))->exists() => 'Payments still arrive through it. Disable it instead.',
            default => null,
        };
        if ($reason !== null) {
            return response()->json(['error' => 'This connection cannot be removed. '.$reason], 409);
        }

        $c->delete();

        return response()->json(['success' => 'Connection removed.']);
    }

    /**
     * Link a bus to a connection
     *
     * Which connection a bus's till sits under. Registering its till and its
     * in-app payments then use that connection. `connection_id: null` unlinks
     * it, so it falls back to the SACCO's default.
     *
     * @authenticated
     *
     * @bodyParam connection_id integer required The connection, or null. Example: 31
     */
    public function linkVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        if (($denied = $this->denyWrite($request)) !== null) {
            return $denied;
        }

        $saccoId = $request->user()->currentSaccoId();
        if ($saccoId !== null && (int) $vehicle->sacco_id !== (int) $saccoId) {
            return response()->json(['error' => 'That vehicle is not in your SACCO.'], 404);
        }

        if (! $request->exists('connection_id')) {
            return response()->json(['errors' => ['connection_id' => ['Send a connection id, or null to unlink.']]], 400);
        }

        $connection = null;
        if ($request->input('connection_id') !== null) {
            $connection = MpesaPaymentSetting::withoutGlobalScopes()->find((int) $request->input('connection_id'));
            if ($connection === null || (int) $connection->sacco_id !== (int) $vehicle->sacco_id) {
                return response()->json(['error' => 'That connection is not one of this SACCO\'s.'], 422);
            }
        }

        $vehicle->forceFill(['mpesa_payment_setting_id' => $connection?->id])->save();

        $effective = $connection ?? MpesaPaymentSetting::defaultFor((int) $vehicle->sacco_id);

        return response()->json([
            'success' => $connection ? 'Bus linked to '.($connection->name ?: $connection->business_short_code).'.' : 'Bus unlinked; it uses the SACCO\'s default connection.',
            'vehicle' => ['id' => (int) $vehicle->id, 'plate' => $vehicle->plate, 'connection_id' => $connection?->id],
            // A till moved to another head office must be registered again
            // under it -- Safaricom still delivers to wherever it last was.
            'registration_needed' => $vehicle->till_registered_url !== null && $effective !== null
                && ! str_ends_with((string) $vehicle->till_registered_url, '/api/confirmation/'.$effective->id),
            'can_take_app_payments' => (bool) $effective?->canTakeAppPayments(),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function row(MpesaPaymentSetting $c, array $usage): array
    {
        return [
            'id' => (int) $c->id,
            'name' => $c->name,
            'business_short_code' => $c->business_short_code,
            'paybill' => $c->paybill,
            'payment_mode' => $c->payment_mode === 'CustomerPayBillOnline' ? 'paybill' : 'buygoods',
            'environment' => $c->is_live ? 'live' : 'sandbox',
            'enabled' => (bool) $c->status,
            'is_default' => (bool) $c->is_default,
            'credentials' => [
                'consumer_key_set' => filled($c->consumer_key),
                'consumer_secret_set' => filled($c->consumer_secret),
                'pass_key_set' => filled($c->pass_key),
            ],
            'can_register_tills' => $c->canRegisterTills(),
            'can_take_app_payments' => $c->canTakeAppPayments(),
            'vehicles_count' => (int) ($usage['vehicles'] ?? 0),
            'registered_tills_count' => (int) ($usage['registered'] ?? 0),
            'payments_7d' => (int) ($usage['payments_7d'] ?? 0),
            'last_payment_at' => isset($usage['last_payment_at']) ? Carbon::parse($usage['last_payment_at'], 'Africa/Nairobi')->toIso8601String() : null,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Usage per connection, in four grouped queries for the whole list.
     * "Payments" are confirmations Safaricom delivered to the connection's
     * URL (mpesas.mpesa_setting_id); TransTime is Nairobi wall-clock.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function usage(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Vehicle::withoutGlobalScopes()->whereIn('mpesa_payment_setting_id', $ids)
            ->selectRaw('mpesa_payment_setting_id id, count(*) n, count(till_registered_at) r')
            ->groupBy('mpesa_payment_setting_id')->get() as $r) {
            $out[(int) $r->id]['vehicles'] = (int) $r->n;
            $out[(int) $r->id]['registered'] = (int) $r->r;
        }

        $weekAgo = Carbon::now('Africa/Nairobi')->subDays(7)->format('Y-m-d H:i:s');
        foreach (DB::table('mpesas')->whereIn('mpesa_setting_id', $ids)->where('TransTime', '>=', $weekAgo)
            ->selectRaw('mpesa_setting_id id, count(*) n')->groupBy('mpesa_setting_id')->get() as $r) {
            $out[(int) $r->id]['payments_7d'] = (int) $r->n;
        }

        foreach ($ids as $id) {
            $last = DB::table('mpesas')->where('mpesa_setting_id', $id)->max('TransTime');
            if ($last !== null) {
                $out[$id]['last_payment_at'] = $last;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:100'],
            'business_short_code' => [$req, 'regex:/^\s*\d{4,10}\s*$/'],
            'paybill' => ['nullable', 'regex:/^\s*\d{4,10}\s*$/'],
            'payment_mode' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(self::MODES))],
            'environment' => ['sometimes', Rule::in(['live', 'sandbox'])],
            'enabled' => ['sometimes', 'boolean'],
            'make_default' => ['sometimes', 'boolean'],
            'consumer_key' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'consumer_secret' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'pass_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function fill(MpesaPaymentSetting $c, Request $request, string $shortCode): void
    {
        if ($request->filled('name')) {
            $c->name = trim((string) $request->name);
        }
        $c->business_short_code = $shortCode;
        if ($request->has('paybill')) {
            $c->paybill = $request->filled('paybill') ? (string) intval($request->paybill) : null;
        }
        if ($request->filled('payment_mode')) {
            $c->payment_mode = self::MODES[$request->payment_mode];
        }
        // Live unless sandbox is asked for: a collecting SACCO pointed at the
        // sandbox sends its pushes nowhere real.
        if ($request->filled('environment')) {
            $c->is_live = $request->input('environment') === 'live';
        } elseif (! $c->exists) {
            $c->is_live = true;
        }
        if ($request->has('enabled')) {
            $c->status = $request->boolean('enabled');
        } elseif (! $c->exists) {
            $c->status = true;
        }
        foreach (['consumer_key', 'consumer_secret', 'pass_key'] as $secret) {
            if (filled($request->input($secret))) {
                $c->{$secret} = trim((string) $request->input($secret));
            }
        }
    }

    /** Make $c the SACCO's only default. */
    private function makeDefault(MpesaPaymentSetting $c): void
    {
        MpesaPaymentSetting::withoutGlobalScopes()
            ->where('sacco_id', $c->sacco_id)->where('id', '!=', $c->id)->where('is_default', true)
            ->update(['is_default' => false]);
        $c->forceFill(['is_default' => true])->save();
    }

    /**
     * One short code, one connection. Two rows for the same head office would
     * give its tills two confirmation URLs and its admins two places to edit
     * the same keys -- in another SACCO, two SACCOs editing one account.
     */
    private function shortCodeClash(string $shortCode, int $saccoId, ?int $exceptId = null): ?JsonResponse
    {
        $other = MpesaPaymentSetting::withoutGlobalScopes()
            ->where('business_short_code', $shortCode)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->first(['id', 'sacco_id']);

        if ($other === null) {
            return null;
        }

        return response()->json([
            'error' => (int) $other->sacco_id === $saccoId
                ? "Short code {$shortCode} is already connected for this SACCO (connection #{$other->id})."
                : "Short code {$shortCode} is already connected on Komiut under another account. Contact Komiut support.",
        ], 409);
    }

    private function find(Request $request, int $id): MpesaPaymentSetting|JsonResponse
    {
        $c = MpesaPaymentSetting::withoutGlobalScopes()->find($id);
        $own = $request->user()->currentSaccoId();

        // Same answer for "missing" and "another SACCO's": do not confirm which.
        if ($c === null || ($own !== null && (int) $c->sacco_id !== (int) $own)) {
            return response()->json(['error' => 'Connection not found.'], 404);
        }

        return $c;
    }

    private function denyWrite(Request $request): ?JsonResponse
    {
        $user = $request->user();

        return $user->can('Add Payment Settings') || $user->can('Edit Payment Settings')
            ? null
            : response()->json(['error' => 'Permission to manage payment settings denied.'], 403);
    }

    /** The form calls the passkey "API key"; Daraja and this table call it pass_key. */
    private function aliasPassKey(Request $request): void
    {
        if ($request->filled('api_key') && ! $request->filled('pass_key')) {
            $request->merge(['pass_key' => $request->input('api_key')]);
        }
    }

    private function saccoId(Request $request): ?int
    {
        $own = $request->user()->currentSaccoId();
        if ($own !== null) {
            return (int) $own;
        }

        return $request->filled('sacco_id') ? (int) $request->input('sacco_id') : null;
    }
}
