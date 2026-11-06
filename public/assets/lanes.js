/*
 * VizLanes — oś pracy (trasa /lanes): tory agentów wszystkich sesji w zakresie na wspólnej osi okna feedu (`window`, 30 min),
 * „teraz” przy prawej krawędzi. Wiersz sesji (kolor projektu, projekt, tytuł), pod nim tor na agenta: sesja główna, potem subagenci
 * według `start`. Pasek = wywołanie z `agents[].calls` (`ts` → `end`, trwające do teraz), kolor = rodzaj narzędzia (własna paleta
 * widoku `--k-*`: żółty zarezerwowany dla „Ty”), obrys = `status` blocked / failed / outside / trying / stopped; klik w pasek = to
 * wywołanie w logu sesji (`view=log&pev=`). Tor subagenta = tło od `start` do `last` (skończony) albo do teraz.
 * Wywołanie dłuższe niż `permissionGuess` (90 s) = „zgoda?”: po progu zakreskowanie jak oczekiwanie na Ciebie z cienką kreską wywołania
 * (z transkryptu nie da się odróżnić prośby o zgodę od długiej komendy — jak w Live); trwające = też stan na końcu toru i licznik w nagłówku.
 * Tor sesji głównej: bieżące oczekiwanie na Ciebie (`idle` od `since`) żółte, minione (`waits`) szare; kreski promptów (`prompts`).
 * Komunikacja: zadanie dla subagenta i jego wynik (`messages` `task` / `result`) = różowa strzałka między torami sesji (w kompakcie
 * subagenci nie mają torów — zostają kreski), wiadomość do sesji o tytule równym adresatowi (`sends`) = biała strzałka między wierszami
 * (warstwa SVG nad listą), adresat bez dopasowania (np. adres gniazda) = sama kreska.
 * Na końcu toru: stan, czas w stanie, liczniki z okna (`counts`: pliki odczytane, pliki zmienione, komendy Bash), koszt od początku
 * (`spent`; podpowiedź: w oknie, `usdWindow`), bieżące wywołanie. Sesje pogrupowane po projekcie (nagłówek: sesje, pracuje,
 * czeka na Ciebie). Kolejność stała (projekt, start sesji głównej), żeby wiersze nie skakały przy odświeżaniu co 1 s.
 * Tryb kompaktowy (`VizViews.density`, domyślny): jedna linia na sesję — tor sesji głównej z tytułem sesji, subagenci jako linie
 * pod nim z ich wywołaniami, liczniki i koszt całej sesji, projekt jako pionowy pasek z nazwą. Sygnatura: templates/lanes.php.
 * Dane: VizFeed; wszystkie teksty przez textContent.
 */
window.VizLanes = (() => {

    const { T, el, tail, color, sessionLink, byProject, density, role } = VizViews;
    const PERSONAS = ['haiku', 'sonnet', 'opus'];
    const STATES = ['working', 'delegated', 'idle', 'stuck', 'done'];
    const STATUSES = ['blocked', 'failed', 'outside', 'trying', 'stopped'];
    /** Rodzaj wywołania po nazwie narzędzia (feed nie niesie operacji dotknięć); klucze jak `viz-kind` w Timeline. */
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
    /** Agent w trwającym wywołaniu dłuższym niż próg — „zgoda?” zamiast „pracuje”. */
    const asking = (a, now, guess) => a.state === 'working' && !!a.tool && now - sec(a.since) >= guess;
    /** Podpis subagenta: opis zadania, typ (bez ogólnego) albo „subagent”. */
    const subName = a => a.description || role(a) || T('subagent');

    /** Pozycja w procentach osi; poza oknem — przycięta do krawędzi. */
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

    /** Paski jednego wywołania; po progu „zgoda?” zakreskowanie (trwające żółte, minione szare) z cienką kreską wywołania. */
    const call = (track, x, s, c, now, guess, cls, prefix = '') => {
        const from = sec(c.ts);
        const to = c.end ? sec(c.end) : now;
        const cut = cls === 'lanes-call' && to - from > guess ? from + guess : to;
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
            b.title = prefix + c.tool + ' · ' + (c.text || '') + (cut < to ? ' · ' + T('permission?') + ' ' + duration(to - from) : '') + cost
                + (c.id ? '\n' + T('click = this call in the session log') : '');
            track.append(b);
        }
    };

    /** Strzałka pionowa w SVG z toru `src` do toru `dst` w chwili `ts`. */
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

    /** Strzałki: zadania i wyniki subagentów w sesji (różowe) oraz wiadomości między sesjami (białe, tytuł sesji = adresat). */
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

    /** Liczniki z okna: pliki odczytane, pliki zmienione, komendy Bash — kwadrat w kolorze rodzaju paska. */
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

    /** Koszt od początku sesji (jak w abonamencie: równowartość API), w podpowiedzi — z okna. */
    const money = (spent, win) => {
        const e = el('span', 'lanes-usd', usd(spent || 0));
        e.title = T('cost since the session start (API prices) · last 30 min {c}', { c: usd(win || 0) });

        return e;
    };

    /** `t` = {now, win, guess (próg „zgoda?”)}; `counts` / `spent` / `usdWin` = liczniki i koszt na końcu toru (domyślnie agenta). */
    const lane = (a, s, t, href, title, counts = a.counts, spent = a.spent, usdWin = a.usdWindow) => {
        const { now, win, guess } = t;
        const x = axis(now, win);
        const row = el(href ? 'a' : 'div', 'lanes-lane');
        if (href) row.href = href;
        row.dataset.persona = PERSONAS.includes(a.persona) ? a.persona : 'default';
        row.dataset.state = STATES.includes(a.state) ? a.state : 'idle';
        const label = el('span', 'lanes-label');
        // subagenci jednego typu różnią się zadaniem: opis zadania w etykiecie, typ agenta w podpowiedzi
        const name = title ?? (a.kind === 'sub' ? subName(a) : a.label || a.id);
        // opis ucinany od prawej (początek zadania jest ważniejszy); `tail` jest do ścieżek
        const short = name.length > 30 ? name.slice(0, 29) + '…' : name;
        label.append(el('span', 'lanes-model', a.modelName || '?'), el('span', null, short));
        label.title = a.kind === 'sub' ? [role(a), a.description].filter(Boolean).join(' · ') : name;
        const track = el('div', 'lanes-track');
        track.dataset.agent = s.id + '|' + a.id;
        if (a.kind === 'sub') track.append(span('lanes-life', x, sec(a.start), a.state === 'done' ? sec(a.last) : now));
        if (a.kind !== 'sub') {
            for (const w of s.waits || []) track.append(span('lanes-waited', x, sec(w.from), sec(w.to)));
            if (a.state === 'idle') track.append(span('lanes-wait', x, sec(a.since), now));
            for (const p of s.prompts || []) track.append(mark(x, p.ts, p.kind, T(p.kind === 'peer' ? 'message from another session' : 'prompt')));
            for (const m of s.sends || []) track.append(mark(x, m.ts, 'send', '→ ' + m.to));
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
            : state === 'delegated' && a.waitingFor ? T('waiting for {n} subagents', { n: a.waitingFor }) : '';
        const ask = asking(a, now, guess);
        if (ask) row.dataset.ask = '';
        const end = el('span', 'lanes-end');
        end.append(el('strong', 'lanes-state', T(ask ? 'permission?' : state === 'idle' && a.kind !== 'sub' ? 'waiting for you' : state)),
            el('span', 'muted lanes-time', Number.isNaN(to - from) ? '' : duration(to - from)), tally(counts), money(spent, usdWin),
            el('span', 'muted lanes-what', what));
        row.append(label, track, end);

        return row;
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
        const n = st => agents.filter(a => a.state === st).length;
        const working = list => list.filter(a => a.state === 'working' && !asking(a, now, guess)).length;
        root.one('[viz-lanes-verdict]').textContent = T('working {w} · permission? {p} · waiting for you {i} · delegating {d} · stuck {s}', {
            w: working(agents), p: agents.filter(a => asking(a, now, guess)).length, i: sessions.filter(s => s.agents[0].state === 'idle').length, d: n('delegated'), s: n('stuck'),
        }) + ' · ' + T('{c} in 30 min', { c: usd(sum(agents.map(a => a.usdWindow))) });
        const list = root.one('[viz-lanes-list]');
        if (!sessions.length) {
            list.replaceChildren(el('p', 'live-empty', T('No sessions active in scope in the last 30 min.')));
            return;
        }
        const sessionWin = s => sum(s.agents.map(a => a.usdWindow));
        /** Kompakt: tor sesji głównej (tytuł sesji w etykiecie) + linia 3 px na subagenta pod nim w tym samym torze, na niej jego wywołania. */
        const compact = s => {
            const href = sessionLink(s.id, '&view=flow');
            const [main, ...subs] = s.agents;
            const row = lane(main, s, t, href, s.title || s.id.slice(0, 8), s.counts, s.spent, sessionWin(s));
            const x = axis(now, win);
            const track = row.querySelector('.lanes-track');
            track.style.height = (12 + subs.length * 4) + 'px';
            subs.forEach((a, i) => {
                const top = (13 + i * 4) + 'px';
                const life = span('lanes-subline', x, sec(a.start), a.state === 'done' ? sec(a.last) : now);
                life.style.top = top;
                life.dataset.persona = PERSONAS.includes(a.persona) ? a.persona : 'default';
                life.dataset.state = STATES.includes(a.state) ? a.state : 'idle';
                life.title = subName(a);
                track.append(life);
                const before = track.children.length;
                for (const c of a.calls) {
                    if ((c.end ? sec(c.end) : now) >= now - win) call(track, x, s, c, now, guess, 'lanes-subcall', subName(a) + ' · ');
                }
                for (const b of [...track.children].slice(before)) b.style.top = top;
            });
            if (subs.length) {
                const what = row.querySelector('.lanes-what');
                what.textContent = T('↳ {n} sub · {w} working', { n: subs.length, w: subs.filter(a => a.state === 'working').length });
            }

            return row;
        };
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
            const all = g.sessions.flatMap(s => s.agents);
            head.append(dot, el('strong', null, g.project), el('span', 'muted', T('sessions {n} · working {w} · waiting for you {i}', {
                n: g.sessions.length, w: working(all), i: g.sessions.filter(s => s.agents[0].state === 'idle').length,
            })));
            if (root.dataset.density === 'compact') {
                const strip = el('div', 'lanes-strip');
                strip.append(el('span', null, g.project));
                strip.title = g.project + ' · ' + head.lastChild.textContent;
                const rows = el('div', 'lanes-rows');
                rows.append(...g.sessions.map(compact));
                box.append(strip, rows);
            } else {
                box.append(head, ...g.sessions.map(session));
            }

            return box;
        }));
        arrows(list, sessions, now, win);
    };

    const Init = root => {
        density(root, () => { if (root._feed?.data) render(root, root._feed); });
        // klik w pasek wywołania = to wywołanie w logu sesji (panel szczegółów); reszta wiersza = Flow sesji
        root.one('[viz-lanes-list]').addEventListener('click', e => {
            const b = e.target.closest('[data-ev]');
            const href = b && sessionLink(b.dataset.s, '&view=log&pev=' + encodeURIComponent(b.dataset.ev));
            if (!href) return;
            e.preventDefault();
            location.href = href;
        });
        root._feed = VizFeed.Start(root, { url: root.attr('viz-lanes-url'), onUpdate: feed => render(root, feed) });
    };

    return { Init };
})();
