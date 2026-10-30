/*
 * VizLive — tablica decyzji (trasa /live): wiersz = sesja w zakresie projektów, kolumna = pytanie, na które user może zareagować.
 * Sygnatura: templates/live.php. Dane: wspólny feed (VizFeed, GET /feed.json — src/Office/Feed.php, LiveState), zakres z ciasteczka viz-scope.
 * Wagi: high > act > warn > info > ok; wiersze z wagą < warn zwinięte w „bez uwag”.
 * Wszystkie dane trafiają do DOM przez textContent; linki tylko z identyfikatorów po walidacji.
 */
window.VizLive = (() => {

    const T = (key, vars) => Viz.T(key, vars);

    const ACK_KEY = 'viz-live-ack';
    const ACK_MAX = 500;
    const SESSION_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    const SEV = { high: 4, act: 3, warn: 2, info: 1, ok: 0 };
    const B_ORDER = ['outside', 'trying', 'unknown', 'stopped'];
    const B_SEV = { outside: 'high', trying: 'act', unknown: 'warn', stopped: 'info' };
    // funkcje, bo słownik czytany jest przy pierwszym użyciu
    const B_LABEL = { outside: () => T('executed outside'), trying: () => T('attempt running'), unknown: () => T('unknown paths'), stopped: () => T('stopped') };
    const B_PLURAL = { outside: () => T('executed outside'), trying: () => T('attempts running'), unknown: () => T('unknown paths'), stopped: () => T('stopped') };
    const STATE = { working: () => T('working'), delegated: () => T('waiting for subagents'), idle: () => T('waiting for you'), stuck: () => T('API limit'), done: () => T('finished') };
    const STATE_SEV = { working: 'ok', delegated: 'info', idle: 'act', stuck: 'high', done: 'info' };

    // ---- pomocnicze ----

    const el = (tag, cls, text) => {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    };
    const sec = iso => Date.parse(iso) / 1000;
    const dur = s => {
        s = Math.max(0, Math.round(s));
        if (s < 60) return s + ' s';
        if (s < 3600) return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        return Math.floor(s / 3600) + ' h ' + Math.floor(s % 3600 / 60) + ' min';
    };
    const fmtK = n => n >= 1e6 ? (n / 1e6).toFixed(2) + 'M' : Math.round(n / 1000) + 'k';
    const usd = v => '$' + (v >= 100 ? v.toFixed(0) : v >= 10 ? v.toFixed(1) : v.toFixed(2));
    const clock = ts => new Date(ts * 1000).toLocaleTimeString(document.documentElement.lang || 'pl', { hour: '2-digit', minute: '2-digit' });
    /** Cel wywołania: ścieżka już przycięta od lewej przez serwer, opis — od prawej. */
    const target = act => act ? act.text : '';
    const agentName = a => a.kind === 'main' ? T('main session') : a.label + (a.description ? ': ' + a.description : '');
    const sessionLink = (sid, extra = '') => SESSION_ID.test(sid) ? '/?s=' + encodeURIComponent(sid) + extra : null;

    const loadAck = () => {
        try { return new Set(JSON.parse(localStorage.getItem(ACK_KEY) || '[]')); } catch { return new Set(); }
    };
    const saveAck = acked => {
        try { localStorage.setItem(ACK_KEY, JSON.stringify([...acked].slice(-ACK_MAX))); } catch { /* bez pamięci */ }
    };

    // ---- reguły: co wymaga reakcji ----

    /** „Ty”: najpilniejsza rzecz, na którą czeka sesja. Długie wywołanie = „zgoda?” — z transkryptu nie da się odróżnić zgody od długiej komendy. */
    const youCell = (live, s) => {
        const now = live.now();
        const main = s.agents.find(a => a.kind === 'main') || s.agents[0];
        const rb = s.agents.find(a => a.reportBlocked && a.state !== 'working');
        if (main.state === 'stuck') {
            return { sev: 'high', key: 'stuck', main: T(main.stuck === 'limit' ? 'API limit' : 'API error') + ' · ' + dur(now - sec(main.since)), sub: T('the session stopped and will not resume by itself') };
        }
        if (rb) return { sev: 'high', key: 'report', main: T('report did not arrive'), sub: T('{agent} — blocked by {by}', { agent: rb.label, by: rb.reportBlocked.by }) };
        const long = s.agents.filter(a => a.state === 'working' && a.tool && now - sec(a.since) >= live.data.permissionGuess)
            .sort((x, y) => sec(x.since) - sec(y.since))[0];
        if (long) return { sev: 'act', key: 'perm', agent: long, main: T('permission?') + ' · ' + dur(now - sec(long.since)), sub: long.tool + ' ' + target(long.activity) };
        if (main.state === 'idle') {
            return { sev: 'act', key: 'idle', main: T(main.interrupted ? 'interrupted by you' : 'waiting for you') + ' · ' + dur(now - sec(main.since)),
                sub: main.after ? T('last: {call}', { call: main.after.tool + ' ' + target(main.after.activity) }) : '' };
        }
        if (main.state === 'delegated') return { sev: 'ok', key: 'ok', main: T('waiting for {n} subagents', { n: main.waitingFor }), sub: T('nothing to do') };
        return { sev: 'ok', key: 'ok', main: T('working'), sub: main.tool ? main.tool + ' ' + target(main.activity) : T('model is writing') };
    };

    const ackKey = (s, b) => s.id + ':' + b.id;
    const liveEvents = (live, s) => (s.boundary || []).filter(b => live.now() - sec(b.ts) <= live.data.window && !(b.kind === 'outside' && live.acked.has(ackKey(s, b))));

    const boundCell = (live, s) => {
        if (!live.data.boundary) return { sev: 'info', key: 'off', main: T('boundary off'), sub: T('no AGENT_ALLOWED_* in .env') };
        const evs = liveEvents(live, s);
        if (!evs.length) {
            const acked = (s.boundary || []).filter(b => live.acked.has(ackKey(s, b))).length;
            return { sev: 'ok', key: 'ok', main: '✓ ' + T('within the boundary'), sub: acked ? T('{n} reviewed', { n: acked }) : T('all paths known and allowed') };
        }
        const worst = B_ORDER.find(k => evs.some(b => b.kind === k));
        const same = evs.filter(b => b.kind === worst).sort((x, y) => sec(y.ts) - sec(x.ts));
        const top = same[0];
        return { sev: B_SEV[worst], key: worst, main: B_LABEL[worst]() + (same.length > 1 ? ' ×' + same.length : ''),
            sub: top.text + (top.by ? ' · ' + top.by : '') + (evs.length > 1 ? ' · +' + (evs.length - 1) : '') };
    };

    const ctxCell = (live, s) => {
        const pct = a => a.contextMax > 0 ? a.context / a.contextMax * 100 : 0;
        const a = s.agents.filter(x => x.state !== 'done').sort((x, y) => pct(y) - pct(x))[0] || s.agents[0];
        const p = Math.min(100, pct(a));
        const sev = p >= 85 ? 'high' : a.context > live.data.contextHigh ? 'warn' : 'ok';
        return { sev, key: sev, pct: p, agent: a, main: Math.round(p) + '%', sub: fmtK(a.context) + (a.kind === 'main' ? '' : ' · ' + T('subagent')) };
    };

    const burnCell = s => {
        const b = s.agents.filter(a => a.state !== 'done').reduce((x, a) => x + a.burn, 0);
        const sev = b >= 4 ? 'high' : b >= 1.5 ? 'warn' : 'ok';
        return { sev, key: sev, main: T('{usd} / 5 min', { usd: usd(b) }), sub: T('total {usd}', { usd: usd(s.spent) }) };
    };

    const rowsOf = live => live.data.sessions.map(s => {
        const cells = { you: youCell(live, s), bound: boundCell(live, s), ctx: ctxCell(live, s), burn: burnCell(s) };
        return { s, cells, sev: Math.max(...Object.values(cells).map(c => SEV[c.sev])) };
    }).sort((x, y) => y.sev - x.sev);

    const matches = (live, row) => {
        const f = live.filter;
        if (!f) return true;
        if (f.group === 'you') return row.cells.you.key === f.key;
        if (f.key === 'ok') return row.cells.bound.key === 'ok';
        return liveEvents(live, row.s).some(b => b.kind === f.key);
    };

    // ---- widok ----

    const counter = (root, label, n, sev, group, key) => {
        const live = root._live;
        const b = el('button', 'live-count');
        b.type = 'button';
        b.dataset.sev = sev;
        b.dataset.zero = String(n === 0);
        b.dataset.key = 'c:' + group + ':' + key;
        b.setAttribute('aria-pressed', String(live.filter?.group === group && live.filter?.key === key));
        b.append(el('b', null, String(n)), el('span', null, label));
        b.addEventListener('click', () => {
            live.filter = live.filter?.group === group && live.filter?.key === key ? null : { group, key };
            render(root);
        });
        return b;
    };

    const renderSummary = (root, rows) => {
        const live = root._live;
        const you = k => rows.filter(r => r.cells.you.key === k).length;
        root.one('[viz-live-you]').replaceChildren(
            counter(root, T('waiting for you'), you('idle'), 'act', 'you', 'idle'),
            counter(root, T('permission?'), you('perm'), 'act', 'you', 'perm'),
            counter(root, T('stuck (limit)'), you('stuck'), 'high', 'you', 'stuck'),
            counter(root, T('report did not arrive'), you('report'), 'high', 'you', 'report'),
        );
        const bound = root.one('[viz-live-bound]');
        if (!live.data.boundary) {
            bound.replaceChildren(el('span', 'muted', T('Boundary check is off — set AGENT_ALLOWED_RW / AGENT_ALLOWED_R in .env')));
        } else {
            const all = live.data.sessions.flatMap(s => liveEvents(live, s));
            const ok = rows.filter(r => r.cells.bound.key === 'ok').length;
            bound.replaceChildren(
                ...B_ORDER.map(k => counter(root, B_PLURAL[k](), all.filter(b => b.kind === k).length, B_SEV[k], 'bound', k)),
                counter(root, T(ok === 1 ? 'session within the boundary' : 'sessions within the boundary'), ok, 'ok', 'bound', 'ok'),
            );
        }
        const need = rows.filter(r => r.sev >= SEV.act).length;
        const verdict = root.one('[viz-live-verdict]');
        verdict.dataset.sev = rows.length === 0 ? 'info' : need ? 'act' : 'ok';
        verdict.textContent = rows.length === 0 ? T('No sessions active in scope in the last 30 min.')
            : need ? T('Needs you: {n} of {m} sessions', { n: need, m: rows.length }) : T('All {m} sessions work without issues', { m: rows.length });
    };

    const cellButton = (root, row, name, c, content) => {
        const live = root._live;
        const b = el('button', 'live-cell' + (name === 'sess' ? ' live-lead' : ''));
        b.type = 'button';
        b.dataset.sev = c.sev;
        b.dataset.key = row.s.id + ':' + name;
        b.setAttribute('aria-expanded', String(live.open?.s === row.s.id && live.open?.cell === name));
        b.append(content || el('span', 'live-main', c.main));
        if (c.sub !== undefined) b.append(el('span', 'live-sub', c.sub));
        if (c.sub) b.title = c.sub;
        b.addEventListener('click', () => {
            live.open = live.open?.s === row.s.id && live.open?.cell === name ? null : { s: row.s.id, cell: name };
            render(root);
        });
        return b;
    };

    const para = parts => {
        const p = el('p');
        for (const part of parts) p.append(typeof part === 'string' ? document.createTextNode(part) : part);
        return p;
    };

    const linkButton = (label, href) => {
        if (!href) return null;
        const a = el('a', 'btn', label);
        a.href = href;
        return a;
    };

    const agentList = (live, s, line) => {
        const list = el('div', 'live-agents');
        for (const a of s.agents) {
            const r = el('div');
            const dot = el('i');
            dot.dataset.sev = STATE_SEV[a.state] || 'info';
            r.append(dot, el('span', 'ell', agentName(a) + ' · ' + a.modelName), ...line(a));
            list.append(r);
        }
        return list;
    };

    const detail = (root, row, name) => {
        const live = root._live;
        const s = row.s;
        const now = live.now();
        const d = el('div', 'live-detail');
        const actions = el('div', 'live-actions');
        if (name === 'sess' || name === 'you') {
            const c = row.cells.you;
            if (name === 'you') {
                d.append(para({
                    perm: [T('The call has been running for '), el('b', null, dur(now - sec(c.agent?.since ?? live.data.now))), T('. Usually a permission prompt in the terminal of this session; it can also be a long command — the transcript cannot tell them apart.')],
                    idle: [T('The agent finished its turn and waits for your message.')],
                    stuck: [T('The last response is an API limit or error message. The session will not resume by itself — return to it when the limit renews.')],
                    report: [T('A hook blocked the call a subagent uses to send its report. '), el('b', null, T('The main session will not get the result')), T(' — check the hook rule or pass the result by hand.')],
                    ok: [T('Nothing to do.')],
                }[c.key]));
            }
            d.append(agentList(live, s, a => [
                el('span', 'muted', STATE[a.state] ? STATE[a.state]() : a.state),
                el('code', 'ell', a.tool ? a.tool + ' ' + target(a.activity) + ' · ' + dur(now - sec(a.since)) : (a.reportBlocked ? T('report blocked ({by})', { by: a.reportBlocked.by }) : '')),
            ]));
            actions.append(...[linkButton(T('Open session') + ' ↗', sessionLink(s.id)), linkButton(T('Messages') + ' ↗', sessionLink(s.id, '&view=messages'))].filter(Boolean));
        } else if (name === 'bound') {
            if (!live.data.boundary) {
                d.append(para([T('Set the directories agents may work in (AGENT_ALLOWED_RW, AGENT_ALLOWED_R) in .env — see README.')]));
            } else {
                const evs = (s.boundary || []).filter(b => now - sec(b.ts) <= live.data.window)
                    .sort((x, y) => B_ORDER.indexOf(x.kind) - B_ORDER.indexOf(y.kind) || sec(y.ts) - sec(x.ts));
                if (!evs.length) d.append(para([T('All paths of the last 30 min are known and inside the allowed directories.')]));
                for (const b of evs) {
                    const r = el('div', 'live-ev');
                    r.dataset.sev = B_SEV[b.kind];
                    r.dataset.ack = String(live.acked.has(ackKey(s, b)));
                    const who = s.agents.find(a => a.id === b.agent);
                    const tail = el('span');
                    if (b.kind === 'outside') {
                        const btn = el('button', 'btn', live.acked.has(ackKey(s, b)) ? T('undo') : T('reviewed'));
                        btn.type = 'button';
                        btn.dataset.key = 'ack:' + ackKey(s, b);
                        btn.addEventListener('click', ev => {
                            ev.stopPropagation();
                            const k = ackKey(s, b);
                            if (live.acked.has(k)) live.acked.delete(k); else live.acked.add(k);
                            saveAck(live.acked);
                            render(root);
                        });
                        tail.append(btn);
                    }
                    r.append(el('span', 'muted num', clock(sec(b.ts))), el('span', 'live-k', B_LABEL[b.kind]()),
                        el('span', 'muted live-who', (who ? agentName(who) : b.agent) + (b.by ? ' · ' + b.by : '')),
                        el('code', null, b.tool + ' ' + b.text + (b.more ? ' · +' + b.more : '')), tail);
                    d.append(r);
                }
            }
            actions.append(...[linkButton(T('Boundary tab') + ' ↗', sessionLink(s.id, '&view=boundary'))].filter(Boolean));
        } else if (name === 'ctx') {
            const c = row.cells.ctx;
            d.append(para(c.sev === 'ok' ? [T('Context is fine.')]
                : [T('Above {n} every response reads the whole context from cache — the most expensive part of a session. Start the next task with ', { n: fmtK(live.data.contextHigh) }), el('code', null, '/compact'), T(' or '), el('code', null, '/clear'), '.']));
            d.append(agentList(live, s, a => [
                el('span', 'muted num', Math.round(Math.min(100, a.context / Math.max(1, a.contextMax) * 100)) + '%'),
                el('code', null, T('{used} of {max}', { used: fmtK(a.context), max: fmtK(a.contextMax) })),
            ]));
        } else {
            d.append(para([T('Cost at API prices in the last 5 min; total = since the session started.')]));
            d.append(agentList(live, s, a => [el('span', 'muted num', T('{usd} / 5 min', { usd: usd(a.burn) })), el('code', null, T('total {usd}', { usd: usd(a.spent) }))]));
        }
        if (actions.childElementCount) d.append(actions);
        return d;
    };

    const renderRow = (root, row) => {
        const live = root._live;
        const s = row.s;
        const tr = el('div', 'live-tr');
        const head = el('span', 'live-head');
        const proj = el('span', 'live-proj');
        const sw = el('i');
        sw.style.background = /^#[0-9a-f]{6}$/i.test(s.projectColor) ? s.projectColor : 'var(--muted)';
        proj.title = s.cwd;
        proj.append(sw, document.createTextNode(s.project + ' · ' + T(s.agents.length === 1 ? '{n} agent' : '{n} agents', { n: s.agents.length })));
        head.append(el('span', 'live-main', s.title || s.id.slice(0, 8)), proj);
        const c = row.cells;
        const tank = el('span', 'live-tank');
        tank.dataset.sev = c.ctx.sev;
        const bar = el('span', 'live-bar');
        const fill = el('b');
        fill.style.width = c.ctx.pct + '%';
        bar.append(fill);
        if (live.data.contextHigh < c.ctx.agent.contextMax) {
            const mark = el('s');
            mark.style.left = (live.data.contextHigh / c.ctx.agent.contextMax * 100) + '%';
            bar.append(mark);
        }
        tank.append(bar, el('span', 'live-main num', c.ctx.main));
        tr.append(
            cellButton(root, row, 'sess', { sev: 'ok' }, head),
            cellButton(root, row, 'you', c.you),
            cellButton(root, row, 'bound', c.bound),
            cellButton(root, row, 'ctx', c.ctx, tank),
            cellButton(root, row, 'burn', c.burn),
        );
        if (live.open?.s === s.id) tr.append(detail(root, row, live.open.cell));
        return tr;
    };

    const render = root => {
        const live = root._live;
        root.one('[viz-live-status]').textContent = live.failed ? T('refresh not working') : '';
        if (!live.data) return;
        const focused = root.contains(document.activeElement) ? document.activeElement.dataset.key : null;
        const rows = rowsOf(live);
        renderSummary(root, rows);
        const shown = rows.filter(r => matches(live, r));
        const loud = live.filter ? shown : shown.filter(r => r.sev >= SEV.warn);
        const calm = live.filter ? [] : shown.filter(r => r.sev < SEV.warn);
        const nodes = loud.map(r => renderRow(root, r));
        if (calm.length) {
            const b = el('button', 'live-calm');
            b.type = 'button';
            b.dataset.key = 'calm';
            b.setAttribute('aria-expanded', String(live.calmOpen));
            b.append((live.calmOpen ? '▾ ' : '▸ '), el('b', null, T(calm.length === 1 ? '{n} session works without issues' : '{n} sessions work without issues', { n: calm.length })),
                document.createTextNode(' — ' + calm.map(r => r.s.title || r.s.id.slice(0, 8)).join(', ')));
            b.addEventListener('click', () => { live.calmOpen = !live.calmOpen; render(root); });
            nodes.push(b);
            if (live.calmOpen) nodes.push(...calm.map(r => renderRow(root, r)));
        }
        if (!nodes.length) nodes.push(el('p', 'live-empty', rows.length ? T('No sessions match the filter.') : T('No sessions active in scope in the last 30 min.')));
        root.one('[viz-live-rows]').replaceChildren(...nodes);
        if (focused) root.querySelector('[data-key="' + CSS.escape(focused) + '"]')?.focus();
    };

    // ---- start: dane z VizFeed (odpytywanie, zabezpieczenia, zakres) ----

    const Init = root => {
        const live = { open: null, filter: null, calmOpen: false, acked: loadAck(), feed: null };
        // stan widoku czyta dane i zegar feedu
        Object.defineProperties(live, {
            data: { get: () => live.feed?.data ?? null },
            failed: { get: () => live.feed?.failed ?? false },
        });
        live.now = () => live.feed.now();
        root._live = live;
        const onKey = event => {
            if (event.code !== 'Escape') return;
            live.open = null;
            live.filter = null;
            render(root);
        };
        document.addEventListener('keydown', onKey);
        live.feed = VizFeed.Start(root, {
            url: root.attr('viz-live-url'),
            onUpdate: () => render(root),
            onStop: () => document.removeEventListener('keydown', onKey),
        });
    };

    return { Init };
})();
