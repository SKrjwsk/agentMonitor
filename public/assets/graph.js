/*
 * VizGraph — widok grafu (force-graph). Sygnatura: templates/graph.php
 * Jeden poziom: agenci ↔ pliki; pliki przyciągane do węzła swojego folderu, wokół skupiska obszar z nazwą folderu.
 * Podpisy plików pojawiają się od przybliżenia; klik = panel ze szczegółami i linkiem do logów.
 * Stan: zaznaczenie w `viz-pick` sekcji; pozycje węzłów w sessionStorage (wygoda per przeglądarka);
 * instancja grafu i dane w `root._graph`, `root._g`.
 */
const VizGraph = (() => {

    const T = (key, vars) => Viz.T(key, vars);

    const css = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    /** nodeLabel force-graph renderuje HTML — dane są niezaufane. */
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const alpha = (hex, a) => /^#[0-9a-f]{6}$/i.test(hex) ? hex + Math.round(a * 255).toString(16).padStart(2, '0') : hex;

    const OPS = {
        read: () => T('read'), context: () => T('context'), search: () => T('search'), list: () => T('listing'),
        edit: () => T('edit'), write: () => T('write'), exec: () => T('exec'),
    };

    /** Podpisy plików od tego przybliżenia; nazwy folderów zawsze. */
    const FILE_LABEL_SCALE = 0.9;

    const storeKey = root => 'viz-graph-pos:' + root.attr('viz-graph-key');

    const savePositions = root => {
        const pos = {};
        root._g.all.forEach(n => { if (n.x !== undefined) pos[n.id] = [Math.round(n.x), Math.round(n.y)]; });
        try { sessionStorage.setItem(storeKey(root), JSON.stringify(pos)); } catch { /* bez pamięci */ }
    };

    const loadPositions = root => {
        try { return JSON.parse(sessionStorage.getItem(storeKey(root)) || 'null'); } catch { return null; }
    };

    const palette = () => ({
        bg: css('--bg'), line: css('--line'), text: css('--text'), muted: css('--muted'), accent: css('--accent'),
        context: css('--context'), self: css('--read'), mod: css('--edit'), other: css('--list'), exec: css('--blind'),
        agents: [0, 1, 2, 3, 4, 5, 6, 7].map(i => css('--a' + i)),
    });

    const radius = n => n.type === 'agent' ? 9 : n.type === 'dir' ? 2 : 2.5 + Math.min(6, Math.sqrt(n.reads || 0) * 1.4);

    const Init = root => {
        const box = root.one('[viz-graph-canvas]');
        fetch(root.attr('viz-graph-url'), { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (!root.isConnected) return;
                root.one('[viz-graph-hidden]').textContent = data.hidden;
                build(root, box, data);
            });
    };

    const build = (root, box, data) => {
        const c = palette();
        const pos = loadPositions(root);
        const hasSaved = pos !== null;
        const all = new Map();
        data.nodes.forEach(n => {
            const p = hasSaved ? pos[n.id] : undefined;
            if (p) { n.x = p[0]; n.y = p[1]; }
            all.set(n.id, n);
        });
        const links = data.links.map(l => ({ source: l.s, target: l.t, kind: l.kind, w: l.w }));
        const adj = new Map();
        const children = new Map();
        const link = (a, b) => { if (!adj.has(a)) adj.set(a, new Set()); adj.get(a).add(b); };
        links.forEach(l => {
            link(l.source, l.target);
            link(l.target, l.source);
            if (l.kind === 'tree' && all.get(l.target)?.type === 'file') {
                if (!children.has(l.source)) children.set(l.source, []);
                children.get(l.source).push(all.get(l.target));
            }
        });
        root._g = { all, adj, selected: null };
        const g = root._g;

        const active = id => !g.selected || id === g.selected || g.adj.get(g.selected)?.has(id);
        const fill = n => n.type === 'agent' ? c.agents[n.color] : c[n.origin === 'both' ? 'self' : n.origin] || c.other;
        const linkBase = l => ({ tree: c.line, context: c.context, mod: c.mod, other: c.other }[l.kind] || c.self);

        const bigFolders = new Set([...children.entries()]
            .sort((a, b) => b[1].length - a[1].length)
            .slice(0, 12)
            .map(([dirId]) => dirId));

        /** Obszar folderu: koło wokół jego plików + nazwa folderu (stały rozmiar na ekranie). */
        const drawFolders = (ctx, scale) => {
            children.forEach((files, dirId) => {
                const placed = files.filter(f => f.x !== undefined);
                if (placed.length === 0) return;
                const cx = placed.reduce((s, f) => s + f.x, 0) / placed.length;
                const cy = placed.reduce((s, f) => s + f.y, 0) / placed.length;
                const r = Math.max(14, ...placed.map(f => Math.hypot(f.x - cx, f.y - cy) + radius(f) + 6));
                const dim = g.selected && !active(dirId) && !placed.some(f => active(f.id));
                ctx.globalAlpha = dim ? 0.25 : 1;
                ctx.beginPath();
                ctx.arc(cx, cy, r, 0, 2 * Math.PI);
                ctx.fillStyle = alpha(c.muted, 0.07);
                ctx.fill();
                ctx.strokeStyle = alpha(c.muted, g.selected === dirId ? 0.9 : 0.25);
                ctx.lineWidth = 1 / scale;
                ctx.stroke();
                // nazwa: zawsze dla największych folderów, dla reszty gdy obszar jest na ekranie dość duży
                const labelled = bigFolders.has(dirId) || r * scale >= 22 || g.selected === dirId || (g.selected && active(dirId));
                if (!labelled) {
                    ctx.globalAlpha = 1;
                    return;
                }
                ctx.font = `600 ${11.5 / scale}px system-ui, sans-serif`;
                ctx.fillStyle = c.text;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                ctx.fillText(all.get(dirId).label + '/', cx, cy - r - 2 / scale);
                ctx.globalAlpha = 1;
            });
        };

        const graph = ForceGraph()(box)
            .width(box.clientWidth)
            .height(box.clientHeight)
            .backgroundColor(c.bg)
            .graphData({ nodes: data.nodes, links })
            .nodeId('id')
            .nodeLabel(n => esc(n.type === 'agent' ? n.label : n.path))
            .onRenderFramePre((ctx, scale) => drawFolders(ctx, scale))
            .nodeCanvasObject((n, ctx, scale) => {
                const r = radius(n);
                ctx.globalAlpha = active(n.id) ? 1 : 0.12;
                ctx.beginPath();
                ctx.arc(n.x, n.y, r, 0, 2 * Math.PI);
                if (n.type === 'dir') {
                    ctx.fillStyle = alpha(c.muted, 0.6);
                    ctx.fill();
                    ctx.globalAlpha = 1;
                    return;
                }
                ctx.fillStyle = fill(n);
                ctx.fill();
                if (n.origin === 'both') {
                    ctx.strokeStyle = c.context;
                    ctx.lineWidth = 1.4;
                    ctx.stroke();
                }
                if (n.modified && n.origin !== 'mod') {
                    ctx.beginPath();
                    ctx.arc(n.x, n.y, r + 1.6, 0, 2 * Math.PI);
                    ctx.strokeStyle = c.mod;
                    ctx.lineWidth = 1;
                    ctx.stroke();
                }
                if (g.selected === n.id) {
                    ctx.beginPath();
                    ctx.arc(n.x, n.y, r + 3, 0, 2 * Math.PI);
                    ctx.strokeStyle = c.accent;
                    ctx.lineWidth = 1.5;
                    ctx.stroke();
                }
                const label = n.type === 'agent' || g.selected === n.id || scale >= FILE_LABEL_SCALE
                    || (g.selected && active(n.id));
                if (label) {
                    ctx.font = `${(n.type === 'agent' ? 13 : 10) / scale}px system-ui, sans-serif`;
                    ctx.fillStyle = n.type === 'agent' ? c.text : c.muted;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'top';
                    ctx.fillText(n.label, n.x, n.y + r + 1.5 / scale);
                }
                ctx.globalAlpha = 1;
            })
            .nodePointerAreaPaint((n, color, ctx) => {
                ctx.fillStyle = color;
                ctx.beginPath();
                ctx.arc(n.x, n.y, radius(n) + 3, 0, 2 * Math.PI);
                ctx.fill();
            })
            .linkColor(l => {
                if (l.kind === 'tree') return alpha(c.line, g.selected ? 0.05 : 0.18);
                const on = !g.selected || l.source.id === g.selected || l.target.id === g.selected;
                return alpha(linkBase(l), on ? (g.selected ? 0.9 : 0.35) : 0.04);
            })
            .linkWidth(l => l.kind === 'tree' ? 0.3 : Math.min(3, 0.5 + Math.log2(1 + l.w) * 0.45))
            .onNodeClick(n => Select(root, n.id))
            .onBackgroundClick(() => Select(root, null))
            .onNodeDragEnd(() => savePositions(root))
            .onEngineStop(() => {
                savePositions(root);
                if (!root._fitted && !hasSaved) {
                    root._fitted = true;
                    graph.zoomToFit(400, 70);
                }
            })
            .cooldownTicks(hasSaved ? 60 : 350);

        // pliki blisko folderu (skupiska), foldery i agenci się odpychają
        graph.d3Force('charge').strength(n => n.type === 'agent' ? -320 : (n.type === 'dir' ? -160 : -22));
        graph.d3Force('link')
            .distance(l => l.kind === 'tree' ? (l.target.type === 'dir' ? 90 : 18) : 120)
            .strength(l => l.kind === 'tree' ? (l.target.type === 'dir' ? 0.15 : 0.9) : 0.03);
        root._graph = graph;

        const pick = root.up('[viz-session]').attr('viz-pick');
        if (pick && all.has('f:' + pick)) Select(root, 'f:' + pick);

        keepSize(root, box);
    };

    /** Dopasowanie rozmiaru płótna do kontenera; zatrzymanie grafu po podmianie fragmentu. */
    const keepSize = (root, box) => {
        const frame = () => {
            const graph = root._graph;
            if (!root.isConnected) {
                graph?._destructor?.();
                return;
            }
            if (graph.width() !== box.clientWidth || graph.height() !== box.clientHeight) {
                graph.width(box.clientWidth).height(box.clientHeight);
            }
            requestAnimationFrame(frame);
        };
        requestAnimationFrame(frame);
    };

    const Select = (root, id) => {
        const g = root._g;
        const next = id && g.selected !== id ? id : null;
        g.selected = next;
        const node = next ? g.all.get(next) : null;
        const hash = node && node.type === 'file' && /^[a-f0-9]{12}$/.test(node.hash) ? node.hash : null;
        root.up('[viz-session]').attr('viz-pick', hash);

        const info = root.one('[viz-graph-info]');
        info.hidden = !node;
        if (!node) return;
        info.one('[viz-graph-info-title]').textContent = node.type === 'dir' ? node.label + '/' : node.label;
        info.one('[viz-graph-info-path]').textContent = node.type === 'agent' ? T('agent') : node.path;
        const ops = Object.entries(node.ops || {}).map(([op, n]) => `${OPS[op] ? OPS[op]() : op} ×${n}`).join(' · ');
        info.one('[viz-graph-info-ops]').textContent = node.type === 'agent'
            ? T('{n} files', { n: g.adj.get(node.id)?.size || 0 })
            : (node.type === 'dir' ? T('{n} files', { n: node.files }) + ' · ' : '') + ops;
        info.one('[viz-graph-info-agents]').textContent = node.type === 'file' && node.agents?.length ? T('agents: {names}', { names: node.agents.join(', ') }) : '';
        const link = info.one('[viz-graph-info-link]');
        link.hidden = !hash;
        if (hash) link.attr('href', root.attr('viz-graph-logs') + '&pick=' + hash);
    };

    return { Init, Select };
})();
