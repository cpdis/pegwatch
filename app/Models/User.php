<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password null for accounts that sign in by emailed link only
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, Prunable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        // Email-first sign-up asks for an address only; start from its local part. Settings can change it.
        static::creating(function (User $user) {
            $user->name = trim((string) $user->name) !== '' ? $user->name : Str::before($user->email, '@');
        });
    }

    /** Accounts made by the watch flow or a login link have no password until the user sets one. */
    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /** @return HasMany<WatchArea, $this> */
    public function watchAreas(): HasMany
    {
        return $this->hasMany(WatchArea::class);
    }

    /**
     * Accounts someone started with this address but never confirmed: no
     * password, never verified, and no watch asked for since the last link
     * expired. Their unconfirmed watches go with them.
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(WatchArea::CONFIRM_DAYS + 1);

        return static::whereNull('password')->whereNull('email_verified_at')->where('created_at', '<', $cutoff)
            ->whereDoesntHave('watchAreas', fn (Builder $q) => $q->where('created_at', '>=', $cutoff));
    }
}
