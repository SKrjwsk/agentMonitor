/*
 * VizLanes — work timeline (route /lanes): agent lanes of all sessions in scope on a common axis of the feed window (`window`, 30 min),
 * "now" at the right edge. Session row (project colour, project, title), below it a lane per agent: main session, then subagents
 * by `start`. Bar = call from `agents[].calls` (`ts` → `end`, ongoing up to now), colour = tool kind (shared palette
 * of kinds `--k-*` from app.css; yellow `--act` reserved for "You"), outline = `status` blocked / failed / outside / trying / stopped; click a bar = that
 * call in the session log (`view=log&pev=`). Subagent lane = background from `start` to `last` (finished) or to now. Agent model = grey
 * level `--m-*` (lane outline, name) and a mark next to the name (○ Haiku, ◐ Sonnet, ● Opus) — no hue, so it is not confused with the kind.
 * A call longer than `permissionGuess` (90 s) = "approval?": after the threshold hatching like waiting for You with a thin call line
 * (a transcript cannot tell an approval request from a long command — as in Live); ongoing = also the state at the lane end and a counter in the header.
 * With the approval hook: hatching from the moment of the request (`calls[].permission`), state `waiting-permission`; a session with `permissionHook` does not guess.
 * Session ended (`SessionEnd`, main session state `ended`) — no yellow waiting, reason in the tooltip.
 * Main session lane: current waiting for You (`idle` since `since`) yellow, past (`waits`) grey; prompt lines (`prompts`);
 * dashed line = context compaction (`compactions` from the hook: time, `trigger` manual / auto; without the hook nothing).
 * Communication: a task for a subagent and its result (`messages` `task` / `result`) = `--k-agent` arrow (magenta) between session lanes
 * and a line on the subagent lane, a message to a session whose title equals the addressee (`sends`) = arrow in text colour (`--k-peer`) between rows
 * (SVG layer above the list), an addressee without a match (e.g. a socket address) = just a line.
 * At the lane end: state, time in state, counters from the window (`counts`: files read, files changed, Bash commands), cost since the start
 * (`spent`; tooltip: in the window, `usdWindow`), current call. Sessions grouped by project (header: sessions, working,
 * waiting for You). Fixed order (project, main session start), so rows do not jump on the 1 s refresh.
 * The view is expanded: a session has a header (title, counters, cost), and below it a separate lane for each agent (main session,
 * subagents). Signature: templates/lanes.php.
 * Data: VizFeed; all texts via textContent.
 */
window.VizLanes = (() => {

    const { T, el, tail, color, sessionLink, byProject, role, clock, remember } = VizViews;
    const PERSONAS = ['haiku', 'sonnet', 'opus'];
    const STATES = ['working', 'delegated', 'idle', 'stuck', 'done', 'waiting-permission', 'ended'];
    const STATUSES = ['blocked', 'failed', 'outside', 'trying', 'stopped'];
    /** Call kind by tool name (the feed carries no touch operations); keys as `viz-kind` in Timeline. */
    const KINDS = [
        ['read', ['Read', 'NotebookRead']],
        ['search', ['Grep', 'Glob', 'LS', 'ToolSearch', 'WebSearch', 'WebFetch']],
        ['edit', ['Edit', 'Write', 'MultiEdit', 'NotebookEdit']],
        ['exec', ['Bash']],
        ['agent', ['Agent', 'Task', 'SendMessage', 'SubagentHandback']],
    ];
    const TICK = 300;
    const SVG = 'http://www.w3.org/2000/svg';

    const kind = tool => KINDS.find(([, tools]) => tools.includes(tool))?.[0] ?? 'other';
    const duration = sec => sec < 60 ? Math.max(0, Math.round(sec)) + ' s' : sec < 3600 ? Math.floor(sec / 60) + ' min' : (sec / 3600).toFixed(1) + ' h';
    const sec = iso => Date.parse(iso) / 1000;
    const usd = v => '$' + (v >= 100 ? v.toFixed(0) : v >= 10 ? v.toFixed(1) : v.toFixed(2));
    const kilo = n => n >= 1000 ? Math.round(n / 1000) + 'k' : String(n);
    const sum = list => list.reduce((x, v) => x + (v || 0), 0);
    /** Agent in an ongoing call longer than the threshold — "approval?" instead of "working"; we do not guess in a session with the approval hook (`permissionHook`). */
    const asking = (a, s, now, guess) => a.state === 'working' && !!a.tool && !s.permissionHook && now - sec(a.since) >= guess;
    /** Waiting for approval: confirmed by the hook or guessed. */
    const asks = (a, s, now, guess) => a.state === 'waiting-permission' || asking(a, s, now, guess);
    /** Subagent caption: task description, type (without the generic one) or "subagent". */
    const subName = a => a.description || role(a) || T('subagent');

    /** Position in percent of the axis; outside the window — clamped to the edge. */
    const axis = (now, win) => t => Math.max(0, Math.min(100, 100 * (t - (now - win)) / win));

    const span = (cls, x, from, to) => {
        const e = el('i', cls);
        e.style.left = x(from) + '%';
        e.style.width = Math.max(0, x(to) - x(from)) + '%';

        return e;
    };

    const mark = (x, ts, kind, title) => {
        const t = el('i', 'lanes-msg');
        t.dataset.kind = kind;
        t.style.left = x(sec(ts)) + '%';
        t.title = title;

        return t;
    };

    /**
     * Bars of one call; from the approval request (from the hook: `c.permission`; without the approval hook in the session: after the `guess` threshold) hatching
     * (ongoing yellow, past grey) with a thin call line.
     */
    const call = (track, x, s, c, now, guess, cls, prefix = '') => {
        const from = sec(c.ts);
        const to = c.end ? sec(c.end) : now;
        const ask = c.permission ? Math.max(from, sec(c.permission)) : s.permissionHook ? to : from + guess;
        const cut = cls === 'lanes-call' && to > ask ? ask : to;
        const parts = [span(cls, x, from, cut)];
        if (cut < to) {
            track.append(span(c.end ? 'lanes-waited' : 'lanes-wait', x, cut, to));
            parts.push(span(cls + ' lanes-thin', x, cut, to));
        }
        const cost = c.usd !== null && c.usd !== undefined ? ' · ' + T('result in context +{t} tokens · {c}', { t: kilo(c.tokens || 0), c: usd(c.usd) }) : '';
        for (const b of parts) {
            b.dataset.kind = kind(c.tool);
            if (STATUSES.includes(c.status)) b.dataset.status = c.status;
            if (c.id) {
                b.dataset.ev = c.id;
                b.dataset.s = s.id;
            }
            b.title = prefix + c.tool + ' · ' + (c.text || '') + (cut < to ? ' · ' + (c.permission ? T('waiting for permission') + ' ' + duration(to - cut) : T('permission?') + ' ' + duration(to - from)) : '') + cost
                + (c.id ? '\n' + T('click = this call in the session log') : '');
            track.append(b);
        }
    };

    /** Vertical arrow in SVG from lane `src` to lane `dst` at time `ts`. */
    const arrow = (svg, box, x, src, dst, ts, cls) => {
        const a = src.getBoundingClientRect();
        const b = dst.getBoundingClientRect();
        const px = a.left - box.left + a.width * x(sec(ts)) / 100;
        const y1 = a.top - box.top + a.height / 2;
        const y2 = b.top - box.top + b.height / 2;
        const d = y2 > y1 ? 1 : -1;
        const line = document.createElementNS(SVG, 'path');
        line.setAttribute('class', cls);
        line.setAttribute('d', 'M' + px + ' ' + y1 + ' L' + px + ' ' + y2 + ' m-4 ' + (-6 * d) + ' l4 ' + (6 * d) + ' l4 ' + (-6 * d));
        svg.append(line);
    };

    /** Arrows: tasks and results of subagents in a session (`--k-agent`) and messages between sessions (`--k-peer`, text colour; session title = addressee). */
    const arrows = (list, sessions, now, win) => {
        const x = axis(now, win);
        const box = list.getBoundingClientRect();
        const svg = document.createElementNS(SVG, 'svg');
        svg.setAttribute('class', 'lanes-arrows');
        svg.setAttribute('width', String(list.scrollWidth));
        svg.setAttribute('height', String(list.scrollHeight));
        const track = (sid, aid) => list.querySelector('[data-agent="' + CSS.escape(sid + '|' + aid) + '"]');
        for (const s of sessions) {
            for (const m of s.messages || []) {
                const src = m.kind !== 'peer' && m.from && track(s.id, m.from);
                const dst = m.to && track(s.id, m.to);
                if (['task', 'result'].includes(m.kind) && src && dst && sec(m.ts) >= now - win) arrow(svg, box, x, src, dst, m.ts, 'lanes-arrow-sub');
            }
            const src = track(s.id, s.agents[0].id);
            for (const m of s.sends || []) {
                const target = sessions.find(t => t.id !== s.id && t.title === m.to);
                const dst = target && track(target.id, target.agents[0].id);
                if (src && dst && sec(m.ts) >= now - win) arrow(svg, box, x, src, dst, m.ts, 'lanes-arrow-peer');
            }
        }
        list.querySelector('.lanes-arrows')?.remove();
        list.append(svg);
    };

    const scale = (root, now, win) => {
        const x = axis(now, win);
        const box = root.one('[viz-lanes-scale]');
        const track = el('div', 'lanes-ticks');
        for (let ago = win; ago >= 0; ago -= TICK) {
            const t = el('span', 'lanes-tick', ago ? '−' + Math.round(ago / 60) + ' min' : T('now'));
            t.style.left = x(now - ago) + '%';
            track.append(t);
        }
        box.replaceChildren(el('span', 'lanes-label'), track, el('span', 'lanes-end'));
    };

    /** Counters from the window: files read, files changed, Bash commands — a square in the bar kind colour. */
    const tally = c => {
        const e = el('span', 'lanes-tally');
        for (const [k, n] of [['read', c?.read], ['edit', c?.write], ['exec', c?.bash]]) {
            const i = el('span', null, String(n || 0));
            i.dataset.kind = k;
            if (!n) i.dataset.zero = '';
            e.append(i);
        }
        e.title = T('last 30 min: files read {r} · files changed {w} · Bash commands {b}', { r: c?.read || 0, w: c?.write || 0, b: c?.bash || 0 });

        return e;
    };

    /** Cost since the session start (as in a subscription: the API equivalent), in the tooltip — from the window. */
    const money = (spent, win) => {
        const e = el('span', 'lanes-usd', usd(spent || 0));
        e.title = T('cost since the session start (API prices) · last 30 min {c}', { c: usd(win || 0) });

        return e;
    };

    /** `t` = {now, win, guess ("approval?" threshold)}; `counts` / `spent` / `usdWin` = counters and cost at the lane end (by default the agent's). */
    const lane = (a, s, t, href, title, counts = a.counts, spent = a.spent, usdWin = a.usdWindow) => {
        const { now, win, guess } = t;
        const x = axis(now, win);
        const row = el(href ? 'a' : 'div', 'lanes-lane');
        if (href) row.href = href;
        row.dataset.persona = PERSONAS.includes(a.persona) ? a.persona : 'default';
        row.dataset.state = STATES.includes(a.state) ? a.state : 'idle';
        const label = el('span', 'lanes-label');
        // subagents of one type differ by task: task description in the label, agent type in the tooltip
        const name = title ?? (a.kind === 'sub' ? subName(a) : a.label || a.id);
        // description cut from the right by CSS (the start of the task matters more), full in the tooltip
        const text = el('span', 'lanes-name', name);
        label.append(el('span', 'lanes-model', a.modelName || '?'), text);
        label.title = text.title = a.kind === 'sub' ? [role(a), a.description].filter(Boolean).join(' · ') : name;
        const track = el('div', 'lanes-track');
        track.dataset.agent = s.id + '|' + a.id;
        if (a.kind === 'sub') track.append(span('lanes-life', x, sec(a.start), a.state === 'done' ? sec(a.last) : now));
        if (a.kind !== 'sub') {
            for (const w of s.waits || []) track.append(span('lanes-waited', x, sec(w.from), sec(w.to)));
            if (a.state === 'idle') track.append(span('lanes-wait', x, sec(a.since), now));
            for (const p of s.prompts || []) track.append(mark(x, p.ts, p.kind, T(p.kind === 'peer' ? 'message from another session' : 'prompt')));
            for (const m of s.sends || []) track.append(mark(x, m.ts, 'send', '→ ' + m.to));
            for (const c of s.compactions || []) {
                if (sec(c.ts) < now - win) continue;
                const k = el('i', 'lanes-compact');
                k.style.left = x(sec(c.ts)) + '%';
                k.title = T('compaction: {t}, {h}', { t: c.trigger === 'manual' ? T('manual') : c.trigger === 'auto' ? T('automatic') : '?', h: clock(c.ts) });
                track.append(k);
            }
        }
        for (const c of a.calls) {
            if ((c.end ? sec(c.end) : now) >= now - win) call(track, x, s, c, now, guess, 'lanes-call');
        }
        for (const m of s.messages) {
            if ((m.kind === 'task' && m.to === a.id) || (m.kind === 'result' && m.from === a.id)) {
                track.append(mark(x, m.ts, m.kind, T(m.kind === 'task' ? 'task for the subagent' : 'subagent result') + ' · ' + m.text));
            }
        }
        const state = STATES.includes(a.state) ? a.state : 'idle';
        const [from, to] = state === 'done' ? [sec(a.start), sec(a.last)] : [sec(a.since), now];
        const what = a.activity?.text ? (a.tool ? a.tool + ' · ' : '') + tail(a.activity.text, 36)
            : state === 'delegated' && a.waitingFor ? T('waiting for {n} subagents', { n: a.waitingFor })
            : state === 'stuck' && a.stuckType ? a.stuckType : '';
        const ask = asking(a, s, now, guess);
        if (ask || state === 'waiting-permission') row.dataset.ask = '';
        const end = el('span', 'lanes-end');
        const stateEl = el('strong', 'lanes-state', ask ? T('permission?') : state === 'waiting-permission' ? T('waiting for permission')
            : state === 'ended' ? T('session ended') : T(state === 'idle' && a.kind !== 'sub' ? 'waiting for you' : state));
        if (state === 'ended') stateEl.title = T('session ended: {r}', { r: a.ended || 'other' });
        end.append(stateEl,
            el('span', 'muted lanes-time', Number.isNaN(to - from) ? '' : duration(to - from)), tally(counts), money(spent, usdWin),
            el('span', 'muted lanes-what', what));
        row.append(label, track, end);

        return row;
    };

    const MCP_LIMIT = 8;

    /** Latest `mcp__<server>__<tool>` calls from all agents in the window: server, tool, call time, status; click = session log. */
    const mcpCalls = (root, sessions, now, win) => {
        const rows = sessions.flatMap(s => s.agents.flatMap(a => a.calls.map(c => [c, s])))
            .filter(([c]) => c.tool.startsWith('mcp__') && (c.end ? sec(c.end) : now) >= now - win)
            .sort((a, b) => sec(b[0].ts) - sec(a[0].ts)).slice(0, MCP_LIMIT);
        const box = root.one('[viz-lanes-mcp]');
        box.hidden = !rows.length;
        root.one('[viz-lanes-mcp-list]').replaceChildren(...rows.map(([c, s]) => {
            const [server, ...tool] = c.tool.slice(5).split('__');
            const li = el('li', 'lanes-mcp-row');
            if (STATUSES.includes(c.status)) li.dataset.status = c.status;
            li.title = c.tool + (c.text ? ' · ' + c.text : '') + (c.id ? '\n' + T('click = this call in the session log') : '');
            if (c.id) {
                li.dataset.ev = c.id;
                li.dataset.s = s.id;
            }
            const ms = c.end ? Math.max(0, Math.round((Date.parse(c.end) - Date.parse(c.ts)))) : null;
            li.append(el('b', null, server), el('span', null, tool.join('__')),
                el('span', 'muted', c.end ? (ms >= 1000 ? (ms / 1000).toFixed(1) + ' s' : ms + ' ms') : T('running')),
                el('span', 'muted', clock(c.ts)));

            return li;
        }));
    };

    const render = (root, feed) => {
        root.one('[viz-lanes-status]').textContent = feed.failed ? T('refresh not working') : '';
        const data = feed.data;
        if (!data) return;
        const now = feed.now();
        const win = data.window || 1800;
        const guess = data.permissionGuess || 90;
        const t = { now, win, guess };
        scale(root, now, win);
        const sessions = data.sessions.filter(s => s.agents.length)
            .sort((a, b) => a.project.localeCompare(b.project) || sec(a.agents[0].start) - sec(b.agents[0].start) || a.id.localeCompare(b.id));
        const agents = sessions.flatMap(s => s.agents);
        const pairs = list => list.flatMap(s => s.agents.map(a => [a, s]));
        const n = st => agents.filter(a => a.state === st).length;
        const working = ps => ps.filter(([a, s]) => a.state === 'working' && !asking(a, s, now, guess)).length;
        // verdict: only non-zero counters, cost always
        root.one('[viz-lanes-verdict]').textContent = [
            ['working {n}', working(pairs(sessions))], ['permission? {n}', pairs(sessions).filter(([a, s]) => asks(a, s, now, guess)).length],
            ['waiting for you {n}', sessions.filter(s => s.agents[0].state === 'idle').length], ['delegating {n}', n('delegated')], ['stuck {n}', n('stuck')],
        ].filter(([, v]) => v > 0).map(([k, v]) => T(k, { n: v })).concat(T('{c} in 30 min', { c: usd(sum(agents.map(a => a.usdWindow))) })).join(' · ');
        mcpCalls(root, sessions, now, win);
        const list = root.one('[viz-lanes-list]');
        if (!sessions.length) {
            list.replaceChildren(el('p', 'live-empty', T('No sessions active in scope in the last 30 min.')));
            return;
        }
        const sessionWin = s => sum(s.agents.map(a => a.usdWindow));
        const session = s => {
            const href = sessionLink(s.id, '&view=flow');
            const box = el('div', 'lanes-session');
            const head = el(href ? 'a' : 'div', 'lanes-head');
            if (href) head.href = href;
            head.append(el('strong', null, tail(s.title || s.id.slice(0, 8), 50)), tally(s.counts), money(s.spent, sessionWin(s)));
            box.append(head, ...s.agents.map(a => lane(a, s, t, href)));

            return box;
        };
        list.replaceChildren(...byProject(sessions).map(g => {
            const box = el('section', 'lanes-project');
            box.style.setProperty('--p', color(g.color));
            const head = el('div', 'lanes-project-head');
            const dot = el('i', 'lanes-dot');
            dot.style.background = color(g.color);
            head.append(dot, el('strong', null, g.project), el('span', 'muted', T('sessions {n} · working {w} · waiting for you {i}', {
                n: g.sessions.length, w: working(pairs(g.sessions)), i: g.sessions.filter(s => s.agents[0].state === 'idle').length,
            })));
            box.append(head, ...g.sessions.map(session));

            return box;
        }));
        arrows(list, sessions, now, win);
    };

    const Init = root => {
        remember(root.one('[viz-lanes-legend]'), 'viz-lanes-legend');
        // click on a call bar = that call in the session log (details panel); the rest of the row = the session's Flow
        root.addEventListener('click', e => {
            const b = e.target.closest('[viz-lanes-list] [data-ev], [viz-lanes-mcp] [data-ev]');
            const href = b && sessionLink(b.dataset.s, '&view=log&pev=' + encodeURIComponent(b.dataset.ev));
            if (!href) return;
            e.preventDefault();
            location.href = href;
        });
        root._feed = VizFeed.Start(root, { url: root.attr('viz-lanes-url'), onUpdate: feed => render(root, feed) });
    };

    return { Init };
})();
