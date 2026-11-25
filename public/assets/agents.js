/*
 * VizAgents — agents overview (route /agents): row = session in scope (project colour, project, title), in the row agent tiles
 * (model in persona colour, agent name, state in one word with time in state, current call, tokens of the whole session, cost). Click a row or tile = the session's Flow
 * tab. Sessions grouped by project (projects alphabetically; header: sessions, active, tokens, cost), within a project active ones
 * (someone is working or delegating) on top, then newest. Signature: templates/agents.php. Data: VizFeed every 1 s
 * (`sessions[].agents[]`: `modelName`, `persona`, `state`, `tokens`, `spent`); all texts via textContent.
 * Orphaned processes under the list, collapsed.
 */
window.VizAgents = (() => {

    const { T, el, tail, color, sessionLink, byProject } = VizViews;
    const PERSONAS = ['haiku', 'sonnet', 'opus'];
    const STATES = ['working', 'delegated', 'idle', 'stuck', 'done', 'waiting-permission', 'ended'];
    // waiting for approval (from the hook) also on top — waiting for you
    const ACTIVE = ['working', 'delegated', 'waiting-permission'];
    /** State label; hook states have their own texts. */
    const label = st => st === 'waiting-permission' ? T('waiting for permission') : st === 'ended' ? T('session ended') : T(st);

    const num = n => n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1) + 'k' : String(n || 0);
    const usd = v => '$' + (v || 0).toFixed(2);
    const duration = sec => sec < 60 ? Math.round(sec) + ' s' : sec < 3600 ? Math.floor(sec / 60) + ' min' : (sec / 3600).toFixed(1) + ' h';
    const sum = t => (t?.input || 0) + (t?.output || 0) + (t?.cacheWrite || 0) + (t?.cacheRead || 0);
    const active = s => s.agents.some(a => ACTIVE.includes(a.state));
    /** Link to the session's Flow tab (null for an invalid id). */
    const flow = sid => sessionLink(sid, '&view=flow');

    const anchor = (cls, href) => {
        const a = el(href ? 'a' : 'div', cls);
        if (href) a.href = href;

        return a;
    };

    const tile = (a, href, now) => {
        const t = anchor('agents-tile', href);
        t.dataset.persona = PERSONAS.includes(a.persona) ? a.persona : 'default';
        t.dataset.state = STATES.includes(a.state) ? a.state : 'idle';
        const tk = a.tokens || {};
        t.title = [[a.label, a.description].filter(Boolean).join(' · '), T('tokens {t}', { t: num(sum(a.tokens)) }) + ' · ' + T('input {i} · output {o} · cache write {w} · cache read {r}', { i: num(tk.input), o: num(tk.output), w: num(tk.cacheWrite), r: num(tk.cacheRead) })]
            .filter(Boolean).join('\n');
        // time in state (`since`); finished subagent — working time (start → last activity)
        const [from, to] = a.state === 'done' ? [Date.parse(a.start), Date.parse(a.last)] : [Date.parse(a.since), now * 1000];
        const secs = (to - from) / 1000;
        const what = a.activity?.text ? (a.tool ? a.tool + ' · ' : '') + tail(a.activity.text, 30)
            : a.state === 'delegated' && a.waitingFor ? T('waiting for {n} subagents', { n: a.waitingFor })
            : a.state === 'stuck' && a.stuckType ? a.stuckType : a.state === 'ended' && a.ended ? T('session ended: {r}', { r: a.ended }) : '';
        // subagents of one type differ by task: task description instead of agent type (type in the tooltip)
        const name = a.kind === 'sub' && a.description ? a.description : a.label || a.id;
        // description cut from the right (the start of the task matters more); `tail` is for paths
        const short = name.length > 26 ? name.slice(0, 25) + '…' : name;
        t.append(el('span', 'agents-model', a.modelName || '?'), el('strong', null, short),
            el('span', 'agents-state', label(t.dataset.state) + (Number.isNaN(secs) || secs < 1 ? '' : ' · ' + duration(secs))),
            el('span', 'agents-what muted', what), el('span', 'agents-num', usd(a.spent)));

        return t;
    };

    const row = (s, now) => {
        const href = flow(s.id);
        const r = el('div', 'agents-row');
        r.dataset.active = String(active(s));
        const head = anchor('agents-session', href);
        head.append(el('strong', null, tail(s.title || s.id.slice(0, 8), 40)),
            el('span', 'agents-num muted', T('tokens {t}', { t: num(s.agents.reduce((x, a) => x + sum(a.tokens), 0)) }) + ' · ' + usd(s.spent)));
        const tiles = el('div', 'agents-tiles');
        tiles.append(...s.agents.map(a => tile(a, href, now)));
        r.append(head, tiles);

        return r;
    };


    // --- processes on the computer (feed `processes`): children of `claude` processes next to the project, orphans in a separate section; only program name, pid, time, CPU, RAM, port ---
    const mem = b => b >= 1073741824 ? (b / 1073741824).toFixed(1) + ' GB' : Math.round(b / 1048576) + ' MB';
    const procLine = p => {
        const li = el('li', 'agents-proc');
        li.append(el('strong', null, p.name), el('span', 'muted', T('pid {p} · up {u} · CPU {c}% · RAM {m}', { p: p.pid, u: duration(p.uptime), c: p.cpu.toFixed(1), m: mem(p.rss) })
            + (p.ports?.length ? ' · ' + p.ports.map(x => T('port {p}', { p: x })).join(', ') : '')));
        if (p.claude) li.dataset.claude = 'true';

        return li;
    };
    /** Expandable process list (collapsed by default); open state in `root._procOpen` under `id`, because the view is redrawn every second. */
    const fold = (root, id) => {
        const d = el('details', 'agents-procs');
        d.open = root._procOpen?.has(id) || false;
        d.addEventListener('toggle', () => {
            root._procOpen ??= new Set();
            d.open ? root._procOpen.add(id) : root._procOpen.delete(id);
        });

        return d;
    };
    /** Processes of a project (`id` = project key). */
    const procs = (root, id, g) => {
        const d = fold(root, id);
        d.append(el('summary', 'muted', T('processes {n} · CPU {c}% · RAM {m}', { n: g.children, c: g.cpu.toFixed(1), m: mem(g.rss) })));
        const ul = el('ul', 'agents-proc-list');
        ul.append(...g.procs.map(procLine));
        d.append(ul);

        return d;
    };
    const orphans = (root, pr) => {
        const box = root.one('[viz-agents-orphans]');
        if (!pr) return;
        if (!pr.available) {
            box.replaceChildren(el('p', 'muted', T('processes unavailable')));
            return;
        }
        if (!pr.orphans.length) {
            box.replaceChildren();
            return;
        }
        const ul = el('ul', 'agents-proc-list');
        ul.append(...pr.orphans.map(p => {
            const li = procLine(p);
            li.append(el('span', 'muted', ' · ' + p.cwd));

            return li;
        }));
        // collapsed by default, counter in the header; the `orphans` key will not collide with a project key (12 hex)
        const d = fold(root, 'orphans');
        const head = el('summary');
        head.append(el('strong', null, T('Orphaned processes ({n})', { n: pr.orphans.length })));
        d.append(head, el('p', 'muted', T('Parent is gone (PPID 1); working directory in your projects.')), ul);
        box.replaceChildren(d);
    };

    const render = (root, feed) => {
        root.one('[viz-agents-status]').textContent = feed.failed ? T('refresh not working') : '';
        const data = feed.data;
        if (!data) return;
        orphans(root, data.processes);
        const list = root.one('[viz-agents-list]');
        const sessions = data.sessions.filter(s => s.agents.length)
            .sort((a, b) => a.project.localeCompare(b.project) || Number(active(b)) - Number(active(a)) || Date.parse(b.last) - Date.parse(a.last));
        const agents = sessions.flatMap(s => s.agents);
        root.one('[viz-agents-verdict]').textContent = T('sessions {s} · agents {a} · active {n} · {c}', {
            s: sessions.length, a: agents.length, n: agents.filter(a => ACTIVE.includes(a.state)).length, c: usd(sessions.reduce((x, s) => x + (s.spent || 0), 0)),
        });
        if (!sessions.length) {
            list.replaceChildren(el('p', 'live-empty', T('No sessions active in scope in the last 30 min.')));
            return;
        }
        list.replaceChildren(...byProject(sessions).map(g => {
            const box = el('section', 'agents-project');
            box.style.setProperty('--p', color(g.color));
            const head = el('div', 'agents-project-head');
            const dot = el('i', 'agents-dot');
            dot.style.background = color(g.color);
            const tokens = g.sessions.reduce((x, s) => x + s.agents.reduce((y, a) => y + sum(a.tokens), 0), 0);
            head.append(dot, el('strong', null, g.project), el('span', 'muted', T('sessions {n} · active {a} · tokens {t} · {c}', {
                n: g.sessions.length, a: g.sessions.filter(active).length, t: num(tokens), c: usd(g.sessions.reduce((x, s) => x + (s.spent || 0), 0)),
            })));
            const els = g.sessions.map(s => row(s, feed.now()));
            box.append(head, ...els);
            // processes have the project's working directory, not the session's: with one session in the project under it, with several under the project header
            const { key: pkey } = g;
            const pg = data.processes?.groups?.[pkey];
            if (pg?.children) {
                const block = procs(root, pkey, pg);
                (els.length === 1 ? els[0] : head).after(block);
            }

            return box;
        }));
    };

    const Init = root => {
        root._feed = VizFeed.Start(root, { url: root.attr('viz-agents-url'), onUpdate: feed => render(root, feed) });
    };

    return { Init };
})();
