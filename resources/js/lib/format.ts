const AWST = 'Australia/Perth';

/** Register timestamps are local (AWST) wall-clock values without a zone. */
export function localDate(value: string | null | undefined, withTime = false): string {
    if (!value) return '';
    const d = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('en-AU', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...(withTime ? { hour: 'numeric', minute: '2-digit' } : {}),
    });
}

/** Timestamps with a zone (extract times, detection times), shown in AWST. */
export function awst(value: string | null | undefined, withTime = true): string {
    if (!value) return '';
    // Postgres gives "2026-11-19 04:11:05+00"; make it ISO 8601.
    const iso = value.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00');
    const d = new Date(/[zZ]|[+-]\d{2}:\d{2}$/.test(iso) ? iso : iso + 'Z');
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('en-AU', {
        timeZone: AWST,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...(withTime ? { hour: 'numeric', minute: '2-digit' } : {}),
    });
}

export function pct(v: unknown): string {
    const n = typeof v === 'string' ? parseFloat(v) : (v as number);
    return n === null || n === undefined || Number.isNaN(n) ? '?' : `${Math.round(n * 100)}%`;
}

export const TYPE_COLOURS: Record<string, string> = {
    E: '#2563eb',
    P: '#ea580c',
    M: '#dc2626',
    L: '#6b7280',
    G: '#7c3aed',
    R: '#0d9488',
};
export const OTHER_COLOUR = '#0f766e';

export const TYPE_NAMES: Record<string, string> = {
    E: 'Exploration licence',
    P: 'Prospecting licence',
    M: 'Mining lease',
    L: 'Miscellaneous licence',
    G: 'General purpose lease',
    R: 'Retention licence',
};

export const EVENT_COLOURS: Record<string, string> = {
    application: '#2563eb',
    death: '#111827',
    grant: '#16a34a',
    holder_change: '#9333ea',
    shrink: '#ea580c',
    grow: '#0891b2',
    release_scheduled: '#dc2626',
    released: '#dc2626',
    release_cancelled: '#6b7280',
    status_change: '#6b7280',
    appeared: '#2563eb',
};
