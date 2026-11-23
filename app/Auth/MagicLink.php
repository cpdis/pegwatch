<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Sign-in by emailed link. A link opens a page with one button and only the
 * button's POST signs in, so mail scanners that open links sign no one in.
 */
final class MagicLink
{
    public const LOGIN_MINUTES = 30;

    /** A signed login link for an address, usable once within LOGIN_MINUTES. */
    public static function loginUrl(string $email): string
    {
        $token = Str::random(40);
        Cache::put('login-link:'.$token, $email, now()->addMinutes(self::LOGIN_MINUTES));

        return URL::temporarySignedRoute('login.link', now()->addMinutes(self::LOGIN_MINUTES), ['token' => $token]);
    }

    /** The address a login link is for, while it is still unused. */
    public static function peek(string $token): ?string
    {
        return Cache::get('login-link:'.$token);
    }

    /** Use a login link up: the address it was for, or null if it was used or expired. */
    public static function pull(string $token): ?string
    {
        return Cache::pull('login-link:'.$token);
    }

    /**
     * The digest sign-up's flood limits, for every form that emails an address
     * it is given: so many per IP each 10 minutes, so many per address.
     */
    public static function allowSend(string $scope, string $email, int $perIp = 5, int $perEmail = 3, int $emailWindow = 86400): bool
    {
        $ipKey = $scope.':ip:'.request()->ip();
        $emailKey = $scope.':email:'.sha1($email);
        if (RateLimiter::tooManyAttempts($ipKey, $perIp) || RateLimiter::tooManyAttempts($emailKey, $perEmail)) {
            return false;
        }
        RateLimiter::hit($ipKey, 600);
        RateLimiter::hit($emailKey, $emailWindow);

        return true;
    }

    /**
     * Sign in after a link was pressed. The press proves the address, so it is
     * verified; an account with two-factor on still has to pass the challenge.
     */
    public static function signIn(Request $request, User $user, string $to, ?string $status = null, bool $intended = false): RedirectResponse
    {
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);

            return redirect()->route('two-factor.login')->with('status', $status);
        }
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return ($intended ? redirect()->intended($to) : redirect()->to($to))->with('status', $status);
    }
}
