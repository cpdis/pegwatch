<?php

namespace App\Http\Controllers;

use App\Models\DigestSubscriber;
use Illuminate\Http\Response;

/** RFC 8058 one-click unsubscribe: the mail client POSTs here with no session. */
class DigestUnsubscribeController extends Controller
{
    public function __invoke(string $token): Response
    {
        DigestSubscriber::where('token', $token)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);

        return response('', 204);
    }
}
