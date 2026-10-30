/*
 * VizBGraph — graf granicy (trasa /boundary-graph): agenci ↔ katalogi dotknięte w ostatnich 30 min, katalogi przyciągane do węzła
 * swojej strefy (odczyt i zapis, tylko odczyt, poza granicą), wokół strefy obszar w jej kolorze. Wywołania bez ustalonych ścieżek
 * łączą agenta z węzłem „nieustalone ścieżki”. Sygnatura: templates/boundary-graph.php. Dane: VizFeed (/feed.json) co 1 s;
 * zmiana samych liczb nie restartuje symulacji (węzły zachowują pozycje), nowy węzeł albo krawędź — dokłada je do grafu.
 */
window.VizBGraph = (() => {

    const { T, el, css, color, tail, total, sessionLink, dirKey, unknownCalls, boundaryVerdict, agentName } = VizViews;

    /** nodeLabel force-graph renderuje HTML — dane są niezaufane. */
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const alpha = (hex, a) => /^#[0-9a-f]{6}$/i.test(hex) ? hex + Math.round(a * 255).toString(16).padStart(2, '0') : hex;

    const palette = () => ({
        bg: css('--bg'), text: css('--text'), muted: css('--muted'), line: css('--line'), accent: css('--accent'),
        rw: css('--ok'), r: css('--search'), out: css('--high'), stopped: css('--info'), unknown: css('--warn'),
    });

    const radius = n => n.type === 'agent' ? 8 : n.type === 'zone' ? 6 : 2.5 + Math.min(9, Math.sqrt(n.ops || 1) * 1.1);

    /** Węzły i krawędzie z feedu. Strefy z konfiguracji są zawsze (także bez ruchu); „poza” i „nieustalone” — gdy coś do nich prowadzi. */
    const model = (data, now) => {
        const nodes = new Map();
        const links = new Map();
        const node = (id, props) => {
            const n = nodes.get(id) ?? { id, ...props, ops: 0 };
            nodes.set(id, n);
            return n;
        };
        const link = (source, target, kind, w) => {
            const id = source + '>' + target;
            const l = links.get(id) ?? { id, source, target, kind, w: 0 };
            l.w += w;
            if (kind === 'out') l.kind = 'out';
            links.set(id, l);
        };
        for (const z of data.zones.rw) node('z:rw|' + z, { type: 'zone', zone: 'rw', label: tail(z, 40), full: z });
        for (const z of data.zones.r) node('z:r|' + z, { type: 'zone', zone: 'r', label: tail(z, 40), full: z });
        for (const s of data.sessions) {
            const agents = new Map(s.agents.map(a => [a.id, a]));
            const agentNode = id => {
                const a = agents.get(id);
                return node('a:' + s.id + ':' + id, { type: 'agent', label: a ? (a.kind === 'main' ? tail(s.title || s.id.slice(0, 8), 32) : a.label) : id,
                    full: a ? agentName(s, a) : id, session: s, color: s.projectColor });
            };
            for (const m of s.map || []) {
                const zoneId = m.zone === 'out' ? 'z:out' : 'z:' + m.zone + '|' + m.root;
                const zone = m.zone === 'out' ? node('z:out', { type: 'zone', zone: 'out', label: T('Outside the boundary') })
                    : node(zoneId, { type: 'zone', zone: m.zone, label: tail(m.root, 40), full: m.root });
                const d = node('d:' + dirKey(m), { type: 'dir', zone: m.zone, root: m.root, label: m.dir === '.' ? T('(zone root)') : tail(m.dir, 28), full: m.dir,
                    ran: 0, stopped: 0, sessions: new Set() });
                const ops = total(m);
                d.ops += ops;
                d.ran += ops - m.stopped;
                d.stopped += m.stopped;
                d.sessions.add(s.title || s.id.slice(0, 8));
                zone.ops += ops;
                link(zoneId, d.id, 'zone', 1);
                const kind = m.zone !== 'out' ? m.zone : (ops > m.stopped ? 'out' : 'stopped');
                for (const aid of m.agents) {
                    const a = agentNode(aid);
                    a.ops += ops / m.agents.length;
                    link(a.id, d.id, kind, ops / m.agents.length);
                }
            }
        }
        for (const u of unknownCalls(data, now)) {
            const z = node('z:unknown', { type: 'zone', zone: 'unknown', label: T('Paths not resolved') });
            z.ops++;
            const a = u.session.agents.find(x => x.id === u.agent);
            const an = node('a:' + u.session.id + ':' + u.agent, { type: 'agent', label: a ? (a.kind === 'main' ? tail(u.session.title || u.session.id.slice(0, 8), 32) : a.label) : u.agent,
                full: a ? agentName(u.session, a) : u.agent, session: u.session, color: u.session.projectColor });
            an.ops++;
            link(an.id, 'z:unknown', 'unknown', 1);
        }
        return { nodes: [...nodes.values()], links: [...links.values()] };
    };

    const fillOf = (c, n) => {
        if (n.type === 'agent') return color(n.color);
        if (n.zone === 'out') return n.type === 'dir' && n.ran === 0 ? c.stopped : c.out;
        return c[n.zone] || c.muted;
    };

    const info = (root, n) => {
        const box = root.one('[viz-bgraph-info]');
        if (!n) { box.hidden = true; return; }
        const rows = [el('b', null, n.full || n.label)];
        if (n.type === 'agent') {
            rows.push(el('span', 'muted', n.session.project + ' · ' + T('{n} operations', { n: Math.round(n.ops) })));
            const href = sessionLink(n.session.id, '&view=boundary');
            if (href) { const a = el('a', 'btn', T('Boundary tab') + ' ↗'); a.href = href; rows.push(a); }
        } else if (n.type === 'dir') {
            rows.push(el('span', 'muted', (n.zone === 'out' ? T('Outside the boundary') : n.root) + ' · ' + T('{n} operations', { n: n.ops })));
            if (n.zone === 'out') rows.push(el('span', null, T('executed {n}', { n: n.ran }) + ' · ' + T('stopped {n}', { n: n.stopped })));
            rows.push(el('span', 'muted', [...n.sessions].join(', ')));
        } else {
            rows.push(el('span', 'muted', T('{n} operations', { n: Math.round(n.ops) })));
        }
        box.replaceChildren(...rows);
        box.hidden = false;
    };

    /** Nowe dane: te same węzły i krawędzie = tylko liczby (bez restartu symulacji); inaczej dokłada / zdejmuje, zachowując pozycje. */
    const update = (root, data, now) => {
        const g = root._bg;
        const next = model(data, now);
        const signature = next.nodes.map(n => n.id).sort().join('\n') + '\n#\n' + next.links.map(l => l.id + ':' + l.kind).sort().join('\n');
        if (signature === g.signature) {
            for (const n of next.nodes) {
                const old = g.byId.get(n.id);
                const pos = keepPosition(old);
                Object.assign(old, n, pos);
            }
            const links = new Map(next.links.map(l => [l.id, l]));
            for (const l of g.graph.graphData().links) l.w = links.get(l.id)?.w ?? l.w;
            return;
        }
        const nodes = next.nodes.map(n => Object.assign(g.byId.get(n.id) ?? {}, n, keepPosition(g.byId.get(n.id))));
        g.byId = new Map(nodes.map(n => [n.id, n]));
        g.signature = signature;
        g.graph.graphData({ nodes, links: next.links });
        if (g.selected && !g.byId.has(g.selected)) { g.selected = null; info(root, null); }
    };

    const keepPosition = n => n ? { x: n.x, y: n.y, vx: n.vx, vy: n.vy, fx: n.fx, fy: n.fy } : {};

    const build = root => {
        const c = palette();
        const box = root.one('[viz-bgraph-canvas]');
        const g = { byId: new Map(), signature: '', selected: null, graph: null, fitted: false };
        root._bg = g;
        const neighbours = id => {
            const out = new Set([id]);
            for (const l of g.graph.graphData().links) {
                const s = l.source.id ?? l.source, t = l.target.id ?? l.target;
                if (s === id) out.add(t);
                if (t === id) out.add(s);
            }
            return out;
        };
        let near = null;
        const active = id => !g.selected || near.has(id);
        /** Obszar strefy: koło wokół jej katalogów, w kolorze strefy, z nazwą. */
        const drawZones = (ctx, scale) => {
            const groups = new Map();
            for (const n of g.byId.values()) {
                if (n.type !== 'dir' || n.x === undefined) continue;
                const key = n.zone === 'out' ? 'z:out' : 'z:' + n.zone + '|' + n.root;
                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(n);
            }
            for (const [zid, dirs] of groups) {
                const z = g.byId.get(zid);
                const pts = z && z.x !== undefined ? [...dirs, z] : dirs;
                const cx = pts.reduce((s, p) => s + p.x, 0) / pts.length;
                const cy = pts.reduce((s, p) => s + p.y, 0) / pts.length;
                const r = Math.max(26, ...pts.map(p => Math.hypot(p.x - cx, p.y - cy) + radius(p) + 10));
                const zc = z ? fillOf(c, z) : c.muted;
                ctx.globalAlpha = g.selected && !active(zid) && !dirs.some(d => active(d.id)) ? 0.25 : 1;
                ctx.beginPath();
                ctx.arc(cx, cy, r, 0, 2 * Math.PI);
                ctx.fillStyle = alpha(zc, 0.07);
                ctx.fill();
                ctx.strokeStyle = alpha(zc, 0.45);
                ctx.lineWidth = 1.2 / scale;
                ctx.stroke();
                ctx.globalAlpha = 1;
            }
        };
        g.graph = ForceGraph()(box)
            .width(box.clientWidth)
            .height(box.clientHeight)
            .backgroundColor(c.bg)
            .autoPauseRedraw(false)
            .graphData({ nodes: [], links: [] })
            .nodeId('id')
            .nodeLabel(n => esc(n.full || n.label))
            .onRenderFramePre((ctx, scale) => { near = g.selected ? neighbours(g.selected) : null; drawZones(ctx, scale); })
            .nodeCanvasObject((n, ctx, scale) => {
                const r = radius(n);
                ctx.globalAlpha = active(n.id) ? 1 : 0.15;
                ctx.beginPath();
                if (n.type === 'zone') {
                    ctx.rect(n.x - r, n.y - r, 2 * r, 2 * r);
                } else {
                    ctx.arc(n.x, n.y, r, 0, 2 * Math.PI);
                }
                ctx.fillStyle = fillOf(c, n);
                ctx.fill();
                if (n.type === 'agent') {
                    ctx.strokeStyle = c.bg;
                    ctx.lineWidth = 2 / scale;
                    ctx.stroke();
                }
                if (g.selected === n.id) {
                    ctx.beginPath();
                    ctx.arc(n.x, n.y, r + 3, 0, 2 * Math.PI);
                    ctx.strokeStyle = c.accent;
                    ctx.lineWidth = 1.5;
                    ctx.stroke();
                }
                // podpisy: agenci i strefy zawsze, katalogi od przybliżenia albo przy zaznaczeniu
                if (n.type !== 'dir' || scale >= 1.2 || (g.selected && active(n.id)) || n.zone === 'out') {
                    const size = n.type === 'zone' ? 12 : n.type === 'agent' ? 12 : 10;
                    ctx.font = `${n.type === 'dir' ? '' : '600 '}${size / scale}px system-ui, sans-serif`;
                    ctx.fillStyle = n.type === 'dir' ? c.muted : c.text;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'top';
                    ctx.fillText(n.label, n.x, n.y + r + 2 / scale);
                }
                ctx.globalAlpha = 1;
            })
            .nodePointerAreaPaint((n, col, ctx) => {
                ctx.fillStyle = col;
                ctx.beginPath();
                ctx.arc(n.x, n.y, radius(n) + 3, 0, 2 * Math.PI);
                ctx.fill();
            })
            .linkColor(l => {
                const s = l.source.id ?? l.source, t = l.target.id ?? l.target;
                const on = !g.selected || s === g.selected || t === g.selected;
                if (l.kind === 'zone') return alpha(c.line, g.selected ? 0.08 : 0.5);
                const base = { rw: c.rw, r: c.r, out: c.out, stopped: c.stopped, unknown: c.unknown }[l.kind] || c.muted;
                return alpha(base, on ? (l.kind === 'out' || l.kind === 'stopped' ? 0.95 : 0.45) : 0.05);
            })
            .linkWidth(l => l.kind === 'zone' ? 0.6 : (l.kind === 'out' ? 1.2 : 0.4) + Math.min(3, Math.log2(1 + l.w) * 0.5))
            .linkLineDash(l => l.kind === 'stopped' || l.kind === 'unknown' ? [3, 3] : null)
            .onNodeClick(n => { g.selected = g.selected === n.id ? null : n.id; info(root, g.selected ? n : null); })
            .onBackgroundClick(() => { g.selected = null; info(root, null); })
            .onEngineStop(() => {
                if (!g.fitted && g.byId.size) {
                    g.fitted = true;
                    // margines na podpisy węzłów na brzegu (canvas ich nie liczy)
                    g.graph.zoomToFit(400, 110);
                }
            })
            .cooldownTicks(300);
        // agenci odpychają się mocno, katalogi trzymają się strefy (krótka krawędź strefa → katalog)
        g.graph.d3Force('charge').strength(n => n.type === 'agent' ? -260 : n.type === 'zone' ? -420 : -30);
        g.graph.d3Force('link').distance(l => l.kind === 'zone' ? 26 : 90).strength(l => l.kind === 'zone' ? 0.9 : 0.12);
        new ResizeObserver(() => g.graph.width(box.clientWidth).height(box.clientHeight)).observe(box);
    };

    const render = (root, feed) => {
        root.one('[viz-bgraph-status]').textContent = feed.failed ? T('refresh not working') : '';
        const data = feed.data;
        if (!data) return;
        boundaryVerdict(root.one('[viz-bgraph-verdict]'), data, feed.now());
        const empty = root.one('[viz-bgraph-empty]');
        if (!data.boundary) {
            empty.textContent = T('Boundary check is off — set AGENT_ALLOWED_RW / AGENT_ALLOWED_R in .env');
            empty.hidden = false;
            return;
        }
        empty.hidden = data.sessions.length > 0;
        empty.textContent = T('No sessions active in scope in the last 30 min.');
        if (!root._bg) build(root);
        update(root, data, feed.now());
    };

    const Init = root => {
        VizFeed.Start(root, { url: root.attr('viz-bgraph-url'), onUpdate: feed => render(root, feed), onStop: () => root._bg?.graph?._destructor?.() });
    };

    return { Init };
})();
