<?php

namespace App\Http\Controllers;

use App\Auth\MagicLink;
use App\Mail\LoginLinkMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "Email me a login link", for new and existing accounts alike: the reply is
 * the same whether or not the address has an account, and pressing the link
 * creates one if it does not.
 */
class LoginLinkController extends Controller
{
    public function send(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $email = $request->validateWithBag('link', ['email' => ['required', 'string', 'email:rfc', 'max:254']])['email'];

        // Honeypot: hidden from people, filled in by form bots. Bots get the same reply.
        if ((string) $request->input('website') === '') {
            if (! MagicLink::allowSend('login-link', $email, perIp: 10, perEmail: 5, emailWindow: 3600)) {
                return back()->withInput()->withErrors(['email' => __('Too many links asked for from here. Try again later.')], 'link');
            }
            Mail::to($email)->send(new LoginLinkMail(MagicLink::loginUrl($email), $email));
        }

        return back()->with('link_sent', $email);
    }

    public function show(Request $request, string $token): View
    {
        $email = $request->hasValidSignature() ? MagicLink::peek($token) : null;
        if ($email === null) {
            return self::expired();
        }

        return view('pages::auth.link', [
            'title' => __('Log in'),
            'heading' => __('Log in to Pegwatch'),
            'lines' => [__('You are logging in as :email.', ['email' => $email])],
            'action' => $request->fullUrl(),
            'button' => __('Log in'),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse|View
    {
        $email = $request->hasValidSignature() ? MagicLink::pull($token) : null;
        if ($email === null) {
            return self::expired();
        }
        $user = User::firstOrCreate(['email' => $email]);

        return MagicLink::signIn($request, $user, route('watch-areas.index'), intended: true);
    }

    private static function expired(): View
    {
        return view('pages::auth.link', [
            'title' => __('Link expired'),
            'heading' => __('This login link has expired'),
            'lines' => [__('Each link works once, for :n minutes. Ask for a new one and use the newest email.', ['n' => MagicLink::LOGIN_MINUTES])],
            'secondary' => [__('Email me a new link'), route('login')],
        ]);
    }
}
