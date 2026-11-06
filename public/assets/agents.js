/*
 * VizAgents — przegląd agentów (trasa /agents): wiersz = sesja w zakresie (kolor projektu, projekt, tytuł), w wierszu kafelki agentów
 * (model w kolorze persony, nazwa agenta, stan jednym słowem z czasem w stanie, bieżące wywołanie, tokeny z całej sesji, koszt). Klik w wiersz albo kafelek = zakładka Flow
 * sesji. Sesje pogrupowane po projekcie (projekty alfabetycznie; nagłówek: sesje, aktywne, tokeny, koszt), w projekcie aktywne
 * (ktoś pracuje albo deleguje) na górze, potem najnowsze. Sygnatura: templates/agents.php. Dane: VizFeed co 1 s
 * (`sessions[].agents[]`: `modelName`, `persona`, `state`, `tokens`, `spent`); wszystkie teksty przez textContent. Tryb kompaktowy
 * (`VizViews.density`, domyślny): projekty w kolumnach, sesja = karta dwuwierszowa (tytuł, stan sesji głównej i czas; kropki agentów
 * — kolor = model, znak = stan — bieżące wywołanie, tokeny i koszt).
 */
window.VizAgents = (() => {

    const { T, el, tail, color, sessionLink, byProject, density } = VizViews;
    const PERSONAS = ['haiku', 'sonnet', 'opus'];
    const STATES = ['working', 'delegated', 'idle', 'stuck', 'done'];
    const ACTIVE = ['working', 'delegated'];

    const num = n => n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1) + 'k' : String(n || 0);
    const usd = v => '$' + (v || 0).toFixed(2);
    const duration = sec => sec < 60 ? Math.round(sec) + ' s' : sec < 3600 ? Math.floor(sec / 60) + ' min' : (sec / 3600).toFixed(1) + ' h';
    const sum = t => (t?.input || 0) + (t?.output || 0) + (t?.cacheWrite || 0) + (t?.cacheRead || 0);
    const active = s => s.agents.some(a => ACTIVE.includes(a.state));
    /** Link do zakładki Flow sesji (null przy niepoprawnym id). */
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
        t.title = [[a.label, a.description].filter(Boolean).join(' · '), T('input {i} · output {o} · cache write {w} · cache read {r}', { i: num(tk.input), o: num(tk.output), w: num(tk.cacheWrite), r: num(tk.cacheRead) })]
            .filter(Boolean).join('\n');
        // czas w stanie (`since`); skończony subagent — czas pracy (start → ostatnia aktywność)
        const [from, to] = a.state === 'done' ? [Date.parse(a.start), Date.parse(a.last)] : [Date.parse(a.since), now * 1000];
        const secs = (to - from) / 1000;
        const what = a.activity?.text ? (a.tool ? a.tool + ' · ' : '') + tail(a.activity.text, 30)
            : a.state === 'delegated' && a.waitingFor ? T('waiting for {n} subagents', { n: a.waitingFor }) : '';
        // subagenci jednego typu różnią się zadaniem: opis zadania zamiast typu agenta (typ w podpowiedzi)
        const name = a.kind === 'sub' && a.description ? a.description : a.label || a.id;
        // opis ucinany od prawej (początek zadania jest ważniejszy); `tail` jest do ścieżek
        const short = name.length > 26 ? name.slice(0, 25) + '…' : name;
        t.append(el('span', 'agents-model', a.modelName || '?'), el('strong', null, short),
            el('span', 'agents-state', T(t.dataset.state) + (Number.isNaN(secs) || secs < 1 ? '' : ' · ' + duration(secs))),
            el('span', 'agents-what muted', what), el('span', 'agents-num', T('tokens {t}', { t: num(sum(a.tokens)) }) + ' · ' + usd(a.spent)));

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

    const PIP = { working: '●', delegated: '●', idle: '○', stuck: '!', done: '✓' };

    /** Kompakt: karta sesji w dwóch wierszach. */
    const card = (s, now) => {
        const [main, ...subs] = s.agents;
        const c = anchor('agents-card', flow(s.id));
        const state = STATES.includes(main.state) ? main.state : 'idle';
        c.dataset.state = state;
        c.dataset.active = String(active(s));
        c.title = s.title || s.id;
        const secs = now - Date.parse(main.since) / 1000;
        const what = main.activity?.text ? (main.tool ? main.tool + ' · ' : '') + tail(main.activity.text, 30)
            : state === 'delegated' && main.waitingFor ? T('waiting for {n} subagents', { n: main.waitingFor })
            : subs.length ? T('↳ {n} sub · {w} working', { n: subs.length, w: subs.filter(a => a.state === 'working').length }) : '';
        const pips = el('span', 'agents-pips');
        pips.append(...s.agents.map(a => {
            const p = el('span', null, PIP[a.state] ?? '○');
            p.dataset.persona = PERSONAS.includes(a.persona) ? a.persona : 'default';
            p.title = (a.modelName || '?') + ' · ' + (a.kind === 'sub' && a.description ? a.description : a.label) + ' · ' + T(STATES.includes(a.state) ? a.state : 'idle');

            return p;
        }));
        const line2 = el('span', 'agents-card-what');
        line2.append(pips, el('span', 'muted', what));
        c.append(el('strong', 'agents-card-title', tail(s.title || s.id.slice(0, 8), 44)),
            el('span', 'agents-card-state', T(state === 'idle' ? 'waiting for you' : state) + (Number.isNaN(secs) || secs < 1 ? '' : ' · ' + duration(secs))),
            line2, el('span', 'agents-num muted', num(s.agents.reduce((x, a) => x + sum(a.tokens), 0)) + ' · ' + usd(s.spent)));

        return c;
    };

    const render = (root, feed) => {
        root.one('[viz-agents-status]').textContent = feed.failed ? T('refresh not working') : '';
        const data = feed.data;
        if (!data) return;
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
            box.append(head, ...g.sessions.map(s => root.dataset.density === 'compact' ? card(s, feed.now()) : row(s, feed.now())));

            return box;
        }));
    };

    const Init = root => {
        density(root, () => { if (root._feed?.data) render(root, root._feed); });
        root._feed = VizFeed.Start(root, { url: root.attr('viz-agents-url'), onUpdate: feed => render(root, feed) });
    };

    return { Init };
})();
