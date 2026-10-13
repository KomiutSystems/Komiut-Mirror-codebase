<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Mpesa;

use App\Http\Controllers\Controller;
use App\Http\Resources\MpesaPaymentSettingResource;
use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A SACCO's M-Pesa collection credentials, for the payments web dashboard.
 *
 * Credentials are SHARED per SACCO (each vehicle differs only by its own
 * till/merchant, set on the vehicle itself). Secrets are write-only: they are
 * accepted here, encrypted at rest, and never returned — the read side reports
 * only whether each is configured.
 *
 * `pass_key` is what Daraja and this table call it; the dashboard labels it
 * API Key, so `api_key` is accepted as an alias on write.
 */
class PaymentSettingsController extends Controller
{
    /** The two Daraja product modes, plus friendly aliases the dashboard may send. */
    private const MODES = [
        'paybill' => 'CustomerPayBillOnline',
        'buygoods' => 'CustomerBuyGoodsOnline',
        'CustomerPayBillOnline' => 'CustomerPayBillOnline',
        'CustomerBuyGoodsOnline' => 'CustomerBuyGoodsOnline',
    ];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /** The current SACCO's settings, masked. `null` when none configured yet. */
    public function show(Request $request): JsonResponse
    {
        $saccoId = $this->saccoId($request);
        if ($saccoId === null) {
            return response()->json(['error' => 'No SACCO context.'], 422);
        }

        // The SACCO's DEFAULT connection. Its other connections are managed
        // through mpesa/connections; this single-setting endpoint stays for the
        // form that edits "the SACCO's M-Pesa connection".
        $setting = MpesaPaymentSetting::defaultFor($saccoId);

        return response()->json([
            'setting' => $setting ? new MpesaPaymentSettingResource($setting) : null,
        ]);
    }

    /** Create or update the SACCO's settings. Secrets are updated only when sent. */
    public function upsert(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('Add Payment Settings') && ! $user->can('Edit Payment Settings')) {
            return response()->json(['error' => 'Permission to manage payment settings denied.'], 403);
        }

        $saccoId = $this->saccoId($request);
        if ($saccoId === null) {
            return response()->json(['error' => 'No SACCO context.'], 422);
        }

        $existing = MpesaPaymentSetting::defaultFor($saccoId);

        // On first save every credential is required; on edit they may be omitted
        // to keep the stored (encrypted) value, since the form never echoes them.
        $req = $existing ? 'nullable' : 'required';

        // `api_key` is what the dashboard labels this field; `pass_key` is what
        // Daraja and this table call it. Accept either so the form and the
        // schema can keep their own vocabulary.
        if ($request->filled('api_key') && ! $request->filled('pass_key')) {
            $request->merge(['pass_key' => $request->input('api_key')]);
        }

        $validator = Validator::make($request->all(), [
            'business_short_code' => 'required|string|max:20',
            'paybill' => 'nullable|string|max:20',
            'payment_mode' => ['required', Rule::in(array_keys(self::MODES))],
            // Which Daraja host this SACCO's pushes go to. See is_live below.
            'environment' => ['sometimes', Rule::in(['live', 'sandbox'])],
            'is_live' => 'sometimes|boolean',
            'status' => 'sometimes|boolean',
            'consumer_key' => $req.'|string',
            'consumer_secret' => $req.'|string',
            'pass_key' => $req.'|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // The same two rules as mpesa/connections, which manages the same
        // rows: one short code per connection platform-wide (a SACCO whose
        // connections were all assigned has no default yet, so this would
        // otherwise create a second row for a head office it already holds),
        // and no change to what another SACCO's buses pay through.
        if (($clash = MpesaConnectionsController::shortCodeClash((string) $request->business_short_code, $saccoId, $existing?->id)) !== null) {
            return $clash;
        }
        if ($existing !== null && ! $user->isSuperAdmin() && $existing->isSharedWithOtherSaccos()) {
            return MpesaConnectionsController::sharedRefusal();
        }

        $setting = $existing ?: new MpesaPaymentSetting;
        $setting->sacco_id = $saccoId;
        $setting->business_short_code = $request->business_short_code;
        $setting->paybill = $request->paybill;
        $setting->payment_mode = self::MODES[$request->payment_mode];

        // Sandbox vs Live is a REAL switch, not a label: DarajaClient and
        // MpesaPaymentsController both derive the host from it
        // (api.safaricom.co.ke vs sandbox.safaricom.co.ke). Pointing a SACCO
        // that is collecting money at sandbox sends its STK pushes nowhere real,
        // so it defaults to live and has to be asked for explicitly.
        if ($request->filled('environment')) {
            $setting->is_live = $request->input('environment') === 'live';
        } elseif ($request->has('is_live')) {
            $setting->is_live = $request->boolean('is_live');
        } else {
            $setting->is_live = $existing?->is_live ?? true;
        }

        $setting->status = $request->boolean('status', true);

        // Only overwrite a secret when a fresh value is provided.
        foreach (['consumer_key', 'consumer_secret', 'pass_key'] as $secret) {
            if (filled($request->input($secret))) {
                $setting->{$secret} = $request->input($secret);
            }
        }

        $setting->save();

        return response()->json([
            'success' => 'M-Pesa settings saved.',
            'setting' => new MpesaPaymentSettingResource($setting),
        ]);
    }

    /**
     * The SACCO whose settings are being managed: the caller's own SACCO, or an
     * explicit sacco_id for a superadmin who has no home SACCO.
     */
    /**
     * The caller's own SACCO; a super admin may name one with `sacco_id`.
     *
     * Anyone else without a SACCO (a bank user, an investor) has none. This
     * used to accept `sacco_id` from any of them and lean on SaccoScope to
     * narrow the read to nothing -- but the setting is now read unscoped, by
     * MpesaPaymentSetting::defaultFor(), the same row the resolver uses.
     */
    private function saccoId(Request $request): ?int
    {
        $user = $request->user();
        if ($user->currentSaccoId() !== null) {
            return (int) $user->currentSaccoId();
        }

        if (! $user->isSuperAdmin() || ! $request->filled('sacco_id')) {
            return null;
        }

        return Sacco::query()->whereKey((int) $request->input('sacco_id'))->exists() ? (int) $request->input('sacco_id') : null;
    }
}
