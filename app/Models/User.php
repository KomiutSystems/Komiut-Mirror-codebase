<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Auth\Roles;
use App\Enums\Financier;
use App\Enums\UserType;
use App\Support\Email;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'firstname',
        'lastname',
        'phone',
        'id_number',
        'email',
        'dob',
        'password',
        'gender_id',
        'sacco_id',
        'status',
        'image',
        'type',
        'provider',
        'provider_id',
    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // auth/user returns the whole model. Without this the SMS reset hash
        // ships to the client on every profile read — a password hash for an
        // account that is, for the length of the window, signed in with it.
        'sms_reset_password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_active_at' => 'datetime',
        'password' => 'hashed',
        // Same cast as `password`, for the same reason: assigning plaintext here
        // must never store plaintext. Laravel's hashed cast is idempotent, so an
        // already-hashed value passes through untouched.
        'sms_reset_password' => 'hashed',
        'sms_reset_expires_at' => 'datetime',
        'type' => UserType::class,
    ];

    /**
     * Find by email the way people actually type it — any case.
     *
     * `where('email', $x)` is case-SENSITIVE on PostgreSQL and was
     * case-insensitive on the legacy MySQL, so exact matching silently locked
     * 224 accounts out of sign-in and out of password reset when the platform
     * moved. See App\Support\Email for the whole story.
     *
     * LOWER() on the column rather than ILIKE: ILIKE would treat an address
     * containing % or _ as a pattern, and this is an equality test.
     *
     * A null or blank argument matches NOTHING, deliberately. Thousands of rows
     * have no email, and a scope that quietly matched them all would be a way to
     * sign in as an arbitrary account.
     */
    public function scopeByEmail(Builder $query, ?string $email): Builder
    {
        $normalised = Email::normalise($email);

        if ($normalised === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereRaw('LOWER(email) = ?', [$normalised]);
    }

    public function isPassenger(): bool
    {
        return $this->type === UserType::Passenger;
    }

    /**
     * The canonical SACCO this user belongs to, used for tenant scoping.
     * Null for passengers/drivers with no home SACCO and for superadmins.
     *
     * NOTE: reads users.sacco_id directly; a backfill from the active
     * sacco_users membership row is a separate data task.
     */
    public function currentSaccoId(): ?int
    {
        return $this->sacco_id;
    }

    /**
     * Superadmins are never tenant-scoped — they see every SACCO within the
     * current brand. Recognised either by account type or the spatie role
     * (the role exists today; the type is the going-forward source of truth).
     */
    public function isSuperAdmin(): bool
    {
        return $this->type === UserType::Superadmin || $this->hasRole('Super Admin');
    }

    /**
     * Is this caller a bank, rather than a SACCO?
     *
     * Two signals on purpose, OR-ed. `financier` alone would not be enough: a
     * Bank Viewer whose column is unset would then look like an ordinary user
     * and be waved past FinancierScope entirely — seeing every SACCO's money
     * because their bank was not recorded, which is the exact failure the scope
     * exists to prevent. The role is what makes "supposed to have a financier,
     * has none" a state we can recognise and deny.
     *
     * `users.financier` is NOT in $fillable and this is why: it decides which
     * bank's fleet an account can read, so it must never be settable through a
     * mass-assigned registration or profile update.
     */
    public function isBankUser(): bool
    {
        return trim((string) $this->financier) !== '' || $this->hasRole(Roles::BANK_VIEWER);
    }

    /**
     * The bank this user reads on behalf of, or null when it cannot be
     * resolved — which for a bank user means "deny", never "no filter".
     *
     * Resolved through the enum rather than cast on the model: a backed-enum
     * cast throws a ValueError when the column holds anything unexpected, and
     * an authorization key must degrade to a denial, not a 500.
     */
    public function currentFinancier(): ?Financier
    {
        return Financier::tryParse($this->financier);
    }
    protected function getDefaultGuardName(): string
    {
        return 'web';
    }

    /** Email the frontend reset link (not the default backend web route). */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordLink($token));
    }
    public function gender()
    {
        return $this->belongsTo(Gender::class);
    }

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }
    public function vehicle_users()
    {
        return $this->hasMany(VehicleUser::class);
    }
    public function firebase_tokens()
    {
        return $this->hasMany(FirebaseToken::class);
    }
}
