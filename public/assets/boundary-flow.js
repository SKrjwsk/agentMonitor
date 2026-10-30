/*
 * VizBFlow — przepływ granicy (trasa /boundary-flow): wykres Sankeya w SVG. Lewa kolumna = sesje, prawa = cele: strefy z konfiguracji
 * granicy, „wykonane poza granicą”, „zatrzymane”, „nieustalone ścieżki”. Wstęga = operacje na plikach sesji w danym celu
 * (z `sessions[].map`; nieustalone = wywołania Bash z `boundary`). Sygnatura: templates/boundary-flow.php. Dane: VizFeed co 1 s,
 * wykres rysowany od nowa (kilkadziesiąt elementów); podświetlenie i podpowiedź zostają po odświeżeniu.
 */
window.VizBFlow = (() => {

    const { T, el, css, color, tail, total, unknownCalls, boundaryVerdict } = VizViews;
    const NS = 'http://www.w3.org/2000/svg';
    const LEFT = 230;
    const RIGHT = 260;
    const BAR = 12;
    const GAP = 10;

    const sv = (tag, attrs, cls) => {
        const e = document.createElementNS(NS, tag);
        for (const k in attrs) e.setAttribute(k, attrs[k]);
        if (cls) e.setAttribute('class', cls);
        return e;
    };
    const fmt = n => n >= 1000 ? (n / 1000).toFixed(1) + 'k' : String(Math.round(n));

    /** Sesje (z lewej), cele (z prawej) i przepływy między nimi. */
    const model = (data, now) => {
        const targets = [
            ...data.zones.rw.map(z => ({ id: 'rw|' + z, label: tail(z, 34), full: z, kind: T('read and write'), sev: 'rw' })),
            ...data.zones.r.map(z => ({ id: 'r|' + z, label: tail(z, 34), full: z, kind: T('read only'), sev: 'r' })),
            { id: 'out', label: T('outside the boundary — executed'), full: '', kind: '', sev: 'out' },
            { id: 'stopped', label: T('outside the boundary — stopped'), full: '', kind: '', sev: 'stopped' },
            { id: 'unknown', label: T('paths not resolved'), full: '', kind: '', sev: 'unknown' },
        ];
        const flows = new Map();
        const add = (sid, tid, v) => {
            if (v <= 0) return;
            const key = sid + '>' + tid;
            flows.set(key, (flows.get(key) ?? 0) + v);
        };
        for (const s of data.sessions) {
            for (const m of s.map || []) {
                const ops = total(m);
                if (m.zone === 'out') {
                    add(s.id, 'out', ops - m.stopped);
                    add(s.id, 'stopped', m.stopped);
                } else {
                    add(s.id, m.zone + '|' + m.root, ops);
                }
            }
        }
        for (const u of unknownCalls(data, now)) add(u.session.id, 'unknown', 1);
        const sum = (pred) => [...flows].filter(([k]) => pred(k)).reduce((x, [, v]) => x + v, 0);
        const sessions = data.sessions.map(s => ({ id: s.id, label: tail(s.title || s.id.slice(0, 8), 30), full: s.title || s.id, project: s.project, color: s.projectColor,
            value: sum(k => k.startsWith(s.id + '>')) })).filter(s => s.value > 0);
        // strefy z konfiguracji zawsze (także bez ruchu), cele „uwagi” tylko gdy coś do nich płynie
        const right = targets.map(t => ({ ...t, value: sum(k => k.endsWith('>' + t.id)) }))
            .filter(t => t.value > 0 || t.sev === 'rw' || t.sev === 'r');
        return { sessions, right, flows };
    };

    /** Pozycje węzłów w kolumnie: wysokość ∝ wartość (min. 3 px), odstęp GAP. */
    const stack = (items, scale) => {
        let y = 0;
        for (const it of items) {
            it.h = Math.max(3, it.value * scale);
            it.y = y;
            it.used = 0;
            y += it.h + GAP;
        }
        return y - GAP;
    };

    const draw = (root, data, now) => {
        const box = root.one('[viz-bflow-chart]');
        const { sessions, right, flows } = model(data, now);
        if (!sessions.length) {
            box.replaceChildren(el('p', 'live-empty', data.sessions.length ? T('No file operations in the last 30 min.') : T('No sessions active in scope in the last 30 min.')));
            return;
        }
        // szerokość treści (bez marginesu wewnętrznego), żeby wykres nie wymuszał przewijania w poziomie
        const width = Math.max(560, box.clientWidth - 28);
        const totalL = sessions.reduce((x, s) => x + s.value, 0);
        const totalR = right.reduce((x, t) => x + t.value, 0);
        const rows = Math.max(sessions.length, right.length);
        const height = Math.max(260, Math.min(900, rows * 46));
        const scale = (height - GAP * (rows - 1)) / Math.max(totalL, totalR, 1);
        const hL = stack(sessions, scale);
        const hR = stack(right, scale);
        const H = Math.max(hL, hR) + 20;
        const offL = (H - hL) / 2;
        const offR = (H - hR) / 2;
        const x0 = LEFT, x1 = width - RIGHT;
        const c = { rw: css('--ok'), r: css('--search'), out: css('--high'), stopped: css('--info'), unknown: css('--warn'), text: css('--text'), muted: css('--muted') };
        const svg = sv('svg', { width, height: H, viewBox: `0 0 ${width} ${H}`, role: 'img' });
        const bands = sv('g', {}, 'bflow-bands');
        const nodes = sv('g', {});
        // wstęgi: kolejność celów z prawej, potem sesji — bez krzyżowania w obrębie węzła
        for (const s of sessions) {
            for (const t of right) {
                const v = flows.get(s.id + '>' + t.id);
                if (!v) continue;
                const h = Math.max(1, v * scale);
                const ya = offL + s.y + s.used + h / 2;
                const yb = offR + t.y + t.used + h / 2;
                s.used += h;
                t.used += h;
                const mx = (x0 + BAR + x1) / 2;
                const p = sv('path', { d: `M${x0 + BAR},${ya} C${mx},${ya} ${mx},${yb} ${x1},${yb}`, 'stroke-width': h, stroke: c[t.sev] }, 'bflow-band');
                p.dataset.s = s.id;
                p.dataset.t = t.id;
                p.dataset.tip = s.full + ' → ' + (t.full || t.label) + '\n' + T('{n} operations', { n: fmt(v) });
                if (t.sev === 'out' || t.sev === 'stopped' || t.sev === 'unknown') p.dataset.alert = 'true';
                bands.append(p);
            }
        }
        for (const s of sessions) {
            const g = sv('g', {}, 'bflow-node');
            g.dataset.s = s.id;
            g.append(sv('rect', { x: x0, y: offL + s.y, width: BAR, height: s.h, rx: 2, fill: color(s.color) }));
            const label = sv('text', { x: x0 - 8, y: offL + s.y + s.h / 2, 'text-anchor': 'end', 'dominant-baseline': 'middle' });
            label.textContent = s.label;
            const n = sv('tspan', { dx: 6 }, 'bflow-num');
            n.textContent = fmt(s.value);
            label.append(n);
            g.append(label);
            g.dataset.tip = s.full + '\n' + s.project + ' · ' + T('{n} operations', { n: fmt(s.value) });
            nodes.append(g);
        }
        for (const t of right) {
            const g = sv('g', {}, 'bflow-node');
            g.dataset.t = t.id;
            g.dataset.sev = t.sev;
            g.append(sv('rect', { x: x1, y: offR + t.y, width: BAR, height: t.h, rx: 2, fill: c[t.sev] }));
            const label = sv('text', { x: x1 + BAR + 8, y: offR + t.y + t.h / 2, 'dominant-baseline': 'middle' });
            label.textContent = t.label;
            const n = sv('tspan', { dx: 6 }, 'bflow-num');
            n.textContent = fmt(t.value);
            label.append(n);
            g.append(label);
            g.dataset.tip = (t.full || t.label) + (t.kind ? '\n' + t.kind : '') + '\n' + T('{n} operations', { n: fmt(t.value) });
            nodes.append(g);
        }
        svg.append(bands, nodes);
        box.replaceChildren(svg);
        highlight(root);
    };

    /** Podświetlenie: wstęgi wskazanej sesji albo celu (zostaje po odświeżeniu). */
    const highlight = root => {
        const f = root._bflow;
        const box = root.one('[viz-bflow-chart]');
        box.dataset.focus = String(!!f.focus);
        for (const p of box.querySelectorAll('.bflow-band')) {
            const on = f.focus && ((f.focus.s && p.dataset.s === f.focus.s) || (f.focus.t && p.dataset.t === f.focus.t) || (f.focus.band && p.dataset.s === f.focus.band[0] && p.dataset.t === f.focus.band[1]));
            p.classList.toggle('on', !!on);
        }
    };

    const pointer = (root, event) => {
        const f = root._bflow;
        const tip = root.one('[viz-bflow-tip]');
        const src = event.target.closest?.('[data-tip]');
        if (!src || !root.contains(src)) {
            tip.hidden = true;
            if (f.focus) { f.focus = null; highlight(root); }
            return;
        }
        f.focus = src.classList.contains('bflow-band') ? { band: [src.dataset.s, src.dataset.t] } : { s: src.dataset.s, t: src.dataset.t };
        highlight(root);
        tip.textContent = src.dataset.tip;
        tip.hidden = false;
        const r = root.getBoundingClientRect();
        tip.style.left = Math.min(event.clientX - r.left + 14, r.width - tip.offsetWidth - 8) + 'px';
        tip.style.top = (event.clientY - r.top + 16) + 'px';
    };

    const render = (root, feed) => {
        root.one('[viz-bflow-status]').textContent = feed.failed ? T('refresh not working') : '';
        const data = feed.data;
        if (!data) return;
        boundaryVerdict(root.one('[viz-bflow-verdict]'), data, feed.now());
        if (!data.boundary) {
            root.one('[viz-bflow-chart]').replaceChildren(el('p', 'live-empty', T('Boundary check is off — set AGENT_ALLOWED_RW / AGENT_ALLOWED_R in .env')));
            return;
        }
        draw(root, data, feed.now());
    };

    const Init = root => {
        root._bflow = { focus: null };
        const chart = root.one('[viz-bflow-chart]');
        chart.addEventListener('mousemove', event => pointer(root, event));
        chart.addEventListener('mouseleave', event => pointer(root, event));
        let last = 0;
        new ResizeObserver(() => { if (root._feed?.data && Date.now() - last > 200) { last = Date.now(); render(root, root._feed); } }).observe(chart);
        root._feed = VizFeed.Start(root, { url: root.attr('viz-bflow-url'), onUpdate: feed => render(root, feed) });
    };

    return { Init };
})();
