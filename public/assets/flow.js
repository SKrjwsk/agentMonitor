/*
 * VizFlow — zakładka Flow w szczegółach sesji (`view=flow`) w układzie diagramu terminalowego: z lewej kolumna „kluczowe momenty”
 * (delegowanie, powrót wyniku, ten sam błąd ≥ 2×, blokada, wyjście poza granicę), w środku sesja główna → mix narzędzi →
 * karty subagentów → powrót do sesji głównej, pod spodem log wywołań i wiadomości oraz linia statusu.
 * Sygnatura: templates/flow.php. Dane: cała sesja osadzona w `[viz-flow-data]` (kształt wiersza feedu: `agents[].calls`, `messages`,
 * `now`); rysowane raz przy montowaniu — odświeżenie robi podmiana fragmentu sesji. Wszystkie teksty przez textContent.
 * Pasek na karcie = zajęte okno kontekstu (`context` / `contextMax`) — transkrypt nie zna postępu zadania. Doradcy (advisor)
 * nie ma: nie wiadomo, jak transkrypt zapisuje jego wywołanie.
 */
window.VizFlow = (() => {

    const { T, el, tail } = VizViews;
    const PERSONAS = ['haiku', 'sonnet', 'opus'];
    const STATES = ['working', 'delegated', 'idle', 'stuck', 'done'];
    const LOG_LIMIT = 14;
    const MOMENT_LIMIT = 10;
    /** Grupy narzędzi w bloku „mix narzędzi” (kolejność wierszy); reszta = `other`. */
    const GROUPS = [
        ['read', ['Read', 'NotebookRead']],
        ['search', ['Grep', 'Glob', 'LS', 'ToolSearch', 'WebSearch', 'WebFetch']],
        ['edit', ['Edit', 'Write', 'MultiEdit', 'NotebookEdit']],
        ['Bash', ['Bash']],
        ['delegate', ['Agent', 'Task', 'SendMessage', 'SubagentHandback']],
    ];

    const duration = sec => sec < 60 ? Math.max(0, Math.round(sec)) + ' s' : sec < 3600 ? Math.floor(sec / 60) + ' min' : (sec / 3600).toFixed(1) + ' h';
    /** Czas z sekundami (log ma wiele wpisów na minutę). */
    const clockSec = iso => new Date(Date.parse(iso)).toLocaleTimeString(document.documentElement.lang || 'pl', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const usd = v => '$' + (v || 0).toFixed(2);
    const persona = a => PERSONAS.includes(a.persona) ? a.persona : 'default';
    const group = tool => GROUPS.find(([, tools]) => tools.includes(tool))?.[0] ?? 'other';
    const count = (calls, g) => calls.filter(c => group(c.tool) === g).length;

    const bar = pct => {
        const b = el('div', 'flow-bar');
        const fill = el('i');
        fill.style.width = Math.max(0, Math.min(100, pct)) + '%';
        b.append(fill);

        return b;
    };

    /** Karta agenta: model (kolor persony), opis zadania, stan, bieżące wywołanie, kontekst, liczba wywołań, czas i koszt. */
    const card = (a, now, main) => {
        const box = el('div', 'flow-card' + (main ? ' flow-main' : ''));
        box.dataset.persona = persona(a);
        box.dataset.state = STATES.includes(a.state) ? a.state : 'idle';
        const head = el('div', 'flow-head');
        head.append(el('strong', null, tail(a.label || a.id, 28)), el('span', 'flow-model', (a.modelName || '?') + (main ? ' · ' + T('main session') : '')));
        const what = a.activity?.text ? (a.tool ? a.tool + ' · ' : '') + tail(a.activity.text, 44) : a.waitingFor ? T('waiting for subagents') : '';
        const pct = a.contextMax ? Math.round(100 * a.context / a.contextMax) : 0;
        // skończony subagent: czas pracy (start → ostatnia aktywność), nie czas od zakończenia; reszta: czas w obecnym stanie
        const [from, to] = a.state === 'done' ? [Date.parse(a.start), Date.parse(a.last)] : [Date.parse(a.since), now * 1000];
        const secs = (to - from) / 1000;
        const meta = [
            T('{n} calls', { n: a.calls.length }),
            Number.isNaN(secs) || secs < 1 ? null : duration(secs),
            usd(a.spent),
        ].filter(Boolean).join(' · ');
        const state = el('div', 'flow-state', T(STATES.includes(a.state) ? a.state : 'idle'));
        box.append(head, el('div', 'flow-desc', main ? T('edits {e} · Bash {b}', { e: count(a.calls, 'edit'), b: count(a.calls, 'Bash') }) : tail(a.description || '', 60)),
            bar(pct), el('div', 'flow-pct muted', T('context {n}%', { n: Math.min(100, pct) })), el('div', 'flow-what muted', what), state, el('div', 'flow-meta muted', meta));

        return box;
    };

    /** Blok w miejscu „fork layer” wzorca: udział grup narzędzi we wszystkich wywołaniach sesji w oknie. */
    const mix = s => {
        const calls = s.agents.flatMap(a => a.calls);
        const box = el('div', 'flow-mix');
        const head = el('div', 'flow-mix-head');
        head.append(el('strong', null, T('tool mix')), el('span', 'muted', T('{n} calls', { n: calls.length })));
        box.append(head);
        const rows = [...GROUPS.map(([g]) => g), 'other'].map(g => [g, count(calls, g)]).filter(([, n]) => n > 0);
        const max = Math.max(1, ...rows.map(([, n]) => n));
        for (const [g, n] of rows) {
            const row = el('div', 'flow-mix-row');
            row.dataset.group = g;
            row.append(el('span', null, T(g)), bar(100 * n / max), el('span', 'flow-num', String(n)), el('span', 'muted', Math.round(100 * n / Math.max(1, calls.length)) + '%'));
            box.append(row);
        }
        const failed = calls.filter(c => c.status !== 'ok' && c.status !== 'unknown').length;
        box.append(el('div', 'flow-mix-foot muted', T('{n} failed or blocked', { n: failed })));

        return box;
    };

    /** Łącznik pionowy; `live` = ktoś po drugiej stronie pracuje (animowana kropka). */
    const stem = live => {
        const e = el('div', 'flow-stem');
        if (live) e.dataset.live = 'true';

        return e;
    };

    const diagram = (root, s, now) => {
        const [main, ...subs] = s.agents;
        const running = subs.filter(a => a.state !== 'done').length;
        const out = [card(main, now, true), stem(main.state === 'working'), mix(s)];
        if (subs.length) {
            out.push(stem(running > 0), el('div', 'flow-link', T('delegates to {n} subagents', { n: subs.length })), el('div', 'flow-bracket'));
            const row = el('div', 'flow-subs');
            row.append(...subs.map(a => card(a, now, false)));
            out.push(row, el('div', 'flow-bracket flow-up'), stem(running === 0));
            const back = el('div', 'flow-card flow-return');
            back.dataset.persona = persona(main);
            back.append(el('strong', 'flow-model', T('back to {a}', { a: (main.modelName || '?') + ' · ' + T('main session') })),
                el('div', null, running ? T('waiting for {n} results', { n: running }) : T('all results back — main session takes over')));
            out.push(back);
        } else {
            out.push(el('div', 'flow-link', T('no subagents in this session')));
        }
        root.one('[viz-flow-diagram]').replaceChildren(...out);
    };

    /** Kluczowe momenty sesji z wywołań i wiadomości: delegowanie, wynik, ten sam błąd ≥ 2×, blokada, poza granicą. */
    const moments = (root, s) => {
        const names = new Map(s.agents.map(a => [a.id, a.label || a.id]));
        const items = [];
        for (const m of s.messages) {
            if (m.kind === 'task') items.push({ ts: m.ts, kind: 'task', text: T('delegates → {a}', { a: names.get(m.to) || m.to }) });
            if (m.kind === 'result') items.push({ ts: m.ts, kind: 'result', text: T('result ← {a}', { a: names.get(m.from) || '—' }) });
        }
        for (const a of s.agents) {
            const seen = new Map();
            for (const c of a.calls) {
                if (c.status === 'blocked') items.push({ ts: c.ts, kind: 'blocked', text: T('blocked') + ': ' + c.tool + ' ' + tail(c.text, 30) });
                if (c.status === 'outside') items.push({ ts: c.ts, kind: 'blocked', text: T('outside the boundary — executed') + ': ' + tail(c.text, 30) });
                if (c.status !== 'failed') continue;
                const key = c.tool + '|' + c.text;
                const n = (seen.get(key) ?? 0) + 1;
                seen.set(key, n);
                if (n === 2) items.push({ ts: c.ts, kind: 'error', text: T('same error ×2') + ': ' + c.tool + ' ' + tail(c.text, 30) + ' (' + (a.label || a.id) + ')' });
            }
        }
        items.sort((x, y) => Date.parse(x.ts) - Date.parse(y.ts));
        const box = root.one('[viz-flow-moments]');
        const list = items.slice(-MOMENT_LIMIT).map(i => {
            const row = el('div', 'flow-moment');
            row.dataset.kind = i.kind;
            row.append(el('span', 'muted', clockSec(i.ts)), el('span', null, i.text));

            return row;
        });
        const calls = s.agents.flatMap(a => a.calls);
        const stats = el('div', 'flow-stats muted');
        stats.append(...[
            T('{n} calls', { n: calls.length }),
            T('{n} failed or blocked', { n: calls.filter(c => c.status !== 'ok' && c.status !== 'unknown').length }),
            T('{n} messages', { n: s.messages.length }),
            T('context {n}%', { n: s.agents[0].contextMax ? Math.min(100, Math.round(100 * s.agents[0].context / s.agents[0].contextMax)) : 0 }),
        ].map(t => el('div', null, t)));
        box.replaceChildren(el('strong', 'flow-moments-title', T('key moments')), ...(list.length ? list : [el('p', 'muted', T('no key moments in this session'))]), stats);
    };

    /** Log: wywołania wszystkich agentów i wiadomości między nimi, najnowsze u góry. */
    const log = (root, s) => {
        const names = new Map(s.agents.map(a => [a.id, a.label || a.id]));
        const rows = [];
        for (const a of s.agents) {
            for (const c of a.calls) if (c.tool !== 'SubagentHandback') rows.push({ ts: c.ts, who: a.label || a.id, persona: persona(a), kind: c.tool, text: c.text, status: c.status });
        }
        const personas = new Map(s.agents.map(a => [a.id, persona(a)]));
        for (const m of s.messages) rows.push({ ts: m.ts, who: names.get(m.from) || '—', persona: personas.get(m.from) || 'default', kind: m.kind + ' → ' + (names.get(m.to) || m.to), text: m.text, status: 'msg' });
        rows.sort((x, y) => Date.parse(y.ts) - Date.parse(x.ts));
        const box = root.one('[viz-flow-log]');
        const title = el('span', 'flow-log-title', T('session log'));
        if (!rows.length) {
            box.replaceChildren(title, el('p', 'live-empty', T('No calls in this session.')));
            return;
        }
        box.replaceChildren(title, ...rows.slice(0, LOG_LIMIT).map(r => {
            const line = el('div', 'flow-line');
            line.dataset.status = r.status;
            const who = el('strong', 'flow-who', tail(r.who, 22));
            who.dataset.persona = r.persona;
            line.append(el('span', 'muted', clockSec(r.ts)), who, el('span', 'flow-kind', tail(r.kind, 30)), el('span', 'muted', tail(r.text || '', 90)));

            return line;
        }));
    };

    const legend = (root, s) => {
        root.one('[viz-flow-legend]').replaceChildren(...PERSONAS.map(p => {
            const n = s.agents.filter(a => a.persona === p).length;
            const it = el('span', 'flow-key', p + (n ? ' · ' + n : ''));
            it.dataset.persona = p;

            return it;
        }));
    };

    const Init = root => {
        let s = null;
        try { s = JSON.parse(root.one('[viz-flow-data]').textContent); } catch { s = null; }
        if (!s || !s.agents?.length) {
            root.one('[viz-flow-diagram]').replaceChildren(el('p', 'live-empty', T('No data for this session.')));
            return;
        }
        const subs = s.agents.filter(a => a.kind === 'sub');
        const running = subs.filter(a => a.state !== 'done').length;
        legend(root, s);
        root.one('[viz-flow-statusline]').textContent = T('subagents [{n}/{m}] · cost {c}', { n: running, m: subs.length, c: usd(s.spent) });
        moments(root, s);
        diagram(root, s, s.now);
        log(root, s);
    };

    return { Init };
})();
