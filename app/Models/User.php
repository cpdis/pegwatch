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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

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
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Prunable, TwoFactorAuthenticatable;

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
        // An account that goes takes its agents' sign-ins with it.
        static::deleting(fn (User $user) => $user->disconnectAgents());
    }

    /**
     * Agent apps this person signed in (OAuth), with their newest use.
     *
     * @return list<object{client_id: string, name: string, connected_at: string, last_token_at: string}>
     */
    public function connectedAgents(): array
    {
        return DB::select(
            'select c.id as client_id, c.name, min(t.created_at) as connected_at, max(t.created_at) as last_token_at
             from oauth_access_tokens t join oauth_clients c on c.id = t.client_id
             where t.user_id = ? and not t.revoked and not c.revoked
               and (t.expires_at > now() or exists (select 1 from oauth_refresh_tokens r
                    where r.access_token_id = t.id and not r.revoked and r.expires_at > now()))
             group by c.id, c.name order by max(t.created_at) desc',
            [$this->id]
        );
    }

    /** Sign an agent app out (all of them with no client): its access and refresh tokens stop working. */
    public function disconnectAgents(?string $clientId = null): void
    {
        $tokens = DB::table('oauth_access_tokens')->where('user_id', $this->id)->when($clientId, fn ($q) => $q->where('client_id', $clientId));
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
        DB::table('oauth_auth_codes')->where('user_id', $this->id)->when($clientId, fn ($q) => $q->where('client_id', $clientId))->update(['revoked' => true]);
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
     * password, not verified, no watch ever confirmed, and no watch asked for
     * since the last link expired. Their unconfirmed watches go with them. An
     * account whose owner changed its address is unverified too, so a confirmed
     * watch is what tells the two apart.
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(WatchArea::CONFIRM_DAYS + 1);

        return static::whereNull('password')->whereNull('email_verified_at')->where('created_at', '<', $cutoff)
            ->whereDoesntHave('watchAreas', fn (Builder $q) => $q->where(
                fn (Builder $q) => $q->where('created_at', '>=', $cutoff)->orWhereNotNull('confirmed_at')
            ));
    }
}
