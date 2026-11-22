import EventList from '@/components/event-list';
import PublicLayout from '@/components/public-layout';
import TenementMap, { type MapLayers } from '@/components/tenement-map';
import { EVENT_COLOURS, OTHER_COLOUR, TYPE_COLOURS, TYPE_NAMES } from '@/lib/format';
import { type Run, type TenementEvent } from '@/types/pegwatch';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface Props {
    run: Run | null;
    totals: { LIVE: number; PENDING: number };
    events: TenementEvent[];
}

const LAYER_LABELS: Record<keyof MapLayers, string> = {
    tenements: 'Live tenements',
    pending: 'Pending applications',
    releases: 'Release pending (s65 surrender)',
    dead: 'Died in the last 12 months',
    restrictions: 'Closed or restricted land (s19, s57, FNAs)',
};

export default function MapPage({ run, totals, events }: Props) {
    const [layers, setLayers] = useState<MapLayers>({ tenements: true, pending: true, dead: false, releases: true, restrictions: false });
    const [hovered, setHovered] = useState<TenementEvent | null>(null);
    const markers = useMemo(
        () =>
            events
                .filter((e) => e.point)
                .map((e) => ({ id: e.id, point: e.point!, colour: hovered?.id === e.id ? '#facc15' : (EVENT_COLOURS[e.type] ?? '#6b7280') })),
        [events, hovered],
    );

    return (
        <PublicLayout run={run} fullBleed>
            <Head title="WA tenement map" />
            <div className="flex flex-1 flex-col md:flex-row">
                <aside className="border-border w-full overflow-y-auto border-b p-4 md:h-[calc(100vh-7rem)] md:w-96 md:border-r md:border-b-0">
                    <h1 className="text-lg font-semibold">Every WA mining tenement, live, pending and dead</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {totals.LIVE.toLocaleString()} live and {totals.PENDING.toLocaleString()} pending. Free email alerts when a tenement near you
                        is recorded dead or has ground scheduled for release.
                    </p>

                    <fieldset className="mt-4 space-y-1 text-sm">
                        <legend className="mb-1 font-medium">Layers</legend>
                        {(Object.keys(LAYER_LABELS) as (keyof MapLayers)[]).map((k) => (
                            <label key={k} className="flex items-center gap-2">
                                <input type="checkbox" checked={layers[k]} onChange={(e) => setLayers({ ...layers, [k]: e.target.checked })} />
                                {LAYER_LABELS[k]}
                            </label>
                        ))}
                    </fieldset>

                    <div className="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                        {Object.entries(TYPE_NAMES).map(([code, name]) => (
                            <span key={code} className="flex items-center gap-1">
                                <span className="inline-block size-3 rounded-sm" style={{ background: TYPE_COLOURS[code] }} />
                                {name}
                            </span>
                        ))}
                        <span className="flex items-center gap-1">
                            <span className="inline-block size-3 rounded-sm" style={{ background: OTHER_COLOUR }} />
                            Other
                        </span>
                        <span className="text-muted-foreground">Dashed outline: pending</span>
                    </div>

                    <h2 className="mt-6 mb-1 font-medium">Latest changes</h2>
                    <EventList events={events} compact onHover={setHovered} />
                </aside>
                <TenementMap className="min-h-[60vh] flex-1" layers={layers} markers={markers} />
            </div>
        </PublicLayout>
    );
}
