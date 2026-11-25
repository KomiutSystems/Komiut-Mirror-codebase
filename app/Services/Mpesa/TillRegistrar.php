<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\MpesaPaymentSetting;
use App\Models\Vehicle;
use App\Services\Ncba\NcbaStkClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registers a bus's till with Safaricom, so its fares are delivered here.
 *
 * WHY THIS IS AUTOMATIC. Until a till's C2B URLs are registered, Safaricom
 * delivers its payments to nobody. NICCO added KEA 742C, 743C and 744C on
 * 2026-11-06 and they collected for 19 days with nothing recorded, because
 * registering was a separate button nobody knew to press. A bus is now
 * registered the moment it is saved with a till (VehicleTillObserver ->
 * RegisterVehicleTill), and the outcome is written on the bus for the SACCO's
 * own screen. The dashboard's "Register till" button runs the same path.
 *
 * NEVER GUESS THE HEAD OFFICE. A till must be registered with the keys of the
 * Daraja app it sits under, and Safaricom keeps the FIRST registration of a
 * till: production answers any later attempt "URLs are already registered"
 * (500.003.1001, seen 2026-11-25). So a wrong guess cannot be taken back. The
 * connection used is, in order: the bus's own; the one its existing
 * registration names; or -- only when the SACCO has exactly one live
 * connection -- that one. A SACCO with several (NICCO has 27; its default,
 * 7071220, was wrong for every one of the November buses) gets "choose the
 * head office".
 *
 * NOT EVERY TILL IS OURS TO REGISTER. A bus on NCBA's paybill 880100, one
 * whose bank account is on record and no head office chosen, or one in a SACCO
 * with no live M-Pesa connection (a bank's tills, Co-op's for MetroTrans), is
 * delivered by its bank. Those are left alone.
 */
final class TillRegistrar
{
    public const REGISTERED = 'registered';

    public const PENDING = 'pending';

    public const NEEDS_HEAD_OFFICE = 'needs_head_office';

    public const REFUSED = 'refused';

    public const ALREADY_REGISTERED = 'already_registered';

    public const NO_SHORT_CODE = 'no_short_code';

    public const NO_KEYS = 'no_keys';

    public const NOT_LIVE = 'not_live';

    public const BAD_KEYS = 'bad_keys';

    public const UNREACHABLE = 'unreachable';

    public const GAVE_UP = 'gave_up';

    public const NOT_OURS = 'not_ours';

    /** A till the bank issued and applied (TillRequestsController::apply). */
    public const BANK_ISSUED = 'bank_issued';

    /**
     * The till or head office changed while Safaricom was being asked. Never
     * written on the bus: whoever asked tries again with the new values.
     */
    public const SUPERSEDED = 'superseded';

    /** The till changed on a bus whose head office nobody confirmed for it. */
    public const CONFIRM_HEAD_OFFICE = 'confirm_head_office';

    /** What each status tells whoever is looking at the bus. */
    public const MESSAGES = [
        self::REGISTERED => 'Registered with Safaricom. Payments on this bus come to Komiut.',
        self::PENDING => 'Registering this till with Safaricom.',
        self::NEEDS_HEAD_OFFICE => 'Choose which M-Pesa head office this till belongs to, so it can be registered with Safaricom.',
        self::REFUSED => 'Safaricom refused to register this till. Check the store number and head office.',
        self::ALREADY_REGISTERED => 'Safaricom says this till was registered before. If its payments do not show here once the bus collects, contact Komiut support.',
        self::NO_SHORT_CODE => 'Add the till\'s store number (merchant short code) to register it.',
        self::NO_KEYS => 'This head office has no M-Pesa API keys saved. Add them under M-Pesa settings.',
        self::NOT_LIVE => 'This head office is set to the Safaricom test system. Switch it to live under M-Pesa settings to register tills.',
        self::BAD_KEYS => 'Safaricom rejected this head office\'s M-Pesa API keys. Re-enter them under M-Pesa settings, then press Register till.',
        self::UNREACHABLE => 'Safaricom could not be reached. Komiut will keep trying.',
        self::GAVE_UP => 'Safaricom could not be reached. Press Register till to try again.',
        self::NOT_OURS => 'Payments on this till are delivered by its bank, so Komiut does not register it with Safaricom.',
        self::BANK_ISSUED => 'This till was issued by the bank, which delivers its payments. Komiut does not register it with Safaricom.',
        self::CONFIRM_HEAD_OFFICE => 'The till number changed. Check which M-Pesa head office it belongs to, then press Register till.',
    ];

    /** Safaricom's answer to registering a till whose URLs are already set. */
    private const ALREADY_REGISTERED_CODE = '500.003.1001';

    /**
     * Is this a till WE register? A bank-delivered bus is not. A head office
     * chosen for the bus outranks every inference below: that is a person
     * saying "this till is ours, under this app".
     */
    public function isOurs(Vehicle $vehicle): bool
    {
        return $this->bankDelivers($vehicle) === null
            && ($this->ownConnection($vehicle) !== null || $this->connections($vehicle) !== []);
    }

    /**
     * Why the BANK delivers this bus's payments, or null when it does not.
     * Checked before any head-office link: a link can be filled in by an
     * earlier registration, and a bank's till must never be sent to Safaricom
     * under the SACCO's own app -- the first registration is permanent.
     */
    public function bankDelivers(Vehicle $vehicle): ?string
    {
        if ($this->shortCode($vehicle) === app(NcbaStkClient::class)->paybill()) {
            return self::NOT_OURS;
        }
        // A bank collection account on record: the bank delivers this bus.
        if (filled($vehicle->coop_till) || filled($vehicle->ncba_till)) {
            return self::NOT_OURS;
        }
        // The bank's own till, still the one on the bus.
        if ($vehicle->till_registration_status === self::BANK_ISSUED
            && $this->shortCode($vehicle) === (string) intval((string) $vehicle->till_number)) {
            return self::BANK_ISSUED;
        }

        return null;
    }

    /**
     * The head office this till is registered under: the bus's own link; the
     * one its existing registration here names; or the SACCO's only live
     * connection. Null when it cannot be known without guessing.
     */
    public function connectionFor(Vehicle $vehicle): ?MpesaPaymentSetting
    {
        if (($own = $this->ownConnection($vehicle)) !== null) {
            return $own;
        }
        if (($named = $this->registeredUnder($vehicle)) !== null) {
            return $named;
        }

        $all = $this->connections($vehicle);

        return count($all) === 1 ? $all[0] : null;
    }

    /**
     * What registering would do, without calling Safaricom: the status to show
     * while it is queued, or the reason it cannot go at all.
     *
     * $force skips "already registered here": the dashboard button always
     * asks Safaricom, because a person pressing it wants an answer, not our
     * memory of one.
     */
    public function precheck(Vehicle $vehicle, bool $force = false): string
    {
        if ($this->shortCode($vehicle) === '') {
            return self::NO_SHORT_CODE;
        }

        $connection = $this->connectionFor($vehicle);
        if ($connection === null) {
            return self::NEEDS_HEAD_OFFICE;
        }
        if (! $connection->canRegisterTills()) {
            return self::NO_KEYS;
        }
        if (! $connection->is_live) {
            return self::NOT_LIVE;
        }

        return ! $force && $this->alreadyOurs($vehicle, $connection) ? self::REGISTERED : self::PENDING;
    }

    /**
     * Register the till and write the outcome on the bus.
     *
     * $willRetry: whether whoever is asking will try again if Safaricom cannot
     * be reached -- the queued job, until it runs out. The note on the bus
     * must not promise a retry nobody will make.
     *
     * @return array{status: string, message: string, safaricom: array<string, mixed>|null, confirmation_url: ?string}
     */
    public function register(Vehicle $vehicle, bool $force = false, bool $willRetry = false): array
    {
        $status = $this->precheck($vehicle, $force);
        $connection = $this->connectionFor($vehicle);

        if ($status !== self::PENDING) {
            return $this->record($vehicle, $status, null, $status === self::REGISTERED ? (string) $vehicle->till_registered_url : null);
        }

        $code = $this->shortCode($vehicle);
        $base = rtrim((string) config('app.url'), '/');
        $confirmation = $base.'/api/confirmation/'.$connection->id;
        $validation = $base.'/api/validation/'.$connection->id;

        $client = new DarajaClient(
            (string) $connection->consumer_key,
            (string) $connection->consumer_secret,
            (string) ($connection->business_short_code ?? ''),
            (string) ($connection->pass_key ?? ''),
            (bool) $connection->is_live,
        );
        $response = $client->registerC2bUrls($code, $confirmation, $validation);

        // Asking took up to 45 seconds. If the bus was given another till or
        // head office meanwhile, this answer is about a till it no longer has:
        // write nothing, and let the caller ask again about the new one.
        $now = Vehicle::withoutGlobalScopes()->find($vehicle->id);
        if ($now === null || $this->shortCode($now) !== $code
            || (int) ($now->mpesa_payment_setting_id ?? 0) !== (int) ($vehicle->mpesa_payment_setting_id ?? 0)) {
            return ['status' => self::SUPERSEDED, 'message' => '', 'safaricom' => $response, 'confirmation_url' => null];
        }

        // Null is "we could not ask", never "it worked" -- unless the keys
        // themselves were refused, which no retry will fix.
        if ($response === null) {
            if ($client->lastTokenFailure === DarajaClient::TOKEN_REFUSED) {
                return $this->record($vehicle, self::BAD_KEYS, null, null);
            }

            return $this->record($vehicle, $willRetry ? self::UNREACHABLE : self::GAVE_UP, null, null);
        }

        $answer = (string) ($response['ResponseCode'] ?? $response['errorCode'] ?? '');

        // SUCCESS IS ANY RUN OF ZEROS: c2b/v2 answers "00000000".
        if (preg_match('/^0+$/', $answer) === 1) {
            $vehicle->forceFill([
                'till_registered_at' => Carbon::now(),
                'till_registered_url' => $confirmation,
                'till_registered_short_code' => $code,
                // The head office that just proved it owns this till.
                'mpesa_payment_setting_id' => $vehicle->mpesa_payment_setting_id ?? $connection->id,
            ]);

            Log::info('till registered', [
                'vehicle_id' => $vehicle->id, 'plate' => $vehicle->plate,
                'short_code' => $code, 'confirmation_url' => $confirmation,
            ]);

            return $this->record($vehicle, self::REGISTERED, $response, $confirmation);
        }

        // Registered before. Ours if our own record of registering it says so,
        // or its payments have ever reached us. Otherwise we do not know --
        // a reply lost on an earlier attempt looks exactly like this -- and the
        // bus says so rather than accusing anyone.
        if ($answer === self::ALREADY_REGISTERED_CODE || stripos((string) ($response['errorMessage'] ?? ''), 'already registered') !== false) {
            $ours = $this->registeredUnder($vehicle) !== null;

            return $this->record($vehicle, $ours || $this->deliversHere($code, $connection) ? self::REGISTERED : self::ALREADY_REGISTERED, $response, null);
        }

        Log::warning('till registration refused by safaricom', [
            'vehicle_id' => $vehicle->id, 'short_code' => $code, 'response' => $response,
        ]);

        return $this->record($vehicle, self::REFUSED, $response, null);
    }

    /** Write that this till is not ours to register, if the bus said otherwise. */
    public function markNotOurs(Vehicle $vehicle): void
    {
        if (! in_array($vehicle->till_registration_status, [null, self::NOT_OURS, self::BANK_ISSUED], true)) {
            $this->record($vehicle, $this->bankDelivers($vehicle) ?? self::NOT_OURS, null, null);
        }
    }

    /** Write a status that needs no call to Safaricom (the observer's early answers). */
    public function note(Vehicle $vehicle, string $status): void
    {
        $this->record($vehicle, $status, null, null);
    }

    /** Write a final word when the queue has given up on Safaricom. */
    public function giveUp(Vehicle $vehicle): void
    {
        if (in_array($vehicle->till_registration_status, [self::PENDING, self::UNREACHABLE], true)) {
            $this->record($vehicle, self::GAVE_UP, null, null);
        }
    }

    /**
     * @param  array<string, mixed>|null  $safaricom
     * @return array{status: string, message: string, safaricom: array<string, mixed>|null, confirmation_url: ?string}
     */
    private function record(Vehicle $vehicle, string $status, ?array $safaricom, ?string $confirmation): array
    {
        $said = $safaricom === null ? null : (string) ($safaricom['errorMessage'] ?? $safaricom['ResponseDescription'] ?? '');
        $note = $status === self::REFUSED && filled($said) ? 'Safaricom: '.$said : (self::MESSAGES[$status] ?? null);
        $askedSafaricom = ! in_array($status, [self::NO_SHORT_CODE, self::NEEDS_HEAD_OFFICE, self::CONFIRM_HEAD_OFFICE, self::NO_KEYS, self::NOT_LIVE, self::NOT_OURS, self::BANK_ISSUED, self::PENDING], true);

        $vehicle->forceFill([
            'till_registration_status' => $status,
            'till_registration_note' => $note === null ? null : mb_substr($note, 0, 255),
            'till_registration_attempted_at' => $askedSafaricom ? Carbon::now() : $vehicle->till_registration_attempted_at,
        ]);

        // Written with a query, not a save: these columns are the observer's
        // OUTPUT, not a change to react to -- and re-saving the instance an
        // observer is looking at would reset its record of what changed, so
        // the observers after this one (CrossSaccoPlateObserver) would miss
        // the plate or SACCO change they exist to catch.
        $dirty = $vehicle->getDirty();
        if ($dirty !== []) {
            Vehicle::withoutGlobalScopes()->whereKey($vehicle->getKey())->update($dirty);
            $vehicle->syncOriginalAttributes(array_keys($dirty));
        }

        return ['status' => $status, 'message' => (string) $note, 'safaricom' => $safaricom, 'confirmation_url' => $confirmation];
    }

    /** Registered here, for THIS till, through this head office. */
    private function alreadyOurs(Vehicle $vehicle, MpesaPaymentSetting $connection): bool
    {
        $ours = rtrim((string) config('app.url'), '/').'/api/confirmation/'.$connection->id;

        return $vehicle->till_registered_at !== null
            && $vehicle->till_registered_url === $ours
            && (string) $vehicle->till_registered_short_code === $this->shortCode($vehicle);
    }

    /** The bus's own head office link, when it still exists. */
    private function ownConnection(Vehicle $vehicle): ?MpesaPaymentSetting
    {
        return $vehicle->mpesa_payment_setting_id === null
            ? null
            : MpesaPaymentSetting::withoutGlobalScopes()->find($vehicle->mpesa_payment_setting_id);
    }

    /**
     * The connection our own record of registering THIS till names, if any.
     * A record for the bus's previous till says nothing about the one it has
     * now: that till may sit under another head office.
     */
    private function registeredUnder(Vehicle $vehicle): ?MpesaPaymentSetting
    {
        $base = preg_quote(rtrim((string) config('app.url'), '/'), '#');
        if ($vehicle->till_registered_at === null
            || (string) $vehicle->till_registered_short_code !== $this->shortCode($vehicle)
            || preg_match('#^'.$base.'/api/confirmation/(\d+)$#', (string) $vehicle->till_registered_url, $m) !== 1) {
            return null;
        }

        return MpesaPaymentSetting::withoutGlobalScopes()->find((int) $m[1]);
    }

    /**
     * Has Safaricom delivered this till's payments to OUR confirmation URL?
     *
     * Only rows that came in through /api/confirmation/{id} carry a
     * connection id; pulled, imported and bank-fed rows do not, and say
     * nothing about where Safaricom delivers. And only since 2026-10-08: until
     * then the legacy tier received confirmations at the same URL shape.
     */
    private function deliversHere(string $code, MpesaPaymentSetting $connection): bool
    {
        return DB::table('mpesas')
            ->where('BusinessShortCode', $code)
            ->whereNotNull('mpesa_setting_id')
            ->where('created_at', '>=', Carbon::now()->subDays(90)->max(Carbon::parse('2026-10-08')))
            ->exists();
    }

    /** More than one head office this bus's till could sit under. */
    public function hasSeveralConnections(Vehicle $vehicle): bool
    {
        return count($this->connections($vehicle)) > 1;
    }

    /** Our own record says this exact till is registered here. */
    public function isRegisteredHere(Vehicle $vehicle): bool
    {
        return $this->registeredUnder($vehicle) !== null;
    }

    /**
     * The SACCO's LIVE connections with keys: a disabled or test-system app is
     * never one a till is registered through by inference.
     *
     * @return list<MpesaPaymentSetting>
     */
    private function connections(Vehicle $vehicle): array
    {
        if ($vehicle->sacco_id === null) {
            return [];
        }

        return MpesaPaymentSetting::withoutGlobalScopes()
            ->where('sacco_id', $vehicle->sacco_id)
            ->where('is_live', true)
            ->where('status', true)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function shortCode(Vehicle $vehicle): string
    {
        // As Safaricom wants it: no spaces, no leading zero (see DarajaClient).
        // "0" and blanks are no till at all.
        $code = intval(preg_replace('/\s+/', '', (string) ($vehicle->merchant_short_code ?? '')));

        return $code > 0 ? (string) $code : '';
    }
}
