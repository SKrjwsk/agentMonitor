/**
 * Podpowiedzi wykresów Przeglądu: element z `data-tip` pokazuje tekst przy najechaniu. Tekst przez textContent (dane są niezaufane).
 */
(() => {
    let tip = null;
    const show = (text, x, y) => {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'chart-tip';
            document.body.appendChild(tip);
        }
        tip.textContent = text;
        tip.hidden = false;
        const w = tip.offsetWidth;
        tip.style.left = Math.max(4, Math.min(x + 14, window.innerWidth - w - 8)) + 'px';
        tip.style.top = (y + 16) + 'px';
    };
    const hide = () => { if (tip) tip.hidden = true; };
    document.addEventListener('pointermove', e => {
        const el = e.target.closest ? e.target.closest('[data-tip]') : null;
        if (el) show(el.getAttribute('data-tip'), e.clientX, e.clientY); else hide();
    });
    document.addEventListener('pointerleave', hide);
})();
