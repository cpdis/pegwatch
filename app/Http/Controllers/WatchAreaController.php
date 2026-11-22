<?php

namespace App\Http\Controllers;

use App\Models\WatchArea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WatchAreaController extends Controller
{
    /** Free plan: one watch area (plan: Free vs Pro). */
    public const FREE_LIMIT = 1;

    public function index(Request $request): Response
    {
        $areas = $request->user()->watchAreas()->latest()->get()->map(fn (WatchArea $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'latitude' => $a->latitude,
            'longitude' => $a->longitude,
            'radius_km' => $a->radius_km,
            'event_types' => $a->event_types,
            'tenements' => (int) DB::scalar(
                'select count(*) from tenements t, watch_areas w where w.id = ? and t.geom && w.area and ST_Intersects(t.geom, w.area)',
                [$a->id]
            ),
        ]);

        return Inertia::render('watch-areas/index', [
            'areas' => $areas,
            'limit' => self::FREE_LIMIT,
            'eventTypes' => WatchArea::FREE_EVENT_TYPES,
            'maxRadius' => WatchArea::MAX_RADIUS_KM,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            // Roughly WA, with a margin
            'latitude' => ['required', 'numeric', 'between:-36,-13'],
            'longitude' => ['required', 'numeric', 'between:112,130'],
            'radius_km' => ['required', 'numeric', 'min:0.5', 'max:'.WatchArea::MAX_RADIUS_KM],
            'event_types' => ['required', 'array', 'list', 'min:1'],
            'event_types.*' => [Rule::in(WatchArea::FREE_EVENT_TYPES)],
        ]);
        // Lock the user row so parallel requests cannot each pass the limit check.
        $created = DB::transaction(function () use ($request, $data) {
            $user = $request->user()::query()->whereKey($request->user()->id)->lockForUpdate()->first();
            if ($user->watchAreas()->count() >= self::FREE_LIMIT) {
                return false;
            }
            $user->watchAreas()->create([...$data, 'event_types' => array_values(array_unique($data['event_types']))]);

            return true;
        });
        if (! $created) {
            return back()->withErrors(['name' => 'The free plan includes one watch area. Delete it to add another.']);
        }

        return to_route('watch-areas.index');
    }

    public function destroy(Request $request, WatchArea $watchArea): RedirectResponse
    {
        abort_unless($watchArea->user_id === $request->user()->id, 403);
        $watchArea->delete();

        return to_route('watch-areas.index');
    }
}
