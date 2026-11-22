export interface Run {
    id: number;
    finished_at: string | null;
    tenements_extract: string | null;
    stale: boolean | null;
}

export interface HolderRef {
    name: string;
    slug: string;
    is_company: boolean;
}

export interface TenementEvent {
    id: number;
    type: string;
    label: string;
    summary: string;
    fmt_tenid: string;
    slug: string;
    tenement_type: string | null;
    holders: HolderRef[];
    occurred_at: string | null;
    extract_at: string | null;
    detected_at: string;
    details: Record<string, unknown>;
    point: [number, number] | null;
}
