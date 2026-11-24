<?php

namespace App\Alerts;

use App\Auth\MagicLink;
use App\Mail\WatchConfirmMail;
use App\Models\User;
use App\Models\WatchArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Asking for a watch, shared by the watch panel, the API and the MCP server.
 * An unconfirmed watch alerts no one: the emailed link (a page with one
 * button) is the owner's approval, whoever asked.
 */
final class WatchRequest
{
    /** A new, unconfirmed watch, replacing any earlier unconfirmed one of its kind: the newest ask wins. */
    public static function create(User $user, WatchShape $shape, string $name, array $alerts, bool $includeCoveredDeaths): WatchArea
    {
        return DB::transaction(function () use ($user, $shape, $name, $alerts, $includeCoveredDeaths) {
            $user->watchAreas()->inGroup($shape->isArea() ? 'area' : 'holder')->whereNull('confirmed_at')->delete();

            return $user->watchAreas()->create([
                ...$shape->attributes(),
                'name' => $name,
                'event_types' => WatchArea::eventTypesFor($alerts),
                'include_covered_deaths' => $includeCoveredDeaths,
            ]);
        });
    }

    /**
     * Email a confirm link for a new watch. False when the sign-up flood
     * limits (per IP and per address) say no; nothing is stored then.
     *
     * @param  list<string>  $alerts  free alert keys (WatchArea::FREE_ALERTS)
     */
    public static function emailConfirm(string $email, WatchShape $shape, string $name, array $alerts, bool $includeCoveredDeaths): bool
    {
        if (! MagicLink::allowSend('watch-signup', $email)) {
            return false;
        }
        $area = self::create(User::firstOrCreate(['email' => $email]), $shape, $name, $alerts, $includeCoveredDeaths);
        Mail::to($email)->send(new WatchConfirmMail($area));

        return true;
    }
}
