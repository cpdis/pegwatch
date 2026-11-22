import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface Results {
    tenements: { fmt_tenid: string; slug: string; type: string | null; status: string | null }[];
    holders: { name: string; slug: string; count: number }[];
}

export default function TenementSearch() {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Results | null>(null);
    const [open, setOpen] = useState(false);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => {
        window.clearTimeout(timer.current);
        if (q.trim().length < 2) {
            setResults(null);
            return;
        }
        timer.current = window.setTimeout(async () => {
            const r = await fetch(`/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
            if (r.ok) setResults(await r.json());
        }, 200);
    }, [q]);

    const go = (href: string) => {
        setOpen(false);
        setQ('');
        router.visit(href);
    };

    return (
        <div className="relative">
            <input
                value={q}
                onChange={(e) => {
                    setQ(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        const first = results?.tenements[0] ?? results?.holders[0];
                        if (first) go('fmt_tenid' in first ? `/tenements/${first.slug}` : `/holders/${first.slug}`);
                    }
                }}
                placeholder="Search tenement (E 45/4894) or holder"
                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                aria-label="Search tenements and holders"
            />
            {open && results && (results.tenements.length > 0 || results.holders.length > 0) && (
                <ul className="bg-popover border-border absolute z-20 mt-1 max-h-96 w-full overflow-auto rounded-md border text-sm shadow-lg">
                    {results.tenements.map((t) => (
                        <li key={t.slug}>
                            <button className="hover:bg-accent w-full px-3 py-1.5 text-left" onMouseDown={() => go(`/tenements/${t.slug}`)}>
                                <span className="font-medium">{t.fmt_tenid}</span>{' '}
                                <span className="text-muted-foreground">
                                    {t.type?.toLowerCase()} · {t.status?.toLowerCase()}
                                </span>
                            </button>
                        </li>
                    ))}
                    {results.holders.map((h) => (
                        <li key={h.slug}>
                            <button className="hover:bg-accent w-full px-3 py-1.5 text-left" onMouseDown={() => go(`/holders/${h.slug}`)}>
                                {h.name} <span className="text-muted-foreground">{h.count} tenements</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
