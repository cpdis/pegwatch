// Small helpers shared by the map modules.

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
