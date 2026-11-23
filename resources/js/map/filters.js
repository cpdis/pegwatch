// The panel's filter chips (`map-filter`) as MapLibre filters, combined with each layer's
// own status filter from style.js. Field filters (dates, size) only bite from zoom 8,
// where the tiles carry those fields: a feature without `tenid` is a low-zoom shape and passes.

import * as style from './style.js';
import { addMonths, perthToday } from './util.js';

const DETAIL = ['has', 'tenid'];
const whenDetailed = (cond) => ['any', ['!', DETAIL], cond];

/**
 * Filter conditions for one `map-filter` detail:
 * { types, statuses, appliedFrom, appliedTo, expiresWithinMonths, minHa, maxHa }, nulls meaning "any".
 */
export function filterConditions(f = {}, today = perthToday()) {
    const out = [];
    if (f.types?.length) {
        // Coverage shapes carry type_group (E, P, M, other) instead of type_code.
        const groups = [...f.types, ...(f.types.some((t) => !['E', 'P', 'M'].includes(t)) ? ['other'] : [])];
        out.push(['in', ['coalesce', ['get', 'type_code'], ['get', 'type_group'], ''], ['literal', groups]]);
    }
    if (f.statuses?.length) out.push(['in', ['coalesce', ['get', 'status'], ''], ['literal', f.statuses]]);
    if (f.appliedFrom) out.push(whenDetailed(['>=', ['coalesce', ['get', 'applied'], ''], f.appliedFrom]));
    if (f.appliedTo) out.push(whenDetailed(['<=', ['coalesce', ['get', 'applied'], '9999'], f.appliedTo]));
    if (f.expiresWithinMonths) out.push(whenDetailed(['<=', ['coalesce', ['get', 'ends'], '9999'], addMonths(today, f.expiresWithinMonths)]));
    if (f.minHa != null && f.minHa !== '') out.push(whenDetailed(['>=', ['to-number', ['get', 'ha'], 0], Number(f.minHa)]));
    if (f.maxHa != null && f.maxHa !== '') out.push(whenDetailed(['<=', ['to-number', ['get', 'ha'], 0], Number(f.maxHa)]));
    return out;
}

/** Each filterable layer's own filter: style.js's BASE_FILTERS, else what the layers carry now. */
function baseFilters(map) {
    if (style.BASE_FILTERS) return style.BASE_FILTERS;
    const ids = map.getStyle().layers.filter((l) => l.source === 'tenements' || l.source === 'coverage').map((l) => l.id);
    return Object.fromEntries(ids.map((id) => [id, map.getFilter(id) ?? null]));
}

export function attachFilters(map) {
    const base = baseFilters(map);
    return {
        apply(detail) {
            const conds = filterConditions(detail);
            for (const [id, own] of Object.entries(base)) {
                if (!map.getLayer(id)) continue;
                const all = [own, ...conds].filter(Boolean);
                map.setFilter(id, all.length === 0 ? null : all.length === 1 ? all[0] : ['all', ...all]);
            }
        },
    };
}
