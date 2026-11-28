<?php

namespace App\Agent;

use App\Alerts\AlertMatcher;
use App\Alerts\WatchRequest;
use App\Alerts\WatchShape;
use App\Models\User;
use App\Models\WatchArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A signed-in person's watches, for an agent they connected by OAuth: the
 * same actions as the My watches page (MCP /mcp/account and /api/v1/me).
 * Signing the agent in is the person's approval, so a new watch starts at
 * once; replacing a confirmed watch on the free plan needs an explicit
 * replace_existing, because the old one's alerts stop.
 */
final class Watches
{
    public const WATCH_MISSING = 'No watch with that id on this account. Call list_my_watches for the ids.';

    /** @return list<array<string, mixed>> */
    public static function list(User $user): array
    {
        return $user->watchAreas()->orderByRaw("kind = 'holder'")->oldest()->get()
            ->map(fn (WatchArea $w) => self::present($w))->all();
    }

    public static function find(User $user, mixed $id): ?WatchArea
    {
        return is_numeric($id) ? $user->watchAreas()->find((int) $id) : null;
    }

    /** @return array<string, mixed> */
    public static function present(WatchArea $w, bool $history = true): array
    {
        $shape = $w->shape();

        return AgentJson::clean([
            'id' => $w->id,
            'name' => $w->name,
            'kind' => $w->kind,
            'status' => $w->confirmed_at === null ? 'waiting_for_confirm' : ($w->unsubscribed_at ? 'stopped' : 'watching'),
            'covers' => $shape->describe(),
            'tenement' => $w->kind === 'tenement' ? $shape->label : null,
            'holder' => $w->kind === 'holder' ? $shape->label : null,
            'latitude' => $w->kind === 'circle' ? $w->latitude : null,
            'longitude' => $w->kind === 'circle' ? $w->longitude : null,
            'radius_km' => $w->kind === 'circle' ? $w->radius_km : null,
            'alerts' => $w->alerts(),
            'include_covered_deaths' => $w->include_covered_deaths,
            'inside' => $shape->summary(),
            'confirmed_at' => $w->confirmed_at?->setTimezone('Australia/Perth')->toIso8601String(),
            'stopped_at' => $w->unsubscribed_at?->setTimezone('Australia/Perth')->toIso8601String(),
            'recent_alert_emails' => $history ? AlertMatcher::history($w, 5) : null,
            'url' => $w->mapUrl(),
        ]);
    }

    /**
     * Start a watch now. Returns the watch, or throws when the free plan's
     * one watch of this kind is taken and replace_existing was not set.
     *
     * @param  list<string>  $alerts
     */
    public static function create(User $user, WatchShape $shape, string $name, array $alerts, bool $includeCovered, bool $replace): array
    {
        return DB::transaction(function () use ($user, $shape, $name, $alerts, $includeCovered, $replace) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $area = WatchRequest::create($user, $shape, $name, $alerts, $includeCovered);
            $old = $area->replaces();
            if ($old && ! $replace) {
                throw ValidationException::withMessages(['replace_existing' => sprintf(
                    'The free plan has one %s watch, and "%s" (id %d) is it. Pass replace_existing: true to stop and delete it and start this one, after checking with the person.',
                    $area->group(), $old->name, $old->id,
                )]);
            }
            $replaced = $area->confirm();

            return ['watch' => self::present($area->refresh(), false), 'replaced' => $replaced];
        });
    }

    /** Confirm a watch that is waiting for its emailed link. */
    public static function confirm(WatchArea $area, bool $replace): array
    {
        $old = $area->confirmed_at === null ? $area->replaces() : null;
        if ($old && ! $replace) {
            throw ValidationException::withMessages(['replace_existing' => sprintf(
                'Confirming this replaces "%s" (id %d) on the free plan. Pass replace_existing: true after checking with the person.', $old->name, $old->id,
            )]);
        }
        $replaced = $area->confirm();

        return ['watch' => self::present($area->refresh(), false), 'replaced' => $replaced];
    }

    /** @return array<string, mixed> validation rules for update() */
    public static function updateRules(): array
    {
        return [
            'name' => ['nullable', 'string', 'min:1', 'max:80'],
            'alerts' => ['nullable', 'array', 'list', 'min:1'],
            'alerts.*' => [Rule::in(array_keys(WatchArea::FREE_ALERTS))],
            'include_covered_deaths' => ['nullable', 'boolean'],
            'radius_km' => ['nullable', 'numeric', 'min:'.WatchShape::MIN_RADIUS_KM, 'max:'.WatchShape::MAX_RADIUS_KM],
            'latitude' => ['nullable', 'numeric', 'between:-36,-13', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:112,130', 'required_with:latitude'],
        ];
    }

    /** Rename, change alerts, or resize or move a circle. Only the fields given change. */
    public static function update(WatchArea $area, array $v): array
    {
        $moves = isset($v['radius_km']) || isset($v['latitude']);
        if ($moves && $area->kind !== 'circle') {
            throw ValidationException::withMessages(['radius_km' => 'Only circle watches have a centre and radius. A tenement watch is its outline plus 2 km; a holder watch has no area.']);
        }
        $changes = array_filter([
            'name' => isset($v['name']) ? trim($v['name']) : null,
            'event_types' => isset($v['alerts']) ? WatchArea::eventTypesFor($v['alerts']) : null,
            'include_covered_deaths' => $v['include_covered_deaths'] ?? null,
        ], fn ($x) => $x !== null && $x !== '');
        if ($moves) {
            $shape = WatchShape::circle($v['longitude'] ?? $area->longitude, $v['latitude'] ?? $area->latitude, $v['radius_km'] ?? $area->radius_km);
            $changes += $shape?->attributes() ?? [];
        }
        $area->update($changes);

        return self::present($area->refresh(), false);
    }
}
