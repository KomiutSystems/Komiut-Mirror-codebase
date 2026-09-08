<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Mpesa;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Mpesa\MpesaCredentialResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Point a bus's till at this system — the one thing the legacy payments tier
 * could do that this one could not.
 *
 * WHY IT MATTERS. Safaricom decides where a C2B payment is delivered from what
 * we registered against the shortcode, so until a till is re-registered, its
 * money goes to Mumbai no matter what this system is capable of. Today Frankfurt
 * only sees that money because the legacy tier relays it — best-effort, one
 * second to connect, no retry — which means `payments.komiut.com` cannot be
 * switched off until every till has been moved with this endpoint.
 *
 * IT IS ALSO THE MOST DANGEROUS ENDPOINT HERE. It redirects real money, takes
 * effect immediately, and Safaricom offers no dry run: the only way back is to
 * register the previous URL again. Hence the guards below, and hence recording
 * the URL that was accepted rather than a boolean.
 *
 * THE URL SHAPE IS A CONTRACT, NOT A CHOICE. `/api/confirmation/{setting_id}` is
 * the shape the fleet has been registered against for 1,336,113 payments, and
 * C2bConfirmationController answers it deliberately so a till can be moved by
 * re-registering it alone. The {id} identifies the CREDENTIALS, never the bus —
 * many tills share one Daraja app and therefore one callback URL — so the
 * receiving end attributes by the payload's BusinessShortCode. Registering a
 * per-vehicle URL here would look tidier and would quietly break that.
 */
class TillRegistrationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Register this vehicle's till with Safaricom
     *
     * @authenticated
     */
    public function register(Request $request, Vehicle $vehicle): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('Add Payment Settings') && ! $user->can('Edit Payment Settings')) {
            return response()->json(['error' => 'Permission to manage payment settings denied.'], 403);
        }

        // The tenant boundary. Route-model binding resolves a vehicle by id from
        // the whole fleet, so without this a SACCO admin could re-point another
        // SACCO's till at their own confirmation URL — which is to say, at their
        // own bank account. currentSaccoId() is null for a super admin, who is
        // deliberately not narrowed.
        $saccoId = $user->currentSaccoId();
        if ($saccoId !== null && (int) $vehicle->sacco_id !== (int) $saccoId) {
            return response()->json(['error' => 'That vehicle is not in your SACCO.'], 404);
        }

        $shortCode = trim((string) ($vehicle->merchant_short_code ?? ''));
        if ($shortCode === '') {
            return response()->json([
                'error' => 'This vehicle has no merchant short code. Add one before registering its till.',
            ], 422);
        }

        $setting = MpesaCredentialResolver::settingFor($vehicle);
        $client = MpesaCredentialResolver::registrarFor($vehicle);

        if ($setting === null || $client === null) {
            return response()->json([
                'error' => 'No M-Pesa API credentials for this vehicle or its SACCO. Save them under M-Pesa settings first.',
            ], 422);
        }

        $confirmation = rtrim((string) config('app.url'), '/').'/api/confirmation/'.$setting->id;
        $validation = rtrim((string) config('app.url'), '/').'/api/validation/'.$setting->id;

        $response = $client->registerC2bUrls($shortCode, $confirmation, $validation);

        // Null is "we could not ask", never "it worked". Recording a
        // registration we cannot prove is worse than recording none: the fleet
        // view would show this bus as moved while its money still goes to Mumbai.
        if ($response === null) {
            return response()->json([
                'error' => 'Safaricom could not be reached. The till has NOT been changed — try again.',
            ], 502);
        }

        $code = $response['ResponseCode'] ?? $response['errorCode'] ?? null;

        if ((string) $code !== '0') {
            Log::warning('till registration refused by safaricom', [
                'vehicle_id' => $vehicle->id,
                'short_code' => $shortCode,
                'response' => $response,
            ]);

            return response()->json([
                'error' => 'Safaricom refused the registration.',
                // Passed through verbatim: their message names the real cause
                // ("wrong credentials", "already registered"), and paraphrasing
                // it would leave whoever is standing at the SACCO office guessing.
                'safaricom' => $response,
            ], 422);
        }

        $vehicle->forceFill([
            'till_registered_at' => Carbon::now(),
            'till_registered_url' => $confirmation,
        ])->save();

        Log::info('till registered', [
            'vehicle_id' => $vehicle->id,
            'plate' => $vehicle->plate,
            'short_code' => $shortCode,
            'confirmation_url' => $confirmation,
            'by' => $user->id,
        ]);

        return response()->json([
            'success' => 'Till registered. Payments on this bus now come here.',
            'vehicle' => [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate,
                'merchant_short_code' => $shortCode,
                'till_registered_at' => $vehicle->till_registered_at,
                'till_registered_url' => $confirmation,
            ],
        ]);
    }
}
