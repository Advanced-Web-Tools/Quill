/* Published click-trigger playback only. Animation Studio is never loaded here. */
(() => {
    'use strict';
    document.addEventListener('click', event => {
        const target = event.target.closest?.('[data-quill-animation-trigger="click"]');
        if (!target || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        target.removeAttribute('data-quill-animation-playing');
        void target.offsetWidth;
        target.setAttribute('data-quill-animation-playing', '');
    });
})();
