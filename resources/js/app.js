import tenementMap from './map';
import mapPanel from './panel';
import { registerWebMcp } from './webmcp';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('tenementMap', tenementMap);
    window.Alpine.data('mapPanel', mapPanel);
});

registerWebMcp();
