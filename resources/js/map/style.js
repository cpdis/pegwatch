// Sources, layers and paint for the tenement map. The map-rendering work owns this file.
//
// Prospectors first: ground is filled by status (live faint, pending hatched, release
// magenta hatch, dead grey dots) and outlined by type. Below zoom 8 the nightly
// `coverage` layer shades all held ground, so the state view drops nothing; it
// cross-fades into single tenements between zoom 7.5 and 8.5.

export const WA_BOUNDS = [112.5, -35.5, 129.5, -13.5];
const BASEMAPS = { light: 'https://tiles.openfreemap.org/styles/positron', dark: 'https://tiles.openfreemap.org/styles/dark' };
const theme = () => (document.documentElement.classList.contains('dark') ? 'dark' : 'light');
/** The basemap for the page's appearance. A live binding: attachRendering updates it when the appearance changes. */
export let BASEMAP = BASEMAPS[theme()];
// Used if the basemap style cannot be fetched, so the tenement layers still draw.
export const BLANK_STYLE = { version: 8, sources: {}, layers: [{ id: 'background', type: 'background', paint: { 'background-color': '#f4f1ea' } }] };

export const STATUS_COLOURS = { LIVE: '#15803d', PENDING: '#d97706', DEAD: '#71717a', RELEASE: '#c026d3', RESTRICTED: '#a16207' };
export const FREED_COLOUR = '#16a34a';
export const TYPE_COLOURS = { E: '#2563eb', P: '#ea580c', M: '#dc2626', L: '#6b7280', G: '#7c3aed', R: '#0d9488' };
export const OTHER_COLOUR = '#0f766e';
export const typeColour = ['match', ['get', 'type_code'], ...Object.entries(TYPE_COLOURS).flat(), OTHER_COLOUR];
export const EMPTY = { type: 'FeatureCollection', features: [] };

const groupColour = ['match', ['get', 'type_group'], 'E', TYPE_COLOURS.E, 'P', TYPE_COLOURS.P, 'M', TYPE_COLOURS.M, OTHER_COLOUR];
const typeHex = ['match', ['get', 'type_code'], ...Object.entries(TYPE_COLOURS).flatMap(([k, c]) => [k, c.slice(1)]), OTHER_COLOUR.slice(1)];
// Draw order within a layer: exploration licences at the bottom, mining leases on top.
const typeOrder = (prop) => ['match', ['get', prop], 'E', 1, 'P', 2, 'M', 4, 3];
const live = ['==', ['get', 'status'], 'LIVE'];
const pending = ['==', ['get', 'status'], 'PENDING'];

// Hovered or selected features (feature-state, set by the card) fill at 0.35 and outline 1.5 px wider.
const active = ['any', ['boolean', ['feature-state', 'hover'], false], ['boolean', ['feature-state', 'selected'], false]];
const stateFill = (base) => ['case', active, 0.35, base];
const stateWidth = (stops) => ['interpolate', ['linear'], ['zoom'], ...stops.flatMap(([z, w]) => [z, ['+', w, ['case', active, 1.5, 0]]])];
// Single tenements fade in as the coverage layer fades out.
const fadeIn = (v) => ['interpolate', ['linear'], ['zoom'], 7.5, 0, 8.5, v];
const fadeOut = (v) => ['interpolate', ['linear'], ['zoom'], 7.5, v, 8.5, 0];
const tenementWidth = stateWidth([[7.5, 0.8], [12, 1.5], [15, 2.5]]);
const pattern = (kind, colour) => `pw-${kind}-${colour.slice(1)}`;
const ID_LABEL = { 'text-field': ['get', 'fmt_tenid'], 'text-font': ['Noto Sans Regular'], 'text-size': 11, 'text-padding': 4 };

/**
 * Every Pegwatch layer, bottom to top. `group` is the layer toggle that shows it;
 * `filter` is its base filter (BASE_FILTERS); `over` puts it above the basemap's labels.
 * Paint that changes with the colour mode lives in MODE_PAINT.
 */
const LAYERS = [
    { id: 'norecord-fill', group: 'norecord', type: 'fill', source: 'norecord', paint: { 'fill-color': '#64748b', 'fill-opacity': 0.16 } },
    { id: 'coverage-pending', group: 'pending', type: 'fill', source: 'coverage', filter: pending, maxzoom: 8.5, sort: 'type_group', paint: { 'fill-opacity': fadeOut(0.22) } },
    { id: 'coverage-live', group: 'tenements', type: 'fill', source: 'coverage', filter: live, maxzoom: 8.5, sort: 'type_group', paint: { 'fill-opacity': fadeOut(0.45) } },
    { id: 'restrictions-fill', group: 'restrictions', type: 'fill', source: 'restrictions', paint: { 'fill-color': STATUS_COLOURS.RESTRICTED, 'fill-opacity': stateFill(0.03) } },
    { id: 'restrictions-hatch', group: 'restrictions', type: 'fill', source: 'restrictions', paint: { 'fill-pattern': pattern('wide', STATUS_COLOURS.RESTRICTED) } },
    { id: 'dead-fill', group: 'dead', type: 'fill', source: 'dead', paint: { 'fill-color': STATUS_COLOURS.DEAD, 'fill-opacity': stateFill(0.03) } },
    { id: 'dead-dots', group: 'dead', type: 'fill', source: 'dead', paint: { 'fill-pattern': pattern('dots', STATUS_COLOURS.DEAD) } },
    { id: 'live-fill', group: 'tenements', type: 'fill', source: 'tenements', filter: live, minzoom: 7, sort: 'type_code', paint: { 'fill-opacity': fadeIn(stateFill(0.14)) } },
    { id: 'pending-fill', group: 'pending', type: 'fill', source: 'tenements', filter: pending, minzoom: 7, sort: 'type_code', paint: { 'fill-opacity': fadeIn(stateFill(0.05)) } },
    { id: 'pending-hatch', group: 'pending', type: 'fill', source: 'tenements', filter: pending, minzoom: 7, sort: 'type_code', paint: { 'fill-opacity': fadeIn(1) } },
    { id: 'freed-fill', group: 'freed', type: 'fill', source: 'freed', paint: { 'fill-color': FREED_COLOUR, 'fill-opacity': stateFill(0.08) } },
    { id: 'freed-hatch', group: 'freed', type: 'fill', source: 'freed', paint: { 'fill-pattern': pattern('back', FREED_COLOUR) } },
    { id: 'releases-fill', group: 'releases', type: 'fill', source: 'releases', paint: { 'fill-color': STATUS_COLOURS.RELEASE, 'fill-opacity': stateFill(0.1) } },
    { id: 'releases-hatch', group: 'releases', type: 'fill', source: 'releases', paint: { 'fill-pattern': pattern('back', STATUS_COLOURS.RELEASE) } },
    { id: 'grid-line', group: 'grid', type: 'line', source: 'grid', geojson: true, minzoom: 11, paint: { 'line-color': '#52525b', 'line-opacity': 0.35, 'line-width': 0.6 } },
    { id: 'restrictions-line', group: 'restrictions', type: 'line', source: 'restrictions', paint: { 'line-color': STATUS_COLOURS.RESTRICTED, 'line-width': 1, 'line-dasharray': [2, 2] } },
    { id: 'dead-line', group: 'dead', type: 'line', source: 'dead', paint: { 'line-color': STATUS_COLOURS.DEAD, 'line-width': stateWidth([[6, 0.8], [12, 1.2]]), 'line-dasharray': [1, 2] } },
    { id: 'live-line', group: 'tenements', type: 'line', source: 'tenements', filter: live, minzoom: 7, sort: 'type_code', paint: { 'line-color': typeColour, 'line-width': tenementWidth, 'line-opacity': fadeIn(1) } },
    { id: 'pending-line', group: 'pending', type: 'line', source: 'tenements', filter: pending, minzoom: 7, sort: 'type_code', paint: { 'line-color': typeColour, 'line-width': tenementWidth, 'line-opacity': fadeIn(1), 'line-dasharray': [3, 2] } },
    { id: 'freed-line', group: 'freed', type: 'line', source: 'freed', paint: { 'line-color': FREED_COLOUR, 'line-width': stateWidth([[8, 0.8], [14, 1.2]]) } },
    { id: 'releases-line', group: 'releases', type: 'line', source: 'releases', paint: { 'line-color': STATUS_COLOURS.RELEASE, 'line-width': stateWidth([[5, 1], [12, 1.8]]) } },
    { id: 'freed-points', group: 'freed', over: true, type: 'circle', source: 'freed', layer: 'freed-points', maxzoom: 10, paint: { 'circle-radius': 3.5, 'circle-color': FREED_COLOUR, 'circle-stroke-color': '#fff', 'circle-stroke-width': 1 } },
    { id: 'release-points', group: 'releases', over: true, type: 'circle', source: 'releases', layer: 'release-points', paint: { 'circle-radius': 5, 'circle-color': STATUS_COLOURS.RELEASE, 'circle-stroke-color': '#fff', 'circle-stroke-width': 1.5 } },
    { id: 'live-label', group: 'tenements', type: 'symbol', source: 'tenements', layer: 'labels', filter: live, minzoom: 12, layout: ID_LABEL },
    { id: 'pending-label', group: 'pending', type: 'symbol', source: 'tenements', layer: 'labels', filter: pending, minzoom: 12, layout: ID_LABEL },
    {
        id: 'release-labels',
        group: 'releases',
        over: true,
        type: 'symbol',
        source: 'releases',
        layer: 'release-points',
        layout: { 'text-field': ['get', 'label'], 'text-font': ['Noto Sans Bold'], 'text-size': 11, 'text-anchor': 'left', 'text-offset': [0.8, 0] },
        paint: { 'text-color': STATUS_COLOURS.RELEASE },
    },
];

/** Paint that follows the colour mode: fill by status (default) or by type. Outlines are always by type. */
const MODE_PAINT = {
    status: {
        'coverage-live': { 'fill-color': STATUS_COLOURS.LIVE },
        'coverage-pending': { 'fill-color': STATUS_COLOURS.PENDING },
        'live-fill': { 'fill-color': STATUS_COLOURS.LIVE },
        'pending-fill': { 'fill-color': STATUS_COLOURS.PENDING },
        'pending-hatch': { 'fill-pattern': pattern('hatch', STATUS_COLOURS.PENDING) },
    },
    type: {
        'coverage-live': { 'fill-color': groupColour },
        'coverage-pending': { 'fill-color': groupColour },
        'live-fill': { 'fill-color': typeColour },
        'pending-fill': { 'fill-color': typeColour },
        'pending-hatch': { 'fill-pattern': ['concat', 'pw-hatch-', typeHex] },
    },
};

const LABEL_PAINT = {
    light: { 'text-color': '#27272a', 'text-halo-color': '#ffffff', 'text-halo-width': 1.4 },
    dark: { 'text-color': '#e4e4e7', 'text-halo-color': '#0b0b0c', 'text-halo-width': 1.4 },
};

/** Vector tile sources at /tiles/{source}/{z}/{x}/{y}.mvt, with the zooms the server fills. */
const TILE_SOURCES = {
    coverage: { minzoom: 3, maxzoom: 8 },
    tenements: { minzoom: 7, promoteId: 'tenid' },
    dead: { minzoom: 6, promoteId: 'tenid' },
    releases: { minzoom: 0, promoteId: 'tenid' },
    freed: { minzoom: 3, promoteId: 'tenid' },
    restrictions: { minzoom: 5 },
    norecord: { minzoom: 6 },
};

/** Layer ids the hover card queries; the card orders what it finds. */
export const HOVERABLE = ['live-fill', 'pending-fill', 'releases-fill', 'dead-fill', 'freed-fill', 'restrictions-fill'];
/** Layer ids a click queries: the hoverable fills plus release markers, which show at every zoom. */
export const CLICKABLE = [...HOVERABLE, 'release-points'];

/** Layer ids behind each layer toggle. The component shows or hides a whole group. */
export const LAYER_GROUPS = LAYERS.reduce((g, l) => ({ ...g, [l.group]: [...(g[l.group] ?? []), l.id] }), {});

/** Which groups show when a map first loads. */
export const DEFAULT_LAYERS = { tenements: true, pending: true, dead: false, releases: true, restrictions: false, freed: true, norecord: false, grid: false };

/** The status filter each layer starts with, so other filters can be combined with it (`['all', base, mine]`). */
export const BASE_FILTERS = Object.fromEntries(LAYERS.filter((l) => l.filter).map((l) => [l.id, l.filter]));

// Basemap layer and source ids per map, so a style switch keeps everything else.
const basemaps = new WeakMap();

/** Add every Pegwatch source and layer to a loaded map. */
export function addLayers(m) {
    const style = m.getStyle();
    basemaps.set(m, { layers: new Set(style.layers.map((l) => l.id)), sources: new Set(Object.keys(style.sources)) });
    m.on('styleimagemissing', ({ id }) => {
        // OpenFreeMap's dark style asks for a town dot its sprite lacks ('circle-11').
        const image = id === 'circle-11' ? townDot() : patternImage(id);
        if (image && !m.hasImage(id)) m.addImage(id, image.data, { pixelRatio: image.pixelRatio });
    });
    // A map that starts dark parsed its first basemap tiles before that handler existed.
    if (BASEMAP === BASEMAPS.dark) for (const [id, s] of Object.entries(style.sources)) if (s.type === 'vector') m.refreshTiles(id);

    for (const [id, s] of Object.entries(TILE_SOURCES)) {
        m.addSource(id, { type: 'vector', tiles: [`${window.location.origin}/tiles/${id}/{z}/{x}/{y}.mvt`], maxzoom: 14, ...s });
    }
    for (const s of ['highlight', 'markers', 'circle', 'grid']) m.addSource(s, { type: 'geojson', data: EMPTY });

    // Fills, outlines and ID labels go under the basemap's place names, so town names win
    // label collisions; release and freed-ground markers go on top.
    const firstLabel = style.layers.find((l) => l.type === 'symbol')?.id;
    for (const l of LAYERS) {
        if (l.type === 'symbol' && !style.glyphs) continue; // a style without glyphs cannot draw text
        m.addLayer(layerSpec(l, theme()), l.over ? undefined : firstLabel);
    }
    m.addLayer({ id: 'circle-fill', type: 'fill', source: 'circle', paint: { 'fill-color': '#0ea5e9', 'fill-opacity': 0.08 } });
    m.addLayer({ id: 'circle-line', type: 'line', source: 'circle', paint: { 'line-color': '#0ea5e9', 'line-width': 2 } });
    m.addLayer({ id: 'highlight-line', type: 'line', source: 'highlight', paint: { 'line-color': '#facc15', 'line-width': 3 } });
    m.addLayer({
        id: 'markers',
        type: 'circle',
        source: 'markers',
        paint: { 'circle-radius': 5, 'circle-color': ['get', 'colour'], 'circle-stroke-color': '#fff', 'circle-stroke-width': 1.5 },
    });
}

function layerSpec(l, look) {
    return {
        id: l.id,
        type: l.type,
        source: l.source,
        ...(l.geojson ? {} : { 'source-layer': l.layer ?? l.source }),
        ...(l.filter ? { filter: l.filter } : {}),
        ...(l.minzoom ? { minzoom: l.minzoom } : {}),
        ...(l.maxzoom ? { maxzoom: l.maxzoom } : {}),
        layout: { ...l.layout, ...(l.sort ? { [`${l.type}-sort-key`]: typeOrder(l.sort) } : {}) },
        paint: l.type === 'symbol' ? labelPaint(l.id, look) : { ...l.paint, ...MODE_PAINT.status[l.id] },
    };
}

/** Text colours for the page's appearance; a layer's own paint wins. */
function labelPaint(id, look) {
    return { ...LABEL_PAINT[look], ...LAYERS.find((l) => l.id === id)?.paint };
}

/** Fill ground by status (the default) or by tenement type. */
export function setColourMode(map, mode) {
    for (const [id, paint] of Object.entries(MODE_PAINT[mode] ?? {})) {
        if (!map.getLayer(id)) continue;
        for (const [prop, value] of Object.entries(paint)) map.setPaintProperty(id, prop, value);
    }
}

/**
 * Fill patterns, drawn on demand when a layer asks for `pw-<kind>-<hex>`, so a
 * style switch or a new colour needs no setup: hatch (/), back (\), wide (sparse /), dots.
 */
function patternImage(name) {
    const m = /^pw-(hatch|back|wide|dots)-([0-9a-f]{6})$/.exec(name);
    if (!m) return null;
    const [, kind, hex] = m;
    const ratio = 2;
    const size = (kind === 'wide' ? 18 : kind === 'dots' ? 6 : 8) * ratio;
    const canvas = Object.assign(document.createElement('canvas'), { width: size, height: size });
    const g = canvas.getContext('2d');
    g.fillStyle = g.strokeStyle = `#${hex}`;
    if (kind === 'dots') {
        g.globalAlpha = 0.55;
        g.beginPath();
        g.arc(size / 2, size / 2, ratio * 0.9, 0, 2 * Math.PI);
        g.fill();
    } else {
        g.globalAlpha = kind === 'wide' ? 0.35 : 0.65;
        g.lineWidth = ratio * 0.8;
        g.beginPath();
        for (const o of [-size, 0, size]) {
            if (kind === 'back') {
                g.moveTo(o, 0);
                g.lineTo(o + size, size);
            } else {
                g.moveTo(o, size);
                g.lineTo(o + size, 0);
            }
        }
        g.stroke();
    }
    return { data: g.getImageData(0, 0, size, size), pixelRatio: ratio };
}

function townDot() {
    const size = 22;
    const canvas = Object.assign(document.createElement('canvas'), { width: size, height: size });
    const g = canvas.getContext('2d');
    g.fillStyle = '#a1a1aa';
    g.strokeStyle = '#0b0b0c';
    g.lineWidth = 3;
    g.beginPath();
    g.arc(size / 2, size / 2, 6, 0, 2 * Math.PI);
    g.fill();
    g.stroke();
    return { data: g.getImageData(0, 0, size, size), pixelRatio: 2 };
}

/** One-minute graticular block lines over the view, from zoom 11. */
function gridLines(map) {
    if (map.getZoom() < 11) return EMPTY;
    const b = map.getBounds();
    const [w, e, s, n] = [Math.floor(b.getWest() * 60), Math.ceil(b.getEast() * 60), Math.floor(b.getSouth() * 60), Math.ceil(b.getNorth() * 60)];
    const lines = [];
    for (let i = w; i <= e; i++) lines.push([[i / 60, s / 60], [i / 60, n / 60]]);
    for (let j = s; j <= n; j++) lines.push([[w / 60, j / 60], [e / 60, j / 60]]);
    return { type: 'Feature', geometry: { type: 'MultiLineString', coordinates: lines }, properties: {} };
}

const CHIP = 'max-w-full rounded-2xl bg-white/90 px-3 py-1 text-center text-xs text-zinc-700 shadow-sm ring-1 ring-zinc-900/10 dark:bg-zinc-900/90 dark:text-zinc-200 dark:ring-white/10';

/** Rendering extras that run once the map has loaded: chips, block grid, colour mode, dark basemap. */
export function attachRendering(map, component) {
    const shown = (id) => map.getLayer(id) && map.getLayoutProperty(id, 'visibility') !== 'none';

    // Chips at the top centre: what the zoomed-out shading means, and tiles loading.
    const box = Object.assign(document.createElement('div'), {
        className: 'pointer-events-none absolute inset-x-12 top-2 z-10 flex flex-col items-center gap-1',
    });
    const zoomChip = Object.assign(document.createElement('div'), {
        className: CHIP,
        textContent: 'Zoomed out: shaded ground is held or applied for. Zoom in to see each tenement.',
    });
    const loadingChip = Object.assign(document.createElement('div'), { className: `${CHIP} animate-pulse`, textContent: 'Loading tenements', role: 'status' });
    zoomChip.dataset.mapChip = 'zoomed-out';
    loadingChip.dataset.mapChip = 'loading';
    box.append(zoomChip, loadingChip);
    map.getContainer().append(box);
    const showZoomChip = () => (zoomChip.hidden = !(map.getZoom() < 8 && (shown('coverage-live') || shown('coverage-pending'))));
    map.on('zoom', showZoomChip);
    map.on('styledata', showZoomChip);
    showZoomChip();

    let loading = null;
    loadingChip.hidden = true;
    map.on('sourcedataloading', (e) => {
        if (!(e.sourceId in TILE_SOURCES) || loading) return;
        loading = setTimeout(() => (loadingChip.hidden = false), 250);
    });
    map.on('idle', () => {
        clearTimeout(loading);
        loading = null;
        loadingChip.hidden = true;
    });

    // The block grid follows the view while it is on.
    let gridKey = '';
    const drawGrid = () => {
        const key = shown('grid-line') ? `${map.getZoom() >= 11}:${map.getBounds().toArray().flat().map((v) => Math.round(v * 60))}` : '';
        if (key === gridKey) return;
        gridKey = key;
        map.getSource('grid')?.setData(key ? gridLines(map) : EMPTY);
    };
    map.on('moveend', drawGrid);
    map.on('styledata', drawGrid);

    // Colour mode: the component's `colourMode` ('status' or 'type'), when it has one.
    if ('colourMode' in component) {
        setColourMode(map, component.colourMode);
        component.$watch('colourMode', (mode) => setColourMode(map, mode));
    }

    // Dark basemap when the page is dark. Pegwatch's sources and layers carry over into the new style.
    let look = BASEMAP === BASEMAPS.dark ? 'dark' : 'light';
    const follow = () => {
        if (theme() === look) return;
        look = theme();
        BASEMAP = BASEMAPS[look];
        map.setStyle(BASEMAP, { transformStyle: (prev, next) => keepPegwatch(map, prev, next, look) });
    };
    const observer = new MutationObserver(follow);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    map.on('remove', () => observer.disconnect());
    map.on('style.load', () => {
        gridKey = '';
        component.sync?.();
    });
    follow();
}

/** The next basemap style with every non-basemap source and layer of the previous one, in the same places. */
function keepPegwatch(map, prev, next, look) {
    const base = basemaps.get(map);
    const lastBase = prev.layers.findLastIndex((l) => base.layers.has(l.id));
    const ours = [];
    prev.layers.forEach((l, i) => {
        if (!base.layers.has(l.id)) ours.push({ under: i < lastBase, layer: l.type === 'symbol' ? { ...l, paint: labelPaint(l.id, look) } : l });
    });
    basemaps.set(map, { layers: new Set(next.layers.map((l) => l.id)), sources: new Set(Object.keys(next.sources)) });

    const at = next.layers.findIndex((l) => l.type === 'symbol');
    const cut = at === -1 ? next.layers.length : at;
    return {
        ...next,
        sources: { ...next.sources, ...Object.fromEntries(Object.entries(prev.sources).filter(([id]) => !base.sources.has(id))) },
        layers: [
            ...next.layers.slice(0, cut),
            ...ours.filter((l) => l.under).map((l) => l.layer),
            ...next.layers.slice(cut),
            ...ours.filter((l) => !l.under).map((l) => l.layer),
        ],
    };
}
