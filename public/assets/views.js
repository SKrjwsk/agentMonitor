/*
 * VizViews — shared pieces of overview views on the feed (VizFeed, /feed.json): DOM via textContent, colours from tokens,
 * boundary data (directories from `sessions[].map` merged across sessions, calls without resolved paths) and the boundary verdict.
 * View: VizFeed.Start(root, { onUpdate }) + functions from here + own drawing.
 */
window.VizViews = (() => {

    const T = (key, vars) => Viz.T(key, vars);
    const OPS = ['read', 'look', 'write', 'exec'];
    const SESSION_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    const el = (tag, cls, text) => {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    };
    const css = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    /** Colour from the data only as #rrggbb; otherwise muted. */
    const color = c => /^#[0-9a-f]{6}$/i.test(c || '') ? c : css('--muted');
    /** Path cut from the left (the directory and its parent stay visible). */
    const tail = (text, n) => text.length > n ? '…' + text.slice(-(n - 1)) : text;
    const total = r => OPS.reduce((x, k) => x + (r[k] || 0), 0);
    const sessionLink = (sid, extra = '') => SESSION_ID.test(sid) ? '/?s=' + encodeURIComponent(sid) + extra : null;
    const clock = iso => new Date(Date.parse(iso)).toLocaleTimeString(document.documentElement.lang || 'pl', { hour: '2-digit', minute: '2-digit' });

    /** Directory key at the boundary (the same directory in the same zone from several sessions = one). */
    const dirKey = m => m.zone + '|' + (m.root ?? '') + '|' + m.dir.toLowerCase();

    /** Bash calls without resolved paths in the feed window, with the session. */
    const unknownCalls = (data, now) => data.sessions.flatMap(s => (s.boundary || [])
        .filter(b => b.kind === 'unknown' && now - Date.parse(b.ts) / 1000 <= data.window).map(b => ({ ...b, session: s })));

    /** Directories out of bounds: executed (> stopped) and stopped only — by key. */
    const outside = data => {
        const ran = new Set();
        const stopped = new Set();
        for (const s of data.sessions) {
            for (const m of s.map || []) {
                if (m.zone !== 'out') continue;
                if (total(m) > m.stopped) ran.add(dirKey(m));
                if (m.stopped > 0) stopped.add(dirKey(m));
            }
        }
        return { ran: ran.size, stopped: stopped.size };
    };

    /** Boundary verdict in the view header (`data-sev` + text). */
    const boundaryVerdict = (verdict, data, now) => {
        if (!data.boundary) {
            verdict.dataset.sev = 'info';
            verdict.textContent = T('boundary off');
            return;
        }
        const out = outside(data);
        const unknown = unknownCalls(data, now).length;
        verdict.dataset.sev = out.ran ? 'high' : unknown ? 'warn' : 'ok';
        verdict.textContent = out.ran ? T('Outside the boundary: {n} directories', { n: out.ran })
            : unknown ? T('Within the boundary, {n} calls with unknown paths', { n: unknown })
            : out.stopped ? T('Within the boundary — {n} attempts stopped', { n: out.stopped }) : T('All agents work within the boundary');
    };

    /** Subagent type for the caption; the generic type (default for all, says nothing) = empty. */
    const GENERIC_AGENTS = ['general-purpose'];
    const role = a => GENERIC_AGENTS.includes(a.label) ? '' : a.label || '';

    /** Agent name for the caption: main session = session title, subagent = type (without the generic) and description of the task. */
    const agentName = (s, a) => a.kind === 'main' ? (s.title || s.id.slice(0, 8)) : [role(a), a.description].filter(Boolean).join(': ') || T('subagent');

    /** Sessions grouped by project (`projectKey`), in order of first occurrence — the order is set by the view, by sorting sessions. */
    const byProject = sessions => {
        const groups = new Map();
        for (const s of sessions) {
            const key = s.projectKey || s.project;
            if (!groups.has(key)) groups.set(key, { key, project: s.project, color: s.projectColor, sessions: [] });
            groups.get(key).sessions.push(s);
        }

        return [...groups.values()];
    };

    /**
     * Secondary section (`<details>`, in the template without `open` — collapsed by default): open state in `localStorage` under `key`.
     * Without storage access — it stays collapsed, toggling works only in this tab.
     */
    const remember = (details, key) => {
        if (!details) return;
        try { details.open = localStorage.getItem(key) === 'open'; } catch { /* no storage: collapsed */ }
        details.addEventListener('toggle', () => {
            try { localStorage.setItem(key, details.open ? 'open' : 'closed'); } catch { /* no storage: only this tab */ }
        });
    };

    return { T, OPS, el, css, color, tail, total, sessionLink, clock, dirKey, unknownCalls, outside, boundaryVerdict, role, agentName, byProject, remember };
})();
