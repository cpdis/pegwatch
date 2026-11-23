<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A weekly digest subscriber: an email address and nothing else. No account,
 * double opt-in, one-click unsubscribe.
 */
class DigestSubscriber extends Model
{
    protected $fillable = ['email', 'source'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (DigestSubscriber $s) {
            $s->token ??= Str::random(40);
        });
    }

    /** @param Builder<DigestSubscriber> $q */
    public function scopeActive(Builder $q): void
    {
        $q->whereNotNull('confirmed_at')->whereNull('unsubscribed_at');
    }

    public function isActive(): bool
    {
        return $this->confirmed_at !== null && $this->unsubscribed_at === null;
    }

    public function confirmUrl(): string
    {
        return route('digest.confirm', ['token' => $this->token]);
    }

    public function unsubscribeUrl(): string
    {
        return route('digest.unsubscribe', ['token' => $this->token]);
    }
}
