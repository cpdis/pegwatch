import { OTHER_COLOUR, TYPE_COLOURS } from '@/lib/format';
import { router } from '@inertiajs/react';
import maplibregl, { type GeoJSONSourceSpecification, type Map as MlMap } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useEffect, useRef } from 'react';

export const WA_BOUNDS: [number, number, number, number] = [112.5, -35.5, 129.5, -13.5];
const BASEMAP = 'https://tiles.openfreemap.org/styles/positron';
// Used if the basemap style cannot be fetched, so tenement layers still draw.
const BLANK_STYLE: maplibregl.StyleSpecification = {
    version: 8,
    sources: {},
    layers: [{ id: 'background', type: 'background', paint: { 'background-color': '#f4f1ea' } }],
};

const typeColour: maplibregl.ExpressionSpecification = [
    'match',
    ['get', 'type_code'],
    ...Object.entries(TYPE_COLOURS).flat(),
    OTHER_COLOUR,
] as unknown as maplibregl.ExpressionSpecification;

export interface MapLayers {
    tenements: boolean;
    pending: boolean;
    dead: boolean;
    releases: boolean;
    restrictions: boolean;
}

interface Props {
    className?: string;
    bounds?: [number, number, number, number] | null;
    layers?: MapLayers;
    highlight?: GeoJSON.Geometry | null;
    markers?: { point: [number, number]; colour: string; id: string | number }[];
    circle?: { center: [number, number]; radiusKm: number } | null;
    onClickMap?: (lngLat: [number, number]) => void;
    interactive?: boolean;
}

const DEFAULT_LAYERS: MapLayers = { tenements: true, pending: true, dead: false, releases: true, restrictions: false };

function circlePolygon(center: [number, number], radiusKm: number): GeoJSON.Polygon {
    const pts: [number, number][] = [];
    const [lng, lat] = center;
    for (let i = 0; i <= 64; i++) {
        const a = (i / 64) * 2 * Math.PI;
        const dLat = (radiusKm / 111.32) * Math.sin(a);
        const dLng = ((radiusKm / 111.32) * Math.cos(a)) / Math.cos((lat * Math.PI) / 180);
        pts.push([lng + dLng, lat + dLat]);
    }
    return { type: 'Polygon', coordinates: [pts] };
}

export default function TenementMap({
    className,
    bounds,
    layers = DEFAULT_LAYERS,
    highlight,
    markers = [],
    circle,
    onClickMap,
    interactive = true,
}: Props) {
    const ref = useRef<HTMLDivElement>(null);
    const map = useRef<MlMap | null>(null);
    const loaded = useRef(false);
    const clickRef = useRef(onClickMap);
    clickRef.current = onClickMap;

    useEffect(() => {
        if (!ref.current) return;
        const origin = window.location.origin;
        const m = new maplibregl.Map({
            container: ref.current,
            style: BASEMAP,
            bounds: bounds ?? WA_BOUNDS,
            fitBoundsOptions: { padding: 40, maxZoom: 13 },
            interactive,
            attributionControl: { compact: true, customAttribution: '© State of WA (DMPE), CC BY 4.0' },
        });
        map.current = m;
        m.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        m.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left');

        let fellBack = false;
        m.on('error', (e) => {
            if (!fellBack && !m.isStyleLoaded() && String(e.error?.message ?? '').match(/style|fetch|Failed/i)) {
                fellBack = true;
                m.setStyle(BLANK_STYLE);
            }
        });

        m.on('load', () => {
            const tiles = (layer: string) => ({ type: 'vector' as const, tiles: [`${origin}/tiles/${layer}/{z}/{x}/{y}.mvt`], maxzoom: 14 });
            m.addSource('tenements', { ...tiles('tenements'), minzoom: 5 });
            m.addSource('dead', { ...tiles('dead'), minzoom: 6 });
            m.addSource('releases', tiles('releases'));
            m.addSource('restrictions', { ...tiles('restrictions'), minzoom: 5 });
            const empty: GeoJSONSourceSpecification = { type: 'geojson', data: { type: 'FeatureCollection', features: [] } };
            m.addSource('highlight', empty);
            m.addSource('markers', empty);
            m.addSource('circle', empty);

            m.addLayer({
                id: 'restrictions-fill',
                type: 'fill',
                source: 'restrictions',
                'source-layer': 'restrictions',
                paint: { 'fill-color': '#a16207', 'fill-opacity': 0.12 },
            });
            m.addLayer({
                id: 'restrictions-line',
                type: 'line',
                source: 'restrictions',
                'source-layer': 'restrictions',
                paint: { 'line-color': '#a16207', 'line-width': 1, 'line-dasharray': [2, 2] },
            });
            m.addLayer({
                id: 'dead-line',
                type: 'line',
                source: 'dead',
                'source-layer': 'dead',
                paint: { 'line-color': '#111827', 'line-width': 1, 'line-dasharray': [1, 2] },
            });
            m.addLayer({
                id: 'live-fill',
                type: 'fill',
                source: 'tenements',
                'source-layer': 'tenements',
                filter: ['==', ['get', 'status'], 'LIVE'],
                paint: { 'fill-color': typeColour, 'fill-opacity': 0.18 },
            });
            m.addLayer({
                id: 'live-line',
                type: 'line',
                source: 'tenements',
                'source-layer': 'tenements',
                filter: ['==', ['get', 'status'], 'LIVE'],
                paint: { 'line-color': typeColour, 'line-width': ['interpolate', ['linear'], ['zoom'], 6, 0.4, 12, 1.5] },
            });
            m.addLayer({
                id: 'pending-fill',
                type: 'fill',
                source: 'tenements',
                'source-layer': 'tenements',
                filter: ['==', ['get', 'status'], 'PENDING'],
                paint: { 'fill-color': typeColour, 'fill-opacity': 0.06 },
            });
            m.addLayer({
                id: 'pending-line',
                type: 'line',
                source: 'tenements',
                'source-layer': 'tenements',
                filter: ['==', ['get', 'status'], 'PENDING'],
                paint: { 'line-color': typeColour, 'line-width': 1, 'line-dasharray': [3, 2] },
            });
            m.addLayer({
                id: 'releases-fill',
                type: 'fill',
                source: 'releases',
                'source-layer': 'releases',
                paint: { 'fill-color': '#dc2626', 'fill-opacity': 0.35 },
            });
            m.addLayer({ id: 'circle-fill', type: 'fill', source: 'circle', paint: { 'fill-color': '#0ea5e9', 'fill-opacity': 0.08 } });
            m.addLayer({ id: 'circle-line', type: 'line', source: 'circle', paint: { 'line-color': '#0ea5e9', 'line-width': 2 } });
            m.addLayer({ id: 'highlight-line', type: 'line', source: 'highlight', paint: { 'line-color': '#facc15', 'line-width': 3 } });
            m.addLayer({
                id: 'markers',
                type: 'circle',
                source: 'markers',
                paint: { 'circle-radius': 5, 'circle-color': ['get', 'colour'], 'circle-stroke-color': '#fff', 'circle-stroke-width': 1.5 },
            });

            const clickable = ['live-fill', 'pending-fill', 'releases-fill', 'dead-line', 'restrictions-fill'];
            const popup = new maplibregl.Popup({ closeButton: false, maxWidth: '280px' });
            m.on('click', (ev) => {
                if (clickRef.current) {
                    clickRef.current([ev.lngLat.lng, ev.lngLat.lat]);
                    return;
                }
                const feats = m.queryRenderedFeatures(ev.point, { layers: clickable.filter((l) => m.getLayer(l)) });
                if (feats.length === 0) {
                    popup.remove();
                    return;
                }
                const seen = new Set<string>();
                const rows = feats
                    .filter((f) => {
                        const k = `${f.layer.id}:${f.properties.fmt_tenid ?? f.properties.name}`;
                        if (seen.has(k)) return false;
                        seen.add(k);
                        return true;
                    })
                    .slice(0, 8)
                    .map((f) => {
                        const p = f.properties as Record<string, string>;
                        if (f.layer.id === 'restrictions-fill') return `<div><b>${esc(p.kind)}</b> ${esc(p.name ?? '')}</div>`;
                        const slug = (p.fmt_tenid ?? '')
                            .toLowerCase()
                            .replace(/[^a-z0-9]+/g, '-')
                            .replace(/^-|-$/g, '');
                        const extra =
                            f.layer.id === 'releases-fill'
                                ? ` release ${esc(p.rlesid)} at ${esc(p.release_at)}`
                                : f.layer.id === 'dead-line'
                                  ? ` dead ${esc(p.ended)} (${esc(p.reason)})`
                                  : ` ${esc((p.status ?? '').toLowerCase())}${p.holder ? ' · ' + esc(p.holder) : ''}`;
                        return `<div><a href="/tenements/${slug}" data-slug="${slug}" style="color:#2563eb;font-weight:600">${esc(p.fmt_tenid)}</a>${extra}</div>`;
                    });
                popup
                    .setLngLat(ev.lngLat)
                    .setHTML(`<div style="font-size:12px;line-height:1.5">${rows.join('')}</div>`)
                    .addTo(m);
                popup
                    .getElement()
                    ?.querySelectorAll('a[data-slug]')
                    .forEach((a) =>
                        a.addEventListener('click', (e) => {
                            e.preventDefault();
                            router.visit(`/tenements/${(a as HTMLElement).dataset.slug}`);
                        }),
                    );
            });
            for (const l of clickable) {
                m.on('mouseenter', l, () => (m.getCanvas().style.cursor = 'pointer'));
                m.on('mouseleave', l, () => (m.getCanvas().style.cursor = ''));
            }
            loaded.current = true;
            m.fire('pegwatch:ready');
        });
        return () => {
            loaded.current = false;
            m.remove();
            map.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Keep overlays in sync with props.
    useEffect(() => {
        const m = map.current;
        if (!m) return;
        const apply = () => {
            const vis = (on: boolean) => (on ? 'visible' : 'none');
            const set = (id: string, on: boolean) => m.getLayer(id) && m.setLayoutProperty(id, 'visibility', vis(on));
            set('live-fill', layers.tenements);
            set('live-line', layers.tenements);
            set('pending-fill', layers.pending);
            set('pending-line', layers.pending);
            set('dead-line', layers.dead);
            set('releases-fill', layers.releases);
            set('restrictions-fill', layers.restrictions);
            set('restrictions-line', layers.restrictions);
            (m.getSource('highlight') as maplibregl.GeoJSONSource)?.setData(
                highlight ? { type: 'Feature', geometry: highlight, properties: {} } : { type: 'FeatureCollection', features: [] },
            );
            (m.getSource('markers') as maplibregl.GeoJSONSource)?.setData({
                type: 'FeatureCollection',
                features: markers.map((mk) => ({
                    type: 'Feature',
                    id: mk.id,
                    geometry: { type: 'Point', coordinates: mk.point },
                    properties: { colour: mk.colour },
                })),
            });
            (m.getSource('circle') as maplibregl.GeoJSONSource)?.setData(
                circle
                    ? { type: 'Feature', geometry: circlePolygon(circle.center, circle.radiusKm), properties: {} }
                    : { type: 'FeatureCollection', features: [] },
            );
        };
        if (loaded.current) apply();
        else m.once('pegwatch:ready', apply);
    }, [layers, highlight, markers, circle]);

    useEffect(() => {
        if (map.current && bounds) map.current.fitBounds(bounds, { padding: 40, maxZoom: 13 });
    }, [bounds?.join(',')]); // eslint-disable-line react-hooks/exhaustive-deps

    return <div ref={ref} className={className} />;
}

function esc(s: string | undefined | null): string {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}
