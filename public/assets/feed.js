/*
 * VizFeed — wspólne odpytywanie danych widoków przeglądowych (Viz\Web\ScopeViews): co 1 s GET danych widoku (zwykle /feed.json,
 * Viz\Office\Feed), zakres projektów z ciasteczka viz-scope. Widok dostaje gotowy stan i tylko rysuje:
 *
 *     const feed = VizFeed.Start(root, { url: '/feed.json', onUpdate: feed => render(root, feed), onStop: () => … });
 *     feed.data      // ostatnia poprawna odpowiedź (null do pierwszej)
 *     feed.failed    // ostatnie odświeżenie się nie udało — widok pokazuje „refresh not working”, dane zostają
 *     feed.now()     // czas serwera w sekundach (przesunięcie zegara z `data.now`)
 *
 * Zabezpieczenia jak w office.js: kolejne żądanie dopiero po zakończeniu poprzedniego (`root._busy`), limit czasu żądania,
 * w ukrytej karcie wstrzymane; zmiana zakresu w lewym panelu (zdarzenie viz-scope) pobiera od razu. Odłączony root kończy odpytywanie.
 */
window.VizFeed = (() => {

    const POLL_MS = 1000;
    /** Bez limitu zawieszone połączenie zostawiłoby `root._busy` na stałe. */
    const FETCH_TIMEOUT_MS = 5000;

    const Start = (root, opts) => {
        const feed = {
            data: null, offset: 0, failed: false,
            now() { return (Date.now() + this.offset) / 1000; },
        };
        const stop = () => {
            clearInterval(root._timer);
            document.removeEventListener('viz-scope', onScope);
            if (opts.onStop) opts.onStop();
        };
        const refresh = force => {
            if (!root.isConnected) return stop();
            if ((document.hidden && !force) || root._busy) return;
            root._busy = true;
            const abort = new AbortController();
            const timer = setTimeout(() => abort.abort(), FETCH_TIMEOUT_MS);
            fetch(opts.url, { cache: 'no-store', signal: abort.signal })
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(data => {
                    feed.failed = false;
                    if (!data || !root.isConnected) return;
                    feed.data = data;
                    feed.offset = data.now ? Date.parse(data.now) - Date.now() : 0;
                })
                // serwer wyłączony albo bez odpowiedzi w FETCH_TIMEOUT_MS: widok zostaje z ostatnimi danymi, status to mówi
                .catch(() => { feed.failed = true; })
                .finally(() => {
                    clearTimeout(timer);
                    root._busy = false;
                    if (root.isConnected) opts.onUpdate(feed);
                });
        };
        // zmiana zakresu projektów (Viz.Scope): ciasteczko już ustawione
        const onScope = () => refresh(true);
        document.addEventListener('viz-scope', onScope);
        refresh(true);
        root._timer = setInterval(() => refresh(false), POLL_MS);
        return feed;
    };

    return { Start };
})();
