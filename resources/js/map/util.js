// Small helpers shared by the map modules. Register dates are AWST wall-clock values,
// so every date here is a plain 'YYYY-MM-DD' compared against today in Perth.

export function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

export function slug(s) {
    return String(s ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

/** A geodesic-ish circle as a GeoJSON polygon, for watch-area previews. */
export function circlePolygon([lng, lat], radiusKm) {
    const pts = [];
    for (let i = 0; i <= 64; i++) {
        const a = (i / 64) * 2 * Math.PI;
        const dLat = (radiusKm / 111.32) * Math.sin(a);
        const dLng = ((radiusKm / 111.32) * Math.cos(a)) / Math.cos((lat * Math.PI) / 180);
        pts.push([lng + dLng, lat + dLat]);
    }
    return { type: 'Polygon', coordinates: [pts] };
}

/** Distance in km between two [lng, lat] points (haversine). */
export function distanceKm([lng1, lat1], [lng2, lat2]) {
    const r = (d) => (d * Math.PI) / 180;
    const a = Math.sin(r(lat2 - lat1) / 2) ** 2 + Math.cos(r(lat1)) * Math.cos(r(lat2)) * Math.sin(r(lng2 - lng1) / 2) ** 2;
    return 12742 * Math.asin(Math.sqrt(a));
}

/** The point `km` from `center` on a bearing (radians, 0 = east, counter-clockwise), on the same flat model as circlePolygon. */
export function offsetKm([lng, lat], km, angle) {
    return [lng + ((km / 111.32) * Math.cos(angle)) / Math.cos((lat * Math.PI) / 180), lat + (km / 111.32) * Math.sin(angle)];
}

/** Our own sources: GeoJSON, the pw-* sources, and vector tiles from this site. Anything else is the basemap. */
export function isOwnSource(sources, id) {
    const s = sources[id];
    return !s || id.startsWith('pw-') || s.type === 'geojson' || (s.tiles ?? []).some((t) => t.startsWith(window.location.origin));
}

/** Move the basemap's labels to just below `beforeId`, so town names stay readable over a veil or imagery. */
export function liftBasemapLabels(map, beforeId) {
    const { layers, sources } = map.getStyle();
    for (const l of layers) if (l.type === 'symbol' && !isOwnSource(sources, l.source) && l.id !== beforeId) map.moveLayer(l.id, beforeId);
}

/** Server-side config from the `pegwatch-map` meta tag (App\Support\Format::mapConfig). */
export function site() {
    try {
        return JSON.parse(document.querySelector('meta[name="pegwatch-map"]')?.content ?? '{}');
    } catch {
        return {};
    }
}

/** Copy text; resolves false when the clipboard is unavailable (insecure origin, permission). */
export async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

/** "−30.35681, 121.47755": latitude first, as GPS units and the register show them. */
export function formatLatLng([lng, lat]) {
    return `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
}

/** Bounding box [w, s, e, n] of any GeoJSON geometry. */
export function bboxOf(geometry, into = [Infinity, Infinity, -Infinity, -Infinity]) {
    const walk = (c) => {
        if (typeof c[0] === 'number') {
            into[0] = Math.min(into[0], c[0]);
            into[1] = Math.min(into[1], c[1]);
            into[2] = Math.max(into[2], c[0]);
            into[3] = Math.max(into[3], c[1]);
        } else c.forEach(walk);
    };
    if (geometry?.type === 'GeometryCollection') geometry.geometries.forEach((g) => bboxOf(g, into));
    else if (geometry?.coordinates) walk(geometry.coordinates);
    return into;
}

/** Approximate area in hectares of a (Multi)Polygon in lng/lat, for sorting when the tile has no `ha`. */
export function areaHa(geometry) {
    const polys = geometry?.type === 'Polygon' ? [geometry.coordinates] : geometry?.type === 'MultiPolygon' ? geometry.coordinates : [];
    let sum = 0;
    for (const rings of polys) {
        rings.forEach((ring, i) => {
            let a = 0;
            for (let j = 0, k = ring.length - 1; j < ring.length; k = j++) a += (ring[k][0] - ring[j][0]) * (ring[k][1] + ring[j][1]);
            const kx = 111.32 * Math.cos(((ring[0]?.[1] ?? 0) * Math.PI) / 180);
            sum += (i === 0 ? 1 : -1) * Math.abs(a / 2) * kx * 111.32 * 100;
        });
    }
    return sum;
}

// ---- Dates ------------------------------------------------------------------------

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** Today in Perth as 'YYYY-MM-DD' (Perth keeps UTC+8 all year). */
export function perthToday(now = new Date()) {
    return new Date(now.getTime() + 8 * 3600e3).toISOString().slice(0, 10);
}

/** Parse the date part of a register value ('YYYY-MM-DD…') into {y, m, d}, or null. */
export function parseDay(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s ?? ''));
    return m && +m[1] < 2999 ? { y: +m[1], m: +m[2], d: +m[3] } : null;
}

/** A detection time with a zone ('2026-11-21T03:00:00Z', '2026-11-21 03:00:00+00') as a Perth day; plain dates pass through. */
export function perthDay(s) {
    const str = String(s ?? '');
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) return str;
    const t = Date.parse(str.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
    return Number.isNaN(t) ? str.slice(0, 10) : perthToday(new Date(t));
}

/** "21 Apr 2023". */
export function formatDay(s) {
    const d = parseDay(s);
    return d ? `${d.d} ${MONTHS[d.m - 1]} ${d.y}` : '';
}

/** "Wed 2 Dec, 8:30am AWST" from 'YYYY-MM-DD HH:MM' in AWST. */
export function formatReleaseTime(s) {
    const d = parseDay(s);
    if (!d) return '';
    const wd = DAYS[new Date(Date.UTC(d.y, d.m - 1, d.d)).getUTCDay()];
    const t = /(\d{2}):(\d{2})/.exec(String(s).slice(10));
    const time = t ? `, ${+t[1] % 12 || 12}:${t[2]}${+t[1] < 12 ? 'am' : 'pm'}` : '';
    return `${wd} ${d.d} ${MONTHS[d.m - 1]}${time} AWST`;
}

/** Whole calendar months from a to b (a <= b), as Carbon's diffInMonths floors them. */
function monthsBetween(a, b) {
    return (b.y - a.y) * 12 + (b.m - a.m) - (b.d < a.d ? 1 : 0);
}

function daysBetween(a, b) {
    return Math.round((Date.UTC(b.y, b.m - 1, b.d) - Date.UTC(a.y, a.m - 1, a.d)) / 864e5);
}

/** 'YYYY-MM-DD' plus n months, clamped to the month's last day. */
export function addMonths(s, n) {
    const d = parseDay(s);
    const first = new Date(Date.UTC(d.y, d.m - 1 + n, 1));
    const last = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();
    first.setUTCDate(Math.min(d.d, last));
    return first.toISOString().slice(0, 10);
}

/**
 * How far a register date is from today in Perth, short: "3 yr 7 mo ago", "in 4 yr",
 * "9 days ago", "today". The card's version of App\Support\Format::relative().
 */
export function relativeShort(s, today = perthToday()) {
    const then = parseDay(s);
    const now = parseDay(today);
    if (!then || !now) return '';
    const past = daysBetween(then, now) >= 0;
    const [from, to] = past ? [then, now] : [now, then];
    const months = monthsBetween(from, to);
    if (months === 0) {
        const days = Math.abs(daysBetween(from, to));
        if (days === 0) return 'today';
        if (days === 1) return past ? 'yesterday' : 'tomorrow';
        return past ? `${days} days ago` : `in ${days} days`;
    }
    const y = Math.floor(months / 12);
    const mo = months % 12;
    const span = [y ? `${y} yr` : '', mo ? `${mo} mo` : ''].filter(Boolean).join(' ');
    return past ? `${span} ago` : `in ${span}`;
}

/** Whole months from today until a date (negative when past). */
export function monthsUntil(s, today = perthToday()) {
    const then = parseDay(s);
    const now = parseDay(today);
    if (!then || !now) return null;
    return daysBetween(now, then) >= 0 ? monthsBetween(now, then) : -monthsBetween(then, now) - 1;
}
