import EventList from '@/components/event-list';
import PublicLayout from '@/components/public-layout';
import { type Run, type TenementEvent } from '@/types/pegwatch';
import { Head, Link } from '@inertiajs/react';

interface Props {
    events: TenementEvent[];
    type: string | null;
    page: number;
    perPage: number;
    labels: Record<string, string>;
    run: Run | null;
}

export default function Activity({ events, type, page, perPage, labels, run }: Props) {
    const hasMore = events.length > perPage;
    const qs = (p: number) => `/activity?${new URLSearchParams({ ...(type ? { type } : {}), ...(p > 1 ? { page: String(p) } : {}) })}`;
    return (
        <PublicLayout run={run}>
            <Head title="Activity" />
            <h1 className="text-2xl font-semibold">What changed on the register</h1>
            <p className="text-muted-foreground mt-1 text-sm">Every change Pegwatch has seen between one night's extract and the next.</p>
            <div className="mt-4 flex flex-wrap gap-2 text-sm">
                <Link href="/activity" className={`rounded-full border px-3 py-0.5 ${!type ? 'bg-primary text-primary-foreground' : ''}`}>
                    All
                </Link>
                {Object.entries(labels).map(([k, v]) => (
                    <Link
                        key={k}
                        href={`/activity?type=${k}`}
                        className={`rounded-full border px-3 py-0.5 ${type === k ? 'bg-primary text-primary-foreground' : ''}`}
                    >
                        {v}
                    </Link>
                ))}
            </div>
            <div className="mt-4">
                <EventList events={events.slice(0, perPage)} />
            </div>
            <div className="mt-4 flex gap-4 text-sm">
                {page > 1 && <Link href={qs(page - 1)}>← Newer</Link>}
                {hasMore && <Link href={qs(page + 1)}>Older →</Link>}
            </div>
        </PublicLayout>
    );
}
