import EventList from '@/components/event-list';
import PublicLayout from '@/components/public-layout';
import TenementMap from '@/components/tenement-map';
import { localDate } from '@/lib/format';
import { type HolderRef, type Run, type TenementEvent } from '@/types/pegwatch';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    holder: HolderRef;
    summary: { live: number; pending: number; area_ha: number; dead: number };
    bbox: [number, number, number, number] | null;
    tenements: {
        fmt_tenid: string;
        slug: string;
        type: string;
        status: string;
        applied_at: string | null;
        granted_at: string | null;
        ends_at: string | null;
        area_ha: number | null;
    }[];
    dead: { fmt_tenid: string; slug: string; type: string; reason: string | null; ended_at: string }[];
    applications_by_month: { month: string; n: number }[];
    geometry: GeoJSON.Geometry | null;
    events: TenementEvent[];
    run: Run | null;
}

// Pending applications carry a placeholder end date of 31 Dec 2999.
const expiry = (v: string | null) => (v && !v.startsWith('2999') ? localDate(v) : '');

export default function HolderShow({ holder, summary, bbox, tenements, dead, applications_by_month, geometry, events, run }: Props) {
    const [showAll, setShowAll] = useState(false);
    const max = Math.max(1, ...applications_by_month.map((m) => m.n));
    const shown = showAll ? tenements : tenements.slice(0, 50);
    return (
        <PublicLayout run={run}>
            <Head title={holder.name} />
            <h1 className="text-2xl font-semibold">{holder.name}</h1>
            <p className="text-muted-foreground mt-1 text-sm">
                {summary.live} live, {summary.pending} pending, {summary.area_ha.toLocaleString()} ha mapped. {summary.dead} dead tenements on record.
                {!holder.is_company && ' Shown by name only, as on the public register.'}
            </p>

            <div className="mt-4 grid gap-6 md:grid-cols-5">
                <div className="md:col-span-3">
                    <TenementMap className="h-[420px] w-full rounded-md" bounds={bbox} highlight={geometry} />
                </div>
                <div className="md:col-span-2">
                    <h2 className="font-medium">Applications per month (24 months)</h2>
                    {applications_by_month.every((m) => m.n === 0) ? (
                        <p className="text-muted-foreground text-sm">None still live or pending.</p>
                    ) : (
                        <div className="mt-2 flex h-32 items-end gap-0.5" role="img" aria-label="Applications per month">
                            {applications_by_month.map((m) => (
                                <div
                                    key={m.month}
                                    className="bg-primary/70 flex-1 rounded-t-sm"
                                    style={{ height: `${(m.n / max) * 100}%`, minHeight: m.n ? 2 : 0 }}
                                    title={`${m.month}: ${m.n}`}
                                />
                            ))}
                        </div>
                    )}
                    {!applications_by_month.every((m) => m.n === 0) && (
                        <div className="text-muted-foreground flex justify-between text-xs">
                            <span>{applications_by_month[0].month}</span>
                            <span>{applications_by_month[applications_by_month.length - 1].month}</span>
                        </div>
                    )}
                    <h2 className="mt-6 font-medium">Recent changes</h2>
                    <EventList events={events} compact />
                </div>
            </div>

            <h2 className="mt-8 font-medium">Tenements ({tenements.length})</h2>
            <div className="overflow-x-auto">
                <table className="mt-2 w-full text-sm">
                    <thead className="text-muted-foreground text-left">
                        <tr>
                            <th className="py-1">Tenement</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Applied</th>
                            <th>Expires</th>
                            <th className="text-right">ha</th>
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((t) => (
                            <tr key={t.slug} className="border-border border-t">
                                <td className="py-1">
                                    <Link href={`/tenements/${t.slug}`} className="text-primary hover:underline">
                                        {t.fmt_tenid}
                                    </Link>
                                </td>
                                <td>{t.type.toLowerCase()}</td>
                                <td>{t.status.toLowerCase()}</td>
                                <td>{localDate(t.applied_at)}</td>
                                <td>{expiry(t.ends_at)}</td>
                                <td className="text-right">{t.area_ha?.toLocaleString()}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {tenements.length > 50 && !showAll && (
                <button className="text-primary mt-2 text-sm underline" onClick={() => setShowAll(true)}>
                    Show all {tenements.length}
                </button>
            )}

            {dead.length > 0 && (
                <>
                    <h2 className="mt-8 font-medium">Dead tenements on record</h2>
                    <ul className="mt-1 columns-2 text-sm md:columns-3">
                        {dead.map((d) => (
                            <li key={d.slug + d.ended_at}>
                                <Link href={`/tenements/${d.slug}`} className="text-primary hover:underline">
                                    {d.fmt_tenid}
                                </Link>{' '}
                                <span className="text-muted-foreground">
                                    {d.reason?.toLowerCase()} {localDate(d.ended_at)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </PublicLayout>
    );
}
