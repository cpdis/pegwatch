// Shapes drawn on the map by hand: the watch-area circle (W1) and the one-minute block
// counter (O13).
//
// Watch circle: `watch-start` (or `draw: true` in the page config) puts a circle at the map
// centre, radius the smaller of 25 km and half the view, with a centre handle to move it and
// an edge handle to size it (1 to 50 km). Every change dispatches `watch-shape`, which the
// watch panel reads. `draw: { tenement: { slug, fmt_tenid } }` or a `watch-start` with
// `tenement` dispatches the tenement shape instead. Maps that cannot draw (embedded maps)
// send the user to the watch page at the same place.

import { circlePolygon, distanceKm, offsetKm } from './util.js';
import { formatHash } from './url.js';

const MIN_KM = 1;
const MAX_KM = 50;
const BLOCKS = 'pw-blocks';

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
const round = (v, d) => +v.toFixed(d);
const emit = (detail) => window.dispatchEvent(new CustomEvent('watch-shape', { detail }));

function handle(className, label) {
    const el = document.createElement('div');
    el.className = `pw-handle ${className}`;
    el.setAttribute('aria-label', label);
    el.innerHTML = '<span class="pw-handle__dot"></span><span class="pw-handle__label"></span>';
    return el;
}

function attachWatchCircle(map, maplibregl, component, { enabled, auto, tenement }) {
    let circle = null; // { center: [lng, lat], radiusKm, angle }
    let centre = null;
    let edge = null;
    let raf = 0;

    const send = () => {
        raf = 0;
        if (circle) emit({ kind: 'circle', center: [round(circle.center[0], 5), round(circle.center[1], 5)], radiusKm: round(circle.radiusKm, 1) });
    };
    const update = (now = false) => {
        component.circle = { center: circle.center, radiusKm: circle.radiusKm };
        centre.setLngLat(circle.center);
        edge.setLngLat(offsetKm(circle.center, circle.radiusKm, circle.angle));
        edge.getElement().querySelector('.pw-handle__label').textContent = `${round(circle.radiusKm, 1)} km`;
        if (now) send();
        else raf ||= requestAnimationFrame(send);
    };

    function start({ center, radiusKm, tenement: t, fit = false } = {}) {
        if (t?.slug) return emit({ kind: 'tenement', slug: t.slug, fmt_tenid: t.fmt_tenid });
        const c = center ?? map.getCenter().toArray();
        if (!enabled) {
            const z = Math.max(map.getZoom(), 10);
            return window.Livewire?.navigate
                ? window.Livewire.navigate(`/watch${formatHash({ zoom: z, lat: c[1], lng: c[0] })}`)
                : window.location.assign(`/watch${formatHash({ zoom: z, lat: c[1], lng: c[0] })}`);
        }
        const b = map.getBounds();
        const half = Math.min(distanceKm(c, [b.getEast(), c[1]]), distanceKm(c, [c[0], b.getNorth()]));
        circle = { center: c, radiusKm: clamp(radiusKm ?? Math.min(25, half), MIN_KM, MAX_KM), angle: 0 };
        if (!centre) {
            centre = new maplibregl.Marker({ element: handle('pw-handle--centre', 'Move the watch area'), draggable: true }).setLngLat(c).addTo(map);
            edge = new maplibregl.Marker({ element: handle('pw-handle--edge', 'Resize the watch area'), draggable: true }).setLngLat(c).addTo(map);
            centre.on('drag', () => {
                circle.center = centre.getLngLat().toArray();
                update();
            });
            edge.on('drag', () => {
                const p = edge.getLngLat().toArray();
                circle.radiusKm = clamp(distanceKm(circle.center, p), MIN_KM, MAX_KM);
                const k = Math.cos((circle.center[1] * Math.PI) / 180);
                circle.angle = Math.atan2(p[1] - circle.center[1], (p[0] - circle.center[0]) * k);
                update();
            });
            for (const m of [centre, edge]) m.on('dragend', () => update(true));
        }
        update(true);
        // A circle much smaller than the view (25 km at state zoom is a dot) or larger than it:
        // frame it. Only for circles placed for the user, never under a drag.
        if ((fit || center) && (half > circle.radiusKm * 4 || half < circle.radiusKm)) {
            const c0 = circle.center;
            const r = circle.radiusKm;
            map.fitBounds([offsetKm(c0, r, Math.PI)[0], offsetKm(c0, r, -Math.PI / 2)[1], offsetKm(c0, r, 0)[0], offsetKm(c0, r, Math.PI / 2)[1]], { padding: 40, duration: 0 });
        }
    }

    function stop() {
        centre?.remove();
        edge?.remove();
        centre = edge = circle = null;
        component.circle = null;
    }

    const onStart = (e) => start(e.detail ?? {});
    window.addEventListener('watch-start', onStart);
    window.addEventListener('watch-stop', stop);
    if (tenement) start({ tenement });
    else if (auto) start({ fit: true });

    return () => {
        window.removeEventListener('watch-start', onStart);
        window.removeEventListener('watch-stop', stop);
    };
}

/** One-minute graticular blocks covering a box, snapped outward to whole minutes. */
export function countBlocks([w, s, e, n]) {
    const box = [Math.floor(w * 60) / 60, Math.floor(s * 60) / 60, Math.ceil(e * 60) / 60, Math.ceil(n * 60) / 60];
    const cols = Math.max(1, Math.round((box[2] - box[0]) * 60));
    const rows = Math.max(1, Math.round((box[3] - box[1]) * 60));
    box[2] = box[0] + cols / 60;
    box[3] = box[1] + rows / 60;
    // A minute of latitude is 1.852 km; a minute of longitude shrinks with the cosine of latitude.
    const ha = cols * rows * 1.852 * 1.852 * Math.cos((((box[1] + box[3]) / 2) * Math.PI) / 180) * 100;
    return { box, cols, rows, blocks: cols * rows, ha };
}

/** With the grid on, a "Count blocks" control; in that mode a drag draws a box and counts the blocks in it. */
function attachBlockCounter(map, component) {
    const canvas = map.getCanvas();
    const ctrl = document.createElement('div');
    ctrl.className = 'maplibregl-ctrl pw-blocks';
    ctrl.innerHTML = '<button type="button" class="pw-blocks__button">Count blocks</button><span class="pw-blocks__result" aria-live="polite"></span>';
    const button = ctrl.querySelector('button');
    const result = ctrl.querySelector('.pw-blocks__result');
    map.addControl({ onAdd: () => ctrl, onRemove: () => ctrl.remove() }, 'top-left');

    let mode = 'off'; // off | armed | dragging | shown
    let from = null;
    let handledAt = 0; // the click that follows a handled pointer is not a map click

    const ensure = () => {
        if (!map.getSource(BLOCKS)) map.addSource(BLOCKS, { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        if (!map.getLayer(`${BLOCKS}-fill`)) map.addLayer({ id: `${BLOCKS}-fill`, type: 'fill', source: BLOCKS, paint: { 'fill-color': '#ffd400', 'fill-opacity': 0.18 } });
        if (!map.getLayer(`${BLOCKS}-line`)) map.addLayer({ id: `${BLOCKS}-line`, type: 'line', source: BLOCKS, paint: { 'line-color': '#0b0b0c', 'line-width': 1.5 } });
    };
    const draw = (box) => {
        ensure();
        const [w, s, e, n] = box ?? [0, 0, 0, 0];
        map.getSource(BLOCKS).setData(
            box ? { type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [[[w, s], [e, s], [e, n], [w, n], [w, s]]] } } : { type: 'FeatureCollection', features: [] },
        );
    };
    const setMode = (next) => {
        mode = next;
        const counting = next === 'armed' || next === 'dragging';
        counting ? map.dragPan.disable() : map.dragPan.enable();
        map.getCanvasContainer().classList.toggle('pw-counting', counting);
        button.textContent = next === 'off' ? 'Count blocks' : next === 'shown' ? 'Clear' : 'Drag a box';
        button.setAttribute('aria-pressed', String(next !== 'off'));
        if (next === 'off') {
            draw(null);
            result.textContent = '';
        }
    };
    const box = (a, b) => [Math.min(a.lng, b.lng), Math.min(a.lat, b.lat), Math.max(a.lng, b.lng), Math.max(a.lat, b.lat)];
    const show = (lngLat) => {
        const c = countBlocks(box(from, lngLat));
        draw(c.box);
        result.textContent = `${c.cols} × ${c.rows} = ${c.blocks.toLocaleString('en-AU')} blocks · about ${Math.round(c.ha).toLocaleString('en-AU')} ha`;
    };

    button.addEventListener('click', () => setMode(mode === 'off' ? 'armed' : 'off'));
    canvas.addEventListener('pointerdown', (e) => {
        if (mode !== 'armed' && mode !== 'shown') return;
        handledAt = Date.now();
        if (mode === 'shown') return setMode('off');
        from = map.unproject([e.offsetX, e.offsetY]);
        canvas.setPointerCapture(e.pointerId);
        setMode('dragging');
    });
    canvas.addEventListener('pointermove', (e) => mode === 'dragging' && show(map.unproject([e.offsetX, e.offsetY])));
    canvas.addEventListener('pointerup', (e) => {
        if (mode !== 'dragging') return;
        handledAt = Date.now();
        show(map.unproject([e.offsetX, e.offsetY]));
        setMode('shown');
    });

    const sync = () => {
        ctrl.hidden = !component.layers.grid;
        if (!component.layers.grid && mode !== 'off') setMode('off');
    };
    sync();
    component.$watch('layers.grid', sync);
    map.on('style.load', () => mode !== 'off' && ensure());
    const onEscape = (e) => e.key === 'Escape' && mode !== 'off' && setMode('off');
    window.addEventListener('keydown', onEscape);
    return {
        busy: () => mode !== 'off' || Date.now() - handledAt < 600,
        destroy: () => window.removeEventListener('keydown', onEscape),
    };
}

/** Called once the map has loaded. Returns { busy, destroy }: busy() is true while a block box is being drawn. */
export function attachDraw(map, maplibregl, component, options) {
    const stopCircle = attachWatchCircle(map, maplibregl, component, options);
    const blocks = attachBlockCounter(map, component);
    return {
        busy: blocks.busy,
        destroy() {
            stopCircle();
            blocks.destroy();
        },
    };
}
