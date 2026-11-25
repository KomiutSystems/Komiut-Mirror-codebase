<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Mpesa;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 * effect immediately, and Safaricom offers no dry run. Since legacy was
 * switched off (2026-10-07) there is no other system to point a till back at,
 * so this only ever registers THIS system. Hence the guards below, and hence
 * recording the URL that was accepted rather than a boolean.
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

        // WHERE TO SEND THE MONEY: this system, and nowhere else.
        //
        // There used to be a second choice, `legacy`, which pointed a till back
        // at the Mumbai payments tier. Legacy was switched off on 2026-10-07,
        // and the Mumbai host it registered no longer resolves: choosing it
        // would have sent a bus's fares to a dead address, silently, from one
        // click. Asked for by name it is REFUSED, not quietly turned into
        // `here` -- a caller who meant "roll back" must be told there is
        // nothing to roll back to.
        //
        // A caller-supplied URL is refused outright, because "register this
        // till to an arbitrary host" is "redirect this bus's income to an
        // arbitrary host", and no permission on this platform should grant that.
        $destination = (string) $request->input('destination', 'here');
        if ($destination === 'legacy') {
            return response()->json([
                'error' => 'The legacy payments server has been switched off. A till can only be registered to this system.',
            ], 422);
        }
        if ($destination !== 'here') {
            return response()->json(['error' => 'destination must be "here".'], 422);
        }

        // The same registrar a newly saved bus goes through on its own
        // (VehicleTillObserver): one set of rules for the button and the
        // automatic path, including never guessing the head office.
        $registrar = app(TillRegistrar::class);

        if (trim((string) ($vehicle->merchant_short_code ?? '')) === '' || intval($vehicle->merchant_short_code) <= 0) {
            return response()->json([
                'error' => 'This vehicle has no merchant short code. Add one before registering its till.',
            ], 422);
        }
        // The bank delivers this bus: its till is never registered here.
        if (($bank = $registrar->bankDelivers($vehicle)) !== null) {
            return response()->json([
                'error' => TillRegistrar::MESSAGES[$bank],
                'reason' => $bank,
            ], 422);
        }
        if (! $registrar->isOurs($vehicle)) {
            return response()->json([
                'error' => 'No M-Pesa API credentials for this vehicle or its SACCO. Save them under M-Pesa settings first.',
            ], 422);
        }

        // force: a person pressing the button gets Safaricom's answer, not our
        // memory of an earlier one.
        $outcome = $registrar->register($vehicle, force: true);
        $vehicle->refresh();

        return match ($outcome['status']) {
            TillRegistrar::REGISTERED => response()->json([
                'success' => $outcome['confirmation_url'] !== null
                    ? 'Till registered. Payments on this bus now come here.'
                    : 'This till was already registered here. Payments on this bus come here.',
                'destination' => $destination,
                'vehicle' => [
                    'id' => $vehicle->id,
                    'plate' => $vehicle->plate,
                    'merchant_short_code' => $vehicle->merchant_short_code,
                    'till_registered_at' => $vehicle->till_registered_at,
                    'till_registered_url' => $vehicle->till_registered_url,
                    'till_registration_status' => $vehicle->till_registration_status,
                ],
            ]),
            // Null from Safaricom is "we could not ask", never "it worked".
            TillRegistrar::UNREACHABLE, TillRegistrar::GAVE_UP => response()->json([
                'error' => 'Safaricom could not be reached. The till has NOT been changed — try again.',
            ], 502),
            TillRegistrar::NEEDS_HEAD_OFFICE => response()->json([
                'error' => $outcome['message'],
                'reason' => TillRegistrar::NEEDS_HEAD_OFFICE,
            ], 422),
            TillRegistrar::SUPERSEDED => response()->json([
                'error' => 'This bus\'s till changed while it was being registered. Press Register till again.',
                'reason' => TillRegistrar::SUPERSEDED,
            ], 409),
            TillRegistrar::ALREADY_REGISTERED => response()->json([
                'error' => $outcome['message'],
                'reason' => TillRegistrar::ALREADY_REGISTERED,
                'safaricom' => $outcome['safaricom'],
            ], 409),
            // Safaricom's own words passed through: they name the real cause.
            default => response()->json(array_filter([
                'error' => $outcome['status'] === TillRegistrar::REFUSED ? 'Safaricom refused the registration.' : $outcome['message'],
                'reason' => $outcome['status'],
                'safaricom' => $outcome['safaricom'],
            ], fn ($v) => $v !== null), 422),
        };
    }
}
