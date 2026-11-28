<?php

namespace App\Agent;

use App\Alerts\WatchShape;
use App\Models\WatchArea;
use App\Support\Format;
use App\Support\Holders;
use App\Support\PgArray;
use App\Support\Register;
use App\Support\Slug;
use App\Support\TenementSummary;
use Illuminate\Support\Facades\DB;

/**
 * Everything a person can look up on Pegwatch, as plain data: one method per
 * thing a page or the map shows. The JSON API, the MCP server and the
 * Markdown pages are thin wrappers over this, so the three cannot drift.
 *
 * Every result carries `url` (the page a person would open) where there is
 * one. Dates follow AgentJson: register dates are AWST.
 */
final class Lookup
{
    public const MAX_LIST = 200;

    /** Tenements, holders, company groups and places matching a query, ranked as Cmd+K ranks them. */
    public static function search(string $q, int $limit = 10): array
    {
        $limit = max(1, min($limit, 25));

        return self::out(array_map(fn ($r) => match ($r['kind']) {
            'tenement' => $r + ['url' => route('tenements.show', $r['slug'])],
            'holder' => $r + ['url' => route('holders.show', $r['slug'])],
            'group' => $r + ['url' => route('holders.matching', $r['slug'])],
            'place' => array_diff_key($r, ['exact' => 0]) + ['map_url' => self::mapUrl($r['center'][1], $r['center'][0], 11)],
            default => $r,
        }, Register::search($q, $limit)));
    }

    /**
     * One tenement by its ID in any spelling ("M 27/518", "m27/518",
     * "m-27-518"): the register record, its history, what overlaps it,
     * releases, restrictions, pending actions and where it is.
     */
    public static function tenement(string $id, bool $geometry = false): ?array
    {
        $data = Register::tenement(self::tenementSlug($id));
        if (! $data) {
            return null;
        }
        $t = $data['tenement'];

        return self::out([
            'tenement' => $t + [
                'summary' => TenementSummary::headlineText(['tenement' => $t]),
                'field' => Format::field($t['fmt_tenid']),
                'url' => route('tenements.show', $t['slug']),
                'release_calendar' => $data['releases'] ? route('tenements.release-ics', $t['slug']) : null,
            ],
            'location' => $data['location'],
            'bbox' => $data['bbox'],
            'geometry' => $data['geometry'],
            'overlaps' => $data['overlaps'],
            'releases' => $data['releases'],
            'restrictions' => $data['restrictions'],
            'pending_actions' => $data['actions'],
            'uncovered_share' => $data['uncovered'],
            'wait_to_grant' => $data['wait'],
            'held_before' => $data['held_before'],
            'neighbours' => $data['neighbours'],
            'holder_context' => $data['holder_context'],
            'events' => array_map(self::event(...), $data['events']),
        ], $geometry);
    }

    /**
     * A tenement ID in any spelling as its page slug: "M 27/518", "m27/518",
     * "M27-518" and "m-27-518" are all "m-27-518". Letter suffixes stay
     * ("P 27/2688-S" is "p-27-2688-s").
     */
    public static function tenementSlug(string $id): string
    {
        return preg_match('~^\s*([a-z]{1,3})[\s-]*(\d{1,2})\s*[/-]\s*(\d+)\s*-?\s*([a-z]*)\s*$~i', $id, $m)
            ? Slug::make("$m[1] $m[2] $m[3] $m[4]")
            : Slug::make($id);
    }

    /** Live and pending tenements over a point, recent deaths there, releases due there, and the nearest place. */
    public static function tenementsAt(float $lng, float $lat): array
    {
        $pt = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)';
        $live = DB::select(
            "select fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, applied_at, granted_at, ends_at
             from tenements where geom && $pt and ST_Intersects(geom, $pt)
             order by applied_at nulls last, fmt_tenid limit 50",
            [$lng, $lat, $lng, $lat]
        );
        $dead = DB::select(
            "select fmt_tenid, type, death_reason, ended_at, holders, holders_are_company
             from dead_tenements where geom && $pt and ST_Intersects(geom, $pt)
             order by ended_at desc limit 10",
            [$lng, $lat, $lng, $lat]
        );
        $releases = DB::select(
            "select fmt_tenid, rlesid, release_at from release_pending
             where geom && $pt and ST_Intersects(geom, $pt) and release_at >= now() order by release_at limit 10",
            [$lng, $lat, $lng, $lat]
        );
        $place = DB::scalar("select to_regclass('public.places') is not null")
            ? DB::selectOne("select name, kind, lga from places order by geom <-> $pt limit 1", [$lng, $lat])
            : null;

        return self::out([
            'point' => [$lng, $lat],
            'map_url' => self::mapUrl($lat, $lng, 13),
            'nearest_place' => $place,
            'held' => $live !== [],
            'tenements' => array_map(fn ($r) => [
                'fmt_tenid' => trim($r->fmt_tenid),
                'type' => $r->type,
                'type_code' => $r->type_code,
                'status' => $r->status,
                'holders' => Holders::present(PgArray::parse($r->holders), PgArray::parse($r->holder_is_company)),
                'area_ha' => $r->area_ha !== null ? round((float) $r->area_ha, 1) : null,
                'applied_at' => $r->applied_at,
                'granted_at' => $r->granted_at,
                'ends_at' => $r->ends_at,
                'url' => route('tenements.show', Slug::make($r->fmt_tenid)),
            ], $live),
            'recently_dead' => array_map(fn ($r) => [
                'fmt_tenid' => trim($r->fmt_tenid),
                'type' => $r->type,
                'death_reason' => $r->death_reason,
                'ended_at' => $r->ended_at,
                'holders' => Holders::present(PgArray::parse($r->holders), PgArray::parse($r->holders_are_company)),
                'url' => route('tenements.show', Slug::make($r->fmt_tenid)),
            ], $dead),
            'releases_due' => array_map(fn ($r) => [
                'fmt_tenid' => trim((string) $r->fmt_tenid),
                'release_id' => $r->rlesid,
                'release_at' => $r->release_at,
                'url' => route('tenements.show', Slug::make((string) $r->fmt_tenid)),
            ], $releases),
        ]);
    }

    /**
     * Live and pending tenements in a box under the map's filters, newest
     * application first, with the map panel's counts for the same box.
     *
     * @param  array{kinds?: list<string>, statuses?: list<string>, appliedFrom?: ?string, appliedTo?: ?string, expiresWithinMonths?: ?int, minHa?: ?float, maxHa?: ?float}  $filter
     */
    public static function tenementsIn(?array $bbox, array $filter = [], int $limit = 50): array
    {
        $limit = max(1, min($limit, self::MAX_LIST));
        $rows = Register::tenementsInBounds($bbox, $filter, $limit);

        return self::out([
            'bbox' => $bbox,
            'filter' => $filter,
            'counts' => Register::countsInBounds($bbox, $filter),
            'returned' => count($rows),
            'tenements' => array_map(fn ($r) => $r + ['url' => route('tenements.show', $r['slug'])], $rows),
        ]);
    }

    /**
     * A holder's portfolio by name or page slug: live and pending tenements,
     * dead ones, activity by month, companies with matching names, recent events.
     * Natural persons are named as the register names them, and nothing more.
     */
    public static function holder(string $nameOrSlug): ?array
    {
        $h = Register::holder(Slug::make($nameOrSlug));
        if (! $h) {
            return null;
        }
        $h['holder']['url'] = route('holders.show', $h['holder']['slug']);
        $h['events'] = array_map(self::event(...), $h['events']);
        unset($h['dead_since']);

        return self::out($h);
    }

    /**
     * The activity feed (the /activity page): register changes, newest first.
     *
     * @param  array{types?: list<string>, kinds?: list<string>, field?: string, holder?: string, from?: string, to?: string, bbox?: array}  $filter
     */
    public static function activity(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min($limit, self::MAX_LIST));
        $items = Register::feed($filter, $limit + 1, max(0, $offset));
        $more = count($items) > $limit;

        return self::out([
            'filter' => $filter,
            'items' => array_map(self::event(...), array_slice($items, 0, $limit)),
            // Explicit paging, so an agent knows when it has everything.
            'next_offset' => $more ? $offset + $limit : null,
        ]);
    }

    /** Compulsory releases still to come, soonest first, optionally in a box. */
    public static function releases(?array $bbox = null, int $limit = 50): array
    {
        $limit = max(1, min($limit, self::MAX_LIST));

        return self::out(array_map(fn ($r) => $r + [
            'url' => route('tenements.show', $r['slug']),
            'calendar' => route('tenements.release-ics', $r['slug']),
        ], Register::upcomingReleases($bbox, $limit)));
    }

    /** How fresh the data is: the last nightly check, the extract it used, and register totals. */
    public static function status(): array
    {
        $run = Register::latestRun();

        return self::out([
            'last_checked_at' => Register::lastCheck(),
            'latest_run' => $run ? [
                'id' => (int) $run->id,
                'finished_at' => $run->finished_at,
                'register_extract_at' => $run->tenements_extract,
            ] : null,
            'stale' => Register::isStale($run),
            'baseline_only' => Register::baselineOnly(),
            'watching_since' => Register::watchingSince(),
            'dead_history_from' => Register::deadHistoryStart(),
            'totals' => Register::totals(),
            'schedule' => 'Checked once a night, usually done by about 7am AWST; the register extract is usually from about 4am AWST on a business day.',
            'url' => route('data'),
        ]);
    }

    /** A weekly digest issue by ISO week ("2026-W47"), or the latest. */
    public static function digest(?string $week = null): ?array
    {
        $q = DB::table('digest_issues');
        $issue = $week ? $q->where('week', $week)->first() : $q->orderByDesc('week')->first();
        if (! $issue) {
            return null;
        }

        return self::out([
            'week' => $issue->week,
            'url' => route('digest.issue', $issue->week),
            'content' => json_decode($issue->content, true),
        ]);
    }

    /**
     * What a watch would cover, before anyone asks for one: a circle, a
     * tenement (its outline plus 2 km) or a holder. Null when the shape is
     * not on the register or outside WA.
     */
    public static function watchPreview(WatchShape $shape): array
    {
        return self::out([
            'kind' => $shape->kind,
            'covers' => $shape->describe(),
            'suggested_name' => $shape->defaultName(),
            'bounds' => $shape->bounds(),
            'now_inside' => $shape->summary(),
            'tenements' => array_map(fn ($r) => $r + ['url' => route('tenements.show', $r['slug'])], $shape->tenements(10)),
            'alerts_available' => array_keys(WatchArea::FREE_ALERTS),
        ]);
    }

    /** A watch shape from agent input: exactly one of a circle, a tenement ID or a holder. */
    public static function shape(array $in): ?WatchShape
    {
        return match (true) {
            isset($in['tenement']) => WatchShape::tenement(self::tenementSlug((string) $in['tenement'])),
            isset($in['holder']) => WatchShape::holder(Slug::make((string) $in['holder'])),
            isset($in['latitude'], $in['longitude']) => WatchShape::circle($in['longitude'], $in['latitude'], $in['radius_km'] ?? 10),
            default => null,
        };
    }

    /** The home map at a point (`/#map=zoom/lat/lng`). */
    public static function mapUrl(float $lat, float $lng, int $zoom = 12): string
    {
        return rtrim(url('/'), '/').'/#map='.$zoom.'/'.round($lat, 5).'/'.round($lng, 5);
    }

    private static function event(array $e): array
    {
        unset($e['id']);
        $e['url'] = ($e['slug'] ?? null) ? route('tenements.show', $e['slug']) : null;

        return $e;
    }

    private static function out(array $data, bool $geometry = false): array
    {
        return AgentJson::clean($data, $geometry);
    }
}
