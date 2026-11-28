// Focus and context modes. Both mute every vector layer under a veil and draw their own
// subject above it:
//   focus:   { features }            one holder's tenements, coloured by status (H1)
//   context: { locator, releases }   one tenement (the `highlight` geometry), its release
//                                    parcel hatched in magenta, and a WA locator inset (T3, G18)

import { liftBasemapLabels, site } from './util.js';

const VEIL = 'pw-veil';
/** style.js layers that stay above the veil (the watch circle, the highlight, event markers). */
const OVERLAYS = ['circle-fill', 'circle-line', 'highlight-line', 'markers'];
const HATCH = 'pw-hatch-release';

/** A tenement's bounds padded to about three times its size, so the towns and roads around it show. */
export function padBounds([w, s, e, n]) {
    const dx = Math.max(e - w, 0.04);
    const dy = Math.max(n - s, 0.04);
    return [w - dx, s - dy, e + dx, n + dy];
}

/** A 45° magenta hatch for release parcels. */
function hatchImage(colour) {
    const c = document.createElement('canvas');
    c.width = c.height = 16;
    const g = c.getContext('2d');
    g.strokeStyle = colour;
    g.lineWidth = 3;
    g.beginPath();
    for (const o of [-16, 0, 16]) {
        g.moveTo(o, 16);
        g.lineTo(o + 16, 0);
    }
    g.stroke();
    return g.getImageData(0, 0, 16, 16);
}

const asCollection = (g) =>
    !g ? { type: 'FeatureCollection', features: [] } : g.type === 'FeatureCollection' ? g : g.type === 'Feature' ? { type: 'FeatureCollection', features: [g] } : { type: 'FeatureCollection', features: [{ type: 'Feature', geometry: g, properties: {} }] };

// A rough WA coastline for the locator inset (clockwise from the NT border).
const WA = [
    [129, -14.88], [128.4, -14.85], [128.1, -15.2], [127.7, -14.6], [127, -13.85], [126.2, -14.1], [125.6, -14.4], [125, -14.7],
    [124.6, -15.3], [124, -15.9], [123.5, -16.5], [123, -16.4], [122.5, -17], [122.2, -17.8], [122.3, -18.2], [121.6, -18.8],
    [121, -19.5], [120, -19.9], [119, -20.1], [118.2, -20.4], [117.4, -20.7], [116.7, -20.6], [115.9, -21.1], [115.3, -21.6],
    [114.6, -21.8], [114.2, -22.3], [113.8, -22], [113.7, -23], [113.8, -23.9], [113.4, -24.4], [113.6, -25.6], [113.9, -26],
    [113.3, -26.3], [114, -27.5], [114.2, -28.2], [114.6, -28.8], [115, -29.6], [115.1, -30.5], [115.6, -31.5], [115.7, -32.3],
    [115.6, -33.3], [115.1, -33.6], [115, -34.3], [115.7, -34.6], [116.5, -35], [117.5, -35.1], [118.2, -34.9], [119.2, -34.5],
    [119.9, -33.9], [121, -33.8], [122, -33.9], [123.3, -33.95], [124, -33.2], [124.3, -32.9], [125.2, -32.6], [126.3, -32.3],
    [127.3, -32.25], [128.2, -31.9], [129, -31.69],
];

class Locator {
    constructor(center) {
        this.center = center;
    }

    onAdd() {
        const k = Math.cos((24 * Math.PI) / 180);
        const xy = ([lng, lat]) => [((lng - 112.8) * k).toFixed(2), (-13.5 - lat).toFixed(2)];
        const [cx, cy] = xy(this.center);
        this.el = document.createElement('div');
        this.el.className = 'maplibregl-ctrl pw-locator';
        this.el.innerHTML = `<svg viewBox="-0.5 -0.5 16 22.5" role="img" aria-label="Where this is in Western Australia">
            <path d="M${WA.map((p) => xy(p).join(' ')).join('L')}Z" />
            <circle cx="${cx}" cy="${cy}" r="1.1" /></svg>`;
        return this.el;
    }

    onRemove() {
        this.el.remove();
    }
}

/**
 * Set up focus or context mode from the page config. Returns `only`, the card's filter:
 * in focus mode the card describes the holder's tenements and nothing under the veil.
 */
export function attachFocus(map, config) {
    const { focus, context } = config;
    if (!focus && !context) return { only: () => null };
    const colours = site().status ?? {};
    const statusColour = ['match', ['get', 'status'], 'LIVE', colours.live ?? '#15803d', 'PENDING', colours.pending ?? '#d97706', colours.dead ?? '#71717a'];
    const release = colours.release ?? '#c026d3';
    const dark = document.documentElement.classList.contains('dark');

    // Idempotent: a style change (the dark basemap) drops these, and this puts back what is missing.
    const ensure = () => {
        const before = OVERLAYS.find((id) => map.getLayer(id));
        const add = (layer) => map.getLayer(layer.id) || map.addLayer(layer, before);
        const source = (id, data) => map.getSource(id) || map.addSource(id, { type: 'geojson', data });
        add({ id: VEIL, type: 'background', paint: { 'background-color': dark ? '#0b0b0c' : '#ffffff', 'background-opacity': 0.6 } });
        liftBasemapLabels(map, before);
        if (focus) {
            source('pw-focus', asCollection(focus.features));
            add({ id: 'pw-focus-fill', type: 'fill', source: 'pw-focus', paint: { 'fill-color': statusColour, 'fill-opacity': 0.3 } });
            add({ id: 'pw-focus-line', type: 'line', source: 'pw-focus', paint: { 'line-color': statusColour, 'line-width': 1.5 } });
            // The holder's own IDs, above the veil, so a muted neighbour's label never wins the spot.
            if (map.getStyle().glyphs) {
                add({
                    id: 'pw-focus-label', type: 'symbol', source: 'pw-focus', minzoom: 9,
                    layout: { 'text-field': ['get', 'fmt_tenid'], 'text-font': ['Noto Sans Bold'], 'text-size': 11 },
                    paint: { 'text-color': dark ? '#ffffff' : '#18181b', 'text-halo-color': dark ? '#0b0b0c' : '#ffffff', 'text-halo-width': 1.5 },
                });
            }
        }
        if (context) {
            source('pw-context', asCollection(config.highlight));
            add({ id: 'pw-context-fill', type: 'fill', source: 'pw-context', paint: { 'fill-color': '#ffd400', 'fill-opacity': 0.2 } });
            if (context.releases) {
                if (!map.hasImage(HATCH)) map.addImage(HATCH, hatchImage(release), { pixelRatio: 2 });
                source('pw-context-releases', asCollection(context.releases));
                add({ id: 'pw-context-releases-fill', type: 'fill', source: 'pw-context-releases', paint: { 'fill-pattern': HATCH } });
                add({ id: 'pw-context-releases-line', type: 'line', source: 'pw-context-releases', paint: { 'line-color': release, 'line-width': 1.5 } });
            }
        }
    };
    ensure();
    map.on('style.load', ensure);

    if (context?.locator && config.bounds) {
        const [w, s, e, n] = config.bounds;
        map.addControl(new Locator([(w + e) / 2, (s + n) / 2]), 'top-left');
    }

    const names = focus ? new Set(asCollection(focus.features).features.map((f) => f.properties?.fmt_tenid)) : null;
    return { only: () => (names ? (item) => names.has(item.p.fmt_tenid) : null) };
}
