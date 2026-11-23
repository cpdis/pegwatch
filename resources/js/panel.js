// The map page's panel (resources/views/pages/map.blade.php). It talks to the map only
// through the window events in contracts.md: it listens to map-moved and sends map-layers
// and map-fly. Filters go through Livewire, which sends map-filter, so the map and the
// panel's counts always use the same filter. On phones the panel is a bottom sheet.

const SHEET_STATES = ['peek', 'half', 'full'];
const phone = () => window.matchMedia('(max-width: 639px)').matches;

/** Layer keys switched on in the map URL's `l=` part (url.js), or null when it has none. */
function layersInHash() {
    const m = window.location.hash.match(/[#&]l=([^&]*)/);
    return m ? new Set(decodeURIComponent(m[1]).split(',').filter(Boolean)) : null;
}

export default function mapPanel({ layers }) {
    const fromHash = layersInHash();

    return {
        // Live and pending ground always draw; the status chip filters them.
        layers: fromHash ? Object.fromEntries(Object.keys(layers).map((k) => [k, ['tenements', 'pending'].includes(k) || fromHash.has(k)])) : { ...layers },
        colour: 'status',
        follow: true,
        view: null,
        sheet: 'peek',
        dragging: false,
        dragHeight: null,

        init() {
            this.$watch('layers', () => this.sendLayers(), { deep: true });
            this.$watch('colour', () => this.sendLayers());
            this.$watch('follow', (on) => on && this.view && this.$wire.moved(this.view.bounds));
            // Tell the map which layers the panel shows, once it is listening.
            if (fromHash === null) queueMicrotask(() => this.sendLayers());
        },

        moved(detail) {
            this.view = detail;
            if (this.follow) this.$wire.moved(detail.bounds);
        },

        /** `colour` is the colour mode ('status' or 'type'), next to the contract's `layers`. */
        sendLayers() {
            window.dispatchEvent(new CustomEvent('map-layers', { detail: { layers: { ...this.layers }, colour: this.colour } }));
        },

        fly(bounds) {
            window.dispatchEvent(new CustomEvent('map-fly', { detail: { bounds } }));
            if (phone()) this.sheet = 'peek';
        },

        toggleSheet() {
            if (this.moveY !== undefined && Math.abs(this.moveY) > 6) return; // a drag, not a tap
            this.sheet = this.sheet === 'peek' ? 'half' : 'peek';
        },

        startDrag(e) {
            this.dragging = true;
            this.startY = e.clientY;
            this.moveY = 0;
            this.startHeight = this.$refs.sheet.getBoundingClientRect().height;
            e.currentTarget.setPointerCapture?.(e.pointerId);
        },

        onDrag(e) {
            if (!this.dragging) return;
            this.moveY = this.startY - e.clientY;
            if (Math.abs(this.moveY) > 6) this.dragHeight = Math.max(120, Math.min(window.innerHeight - 64, this.startHeight + this.moveY));
        },

        /** Snap to the nearest of peek, half and full. */
        endDrag() {
            if (!this.dragging) return;
            this.dragging = false;
            if (this.dragHeight !== null) {
                const vh = window.innerHeight;
                const heights = { peek: 200, half: vh * 0.55, full: vh - 72 };
                this.sheet = SHEET_STATES.reduce((best, s) => (Math.abs(heights[s] - this.dragHeight) < Math.abs(heights[best] - this.dragHeight) ? s : best));
            }
            this.dragHeight = null;
            setTimeout(() => (this.moveY = 0));
        },
    };
}
