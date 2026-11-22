import tenementMap from './map';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('tenementMap', tenementMap);
});
