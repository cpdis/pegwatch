// Map controls: zoom, locate me, scale (top left, PH2), the satellite toggle, cursor
// coordinates with copy (G3), the keyboard crosshair (O14) and the collapsed attribution.

import { copyText, formatLatLng, isOwnSource, liftBasemapLabels, site } from './util.js';

const SATELLITE = 'pw-satellite';

/** A control from an element: MapLibre places and stacks it in its corner. */
function control(el) {
    return { onAdd: () => el, onRemove: () => el.remove() };
}

export function addControls(map, maplibregl, component) {
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(
        new maplibregl.GeolocateControl({ positionOptions: { enableHighAccuracy: true }, fitBoundsOptions: { maxZoom: 13 }, trackUserLocation: false }),
        'top-right',
    );
    if (site().satellite) {
        const group = document.createElement('div');
        group.className = 'maplibregl-ctrl maplibregl-ctrl-group';
        group.innerHTML = `<button type="button" class="pw-satellite-toggle" aria-pressed="false" title="Satellite imagery" aria-label="Satellite imagery">
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5l5-2 4 2 5-2v12l-5 2-4-2-5 2z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 3v12M12 5v12" stroke="currentColor" stroke-width="1.6"/></svg></button>`;
        group.firstElementChild.addEventListener('click', () => (component.layers.satellite = !component.layers.satellite));
        map.addControl(control(group), 'top-right');
    }
    map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'top-left');
}

/** The satellite raster under every Pegwatch layer, with the basemap's labels lifted above it. */
function ensureSatellite(map, satellite, on) {
    if (!map.getSource(SATELLITE)) {
        map.addSource(SATELLITE, { type: 'raster', tiles: [satellite.url], tileSize: 256, attribution: satellite.attribution, maxzoom: 19 });
    }
    if (!map.getLayer(SATELLITE)) {
        const { layers, sources } = map.getStyle();
        const firstOwn = layers.find((l) => l.source && isOwnSource(sources, l.source))?.id;
        map.addLayer({ id: SATELLITE, type: 'raster', source: SATELLITE, layout: { visibility: on ? 'visible' : 'none' } }, firstOwn);
        liftBasemapLabels(map, firstOwn);
    }
    map.setLayoutProperty(SATELLITE, 'visibility', on ? 'visible' : 'none');
}

/**
 * Controls that need the loaded map and the card. `full` is the full map page: only there
 * do touch screens get the centre crosshair and its coordinates (an embedded map's centre
 * is the subject, not a point the user chose). Returns a cleanup function.
 */
export function attachControls(map, component, card, { full = true } = {}) {
    const container = map.getContainer();
    const canvas = map.getCanvas();
    const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const { satellite } = site();

    // MapLibre opens the compact attribution expanded; start it collapsed (PH2).
    const collapse = () => container.querySelector('.maplibregl-ctrl-attrib')?.classList.remove('maplibregl-compact-show');
    collapse();
    map.once('idle', collapse);

    if (satellite) {
        const sync = () => {
            ensureSatellite(map, satellite, !!component.layers.satellite);
            container.querySelector('.pw-satellite-toggle')?.setAttribute('aria-pressed', String(!!component.layers.satellite));
        };
        sync();
        component.$watch('layers.satellite', sync);
        map.on('style.load', sync);
    }

    // Coordinates: the cursor on desktop; the centre crosshair on touch and from the keyboard.
    const crosshair = document.createElement('div');
    crosshair.className = 'pw-crosshair';
    crosshair.setAttribute('aria-hidden', 'true');
    container.appendChild(crosshair);
    container.classList.toggle('pw-touch', !canHover && full);

    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'maplibregl-ctrl pw-coords';
    chip.title = canHover ? 'Copy these coordinates. Right-click the map to copy any point.' : 'Copy the coordinates at the crosshair';
    if (canHover || full) map.addControl(control(chip), 'bottom-left');

    let shown = null;
    let flashUntil = 0;
    const show = (lngLat) => {
        shown = [lngLat.lng, lngLat.lat];
        if (Date.now() > flashUntil) chip.innerHTML = `${formatLatLng(shown)} <span>WGS84</span>`;
    };
    const flash = (text) => {
        flashUntil = Date.now() + 1500;
        chip.textContent = text;
        setTimeout(() => shown && ((flashUntil = 0), show({ lng: shown[0], lat: shown[1] })), 1500);
    };
    const centred = () => !canHover || container.classList.contains('pw-kbd');
    show(map.getCenter());
    map.on('move', () => centred() && show(map.getCenter()));
    if (canHover) {
        let raf = 0;
        let last = null;
        map.on('mousemove', (e) => {
            last = e.lngLat;
            raf ||= requestAnimationFrame(() => ((raf = 0), !centred() && show(last)));
        });
    }
    chip.addEventListener('click', async () => shown && flash((await copyText(formatLatLng(shown))) ? 'Copied' : 'Copy failed'));
    map.on('contextmenu', async (e) => {
        e.originalEvent.preventDefault();
        show(e.lngLat);
        flash((await copyText(formatLatLng(shown))) ? `Copied ${formatLatLng(shown)}` : 'Copy failed');
    });

    // Keyboard (O14): with the canvas focused, a centre crosshair; Enter clicks there; Up/Down
    // move through a pinned list instead of panning; Escape closes the card.
    const keyboard = (on) => {
        container.classList.toggle('pw-kbd', on);
        if (on) show(map.getCenter());
    };
    canvas.addEventListener('focus', () => keyboard(canvas.matches(':focus-visible')));
    canvas.addEventListener('blur', () => keyboard(false));
    canvas.addEventListener('mousedown', () => keyboard(false));
    canvas.addEventListener('keydown', (e) => {
        if (e.altKey || e.ctrlKey || e.metaKey) return;
        keyboard(true);
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!card.enter()) {
                const c = map.getCenter();
                card.clickAt(map.project(c), c);
            }
        } else if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && card.step(e.key === 'ArrowDown' ? 1 : -1)) {
            e.preventDefault();
            e.stopPropagation(); // MapLibre listens on the canvas container and would pan
        }
    });
    const onEscape = (e) => e.key === 'Escape' && card.isOpen() && card.close();
    window.addEventListener('keydown', onEscape);

    return () => window.removeEventListener('keydown', onEscape);
}
