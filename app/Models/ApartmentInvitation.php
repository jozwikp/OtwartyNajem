<?php

namespace App\Models;

use Database\Factories\ApartmentInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $apartment_id
 * @property string $email
 * @property string $token
 * @property int|null $invited_by
 * @property int|null $accepted_by
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['email', 'token', 'invited_by', 'expires_at'])]
#[Hidden(['token'])]
class ApartmentInvitation extends Model
{
    /** @use HasFactory<ApartmentInvitationFactory> */
    use HasFactory;

    public const VALID_DAYS = 14;

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Only a hash of the token is stored, the plain token goes into the e-mail link.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return static::where('token', static::hashToken($plainToken))->first();
    }

    /**
     * Assign a fresh token and expiry date. Returns the plain token for the e-mail link.
     */
    public function refreshToken(): string
    {
        $plainToken = Str::random(48);

        $this->token = static::hashToken($plainToken);
        $this->expires_at = now()->addDays(self::VALID_DAYS);

        return $plainToken;
    }

    /**
     * @return BelongsTo<Apartment, $this>
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @param  Builder<ApartmentInvitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->where('expires_at', '>', now());
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isDeclined() && ! $this->isExpired();
    }
}
