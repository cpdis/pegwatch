// What the map shows when a tenement is hovered or clicked. The map-interaction work owns this file.

import { esc, slug } from './util.js';
import { CLICKABLE } from './style.js';

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

/**
 * Open a popup for whatever is under a click. `closeOnClick` stays off: MapLibre's
 * default closes the popup on the same click that fills it, so every second click
 * showed nothing (bug B19). A click on empty map closes it instead.
 */
export function attachCard(map, maplibregl, component) {
    const popup = new maplibregl.Popup({ closeButton: false, closeOnClick: false, maxWidth: '280px' });
    map.on('click', (ev) => {
        if (component.pick) {
            component.$dispatch('map-pick', { lng: +ev.lngLat.lng.toFixed(5), lat: +ev.lngLat.lat.toFixed(5) });
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
    return popup;
}
