<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\UserType;
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
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'type' => UserType::class,
    ];

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
