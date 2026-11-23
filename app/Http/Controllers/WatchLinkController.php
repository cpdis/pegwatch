<?php

namespace App\Http\Controllers;

use App\Alerts\AlertMatcher;
use App\Auth\MagicLink;
use App\Mail\WatchAreaDigest;
use App\Models\WatchArea;
use App\Support\Register;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The two links in watch emails. Each GET shows a page with one button and
 * changes nothing (mail scanners open links); the POST acts.
 */
class WatchLinkController extends Controller
{
    /** The confirm link from the email a new watch sends. */
    public function show(Request $request, int $watchArea): View
    {
        $area = $this->signed($request, $watchArea);
        if (! $area) {
            return self::expired();
        }
        if ($area->confirmed_at) {
            return view('pages::auth.link', [
                'title' => __('Already watching'),
                'heading' => __('You already watch :name', ['name' => $area->name]),
                'lines' => [__('This link was used. Log in to change the watch or see its alerts.')],
                'secondary' => [__('My watch areas'), route('watch-areas.index')],
            ]);
        }
        $replaces = $area->replaces();

        return view('pages::auth.link', [
            'title' => __('Confirm your watch'),
            'heading' => __('Start watching :name?', ['name' => $area->name]),
            'lines' => [
                $area->shape()->describe().'.',
                __('Alerts go to :email, one email after a night with changes.', ['email' => $area->user->email]),
            ],
            'note' => $replaces ? __('The free plan watches one :what. Confirming replaces :old.', [
                'what' => $area->group() === 'holder' ? __('holder') : __('area'), 'old' => $replaces->name,
            ]) : null,
            'pro' => (bool) $replaces,
            'action' => $request->fullUrl(),
            'button' => $replaces ? __('Replace :old with :new', ['old' => $replaces->name, 'new' => $area->name]) : __('Confirm and start watching'),
        ]);
    }

    /** Confirm, verify the address and sign in. The link works once: a used link signs no one in. */
    public function confirm(Request $request, int $watchArea): RedirectResponse|View
    {
        $area = $this->signed($request, $watchArea);
        if (! $area) {
            return self::expired();
        }
        if ($area->confirmed_at) {
            return redirect()->route('watch-areas.index');
        }
        $area->confirm();

        return MagicLink::signIn($request, $area->user, route('watch-areas.index'),
            __('Watching :name. Your first email comes after the next extract with changes.', ['name' => $area->name]));
    }

    /** "Stop alerts for this area" from an alert email: no login needed. */
    public function showStop(Request $request, int $watchArea): View
    {
        $area = $this->signed($request, $watchArea);
        abort_unless($area, 404);
        $stopped = $area->unsubscribed_at !== null;

        return view('pages::auth.link', [
            'title' => __('Stop alerts'),
            'heading' => $stopped ? __('Alerts for :name are stopped', ['name' => $area->name]) : __('Stop alerts for :name?', ['name' => $area->name]),
            'lines' => [$stopped
                ? __('No more alert emails will be sent for this watch. It stays in your account until you delete it.')
                : __('No more alert emails will be sent for this watch. You can turn them back on here or in your account.')],
            'action' => $request->fullUrl(),
            'button' => $stopped ? __('Turn alerts back on') : __('Stop alerts'),
            'variant' => $stopped ? 'filled' : 'danger',
            'fields' => $stopped ? ['resume' => '1'] : [],
        ]);
    }

    /**
     * Stop (or resume) one watch's alerts. Mail clients POST here for RFC 8058
     * one-click unsubscribe with no session; running it twice changes nothing more.
     */
    public function stop(Request $request, int $watchArea): RedirectResponse|Response
    {
        $area = $this->signed($request, $watchArea);
        abort_unless($area, 404);
        if ($request->boolean('resume')) {
            $area->forceFill(['unsubscribed_at' => null])->save();
        } elseif ($area->unsubscribed_at === null) {
            DB::transaction(function () use ($area) {
                $area->forceFill(['unsubscribed_at' => now()])->save();
                // Changes matched but not yet sent would arrive stale after a resume.
                DB::table('alert_deliveries')->where('watch_area_id', $area->id)->whereNull('sent_at')->delete();
            });
        }

        return $request->input('List-Unsubscribe') === 'One-Click' ? response('', 204) : redirect()->to($request->fullUrl());
    }

    /** The alert email this watch would have had over the last 30 days (the watch page's email preview). */
    public function preview(Request $request, int $watchArea): string
    {
        $area = WatchArea::findOrFail($watchArea);
        abort_unless($area->user_id === $request->user()->id, 404);

        return (new WatchAreaDigest($area, AlertMatcher::recent($area), Register::latestRun(), preview: true))->render();
    }

    private function signed(Request $request, int $id): ?WatchArea
    {
        return $request->hasValidSignature() ? WatchArea::with('user')->find($id) : null;
    }

    private static function expired(): View
    {
        return view('pages::auth.link', [
            'title' => __('Link expired'),
            'heading' => __('This link has expired'),
            'lines' => [__('Confirm links work for :n days, and only the newest one for each watch. Start again and use the newest email.', ['n' => WatchArea::CONFIRM_DAYS])],
            'secondary' => [__('Watch an area'), route('watch.start')],
        ]);
    }
}
