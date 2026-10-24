/*
 * VizTl — szerokość kolumny nazw na osi czasu. Sygnatura: templates/timeline.php
 * Stan: CSS var --tl-label-w na [viz-tl]; wybór zapamiętany w localStorage (wygoda per przeglądarka).
 */
const VizTl = (() => {

    const KEY = 'viz-tl-label-w';
    const MIN = 140;
    const MAX = 1200;

    const set = (root, width) => {
        const w = Math.max(MIN, Math.min(MAX, Math.round(width)));
        root.style.setProperty('--tl-label-w', w + 'px');
        return w;
    };

    const save = w => {
        try { localStorage.setItem(KEY, String(w)); } catch { /* bez pamięci */ }
    };

    const Init = root => {
        let w = 0;
        try { w = +localStorage.getItem(KEY) || 0; } catch { w = 0; }
        if (w) set(root, w);
    };

    const Down = (handle, event) => {
        event.preventDefault();
        handle.setPointerCapture(event.pointerId);
        handle._drag = { x: event.clientX, w: handle.up('[viz-tl-labels]').one('svg').getBoundingClientRect().width };
        handle.flag('viz-tl-dragging', true);
    };

    const Move = (handle, event) => {
        if (!handle._drag) return;
        handle._w = set(handle.up('[viz-tl]'), handle._drag.w + event.clientX - handle._drag.x);
    };

    const Up = handle => {
        if (!handle._drag) return;
        handle._drag = null;
        handle.flag('viz-tl-dragging', false);
        if (handle._w) save(handle._w);
    };

    /** Dopasowanie do najdłuższej etykiety. */
    const Fit = handle => {
        const svg = handle.up('[viz-tl-labels]').one('svg');
        let max = 0;
        svg.querySelectorAll('text').forEach(t => {
            max = Math.max(max, +t.getAttribute('x') + t.getComputedTextLength());
        });
        // nie więcej niż 60% okna — oś czasu musi zostać widoczna
        save(set(handle.up('[viz-tl]'), Math.min(max + 16, window.innerWidth * 0.6)));
    };

    return { Init, Down, Move, Up, Fit };
})();
