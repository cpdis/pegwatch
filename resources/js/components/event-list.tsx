import { EVENT_COLOURS, awst, localDate } from '@/lib/format';
import { type TenementEvent } from '@/types/pegwatch';
import { Link } from '@inertiajs/react';

export default function EventList({
    events,
    compact = false,
    onHover,
}: {
    events: TenementEvent[];
    compact?: boolean;
    onHover?: (e: TenementEvent | null) => void;
}) {
    if (events.length === 0) {
        return <p className="text-muted-foreground text-sm">No changes recorded yet. Changes appear after the second nightly run.</p>;
    }
    return (
        <ul className="divide-border divide-y">
            {events.map((e) => (
                <li key={e.id} className="py-2" onMouseEnter={() => onHover?.(e)} onMouseLeave={() => onHover?.(null)}>
                    <div className="flex items-baseline gap-2 text-sm">
                        <span className="inline-block size-2 shrink-0 rounded-full" style={{ background: EVENT_COLOURS[e.type] ?? '#6b7280' }} />
                        <span className="font-medium">{e.label}</span>
                        <Link href={`/tenements/${e.slug}`} className="text-primary hover:underline">
                            {e.fmt_tenid}
                        </Link>
                        {!compact && <span className="text-muted-foreground ml-auto text-xs">detected {awst(e.detected_at, false)}</span>}
                    </div>
                    <div className="text-muted-foreground pl-4 text-xs">
                        {e.summary}
                        {e.holders.length > 0 && (
                            <>
                                {' · '}
                                {e.holders.slice(0, 2).map((h, i) => (
                                    <span key={h.slug}>
                                        {i > 0 && ', '}
                                        <Link href={`/holders/${h.slug}`} className="hover:underline">
                                            {h.name}
                                        </Link>
                                    </span>
                                ))}
                                {e.holders.length > 2 && ` +${e.holders.length - 2}`}
                            </>
                        )}
                        {e.occurred_at && !compact && <> · {localDate(e.occurred_at, true)}</>}
                    </div>
                </li>
            ))}
        </ul>
    );
}
