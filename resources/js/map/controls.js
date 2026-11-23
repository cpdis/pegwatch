// Map controls (zoom, scale; later coordinates, locate, satellite, keyboard). The map-interaction work owns this file.

export function addControls(map, maplibregl) {
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left');
}

/** Controls that need the loaded map (coordinates, locate, satellite, keyboard). */
export function attachControls(map, component) {}
