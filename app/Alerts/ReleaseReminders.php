<?php

namespace App\Alerts;

use App\Mail\ReleaseReminderMail;
use App\Models\WatchArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Day-before and hour-before emails for compulsory releases in watched
 * ground (E3). Run every 15 minutes; safe to run twice: each watch gets
 * each reminder for each release time once.
 */
class ReleaseReminders
{
    /** Each reminder and the window it goes out in, in minutes before release time. */
    public const WINDOWS = ['day' => [60, 1440], 'hour' => [0, 60]];

    /** @return int emails sent */
    public function run(): int
    {
        foreach (self::WINDOWS as $kind => [$from, $to]) {
            DB::insert(
                "insert into release_reminders (watch_area_id, tenid, rlesid, release_at, kind, created_at, updated_at)
                 select w.id, r.tenid, r.rlesid, r.release_at, ?, now(), now()
                 from release_pending r
                 join watch_areas w on w.confirmed_at is not null and w.unsubscribed_at is null
                   and w.event_types @> '[\"release_scheduled\"]'::jsonb
                   and case when w.kind = 'holder'
                         then w.holder_slug = any(public.pw_slugs((select t.holders from tenements t where t.tenid = r.tenid)))
                         else r.geom && w.area and ST_Intersects(r.geom, w.area) end
                 where r.release_at > ?::timestamptz and r.release_at <= ?::timestamptz
                 on conflict do nothing",
                [$kind, now()->addMinutes($from)->toIso8601String(), now()->addMinutes($to)->toIso8601String()]
            );
        }

        // What is due and still ahead, one email per watch and kind.
        $due = DB::table('release_reminders')->whereNull('sent_at')->where('release_at', '>', now())
            ->orderBy('release_at')->get()->groupBy(fn ($r) => $r->watch_area_id.':'.$r->kind);
        $sent = 0;
        foreach ($due as $rows) {
            $area = WatchArea::with('user')->confirmed()->whereNull('unsubscribed_at')->find($rows[0]->watch_area_id);
            if ($area) {
                $releases = array_map(fn ($r) => (array) $r, DB::select(
                    'select r.tenid, r.rlesid, trim(coalesce(r.fmt_tenid, r.tenid)) as fmt_tenid, r.ten_type, r.release_at,
                            round((ST_Area(r.geom::geography) / 10000)::numeric, 1) as area_ha
                     from release_pending r join release_reminders m on m.tenid = r.tenid and m.rlesid = r.rlesid and m.release_at = r.release_at
                     where m.id = any(?::bigint[]) order by r.release_at, r.fmt_tenid',
                    ['{'.$rows->pluck('id')->implode(',').'}']
                ));
                if ($releases) {
                    Mail::to($area->user)->send(new ReleaseReminderMail($area, $rows[0]->kind, $releases));
                    $sent++;
                }
            }
            DB::table('release_reminders')->whereIn('id', $rows->pluck('id'))->update(['sent_at' => now()]);
        }

        return $sent;
    }
}
