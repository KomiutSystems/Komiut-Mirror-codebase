<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Auth\Roles;
use App\Enums\BankPartner;
use App\Enums\UserType;
use App\Models\DriverBankLead;
use App\Models\Sacco;
use App\Models\User;
use App\Services\Sacco\SaccoDirectory;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Signing a matatu driver up on the street, in one transaction.
 *
 * A marketing agent does this standing at a stage: the driver is present, their
 * SACCO usually is not, and there is no time for anything the driver cannot
 * answer from memory. Everything downstream — the account, the SACCO directory
 * entry, the vehicle, the assignment, the bank lead — is derived from that one
 * conversation, so it either all lands or none of it does.
 *
 * The driver leaves with no credentials to remember: they sign in afterwards
 * with the phone number given here and the plate on the vehicle (see
 * DriverAuthController). There is deliberately no OTP.
 */
final class DriverOnboarding
{
    public function __construct(
        private readonly SaccoDirectory $directory,
        private readonly VehicleAssignment $assignments,
    ) {}

    /**
     * @param  array{
     *     firstname: string, lastname: string, phone: string, id_number: string,
     *     email?: string|null, sacco_id?: int|string|null, sacco_name?: string|null,
     *     plate: string, vehicle_capacity?: int|string|null,
     *     bank_opt_in?: bool|null, preferred_branch?: string|null,
     *     account_number?: string|null, bank_consent?: bool|null,
     *     consent_text_version?: string|null, agent_identifier?: string|null,
     *     consent_ip?: string|null
     * }  $input  Already validated by the caller.
     * @return User The driver, with `sacco` and the vehicle they were put on.
     *
     * @throws PlateNotAvailable
     * @throws PhoneNotOnboardable
     * @throws ValidationException
     */
    public function onboard(array $input): User
    {
        return DB::transaction(function () use ($input): User {
            $sacco = $this->resolveSacco($input);
            $driver = $this->resolveDriver($input, $sacco);
            $vehicle = $this->assignments->resolveOrCreate((string) $input['plate'], $sacco);

            $this->assignments->assign($driver, $vehicle);
            $this->grantDriverRole($driver);

            // Defaults to TRUE now. Onboarding is an NCBA drive: the agent no
            // longer asks whether the driver wants an account, only which branch
            // suits them, so every sign-up is a lead. An older client that still
            // sends bank_opt_in=false is still honoured.
            //
            // This is why the portal showed 2 of 5 — three drivers were onboarded
            // with the box unticked and never reached the bank at all.
            if (filter_var($input['bank_opt_in'] ?? true, FILTER_VALIDATE_BOOL)) {
                $this->recordBankLead($driver, $input);
            }

            $driver->load('sacco');
            // Not a declared relation on User — a driver has many assignments over
            // time, only one of which is today's. Carried on the model so the
            // controller can shape the response without re-querying.
            $driver->setRelation('vehicle', $vehicle);

            return $driver;
        });
    }

    /**
     * The SACCO the driver named: an existing one they picked from the
     * type-ahead, or a new directory entry submitted on their word alone.
     */
    private function resolveSacco(array $input): Sacco
    {
        $id = $input['sacco_id'] ?? null;

        if ($id !== null && $id !== '') {
            // Brand-scoped find: an id from another brand is not "ours" even
            // though `exists:saccos,id` accepted it (the validator queries raw).
            $sacco = Sacco::find((int) $id);

            if ($sacco !== null) {
                return $sacco;
            }

            if (blank($input['sacco_name'] ?? null)) {
                throw ValidationException::withMessages(['sacco_id' => 'Unknown SACCO.']);
            }
        }

        return $this->directory->resolveOrSubmit((string) $input['sacco_name']);
    }

    /**
     * The driver's account, reused if we already know this phone.
     *
     * Re-onboarding happens constantly: a second agent works the same stage, or
     * the driver changes vehicle and gets signed up again. `users.phone` is
     * unique, so the phone is the identity — a second account would split the
     * driver's history and lock them out of the vehicle they are standing next to.
     */
    private function resolveDriver(array $input, Sacco $sacco): User
    {
        $phone = trim((string) $input['phone']);
        $existing = User::where('phone', $phone)->first();

        if ($existing === null) {
            return $this->createDriver($input, $sacco, $phone);
        }

        return $this->refreshDriver($existing, $input, $sacco);
    }

    private function createDriver(array $input, Sacco $sacco, string $phone): User
    {
        return User::create([
            'firstname' => trim((string) $input['firstname']),
            'lastname' => trim((string) $input['lastname']),
            'email' => $input['email'] ?? null,
            'phone' => $phone,
            'id_number' => trim((string) $input['id_number']),
            'type' => UserType::Driver,
            'sacco_id' => $sacco->id,
            'status' => true,
            // Drivers authenticate with phone + plate and never see a password,
            // but users.password is a real column. An unguessable value keeps
            // password login closed rather than leaving the field empty.
            'password' => Str::random(40),
        ]);
    }

    /**
     * Take only what the driver did not already have on file: a hurried second
     * capture must not overwrite a name or email recorded correctly the first
     * time.
     *
     * The SACCO used to be the exception, on the reasoning that an agent
     * standing with the driver today knows where they work better than an older
     * record does. That is true of an agent — and this endpoint cannot tell an
     * agent from anyone else. It is public, it has to be (neither the driver nor
     * their SACCO has an account yet), and it matches on a phone number, which
     * is not a secret. So the write was reachable by anyone: post a phone with
     * any SACCO name and that account moved to that SACCO, came back on if it
     * had been switched off, and became a Driver.
     *
     * An existing account is now only adopted when there is nothing to take.
     * Everything else is a 409 naming the person who can resolve it — see
     * assertAdoptable().
     */
    private function refreshDriver(User $driver, array $input, Sacco $sacco): User
    {
        $this->assertAdoptable($driver, $sacco);

        $fillable = [
            'firstname' => trim((string) $input['firstname']),
            'lastname' => trim((string) $input['lastname']),
            'email' => $input['email'] ?? null,
            'id_number' => trim((string) $input['id_number']),
        ];

        foreach ($fillable as $column => $value) {
            if (blank($driver->getAttribute($column)) && filled($value)) {
                $driver->setAttribute($column, $value);
            }
        }

        // Safe by the time we get here: either it was null, or it already is
        // this SACCO. Never a move between SACCOs.
        $driver->sacco_id = $sacco->id;

        // `status` is deliberately NOT set. It was set to true unconditionally,
        // which made deactivating a driver pointless — anyone could undo it by
        // re-onboarding the number. A deactivated account does not reach here at
        // all now, and an active one needs no help staying active.

        // Promote a passenger who now drives; never demote a SACCO admin, whose
        // type carries dashboard capabilities this flow has no business changing.
        if ($driver->type === UserType::Passenger) {
            $driver->type = UserType::Driver;
        }

        $driver->save();

        return $driver;
    }

    /**
     * May this public, unauthenticated flow write to an account that already
     * exists?
     *
     * Only when there is nothing behind it to take:
     *
     *   - it must be a driver or a passenger. Staff accounts — SACCO admins,
     *     conductors, queue supervisors, superadmins — are never touched;
     *     silently reassigning a SACCO admin would move them out of their own
     *     SACCO, taking their dashboard with them.
     *   - it must be active. Re-onboarding used to switch an account back on,
     *     so a suspension was reversible by anyone who knew the number.
     *   - it must have no SACCO yet, or already be at the SACCO the agent named.
     *     A driver genuinely changing SACCO is a real case and it is now a
     *     dashboard action, because from here it is indistinguishable from
     *     someone typing a stranger's phone number into their own SACCO.
     *
     * @throws PhoneNotOnboardable
     */
    private function assertAdoptable(User $driver, Sacco $sacco): void
    {
        if (! in_array($driver->type, [UserType::Driver, UserType::Passenger], true)) {
            throw PhoneNotOnboardable::isNotADriverAccount();
        }

        // Belt and braces with the type check, which is one column and can lag
        // reality: an account carrying dashboard capability is not the blank
        // slate this flow assumes, whatever its `type` says.
        //
        // DIRECT permissions and NON-Driver roles only. getAllPermissions()
        // would be wrong here — it resolves through roles, and every driver
        // already onboarded holds the Driver role and its bundle, so it would
        // reject the ordinary re-onboarding this method exists to serve.
        $extraRoles = $driver->getRoleNames()->reject(fn ($name) => $name === Roles::DRIVER);

        if ($extraRoles->isNotEmpty() || $driver->getDirectPermissions()->isNotEmpty()) {
            throw PhoneNotOnboardable::isNotADriverAccount();
        }

        if (! $driver->status) {
            throw PhoneNotOnboardable::isDeactivated();
        }

        if ($driver->sacco_id !== null && (int) $driver->sacco_id !== (int) $sacco->id) {
            throw PhoneNotOnboardable::belongsToAnotherSacco();
        }
    }

    /**
     * Without this the driver authenticates and then 403s on every endpoint they
     * exist to use: queues/join, trips/start and the rest gate on `Edit Queues`,
     * which reaches them only through the Driver role.
     *
     * Roles are seeded under the `web` guard (RoleSeeder), never `sanctum`, so
     * the lookup is guard-filtered. The role is looked up, not created: RoleSeeder
     * owns its permission bundle, and an empty role of the same name would shadow
     * it. An unseeded environment therefore grants nothing rather than something
     * hollow.
     */
    private function grantDriverRole(User $driver): void
    {
        $role = Role::where('name', Roles::DRIVER)->where('guard_name', 'web')->first();

        if ($role !== null && ! $driver->hasRole($role)) {
            $driver->assignRole($role);
        }
    }

    /**
     * The bank is derived from the brand, never sent by the client — the driver
     * is asked whether they want an account, not which bank offers it.
     *
     * Keyed on (driver, bank) so re-onboarding refreshes the lead instead of
     * handing the bank the same person twice; `status` is left alone once set,
     * because by then the bank owns it.
     */
    private function recordBankLead(User $driver, array $input): void
    {
        $bank = BankPartner::forBrand((string) Context::get('brand', ''));

        $lead = DriverBankLead::firstOrNew([
            'user_id' => $driver->id,
            'bank' => $bank->value,
        ]);

        $lead->preferred_branch = $input['preferred_branch'] ?? null;
        $lead->vehicle_capacity = isset($input['vehicle_capacity']) && $input['vehicle_capacity'] !== ''
            ? (int) $input['vehicle_capacity']
            : null;

        // Only overwrite the account number when one was actually supplied. A
        // re-onboarding that omits it (an older client, or an agent moving the
        // driver to another matatu) must not blank a number the bank is already
        // working from.
        $account = trim((string) ($input['account_number'] ?? ''));
        if ($account !== '') {
            $lead->account_number = $account;
        }

        // Consent is recorded ONLY when it was actually given on this pass, and
        // never cleared: it is the record that the driver was told their name,
        // phone and ID go to a bank, and it stands in for a signature nobody can
        // collect at a matatu stage. Re-onboarding without ticking the box
        // leaves the original attestation intact rather than silently voiding
        // it.
        if (filter_var($input['bank_consent'] ?? false, FILTER_VALIDATE_BOOL)) {
            $lead->consent_given_at = now();
            $lead->consent_text_version = $input['consent_text_version'] ?? null;
            $lead->consent_agent = $input['agent_identifier'] ?? null;
            $lead->consent_ip = $input['consent_ip'] ?? null;
        }

        $lead->opted_in_at = now();
        $lead->save();
    }
}
