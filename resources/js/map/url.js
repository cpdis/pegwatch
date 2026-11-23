// Map state in the URL: #map=<zoom>/<lat>/<lng>&l=<layer keys>&sel=<tenement slug>.
// Written with replaceState (no history entry per pan), read on load and on hashchange.
// Livewire's navigation restores the URL on Back, so the view comes back with it.

/** Parse a location hash. Returns null when it holds no map view. */
export function parseHash(hash) {
    const params = new URLSearchParams(String(hash ?? '').replace(/^#/, ''));
    const m = /^(\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)$/.exec(params.get('map') ?? '');
    if (!m) return null;
    const [zoom, lat, lng] = [+m[1], +m[2], +m[3]];
    if (zoom > 22 || Math.abs(lat) > 85 || Math.abs(lng) > 180) return null;
    const l = params.get('l');
    return { zoom, lat, lng, layers: l === null ? null : l.split(',').filter(Boolean), sel: params.get('sel') || null };
}

/** The hash for a view; `layers` is omitted when it is null (the defaults). */
export function formatHash({ zoom, lat, lng, layers = null, sel = null }) {
    let h = `#map=${+zoom.toFixed(2)}/${lat.toFixed(5)}/${lng.toFixed(5)}`;
    if (layers) h += `&l=${layers.join(',')}`;
    if (sel) h += `&sel=${sel}`;
    return h;
}

/** Layer toggles from a hash's key list: listed keys on, every other known key off. */
export function layersFrom(keys, defaults) {
    return Object.fromEntries([...new Set([...Object.keys(defaults), ...keys])].map((k) => [k, keys.includes(k)]));
}

const onKeys = (layers) => Object.keys(layers).filter((k) => layers[k]).sort();

export function attachUrlState(map, component, card, defaults) {
    const write = () => {
        const c = map.getCenter();
        const keys = onKeys(component.layers);
        const changed = keys.join(',') !== onKeys(defaults).join(',');
        const hash = formatHash({ zoom: map.getZoom(), lat: c.lat, lng: c.lng, layers: changed ? keys : null, sel: component.selection });
        if (hash !== window.location.hash) window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}${hash}`);
    };

    const apply = (state) => {
        map.jumpTo({ center: [state.lng, state.lat], zoom: state.zoom });
        if (state.layers) Object.assign(component.layers, layersFrom(state.layers, defaults));
        if (state.sel) map.once('idle', () => card.pinSlug(state.sel));
        else card.close();
    };

    const onHash = () => {
        const state = parseHash(window.location.hash);
        if (state) apply(state);
    };

    const initial = parseHash(window.location.hash);
    if (initial?.sel) map.once('idle', () => card.pinSlug(initial.sel));

    map.on('moveend', write);
    component.$watch('layers', write, { deep: true });
    component.$watch('selection', write);
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
}
