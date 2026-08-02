<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Mpesa;

use App\Http\Controllers\Controller;
use App\Http\Resources\MpesaPaymentSettingResource;
use App\Models\MpesaPaymentSetting;
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
 * only whether each is configured. is_live is always true; there is no sandbox.
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

        $setting = MpesaPaymentSetting::where('sacco_id', $saccoId)->first();

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

        $existing = MpesaPaymentSetting::where('sacco_id', $saccoId)->first();

        // On first save every credential is required; on edit they may be omitted
        // to keep the stored (encrypted) value, since the form never echoes them.
        $req = $existing ? 'nullable' : 'required';
        $validator = Validator::make($request->all(), [
            'business_short_code' => 'required|string|max:20',
            'paybill' => 'nullable|string|max:20',
            'payment_mode' => ['required', Rule::in(array_keys(self::MODES))],
            'status' => 'sometimes|boolean',
            'consumer_key' => $req.'|string',
            'consumer_secret' => $req.'|string',
            'pass_key' => $req.'|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $setting = $existing ?: new MpesaPaymentSetting;
        $setting->sacco_id = $saccoId;
        $setting->business_short_code = $request->business_short_code;
        $setting->paybill = $request->paybill;
        $setting->payment_mode = self::MODES[$request->payment_mode];
        $setting->is_live = true; // real credentials only — no sandbox
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
    private function saccoId(Request $request): ?int
    {
        $own = $request->user()->currentSaccoId();
        if ($own !== null) {
            return (int) $own;
        }

        return $request->filled('sacco_id') ? (int) $request->input('sacco_id') : null;
    }
}
