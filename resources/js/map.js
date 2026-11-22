
// Alpine component for the MapLibre map. Livewire renders the page; the map is a
// client-side island (wire:ignore) fed by the vector tiles at /tiles/{layer}/{z}/{x}/{y}.mvt.

const WA_BOUNDS = [112.5, -35.5, 129.5, -13.5];
const BASEMAP = 'https://tiles.openfreemap.org/styles/positron';
// Used if the basemap style cannot be fetched, so the tenement layers still draw.
const BLANK_STYLE = { version: 8, sources: {}, layers: [{ id: 'background', type: 'background', paint: { 'background-color': '#f4f1ea' } }] };

const TYPE_COLOURS = { E: '#2563eb', P: '#ea580c', M: '#dc2626', L: '#6b7280', G: '#7c3aed', R: '#0d9488' };
const OTHER_COLOUR = '#0f766e';
const typeColour = ['match', ['get', 'type_code'], ...Object.entries(TYPE_COLOURS).flat(), OTHER_COLOUR];
const EMPTY = { type: 'FeatureCollection', features: [] };
const CLICKABLE = ['live-fill', 'pending-fill', 'releases-fill', 'dead-line', 'restrictions-fill'];

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

function slug(s) {
    return String(s ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function circlePolygon([lng, lat], radiusKm) {
    const pts = [];
    for (let i = 0; i <= 64; i++) {
        const a = (i / 64) * 2 * Math.PI;
        const dLat = (radiusKm / 111.32) * Math.sin(a);
        const dLng = ((radiusKm / 111.32) * Math.cos(a)) / Math.cos((lat * Math.PI) / 180);
        pts.push([lng + dLng, lat + dLat]);
    }
    return { type: 'Polygon', coordinates: [pts] };
}

function addLayers(m) {
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

function popupHtml(features) {
    const seen = new Set();
    return features
        .filter((f) => {
            const k = `${f.layer.id}:${f.properties.fmt_tenid ?? f.properties.name}`;
            return seen.has(k) ? false : seen.add(k);
        })
        .slice(0, 8)
        .map((f) => {
            const p = f.properties;
            if (f.layer.id === 'restrictions-fill') return `<div><b>${esc(p.kind)}</b> ${esc(p.name)}</div>`;
            const extra =
                f.layer.id === 'releases-fill'
                    ? ` release ${esc(p.rlesid)} at ${esc(p.release_at)}`
                    : f.layer.id === 'dead-line'
                      ? ` dead ${esc(p.ended)} (${esc(p.reason)})`
                      : ` ${esc((p.status ?? '').toLowerCase())}${p.holder ? ' · ' + esc(p.holder) : ''}`;
            return `<div><a href="/tenements/${slug(p.fmt_tenid)}" style="color:#2563eb;font-weight:600">${esc(p.fmt_tenid)}</a>${extra}</div>`;
        })
        .join('');
}

export default function tenementMap(config = {}) {
    let map = null;
    let ready = false;

    return {
        layers: { tenements: true, pending: true, dead: false, releases: true, restrictions: false, ...(config.layers ?? {}) },
        highlight: config.highlight ?? null,
        markers: config.markers ?? [],
        circle: config.circle ?? null,
        hovered: null,
        pick: config.pick ?? false,

        async init() {
            // MapLibre is about 1 MB, so only pages with a map load it.
            const [{ default: maplibregl }] = await Promise.all([import('maplibre-gl'), import('maplibre-gl/dist/maplibre-gl.css')]);
            if (!this.$refs.map?.isConnected) return;
            map = new maplibregl.Map({
                container: this.$refs.map,
                style: BASEMAP,
                bounds: config.bounds ?? WA_BOUNDS,
                fitBoundsOptions: { padding: 40, maxZoom: 13 },
                attributionControl: { compact: true, customAttribution: '© State of WA (DMPE), CC BY 4.0' },
            });
            map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
            map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left');

            let fellBack = false;
            map.on('error', (e) => {
                if (!fellBack && !map.isStyleLoaded() && /style|fetch|Failed/i.test(String(e.error?.message ?? ''))) {
                    fellBack = true;
                    map.setStyle(BLANK_STYLE);
                }
            });

            map.on('load', () => {
                addLayers(map);
                const popup = new maplibregl.Popup({ closeButton: false, maxWidth: '280px' });
                map.on('click', (ev) => {
                    if (this.pick) {
                        this.$dispatch('map-pick', { lng: +ev.lngLat.lng.toFixed(5), lat: +ev.lngLat.lat.toFixed(5) });
                        return;
                    }
                    const feats = map.queryRenderedFeatures(ev.point, { layers: CLICKABLE.filter((l) => map.getLayer(l)) });
                    if (feats.length === 0) return popup.remove();
                    popup.setLngLat(ev.lngLat).setHTML(`<div style="font-size:12px;line-height:1.5">${popupHtml(feats)}</div>`).addTo(map);
                    popup.getElement()?.querySelectorAll('a').forEach((a) =>
                        a.addEventListener('click', (e) => {
                            e.preventDefault();
                            window.Livewire.navigate(a.getAttribute('href'));
                        }),
                    );
                });
                for (const l of CLICKABLE) {
                    map.on('mouseenter', l, () => (map.getCanvas().style.cursor = 'pointer'));
                    map.on('mouseleave', l, () => (map.getCanvas().style.cursor = ''));
                }
                ready = true;
                this.sync();
            });

            this.$watch('layers', () => this.sync(), { deep: true });
            this.$watch('circle', () => this.sync());
            this.$watch('hovered', () => this.sync());
        },

        destroy() {
            map?.remove();
            map = null;
            ready = false;
        },

        fit(bounds) {
            map?.fitBounds(bounds, { padding: 40, maxZoom: 13 });
        },

        sync() {
            if (!ready) return;
            const set = (id, on) => map.getLayer(id) && map.setLayoutProperty(id, 'visibility', on ? 'visible' : 'none');
            set('live-fill', this.layers.tenements);
            set('live-line', this.layers.tenements);
            set('pending-fill', this.layers.pending);
            set('pending-line', this.layers.pending);
            set('dead-line', this.layers.dead);
            set('releases-fill', this.layers.releases);
            set('restrictions-fill', this.layers.restrictions);
            set('restrictions-line', this.layers.restrictions);
            map.getSource('highlight').setData(this.highlight ? { type: 'Feature', geometry: this.highlight, properties: {} } : EMPTY);
            map.getSource('markers').setData({
                type: 'FeatureCollection',
                features: this.markers.map((mk) => ({
                    type: 'Feature',
                    geometry: { type: 'Point', coordinates: mk.point },
                    properties: { colour: this.hovered === mk.id ? '#facc15' : mk.colour },
                })),
            });
            const c = this.circle;
            map.getSource('circle').setData(
                c && c.center[0] !== null && c.center[1] !== null
                    ? { type: 'Feature', geometry: circlePolygon(c.center, c.radiusKm), properties: {} }
                    : EMPTY,
            );
        },
    };
}
