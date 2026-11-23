// What the map shows when a tenement is hovered or clicked (Build spec 1).
//
// The card is a three-state machine: idle, hover (follows the cursor, desktop only) and
// pinned (after a click: actions, a row per feature, a bottom sheet on phones). Every
// rendered feature becomes an "item" through KINDS, keyed by its source, so a tile with
// fewer fields (today's tiles, or below zoom 8) only drops rows.

import * as style from './style.js';
import { areaHa, bboxOf, copyText, esc, formatDay, formatLatLng, formatReleaseTime, monthsUntil, parseDay, perthDay, perthToday, relativeShort, site, slug } from './util.js';

/** Layers the card reads, from style.js when it says, else today's layer ids. */
const HOVERABLE = style.HOVERABLE ?? ['releases-fill', 'pending-fill', 'live-fill', 'dead-fill', 'freed-fill', 'restrictions-fill'];
const CLICKABLE = style.CLICKABLE ?? HOVERABLE;
/** Below this zoom the tiles carry no card fields (a-render's state view). */
export const DETAIL_ZOOM = 8;

const SHOW_MS = 120;
const HIDE_MS = 80;

const TYPE_NAMES = {
    E: 'Exploration licence', P: 'Prospecting licence', M: 'Mining lease', L: 'Miscellaneous licence',
    G: 'General purpose lease', R: 'Retention licence', MC: 'Mineral claim', ML: 'Mineral lease',
    CML: 'Coal mining lease', TR: 'Temporary reserve', AM: 'Mining lease S.A.', AML: 'Mineral lease S.A.',
    AG: 'General purpose lease S.A.', AL: 'Miscellaneous licence S.A.', MCI: 'Christmas Island mining lease',
    ECI: 'Christmas Island exploration licence', GCI: 'Christmas Island general purpose lease',
    LCI: 'Christmas Island miscellaneous licence', EOS: 'Exploration licence offshore',
    MEL: 'Commonwealth exploration licence', LTT: 'Licence to treat tailings',
};
const RESTRICTION_NAMES = {
    s19: 'Section 19 land', s57_4: 'Section 57(4) land', s57_2aa: 'Section 57(2aa) land',
    file_notation: 'File notation area', clearance: 'Clearance area',
};
const STATUS_LABELS = { live: 'Live', pending: 'Pending', dead: 'Dead', release: 'Release due', restricted: 'Restricted' };
/** Order among features of the same size: what changes soonest first, context last. */
const RANK = { release: 0, pending: 1, live: 2, dead: 3, restricted: 4 };

const sentence = (s) => (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).toLowerCase() : '');
const number = (n) => Number(n).toLocaleString('en-AU', { maximumFractionDigits: n < 10 ? 1 : 0 });
const codeOf = (p) => p.type_code ?? /^([A-Z]+)\s/.exec(p.fmt_tenid ?? '')?.[1];

function typeLine(p, cfg, suffix = '') {
    const code = codeOf(p);
    const name = p.special === true || p.special === 'true' ? 'Special prospecting licence' : (TYPE_NAMES[code] ?? code ?? '');
    const field = cfg.fields?.[/^\s*[A-Z]{1,3}\s*(\d{2})\s*\//i.exec(p.fmt_tenid ?? '')?.[1]];
    return [name + suffix, field].filter(Boolean).join(' · ');
}

function holderLine(p) {
    if (!p.holder) return '';
    const more = Number(p.holders_n) > 1 ? ` <span class="pw-muted">+${Number(p.holders_n) - 1}</span>` : '';
    return `${esc(p.holder)}${more}`;
}

const ha = (v) => (v === undefined || v === null || v === '' ? '' : `${number(v)} ha`);

/** Row 4's date for a live or pending tenement (Build spec 1 date rule). */
function tenementDate(p, status, today) {
    if (status === 'pending' && p.applied) return esc(`Applied ${formatDay(p.applied)} · ${relativeShort(p.applied, today)}`);
    if (status === 'live' && p.ends) {
        const m = monthsUntil(p.ends, today);
        if (m < 0) return esc(`Past expiry (${formatDay(p.ends)}), still live`);
        const text = esc(`Expires ${formatDay(p.ends)} · ${relativeShort(p.ends, today)}`);
        return m < 6 ? `<span class="pw-soon">${text}</span>` : text;
    }
    if (status === 'live' && p.granted) return esc(`Granted ${formatDay(p.granted)} · ${relativeShort(p.granted, today)}`);
    return '';
}

function changeFlag(p, today) {
    if (!p.changed_type || !p.changed_at) return '';
    return esc(`Changed ${relativeShort(perthDay(p.changed_at), today)}: ${String(p.changed_type).replace(/_/g, ' ')}`);
}

/**
 * Each kind of map feature, by the source it is drawn from: its status, its title and
 * the card rows after the title (HTML, already escaped). Empty rows are dropped.
 */
const KINDS = {
    tenements: {
        status: (p) => ({ LIVE: 'live', PENDING: 'pending' })[p.status] ?? 'dead',
        rows: (p, status, cfg, today) => [
            esc(typeLine(p, cfg, status === 'pending' ? ' application' : '')),
            holderLine(p),
            esc([ha(p.ha), p.survstatus ? String(p.survstatus).toLowerCase() : ''].filter(Boolean).join(' · ')),
            tenementDate(p, status, today),
        ],
        flags: (p, today) => [p.rel_at ? esc(`Part releases ${formatReleaseTime(p.rel_at)}`) : '', changeFlag(p, today)],
    },
    releases: {
        status: () => 'release',
        rows: (p, status, cfg, today) => [
            esc(typeLine(p, cfg)),
            holderLine(p),
            esc(ha(p.ha)),
            esc(`${p.fmt_tenid} releases ${formatReleaseTime(p.release_at)}${parseDay(p.release_at) ? ` · ${relativeShort(p.release_at, today)}` : ''}`),
        ],
    },
    dead: {
        status: () => 'dead',
        rows: (p, status, cfg) => [esc(typeLine(p, cfg)), holderLine(p), esc(ha(p.ha)), esc([sentence(p.reason), formatDay(p.ended)].filter(Boolean).join(' '))],
    },
    freed: {
        status: () => 'dead',
        rows: (p, status, cfg) => [
            esc(typeLine(p, cfg)),
            holderLine(p),
            p.uncovered_ha != null ? esc(`${ha(p.uncovered_ha)} with no live or pending tenement over it on the register`) : '',
            esc([sentence(p.reason), formatDay(p.ended_at)].filter(Boolean).join(' ')),
        ],
    },
    restrictions: {
        status: () => 'restricted',
        title: (p) => RESTRICTION_NAMES[p.kind] ?? sentence(p.kind),
        rows: (p) => [esc(p.name ?? ''), esc(sentence(p.purpose))],
    },
};
KINDS['pw-focus'] = KINDS.tenements;

function toItem(f) {
    const kind = KINDS[f.source];
    if (!kind) return null;
    const p = f.properties ?? {};
    const status = kind.status(p);
    const title = kind.title?.(p) ?? p.fmt_tenid ?? '';
    const size = status === 'restricted' ? Infinity : Number.isFinite(+p.ha) && p.ha !== null && p.ha !== '' ? +p.ha : areaHa(f.geometry);
    return {
        key: `${f.source === 'pw-focus' ? 'tenements' : f.source}:${p.fmt_tenid ?? `${p.kind}:${p.name}:${p.purpose}`}`,
        kind, f, p, status, title,
        slug: p.fmt_tenid ? slug(p.fmt_tenid) : null,
        target: f.id !== undefined && f.id !== null ? { source: f.source, sourceLayer: f.sourceLayer, id: f.id } : null,
        // Sizes compare as the tile rounds them, so equal parcels tie and fall to the next rule.
        size: size < 10 ? Math.round(size * 10) / 10 : Math.round(size),
    };
}

/** Smallest first; equal sizes put the newest application, then the soonest change, on top. */
function compare(a, b) {
    return a.size - b.size || String(b.p.applied ?? '').localeCompare(String(a.p.applied ?? '')) || RANK[a.status] - RANK[b.status];
}

/** The features under a screen point as card items, de-duplicated (richest copy wins) and sorted. */
export function itemsAt(map, point, layers, only = null) {
    const ids = [...layers, 'pw-focus-fill'].filter((l) => map.getLayer(l));
    const byKey = new Map();
    for (const f of map.queryRenderedFeatures(point, { layers: ids })) {
        const item = toItem(f);
        if (!item || (only && !only(item))) continue;
        const prev = byKey.get(item.key);
        if (!prev || Object.keys(item.p).length > Object.keys(prev.p).length) byKey.set(item.key, item);
    }
    return [...byKey.values()].sort(compare);
}

function badge(item, cfg) {
    return `<span class="pw-badge" style="--pw-status:${esc(cfg.status?.[item.status] ?? '#71717a')}">${STATUS_LABELS[item.status]}</span>`;
}

function detailHtml(item, cfg, today) {
    const rows = item.kind.rows(item.p, item.status, cfg, today).filter(Boolean);
    const flags = (item.kind.flags?.(item.p, today) ?? []).filter(Boolean);
    return `<div class="pw-card__head"><span class="pw-card__id">${esc(item.title)}</span>${badge(item, cfg)}</div>
        ${rows.map((r) => `<div class="pw-card__row">${r}</div>`).join('')}
        ${flags.map((r) => `<div class="pw-card__flag">${r}</div>`).join('')}`;
}

function hoverHtml(items, cfg, today) {
    const [top, ...rest] = items;
    const more = rest.length
        ? `<div class="pw-card__more">+${rest.length} more here: ${rest
              .slice(0, 3)
              .map((i) => `${esc(i.title)} (${STATUS_LABELS[i.status].toLowerCase()})`)
              .join(', ')}${rest.length > 3 ? ', …' : ''}</div>`
        : '';
    return `${detailHtml(top, cfg, today)}${more}<div class="pw-card__hint">${rest.length > 3 ? 'Click to see all' : 'Click for actions'}</div>`;
}

function pinnedHtml(state, cfg, today) {
    const item = state.items[state.active];
    const list =
        state.items.length > 1
            ? `<div class="pw-card__list" role="listbox" aria-label="${state.items.length} here">
                ${state.items
                    .map(
                        (i, n) => `<button type="button" role="option" data-act="pick" data-i="${n}" aria-selected="${n === state.active}">
                            <span class="pw-dot" style="--pw-status:${esc(cfg.status?.[i.status] ?? '#71717a')}"></span>
                            <span class="pw-card__id">${esc(i.title)}</span>
                            <span class="pw-muted">${esc(STATUS_LABELS[i.status])}${Number.isFinite(i.size) ? ` · ${number(i.size)} ha` : ''}</span>
                        </button>`,
                    )
                    .join('')}
            </div>`
            : '';
    const tenement = item.slug && item.status !== 'restricted';
    const actions = [
        tenement && `<button type="button" class="pw-btn pw-btn--primary" data-act="open">Open</button>`,
        `<button type="button" class="pw-btn" data-act="zoom">Zoom to</button>`,
        tenement && `<button type="button" class="pw-btn" data-act="copy">Copy ID</button>`,
        `<a class="pw-btn" href="${esc(cfg.tengraph ?? 'https://www.wa.gov.au/service/natural-resources/mineral-resources/access-tengraph-web')}" target="_blank" rel="noopener">TENGRAPH</a>`,
        `<button type="button" class="pw-btn" data-act="watch">Watch around here</button>`,
    ].filter(Boolean);
    return `<button type="button" class="pw-card__close" data-act="close" aria-label="Close">&times;</button>
        ${list}
        <div class="pw-card__detail">${detailHtml(item, cfg, today)}</div>
        <div class="pw-card__actions">${actions.join('')}</div>
        <div class="pw-card__coords"><span>${esc(formatLatLng(state.lngLat))} <span class="pw-muted">WGS84</span></span>
            <button type="button" class="pw-link" data-act="coords">Copy</button></div>
        <div class="pw-card__note">Confirm in TENGRAPH and with the Mining Registrar.</div>`;
}

/**
 * Wire the card to a loaded map. Returns the card's API for the keyboard, URL and
 * panel events: clickAt, close, isOpen, step, enter, pinSlug, select, destroy.
 */
export function attachCard(map, component, { only = () => null, busy = () => false } = {}) {
    const cfg = site();
    const container = map.getContainer();
    const canvas = map.getCanvas();
    const card = document.createElement('div');
    card.className = 'pw-card';
    card.hidden = true;
    container.appendChild(card);

    const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const phone = window.matchMedia('(max-width: 639px)');
    let state = { mode: 'idle', items: [], active: 0, lngLat: null, fresh: false };
    let pending = null; // { key, items, point } waiting out the show delay
    let showTimer = 0;
    let hideTimer = 0;
    let hovered = null;
    let selected = null;
    let listTarget = null;

    const sameTarget = (a, b) => a?.source === b?.source && a?.id === b?.id;
    const setState = (target, key, on) => {
        if (target && map.getSource(target.source)) map.setFeatureState(target, { [key]: on });
    };
    const hover = (target) => {
        if (sameTarget(target, hovered)) return;
        setState(hovered, 'hover', false);
        hovered = target;
        setState(hovered, 'hover', true);
    };
    const refreshSelected = () => {
        const next = listTarget ?? (state.mode === 'pinned' ? state.items[state.active]?.target : null) ?? null;
        if (sameTarget(next, selected)) return;
        setState(selected, 'selected', false);
        selected = next;
        setState(selected, 'selected', true);
    };

    function place(x, y) {
        if (card.classList.contains('pw-card--sheet')) return void (card.style.transform = '');
        const W = container.clientWidth;
        const H = container.clientHeight;
        const w = card.offsetWidth;
        const h = card.offsetHeight;
        let left = x + 14;
        let top = y + 14;
        if (left + w > W - 4) left = x - 14 - w;
        if (top + h > H - 4) top = y - 14 - h;
        card.style.transform = `translate(${Math.round(Math.max(4, Math.min(left, W - w - 4)))}px, ${Math.round(Math.max(4, Math.min(top, H - h - 4)))}px)`;
    }

    function render(mode, html) {
        card.innerHTML = html;
        card.hidden = false;
        card.classList.toggle('pw-card--pinned', mode === 'pinned');
        card.classList.toggle('pw-card--sheet', mode === 'pinned' && phone.matches);
        card.setAttribute('role', mode === 'pinned' ? 'dialog' : 'tooltip');
        if (mode === 'pinned') card.setAttribute('aria-label', state.items[state.active]?.title ?? 'Map');
        else card.removeAttribute('aria-label');
    }

    function hideHover() {
        clearTimeout(showTimer);
        clearTimeout(hideTimer);
        hideTimer = 0;
        pending = null;
        hover(null);
        if (state.mode === 'hover') {
            state = { ...state, mode: 'idle' };
            card.hidden = true;
        }
    }

    function showHover(entry) {
        const sig = entry.zoomOut ? 'zoom' : entry.items.map((i) => i.key).join('|');
        if (state.mode !== 'hover' || state.sig !== sig) {
            state = { mode: 'hover', items: entry.items, active: 0, lngLat: null, fresh: false, sig };
            render('hover', entry.zoomOut ? '<div class="pw-card__zoom">Zoom in to see tenements here</div>' : hoverHtml(entry.items, cfg, perthToday()));
        }
        place(entry.point.x, entry.point.y);
    }

    /** Features at a point for the current zoom: releases always, everything else from zoom 8. */
    function query(point, layers) {
        const items = itemsAt(map, point, layers, only());
        if (map.getZoom() >= DETAIL_ZOOM) return { items, zoomOut: false };
        const releases = items.filter((i) => i.status === 'release');
        if (releases.length) return { items: releases, zoomOut: false };
        const lowZoom = [...layers, ...(style.LAYER_GROUPS?.coverage ?? [])].filter((l) => map.getLayer(l));
        return { items: [], zoomOut: map.queryRenderedFeatures(point, { layers: lowZoom }).length > 0 };
    }

    function onMove(point) {
        if (busy()) return hideHover();
        if (state.mode === 'pinned') {
            canvas.style.cursor = itemsAt(map, point, CLICKABLE).length ? 'pointer' : '';
            return;
        }
        const { items, zoomOut } = query(point, HOVERABLE);
        canvas.style.cursor = items.length || zoomOut ? 'pointer' : '';
        if (!items.length && !zoomOut) {
            hover(null);
            clearTimeout(showTimer);
            pending = null;
            if (state.mode === 'hover' && !hideTimer) hideTimer = setTimeout(() => ((hideTimer = 0), hideHover()), HIDE_MS);
            return;
        }
        clearTimeout(hideTimer);
        hideTimer = 0;
        hover(items[0]?.target ?? null);
        const entry = { key: zoomOut ? 'zoom' : items[0].key, items, zoomOut, point };
        if (state.mode === 'hover') return showHover(entry); // already showing: follow at once
        if (pending?.key !== entry.key) {
            clearTimeout(showTimer);
            showTimer = setTimeout(() => pending && showHover(pending), SHOW_MS);
        }
        pending = entry;
    }

    function pin(items, lngLat, active = 0) {
        hideHover();
        state = { mode: 'pinned', items, active, lngLat, fresh: true };
        render('pinned', pinnedHtml(state, cfg, perthToday()));
        const pt = map.project(lngLat);
        place(pt.x, pt.y);
        // A bottom sheet covers the lower map: bring the tapped point into the strip above it.
        const free = container.clientHeight - card.offsetHeight;
        if (card.classList.contains('pw-card--sheet') && pt.y > free - 24) map.panBy([0, pt.y - free / 2]);
        component.selection = items[active]?.slug ?? null;
        refreshSelected();
    }

    function close() {
        hideHover();
        if (state.mode !== 'pinned') return;
        state = { mode: 'idle', items: [], active: 0, lngLat: null, fresh: false };
        card.hidden = true;
        component.selection = null;
        refreshSelected();
    }

    function setActive(i) {
        if (state.mode !== 'pinned') return;
        state = { ...state, active: (i + state.items.length) % state.items.length };
        render('pinned', pinnedHtml(state, cfg, perthToday()));
        const pt = map.project(state.lngLat);
        place(pt.x, pt.y);
        component.selection = state.items[state.active]?.slug ?? null;
        refreshSelected();
        card.querySelector(`[data-i="${state.active}"]`)?.scrollIntoView({ block: 'nearest' });
    }

    /** A click (or Enter at the crosshair) at a screen point. */
    function clickAt(point, lngLat) {
        const { items, zoomOut } = query(point, CLICKABLE);
        if (zoomOut) {
            close();
            return map.easeTo({ center: lngLat, zoom: DETAIL_ZOOM + 1 });
        }
        if (!items.length) return close();
        pin(items, [lngLat.lng ?? lngLat[0], lngLat.lat ?? lngLat[1]]);
    }

    const go = (href) => (window.Livewire?.navigate ? window.Livewire.navigate(href) : window.location.assign(href));
    const open = () => {
        const item = state.items[state.active];
        if (item?.slug && item.status !== 'restricted') go(`/tenements/${item.slug}`);
    };

    function zoomTo(item) {
        const box = bboxOf(item.f.geometry);
        if (item.p.fmt_tenid) {
            const filter = ['==', ['get', 'fmt_tenid'], item.p.fmt_tenid];
            for (const f of map.querySourceFeatures(item.f.source, item.f.sourceLayer ? { sourceLayer: item.f.sourceLayer, filter } : { filter })) bboxOf(f.geometry, box);
        }
        if (Number.isFinite(box[0])) map.fitBounds(box, { padding: 60, maxZoom: 15 });
    }

    function flash(button, ok) {
        const label = button.textContent;
        button.textContent = ok ? 'Copied' : 'Copy failed';
        setTimeout(() => (button.textContent = label), 1500);
    }

    card.addEventListener('click', async (e) => {
        const el = e.target.closest('[data-act]');
        if (!el || state.mode !== 'pinned') return;
        const item = state.items[state.active];
        switch (el.dataset.act) {
            case 'close':
                return close();
            case 'pick':
                return setActive(+el.dataset.i);
            case 'open':
                return open();
            case 'zoom':
                return zoomTo(item);
            case 'copy':
                return flash(el, await copyText(item.p.fmt_tenid));
            case 'coords':
                return flash(el, await copyText(formatLatLng(state.lngLat)));
            case 'watch': {
                const center = state.lngLat;
                close(); // out of the way of the circle
                return window.dispatchEvent(new CustomEvent('watch-start', { detail: { center } }));
            }
        }
    });
    card.addEventListener('dblclick', (e) => e.target.closest('[data-act="pick"]') && open());
    card.addEventListener('mouseover', (e) => {
        const i = e.target.closest('[data-i]')?.dataset.i;
        if (i !== undefined) hover(state.items[+i]?.target ?? null);
    });
    card.addEventListener('mouseleave', () => hover(null));
    card.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') return close(), canvas.focus();
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && state.items.length > 1) {
            e.preventDefault();
            setActive(state.active + (e.key === 'ArrowDown' ? 1 : -1));
            card.querySelector(`[data-i="${state.active}"]`)?.focus();
        }
    });

    if (canHover) {
        let raf = 0;
        let last = null;
        map.on('mousemove', (e) => {
            last = e.point;
            raf ||= requestAnimationFrame(() => ((raf = 0), onMove(last)));
        });
        map.on('mouseout', () => {
            clearTimeout(showTimer);
            pending = null;
            hover(null);
            if (state.mode === 'hover' && !hideTimer) hideTimer = setTimeout(() => ((hideTimer = 0), hideHover()), HIDE_MS);
        });
    }
    map.on('movestart', () => {
        hideHover();
        state.fresh = false;
    });
    map.on('move', () => {
        if (state.mode !== 'pinned' || card.classList.contains('pw-card--sheet')) return;
        const pt = map.project(state.lngLat);
        place(pt.x, pt.y);
    });
    map.on('click', (ev) => {
        if (component.pick) {
            component.$dispatch('map-pick', { lng: +ev.lngLat.lng.toFixed(5), lat: +ev.lngLat.lat.toFixed(5) });
            return;
        }
        if (!busy()) clickAt(ev.point, ev.lngLat);
    });
    const onPhoneChange = () => state.mode === 'pinned' && setActive(state.active);
    phone.addEventListener('change', onPhoneChange);

    return {
        clickAt,
        close,
        isOpen: () => state.mode !== 'idle',
        /** Up/Down in a pinned list. Returns true when it moved, so the map does not pan. */
        step(dir) {
            if (state.mode !== 'pinned' || state.items.length < 2) return false;
            setActive(state.active + dir);
            return true;
        },
        /** Enter: open the pinned row if the map has not moved since the pin. */
        enter() {
            if (state.mode !== 'pinned' || !state.fresh) return false;
            open();
            return true;
        },
        /** Pin the card on the feature with this slug if it is drawn in view (URL restore). */
        pinSlug(target) {
            const ids = CLICKABLE.filter((l) => map.getLayer(l));
            const feats = map.queryRenderedFeatures({ layers: ids }).filter((f) => slug(f.properties?.fmt_tenid) === target);
            const items = feats.map(toItem).filter(Boolean).sort(compare);
            if (!items.length) return false;
            const [w, s, e, n] = feats.reduce((box, f) => bboxOf(f.geometry, box), [Infinity, Infinity, -Infinity, -Infinity]);
            pin([items[0]], [(w + e) / 2, (s + n) / 2]);
            return true;
        },
        /** Feature-state `selected` from a list row (`map-select`); null hands it back to the pinned card. */
        select(tenid) {
            // Feature-state needs ids, which the tiles have once the source promotes `tenid`.
            listTarget = tenid && map.getSource('tenements')?.promoteId ? { source: 'tenements', sourceLayer: 'tenements', id: tenid } : null;
            refreshSelected();
        },
        destroy() {
            phone.removeEventListener('change', onPhoneChange);
            clearTimeout(showTimer);
            clearTimeout(hideTimer);
        },
    };
}
