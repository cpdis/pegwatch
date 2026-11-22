import EventList from '@/components/event-list';
import PublicLayout from '@/components/public-layout';
import TenementMap from '@/components/tenement-map';
import { awst, localDate, pct } from '@/lib/format';
import { type HolderRef, type Run, type TenementEvent } from '@/types/pegwatch';
import { Head, Link } from '@inertiajs/react';

interface Tenement {
    fmt_tenid: string;
    slug: string;
    type: string | null;
    type_code: string | null;
    status: string;
    survey_status: string | null;
    holders: HolderRef[];
    legal_area: number | null;
    area_unit: string | null;
    area_ha: number | null;
    applied_at: string | null;
    granted_at: string | null;
    ends_at: string | null;
    extract_at: string | null;
    dead: { reason: string | null; ended_at: string } | null;
}

interface Props {
    tenement: Tenement;
    geometry: GeoJSON.Geometry | null;
    bbox: [number, number, number, number] | null;
    events: TenementEvent[];
    overlaps: { fmt_tenid: string; slug: string; type: string; status: string; share: number }[];
    releases: { rlesid: string; release_at: string }[];
    run: Run | null;
}

export default function TenementShow({ tenement: t, geometry, bbox, events, overlaps, releases, run }: Props) {
    const rows: [string, string | null][] = [
        ['Type', t.type],
        ['Status', t.dead ? `Dead (${t.dead.reason ?? 'reason not yet published'})` : t.status],
        ['Survey', t.survey_status],
        ['Area', t.legal_area ? `${t.legal_area} ${(t.area_unit ?? '').toLowerCase().replace('.', '')}` : null],
        ['Mapped area', t.area_ha ? `${t.area_ha.toLocaleString()} ha` : null],
        [t.status === 'PENDING' ? 'Applied' : 'Started', localDate(t.applied_at, true)],
        ['Granted', localDate(t.granted_at)],
        [t.dead ? 'Ended' : 'Expires', t.ends_at?.startsWith('2999') ? null : localDate(t.ends_at)],
    ];
    return (
        <PublicLayout run={run}>
            <Head title={t.fmt_tenid} />
            <div className="grid gap-6 md:grid-cols-5">
                <div className="md:col-span-2">
                    <h1 className="text-2xl font-semibold">{t.fmt_tenid}</h1>
                    <dl className="mt-4 grid grid-cols-3 gap-y-1 text-sm">
                        {rows
                            .filter(([, v]) => v)
                            .map(([k, v]) => (
                                <div key={k} className="contents">
                                    <dt className="text-muted-foreground">{k}</dt>
                                    <dd className="col-span-2">{v}</dd>
                                </div>
                            ))}
                        <dt className="text-muted-foreground">Holders</dt>
                        <dd className="col-span-2">
                            {t.holders.length === 0 && '—'}
                            {t.holders.map((h) => (
                                <div key={h.slug}>
                                    <Link href={`/holders/${h.slug}`} className="text-primary hover:underline">
                                        {h.name}
                                    </Link>
                                </div>
                            ))}
                        </dd>
                    </dl>

                    {releases.length > 0 && (
                        <div className="mt-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm dark:bg-red-950">
                            <b>Part of this licence is due for compulsory surrender.</b>
                            {releases.map((r) => (
                                <div key={r.rlesid}>
                                    {r.rlesid}: releases {awst(r.release_at)} AWST. Applications in the first 15 minutes go to ballot.
                                </div>
                            ))}
                        </div>
                    )}

                    {overlaps.length > 0 && (
                        <>
                            <h2 className="mt-6 font-medium">Overlapping tenements</h2>
                            <ul className="mt-1 text-sm">
                                {overlaps.map((o) => (
                                    <li key={o.slug}>
                                        <Link href={`/tenements/${o.slug}`} className="text-primary hover:underline">
                                            {o.fmt_tenid}
                                        </Link>{' '}
                                        <span className="text-muted-foreground">
                                            {o.type.toLowerCase()}, {o.status.toLowerCase()}, over {pct(o.share)} of this one
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}

                    <h2 className="mt-6 font-medium">History seen by Pegwatch</h2>
                    <EventList events={events} />
                </div>
                <div className="md:col-span-3">
                    <TenementMap className="h-[480px] w-full rounded-md" bounds={bbox} highlight={geometry} />
                    <p className="text-muted-foreground mt-2 text-xs">
                        Extract of {awst(t.extract_at)} AWST. Check the current position in TENGRAPH before acting.
                    </p>
                </div>
            </div>
        </PublicLayout>
    );
}
