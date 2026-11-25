/*
 * VizFeed — shared polling of overview view data (Viz\Web\ScopeViews): every 1 s GET of the view data (usually /feed.json,
 * Viz\Office\Feed), project scope from the viz-scope cookie. The view gets a ready state and only draws:
 *
 *     const feed = VizFeed.Start(root, { url: '/feed.json', onUpdate: feed => render(root, feed), onStop: () => … });
 *     feed.data      // last valid response (null until the first one)
 *     feed.failed    // the last two refreshes in a row failed — the view shows "refresh not working", the data stays
 *     feed.now()     // server time in seconds (clock offset from `data.now`)
 *
 * Safeguards as in office.js: the next request only after the previous one finishes (`root._busy`), request timeout,
 * paused in a hidden tab; a scope change in the left panel (event viz-scope) fetches right away. A detached root stops polling.
 */
window.VizFeed = (() => {

    const POLL_MS = 1000;
    /** Without a limit a hung connection would leave `root._busy` set forever. */
    const FETCH_TIMEOUT_MS = 5000;

    const Start = (root, opts) => {
        const feed = {
            data: null, offset: 0, failed: false, misses: 0,
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
                    feed.misses = 0;
                    if (!data || !root.isConnected) return;
                    feed.data = data;
                    feed.offset = data.now ? Date.parse(data.now) - Date.now() : 0;
                })
                // server down or not answering within FETCH_TIMEOUT_MS: the view keeps the last data, the status says so — only after two
                // errors in a row (switching to another view aborts the pending request and is not a failure)
                .catch(() => { feed.misses++; feed.failed = feed.misses >= 2; })
                .finally(() => {
                    clearTimeout(timer);
                    root._busy = false;
                    if (root.isConnected) opts.onUpdate(feed);
                });
        };
        // project scope change (Viz.Scope): the cookie is already set
        const onScope = () => refresh(true);
        document.addEventListener('viz-scope', onScope);
        refresh(true);
        root._timer = setInterval(() => refresh(false), POLL_MS);
        return feed;
    };

    return { Start };
})();
