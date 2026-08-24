<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refresh token row. See the migration for why these are not Sanctum PATs.
 *
 * Carries no tenancy trait on purpose: it is a credential belonging to one
 * user, reached only by hash during an unauthenticated exchange, never listed
 * or filtered by SACCO, brand or financier.
 */
class RefreshToken extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'revoked_at', 'last_used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * Never serialise the hash. It is not the plaintext, but it is the value a
     * lookup matches on, so it has no business in an API response or a log.
     */
    protected $hidden = ['token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Live means: issued, not yet revoked, not yet expired. */
    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
