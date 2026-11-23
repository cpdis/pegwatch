// Sources, layers and paint for the tenement map. The map-rendering work owns this file.

export const WA_BOUNDS = [112.5, -35.5, 129.5, -13.5];
export const BASEMAP = 'https://tiles.openfreemap.org/styles/positron';
// Used if the basemap style cannot be fetched, so the tenement layers still draw.
export const BLANK_STYLE = { version: 8, sources: {}, layers: [{ id: 'background', type: 'background', paint: { 'background-color': '#f4f1ea' } }] };

export const TYPE_COLOURS = { E: '#2563eb', P: '#ea580c', M: '#dc2626', L: '#6b7280', G: '#7c3aed', R: '#0d9488' };
export const OTHER_COLOUR = '#0f766e';
export const typeColour = ['match', ['get', 'type_code'], ...Object.entries(TYPE_COLOURS).flat(), OTHER_COLOUR];
export const EMPTY = { type: 'FeatureCollection', features: [] };
export const CLICKABLE = ['live-fill', 'pending-fill', 'releases-fill', 'dead-line', 'restrictions-fill'];

/** Layer ids behind each layer toggle. The component shows or hides a whole group. */
export const LAYER_GROUPS = {
    tenements: ['live-fill', 'live-line'],
    pending: ['pending-fill', 'pending-line'],
    dead: ['dead-line'],
    releases: ['releases-fill'],
    restrictions: ['restrictions-fill', 'restrictions-line'],
};

/** Which groups show when a map first loads. */
export const DEFAULT_LAYERS = { tenements: true, pending: true, dead: false, releases: true, restrictions: false };

/** Add every Pegwatch source and layer to a loaded map. */
export function addLayers(m) {
    const tiles = (layer) => ({ type: 'vector', tiles: [`${window.location.origin}/tiles/${layer}/{z}/{x}/{y}.mvt`], maxzoom: 14 });
    m.addSource('tenements', { ...tiles('tenements'), minzoom: 3 });
    m.addSource('dead', { ...tiles('dead'), minzoom: 6 });
    m.addSource('releases', tiles('releases'));
    m.addSource('restrictions', { ...tiles('restrictions'), minzoom: 5 });
    for (const s of ['highlight', 'markers', 'circle']) m.addSource(s, { type: 'geojson', data: EMPTY });

    const vec = (id, type, source, paint, filter) =>
        m.addLayer({ id, type, source, 'source-layer': source, paint, ...(filter ? { filter } : {}) });
    const live = ['==', ['get', 'status'], 'LIVE'];
    const pending = ['==', ['get', 'status'], 'PENDING'];
    vec('restrictions-fill', 'fill', 'restrictions', { 'fill-color': '#a16207', 'fill-opacity': 0.12 });
    vec('restrictions-line', 'line', 'restrictions', { 'line-color': '#a16207', 'line-width': 1, 'line-dasharray': [2, 2] });
    vec('dead-line', 'line', 'dead', { 'line-color': '#111827', 'line-width': 1, 'line-dasharray': [1, 2] });
    vec('live-fill', 'fill', 'tenements', { 'fill-color': typeColour, 'fill-opacity': 0.18 }, live);
    vec('live-line', 'line', 'tenements', { 'line-color': typeColour, 'line-width': ['interpolate', ['linear'], ['zoom'], 6, 0.4, 12, 1.5] }, live);
    vec('pending-fill', 'fill', 'tenements', { 'fill-color': typeColour, 'fill-opacity': 0.06 }, pending);
    vec('pending-line', 'line', 'tenements', { 'line-color': typeColour, 'line-width': 1, 'line-dasharray': [3, 2] }, pending);
    vec('releases-fill', 'fill', 'releases', { 'fill-color': '#dc2626', 'fill-opacity': 0.35 });
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

/** Rendering extras that run once the map has loaded (loading state, dark style, grid). */
export function attachRendering(map, component) {}
