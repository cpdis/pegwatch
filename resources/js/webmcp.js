// WebMCP (https://webmachinelearning.github.io/webmcp/): the site's own actions as tools for an
// agent working in this browser tab. Lookups go through the same JSON API as everything else
// (/api/v1); the map tools send the window events the map already listens to (map/index.js).
// Starting a watch only opens the watch form: the person still gives their email and confirms.

const LAYERS = ['tenements', 'pending', 'dead', 'releases', 'restrictions', 'freed', 'norecord', 'grid'];

let lastView = null;
window.addEventListener('map-moved', (e) => (lastView = e.detail));

const api = async (path, params = {}) => {
    const url = new URL(`/api/v1/${path}`, window.location.origin);
    Object.entries(params).forEach(([k, v]) => v !== undefined && v !== null && url.searchParams.set(k, Array.isArray(v) ? v.join(',') : v));
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body.message ?? `Pegwatch answered ${res.status}`);
    return body;
};

const slug = (text) => String(text).replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase();

const go = (path) => (window.Livewire?.navigate ? window.Livewire.navigate(path) : window.location.assign(path));

const hasMap = () => lastView !== null || document.querySelector('[x-data^="tenementMap"]') !== null;

const text = (data) => ({ content: [{ type: 'text', text: typeof data === 'string' ? data : JSON.stringify(data) }] });

const point = { latitude: { type: 'number', minimum: -36, maximum: -13 }, longitude: { type: 'number', minimum: 112, maximum: 130 } };

export const tools = [
    {
        name: 'search_register',
        description: 'Search the WA mining register for tenements by ID, holders by name, company groups and towns.',
        inputSchema: { type: 'object', properties: { query: { type: 'string', minLength: 2 } }, required: ['query'] },
        annotations: { readOnlyHint: true },
        execute: async ({ query }) => text(await api('search', { q: query })),
    },
    {
        name: 'get_tenement',
        description: 'One tenement (live, pending or dead) by ID in any spelling, e.g. "M 27/518": holders, dates, area, history, overlaps, releases.',
        inputSchema: { type: 'object', properties: { id: { type: 'string' } }, required: ['id'] },
        annotations: { readOnlyHint: true },
        execute: async ({ id }) => text(await api(`tenements/${encodeURIComponent(id)}`)),
    },
    {
        name: 'tenements_at_point',
        description: 'Who holds the ground at a point in WA, what died there recently, and releases due there.',
        inputSchema: { type: 'object', properties: point, required: ['latitude', 'longitude'] },
        annotations: { readOnlyHint: true },
        execute: async ({ latitude, longitude }) => text(await api('tenements/at', { lat: latitude, lng: longitude })),
    },
    {
        name: 'open_page',
        description: "Open a Pegwatch page in this tab: a tenement's or holder's page, the activity feed, the data status page, or the map.",
        inputSchema: {
            type: 'object',
            properties: {
                page: { type: 'string', enum: ['tenement', 'holder', 'activity', 'data', 'map'] },
                id: { type: 'string', description: 'The tenement ID or holder name, for those pages.' },
            },
            required: ['page'],
        },
        execute: async ({ page, id }) => {
            const path = { tenement: `/tenements/${slug(id ?? '')}`, holder: `/holders/${slug(id ?? '')}`, activity: '/activity', data: '/data', map: '/' }[page];
            if ((page === 'tenement' || page === 'holder') && !id) throw new Error(`Give the ${page}'s ${page === 'tenement' ? 'ID' : 'name'}.`);
            go(path);
            return text(`Opened ${path}`);
        },
    },
    {
        name: 'show_on_map',
        description: 'Move the map to a point (with a zoom, 5 to 16) or fit a box [west, south, east, north]. Opens the map first if this page has none.',
        inputSchema: {
            type: 'object',
            properties: { ...point, zoom: { type: 'number', minimum: 5, maximum: 16 }, bbox: { type: 'array', items: { type: 'number' }, minItems: 4, maxItems: 4 } },
        },
        execute: async ({ latitude, longitude, zoom, bbox }) => {
            if (!bbox && (latitude === undefined || longitude === undefined)) throw new Error('Give a latitude and longitude, or a bbox.');
            if (!hasMap()) {
                const [lat, lng] = bbox ? [(bbox[1] + bbox[3]) / 2, (bbox[0] + bbox[2]) / 2] : [latitude, longitude];
                go(`/#map=${zoom ?? 12}/${lat}/${lng}`);
                return text('Opened the map there.');
            }
            window.dispatchEvent(new CustomEvent('map-fly', { detail: bbox ? { bounds: bbox } : { center: [longitude, latitude], zoom } }));
            return text('Moved the map.');
        },
    },
    {
        name: 'get_map_view',
        description: 'What the map in this tab is showing: its box [west, south, east, north], centre and zoom.',
        inputSchema: { type: 'object', properties: {} },
        annotations: { readOnlyHint: true },
        execute: async () => text(lastView ?? 'No map on this page yet.'),
    },
    {
        name: 'set_map_layers',
        description: `Turn map layers on or off: ${LAYERS.join(', ')}. "freed" is ground dead tenements left with no tenement over it.`,
        inputSchema: { type: 'object', properties: Object.fromEntries(LAYERS.map((l) => [l, { type: 'boolean' }])) },
        execute: async (layers) => {
            if (!hasMap()) throw new Error('This page has no map. Use show_on_map or open_page first.');
            const chosen = Object.fromEntries(Object.entries(layers ?? {}).filter(([k, v]) => LAYERS.includes(k) && typeof v === 'boolean'));
            window.dispatchEvent(new CustomEvent('map-layers', { detail: { layers: chosen } }));
            return text(chosen);
        },
    },
    {
        name: 'start_watch',
        description:
            'Open the free email-alert form for a tenement, a holder, or a circle around a point. The person enters their email and confirms by email; nothing is watched until they do.',
        inputSchema: {
            type: 'object',
            properties: { tenement: { type: 'string' }, holder: { type: 'string' }, ...point, radius_km: { type: 'number', minimum: 1, maximum: 50 } },
        },
        execute: async ({ tenement, holder, latitude, longitude, radius_km }) => {
            if (tenement || holder) {
                go(`/watch?${tenement ? 'tenement' : 'holder'}=${slug(tenement ?? holder)}`);
            } else if (latitude !== undefined && longitude !== undefined) {
                if (!hasMap()) go(`/#map=11/${latitude}/${longitude}`);
                setTimeout(() => window.dispatchEvent(new CustomEvent('watch-start', { detail: { center: [longitude, latitude], radiusKm: radius_km ?? 10 } })), hasMap() ? 0 : 1500);
            } else {
                throw new Error('Give a tenement, a holder, or a latitude and longitude.');
            }
            return text('The watch form is open. Ask the person to enter their email and press the link Pegwatch sends them.');
        },
    },
];

/** Register the tools once per page load (wire:navigate keeps the same document). */
export function registerWebMcp() {
    const ctx = document.modelContext ?? navigator.modelContext;
    if (!ctx || window.__pegwatchWebMcp) return;
    window.__pegwatchWebMcp = true;
    if (typeof ctx.registerTool === 'function') {
        tools.forEach((tool) => Promise.resolve(ctx.registerTool(tool)).catch(() => {}));
    } else if (typeof ctx.provideContext === 'function') {
        ctx.provideContext({ tools });
    }
}
