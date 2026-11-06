/*
 * VizFlow — przepływ pracy sesji (trasa /flow): sesja główna → karty subagentów → powrót wyników, pod spodem log wywołań i wiadomości.
 * Jedna sesja naraz, wybierana z listy aktywnych w zakresie (domyślnie najnowsza; wybór zostaje po odświeżeniu). Sygnatura:
 * templates/flow.php. Dane: VizFeed co 1 s (`sessions[].agents`, `.messages`); wszystkie teksty przez textContent.
 * Pasek na karcie = zajęte okno kontekstu (`context` / `contextMax`) — transkrypt nie zna postępu zadania.
 */
window.VizFlow = (() => {

    const { T, el, tail, clock, sessionLink } = VizViews;
    const PERSONAS = ['haiku', 'sonnet', 'opus'];
    const STATES = ['working', 'delegated', 'idle', 'stuck', 'done'];
    const LOG_LIMIT = 14;

    const duration = sec => sec < 60 ? Math.max(0, Math.round(sec)) + ' s' : sec < 3600 ? Math.floor(sec / 60) + ' min' : (sec / 3600).toFixed(1) + ' h';
    const usd = v => '$' + (v || 0).toFixed(2);

    /** Karta agenta: model (kolor persony), stan, bieżące wywołanie, kontekst, liczba wywołań, czas i koszt. */
    const card = (a, now, main) => {
        const box = el('div', 'flow-card' + (main ? ' flow-main' : ''));
        box.dataset.persona = PERSONAS.includes(a.persona) ? a.persona : 'default';
        box.dataset.state = STATES.includes(a.state) ? a.state : 'idle';
        const head = el('div', 'flow-head');
        head.append(el('strong', null, tail(a.label || a.id, 28)), el('span', 'flow-model', a.modelName || '?'));
        const stateText = a.state === 'working' && a.tool ? T('working') : T(STATES.includes(a.state) ? a.state : 'idle');
        const what = a.activity?.text ? (a.tool ? a.tool + ' · ' : '') + tail(a.activity.text, 44) : a.waitingFor ? T('waiting for subagents') : '';
        const pct = a.contextMax ? Math.min(100, Math.round(100 * a.context / a.contextMax)) : 0;
        const bar = el('div', 'flow-bar');
        const fill = el('i');
        fill.style.width = pct + '%';
        bar.append(fill);
        const since = Date.parse(a.since);
        const meta = el('div', 'flow-meta muted', [
            T('{n} calls', { n: a.calls.length }),
            Number.isNaN(since) ? null : duration(now - since / 1000),
            usd(a.spent),
        ].filter(Boolean).join(' · '));
        box.append(head, el('div', 'flow-state', stateText), el('div', 'flow-what muted', what), bar, el('div', 'flow-pct muted', T('context {n}%', { n: pct })), meta);

        return box;
    };

    const link = (label, sid) => {
        const href = sessionLink(sid);

        return href ? Object.assign(el('a', 'flow-open', label), { href }) : el('span', 'flow-open', label);
    };

    const tabs = (root, sessions, current) => {
        const nav = root.one('[viz-flow-tabs]');
        nav.replaceChildren(...sessions.map(s => {
            const b = el('button', 'flow-tab', tail(s.title || s.id.slice(0, 8), 30) + ' · ' + s.project);
            b.type = 'button';
            b.dataset.sid = s.id;
            if (s.id === current.id) b.setAttribute('aria-current', 'true');
            b.addEventListener('click', () => { root._flow.sid = s.id; render(root, root._feed); });

            return b;
        }));
    };

    const diagram = (root, s, now) => {
        const [main, ...subs] = s.agents;
        const box = root.one('[viz-flow-diagram]');
        const out = [card(main, now, true)];
        if (subs.length) {
            const running = subs.filter(a => a.state !== 'done').length;
            out.push(el('div', 'flow-link', T('delegates to {n} subagents', { n: subs.length })));
            const row = el('div', 'flow-subs');
            row.append(...subs.map(a => card(a, now, false)));
            out.push(row);
            out.push(el('div', 'flow-link', running ? T('{n} still running', { n: running }) : T('all done — results back to the main session')));
        } else {
            out.push(el('div', 'flow-link', T('no subagents in the last 30 min')));
        }
        const foot = el('div', 'flow-foot');
        foot.append(link(T('open session'), s.id));
        out.push(foot);
        box.replaceChildren(...out);
    };

    /** Log: wywołania wszystkich agentów i wiadomości między nimi, najnowsze u góry. */
    const log = (root, s) => {
        const names = new Map(s.agents.map(a => [a.id, a.label || a.id]));
        const rows = [];
        for (const a of s.agents) {
            for (const c of a.calls) rows.push({ ts: c.ts, who: a.label || a.id, kind: c.tool, text: c.text, status: c.status });
        }
        for (const m of s.messages) rows.push({ ts: m.ts, who: names.get(m.from) || '—', kind: m.kind + ' → ' + (names.get(m.to) || m.to), text: m.text, status: 'msg' });
        rows.sort((x, y) => Date.parse(y.ts) - Date.parse(x.ts));
        const box = root.one('[viz-flow-log]');
        if (!rows.length) {
            box.replaceChildren(el('p', 'live-empty', T('No calls in the last 30 min.')));
            return;
        }
        box.replaceChildren(...rows.slice(0, LOG_LIMIT).map(r => {
            const line = el('div', 'flow-line');
            line.dataset.status = r.status;
            line.append(el('span', 'muted', clock(r.ts)), el('strong', null, tail(r.who, 22)), el('span', 'flow-kind', tail(r.kind, 30)), el('span', 'muted', tail(r.text || '', 90)));

            return line;
        }));
    };

    const render = (root, feed) => {
        root.one('[viz-flow-status]').textContent = feed.failed ? T('refresh not working') : '';
        const data = feed.data;
        if (!data) return;
        const verdict = root.one('[viz-flow-verdict]');
        const sessions = [...data.sessions].filter(s => s.agents.length).sort((a, b) => Date.parse(b.last) - Date.parse(a.last));
        if (!sessions.length) {
            verdict.textContent = '';
            root.one('[viz-flow-tabs]').replaceChildren();
            root.one('[viz-flow-diagram]').replaceChildren(el('p', 'live-empty', T('No sessions active in scope in the last 30 min.')));
            root.one('[viz-flow-log]').replaceChildren();
            return;
        }
        const current = sessions.find(s => s.id === root._flow.sid) ?? sessions[0];
        verdict.textContent = T('{n} sessions in scope · {m} agents in the selected one', { n: sessions.length, m: current.agents.length });
        tabs(root, sessions, current);
        diagram(root, current, feed.now());
        log(root, current);
    };

    const Init = root => {
        root._flow = { sid: null };
        root._feed = VizFeed.Start(root, { url: root.attr('viz-flow-url'), onUpdate: feed => render(root, feed) });
    };

    return { Init };
})();
