// Alpine component for the MapLibre map. Livewire renders the page; the map is a
// client-side island (wire:ignore) fed by the vector tiles at /tiles/{layer}/{z}/{x}/{y}.mvt.
// Each concern lives in its own module so separate pieces of work do not collide.

import { BASEMAP, BLANK_STYLE, DEFAULT_LAYERS, EMPTY, LAYER_GROUPS, WA_BOUNDS, addLayers, attachRendering } from './style.js';
import { attachCard } from './card.js';
import { addControls, attachControls } from './controls.js';
import { attachUrlState, layersFrom, parseHash } from './url.js';
import { attachDraw } from './draw.js';
import { attachFilters } from './filters.js';
import { attachFocus, padBounds } from './focus.js';
import { circlePolygon } from './util.js';

export default function tenementMap(config = {}) {
    let map = null;
    let ready = false;
    const cleanups = [];
    // The full map page (no bounds of its own), as opposed to a map embedded in a tenement or holder page.
    const full = config.url ?? !config.bounds;
    const fromUrl = full ? parseHash(window.location.hash) : null;
    const defaults = { ...DEFAULT_LAYERS, ...(config.layers ?? {}) };

    return {
        layers: fromUrl?.layers ? layersFrom(fromUrl.layers, defaults) : { ...defaults },
        highlight: config.highlight ?? null,
        markers: config.markers ?? [],
        circle: config.circle ?? null,
        hovered: null,
        pick: config.pick ?? false,
        /** Slug of the tenement the pinned card shows (written to the URL as `sel`). */
        selection: null,

        async init() {
            // MapLibre is about 1 MB, so only pages with a map load it.
            const [{ default: maplibregl }] = await Promise.all([import('maplibre-gl'), import('maplibre-gl/dist/maplibre-gl.css')]);
            if (!this.$refs.map?.isConnected) return;
            const view = fromUrl
                ? { center: [fromUrl.lng, fromUrl.lat], zoom: fromUrl.zoom }
                : { bounds: config.context && config.bounds ? padBounds(config.bounds) : (config.bounds ?? WA_BOUNDS), fitBoundsOptions: { padding: 40, maxZoom: 13 } };
            map = new maplibregl.Map({
                container: this.$refs.map,
                style: BASEMAP,
                ...view,
                attributionControl: { compact: true, customAttribution: '© State of WA (DMPE), CC BY 4.0' },
            });
            addControls(map, maplibregl, this);

            // Only a basemap style that never loaded falls back. A tile error (sourceId set) must
            // not: swapping the style then would drop every Pegwatch layer.
            let fellBack = false;
            map.on('error', (e) => {
                if (!fellBack && !ready && !e.sourceId && !map.isStyleLoaded() && /style|fetch|Failed/i.test(String(e.error?.message ?? ''))) {
                    fellBack = true;
                    map.setStyle(BLANK_STYLE);
                }
            });

            map.on('load', () => {
                addLayers(map);
                attachRendering(map, this);
                const { only } = attachFocus(map, config);
                const card = attachCard(map, this, { only });
                const filters = attachFilters(map);
                cleanups.push(card.destroy, attachControls(map, this, card, { full }));
                if (full) cleanups.push(attachUrlState(map, this, card, defaults)); // embedded maps leave the URL alone
                attachDraw(map, this);
                this.listen(card, filters);
                ready = true;
                this.sync();
                this.moved();
            });

            this.$watch('layers', () => this.sync(), { deep: true });
            this.$watch('circle', () => this.sync());
            this.$watch('hovered', () => this.sync());
        },

        /** The panel's window events (contracts.md). */
        listen(card, filters) {
            const on = (name, fn) => {
                const handler = (e) => fn(e.detail ?? {});
                window.addEventListener(name, handler);
                cleanups.push(() => window.removeEventListener(name, handler));
            };
            on('map-filter', (d) => filters.apply(d));
            on('map-layers', (d) => Object.assign(this.layers, d.layers ?? {}));
            on('map-select', (d) => card.select(d.tenid ?? null));
            on('map-fly', (d) => {
                if (d.bounds) map.fitBounds(d.bounds, { padding: 40, maxZoom: 14 });
                else if (d.center) map.flyTo({ center: d.center, zoom: d.zoom ?? Math.max(map.getZoom(), 12) });
            });
            map.on('moveend', () => this.moved());
        },

        /** Tell the panel what is in view. */
        moved() {
            const b = map.getBounds();
            const c = map.getCenter();
            const r = (v) => +v.toFixed(5);
            window.dispatchEvent(
                new CustomEvent('map-moved', {
                    detail: { bounds: [r(b.getWest()), r(b.getSouth()), r(b.getEast()), r(b.getNorth())], zoom: +map.getZoom().toFixed(2), center: [r(c.lng), r(c.lat)] },
                }),
            );
        },

        destroy() {
            cleanups.splice(0).forEach((fn) => fn());
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
            for (const [group, ids] of Object.entries(LAYER_GROUPS)) ids.forEach((id) => set(id, !!this.layers[group]));
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
