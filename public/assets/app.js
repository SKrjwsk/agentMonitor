/*
 * Viz — session view interactions (attribute pattern: state in attributes, inline events, init in place).
 * Signature: templates/session.php
 */
const Viz = (() => {

    /**
     * Interface text translation: the key is the English text, the dictionary is inserted by the server (layout.php, `#viz-i18n`, empty for EN).
     * Syntax: T("English text") or T("{n} files", { n: 3 }). Missing key = English text. The dictionary is read once, on first use.
     */
    let dict = null;
    const T = (key, vars) => {
        if (dict === null) {
            try { dict = JSON.parse(document.getElementById('viz-i18n')?.textContent || '{}') || {}; } catch { dict = {}; }
        }
        const text = Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
        return vars ? text.replace(/\{(\w+)\}/g, (m, name) => name in vars ? String(vars[name]) : m) : text;
    };

    /** Every this many ms a request for the source version (`/version`, a few ms); the fragment is fetched only when it changes. */
    const POLL_MS = 1000;
    /** Fragment in flight: Hx returns no promise, so until it arrives (or the section is swapped) we do not start another one. */
    const FRAGMENT_WAIT_MS = 10000;
    /** A version request unanswered this long = refresh error; without a limit a hung connection would leave `_busy` set forever. */
    const FETCH_TIMEOUT_MS = 5000;

    /** Response text; HTTP ≠ 2xx, a network error and a timeout reject the promise. */
    const fetchText = url => {
        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), FETCH_TIMEOUT_MS);
        return fetch(url, { cache: 'no-store', signal: abort.signal })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .finally(() => clearTimeout(timer));
    };

    /** The "refresh not working" message (layout.php) — shown after a failed refresh, disappears on the first successful one. */
    const refreshFailed = failed => {
        const note = document.querySelector('[viz-refresh-error]');
        if (note) note.hidden = !failed;
    };

    const space = el => el.up('[viz-session]');

    /** Parameters passed when refreshing the fragment — view state goes through the server. */
    const Query = section => ({
        pick: section.attr('viz-pick') || '',
        pev: section.attr('viz-pev') || '',
        pcmd: section.attr('viz-pcmd') || '',
        st: Math.round(section.one('[viz-pane="list"]')?.scrollTop || 0),
        se: Math.round(section.one('[viz-pane="drawer"]')?.scrollTop || 0),
        sx: Math.round(section.one('[viz-pane="tl"]')?.scrollLeft || 0),
        sy: Math.round(section.one('[viz-pane="tl"]')?.scrollTop || 0),
        sr: Math.round(section.one('[viz-pane="rank"]')?.scrollTop || 0),
        find: section.one('[filter-query]')?.value.trim() || '',
    });

    const Init = section => {
        // new section = the fragment has just arrived, so refreshing works
        refreshFailed(false);
        const list = section.one('[viz-pane="list"]');
        const drawer = section.one('[viz-pane="drawer"]');
        if (list) list.scrollTop = +section.attr('viz-st') || 0;
        if (drawer) drawer.scrollTop = +section.attr('viz-se') || 0;
        const rank = section.one('[viz-pane="rank"]');
        if (rank) rank.scrollTop = +section.attr('viz-sr') || 0;
        const tl = section.one('[viz-pane="tl"]');
        if (tl) {
            tl.scrollLeft = +section.attr('viz-sx') || 0;
            tl.scrollTop = +section.attr('viz-sy') || 0;
        }
        section._timer = setInterval(() => tick(section), POLL_MS);
        if (section.attr('viz-view') && !section.flag('viz-all')) {
            try { localStorage.setItem('viz-view', section.attr('viz-view')); } catch { /* no storage */ }
        }
    };

    /**
     * Swaps the fragment only when the source changed — with a refresh every second and no data change the DOM stays put
     * (scroll position, text selection and expanded elements stay). A new request only after the previous one finishes:
     * the version request (`_busy`, with a timeout) and the fragment fetch (`_fragmentUntil`; after the swap the section is a new element).
     * A fragment that did not swap the section within FRAGMENT_WAIT_MS is a refresh error (Hx reports no errors) — next try at the next tick.
     * An error (network, HTTP, timeout, no swap) shows the message; the first successful refresh or section swap hides it.
     */
    const tick = section => {
        if (!section.isConnected) {
            clearInterval(section._timer);
            return;
        }
        if (!section.flag('viz-live') || document.hidden || section._busy) return;
        if (section._fragmentUntil) {
            if (Date.now() < section._fragmentUntil) return;
            section._fragmentUntil = 0;
            refreshFailed(true);
        }
        section._busy = true;
        fetchText(section.attr('viz-version-url'))
            .then(version => {
                if (!version) throw new Error('empty version');
                if (version === section.attr('viz-version')) {
                    refreshFailed(false);
                    return;
                }
                section._fragmentUntil = Date.now() + FRAGMENT_WAIT_MS;
                hx(section);
            })
            .catch(() => refreshFailed(true))
            .finally(() => { section._busy = false; });
    };

    /**
     * Click on a file (viz-file) or call (viz-ev): details panel on the right, rendered by the server.
     * A second click on the same one closes the panel.
     */
    const Open = (el, event) => {
        // a link, expander or another button in the row does its own thing; the viz-file/viz-ev element itself may be a button
        const own = event?.target.closest('a, summary, button');
        if (own && own !== el) return;
        event?.stopPropagation();
        const section = space(el);
        const file = el.attr('viz-file');
        const ev = el.attr('viz-ev');
        const same = file ? section.attr('viz-pick') === file : section.attr('viz-pev') === ev;
        section.attr('viz-pick', !same && file ? file : null);
        section.attr('viz-pev', !same && !file ? ev : null);
        swap(section);
    };

    /** All sessions: click on a program = panel with subcommands, pipelines and sessions. */
    const OpenCmd = (el, event) => {
        event?.stopPropagation();
        const section = space(el);
        const program = el.attr('viz-cmd');
        section.attr('viz-pcmd', section.attr('viz-pcmd') === program ? null : program);
        section.attr('viz-pick', null);
        swap(section);
    };

    const Close = el => {
        const section = space(el);
        section.attr('viz-pick', null);
        section.attr('viz-pev', null);
        // in a session pcmd is a command list filter, not a panel — it stays
        if (section.flag('viz-all')) section.attr('viz-pcmd', null);
        swap(section);
    };

    /** New drawer content starts from the top. */
    const swap = section => {
        const drawer = section.one('[viz-pane="drawer"]');
        if (drawer) drawer.scrollTop = 0;
        hx(section);
    };

    /** Filters in <select>: the option value is a ready URL from the server; only paths of this app. */
    const Go = select => {
        if (select.value.startsWith('/?')) location.href = select.value;
    };

    document.addEventListener('keydown', event => {
        if (event.code !== 'Escape') return;
        const section = document.querySelector('[viz-session][viz-pick], [viz-session][viz-pev], [viz-session][viz-all][viz-pcmd]');
        if (section && section.one('[viz-pane="drawer"]')) Close(section);
    });

    /** Interface language: the viz-lang cookie is read by the server (Viz\Web\Lang), so a reload. */
    const Lang = () => {
        const next = document.documentElement.lang === 'pl' ? 'en' : 'pl';
        document.cookie = 'viz-lang=' + next + '; path=/; max-age=31536000; SameSite=Lax';
        location.reload();
    };

    /** Dark by default; the light choice is remembered in the browser. The graph and office read colours at build time, so a reload. */
    const Theme = () => {
        const light = document.documentElement.dataset.theme !== 'light';
        if (light) document.documentElement.dataset.theme = 'light'; else delete document.documentElement.dataset.theme;
        try { localStorage.setItem('viz-theme', light ? 'light' : 'dark'); } catch { /* no storage */ }
        if (document.one('[viz-office]')) location.reload();
    };

    /**
     * Project scope (Viz\Web\Scope): choice in the left panel = viz-scope cookie (comma-separated keys, empty = all).
     * The server reads it on pages and when polling overview views (/feed.json), so the `viz-scope` event
     * is enough for a view to fetch data right away. Pages computed by scope on the server (Overview, "All sessions" — `[viz-scope-reload]`)
     * reload after a moment with no further choice changes (several clicks = one reload), without the `projects` parameter (it takes precedence over the cookie).
     * The session list is filtered in the browser; the open session is always visible.
     */
    const SCOPE_COOKIE = 'viz-scope';
    const SCOPE_RELOAD_MS = 700;
    let scopeReload = 0;

    const scopeKeys = box => [...box.all('input[type="checkbox"]')].filter(i => i.value !== '' && i.checked).map(i => i.value);

    const showScope = (box, keys) => {
        box.up('aside').all('li[viz-project]').forEach(li => li.show(keys.length === 0 || keys.includes(li.attr('viz-project')) || li.hasAttribute('viz-current')));
        box.up('aside').all('li.s-day').forEach(head => {
            let next = head.nextElementSibling;
            let any = false;
            while (next && !next.classList.contains('s-day')) { if (next.hidden !== true && next.style.display !== 'none') any = true; next = next.nextElementSibling; }
            head.show(any);
        });
        const names = [...box.all('input[type="checkbox"]')].filter(i => i.value !== '' && i.checked).map(i => i.attr('viz-scope-name'));
        box.one('[viz-scope-label]').textContent = keys.length === 0 ? T('all') : keys.length === 1 && names.length === 1 ? names[0] : T('{n} selected', { n: keys.length });
    };

    const saveScope = keys => {
        document.cookie = SCOPE_COOKIE + '=' + keys.join(',') + '; path=/; max-age=31536000; SameSite=Lax';
    };

    const Scope = input => {
        const box = input.closest('[viz-scope]');
        const all = box.one('input[value=""]');
        const items = [...box.all('input[type="checkbox"]')].filter(i => i.value !== '');
        if (input === all) items.forEach(i => { i.checked = false; });
        all.checked = !items.some(i => i.checked);
        const keys = scopeKeys(box);
        saveScope(keys);
        showScope(box, keys);
        document.dispatchEvent(new CustomEvent('viz-scope'));
        if (document.one('[viz-scope-reload]')) {
            clearTimeout(scopeReload);
            scopeReload = setTimeout(() => {
                const url = new URL(location.href);
                url.searchParams.delete('projects');
                location.replace(url.pathname + url.search);
            }, SCOPE_RELOAD_MS);
        }
    };

    /**
     * Scope from the address (`projects=`) remembered like a panel choice — a link with a scope sets it permanently. Keys from the address or cookie,
     * not from panel fields: a project outside the panel list (e.g. from the Overview "cost by project" chart) also stays in the scope, as on the server.
     */
    const ScopeInit = aside => {
        const box = aside.one('[viz-scope]');
        if (!box) return;
        const param = new URLSearchParams(location.search).get('projects');
        const cookie = document.cookie.split('; ').find(c => c.startsWith(SCOPE_COOKIE + '='))?.slice(SCOPE_COOKIE.length + 1) ?? '';
        const keys = (param ?? decodeURIComponent(cookie)).split(',').filter(k => /^[a-f0-9]{12}$/.test(k));
        if (param !== null) saveScope(keys);
        showScope(box, keys);
    };

    /**
     * Last opened session tab (`viz-view`, remembered at section Init) — a session link in the left panel opens it right away,
     * so consecutive sessions can be browsed in the same view (e.g. Office) without clicking through.
     */
    /**
     * Left panel width: dragging the handle on its edge; narrower than SIDE_HIDE = panel hidden (0), double click = default.
     * CSS var --side-w on <html> (also set in <head> before painting), choice in localStorage (per-browser convenience).
     */
    const SIDE_KEY = 'viz-side-w';
    const SIDE_DEFAULT = 280;
    const SIDE_MIN = 180;
    const SIDE_HIDE = 120;

    const sideSet = width => {
        const max = Math.round(window.innerWidth * 0.6);
        const w = width < SIDE_HIDE ? 0 : Math.max(SIDE_MIN, Math.min(max, Math.round(width)));
        document.documentElement.style.setProperty('--side-w', w + 'px');
        return w;
    };

    const sideSave = w => {
        try { localStorage.setItem(SIDE_KEY, String(w)); } catch { /* no storage */ }
    };

    const SideDown = (handle, event) => {
        event.preventDefault();
        handle.setPointerCapture(event.pointerId);
        handle._drag = { x: event.clientX, w: document.querySelector('.app > .sessions').getBoundingClientRect().width };
        handle.flag('viz-side-dragging', true);
    };

    const SideMove = (handle, event) => {
        if (!handle._drag) return;
        handle._w = sideSet(handle._drag.w + event.clientX - handle._drag.x);
    };

    const SideUp = handle => {
        if (!handle._drag) return;
        handle._drag = null;
        handle.flag('viz-side-dragging', false);
        if (handle._w !== undefined) sideSave(handle._w);
        // office scene and timeline compute their size on window resize
        window.dispatchEvent(new Event('resize'));
    };

    const SideReset = () => {
        sideSave(sideSet(SIDE_DEFAULT));
        window.dispatchEvent(new Event('resize'));
    };

    const SessionLink = link => {
        let view = '';
        try { view = localStorage.getItem('viz-view') || ''; } catch { /* no storage */ }
        if (view === '' || view === 'read') return;
        const url = new URL(link.href);
        url.searchParams.set('view', view);
        link.href = url.pathname + url.search;
    };

    return { T, Init, Query, Open, OpenCmd, Close, Go, Theme, Lang, Scope, ScopeInit, SessionLink, SideDown, SideMove, SideUp, SideReset };
})();
