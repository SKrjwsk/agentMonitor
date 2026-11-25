/*
 * VizOrbit — equipment orbit (route /orbit): agents of all sessions in scope are one node in the middle, around it satellites — MCP servers
 * (`mcp__<server>__*`), skills (`Skill`), subagents (`Agent` / `Task`, type from the start of the description) and groups of built-in tools (read, search, edit,
 * Bash, the rest as "other") used in the feed window (`window`, 30 min). Planet = sphere with a glow (radial gradient per kind).
 * Satellite size = number of uses in the window, red outline = calls with `status` failed / blocked / stopped, the line from the centre brightens on
 * use in the last 10 s, a new call (id not seen at the previous refresh) flies as a pulse centre → satellite. The first
 * load throws no pulses. Kind colours from `--k-*` as on the Work timeline (read, search, edit, Bash, subagent = delegation; MCP, skill and "other" = `--k-other`, skill
 * additionally with a dashed outline). Click a satellite =
 * panel with its 10 latest calls (tool, target, duration, time); click a call = session log (`view=log&pev=`), a second
 * click on the satellite closes the panel. Satellite layout fixed (kind, name). Signature: templates/orbit.php.
 * Data: VizFeed; all texts via textContent.
 */
window.VizOrbit = (() => {

    const { T, el, tail, sessionLink, clock } = VizViews;
    const SVG = 'http://www.w3.org/2000/svg';
    const W = 1000;
    const H = 560;
    const CX = W / 2;
    const CY = H / 2;
    const RX = 390;
    const RY = 200;
    const FRESH = 10;
    const PULSE_MS = 800;
    const DETAIL_LIMIT = 10;
    const KIND_ORDER = ['read', 'search', 'edit', 'exec', 'mcp', 'skill', 'sub', 'other'];
    /** Built-in tool groups (keys as `KINDS` in lanes.js); the remaining tools are the "other" planet. */
    const GROUPS = [
        ['read', ['Read', 'NotebookRead']],
        ['search', ['Grep', 'Glob', 'LS', 'ToolSearch', 'WebSearch', 'WebFetch']],
        ['edit', ['Edit', 'Write', 'MultiEdit', 'NotebookEdit']],
        ['exec', ['Bash']],
    ];
    const STATUSES = ['failed', 'blocked', 'stopped'];

    const sec = iso => Date.parse(iso) / 1000;
    const svg = (tag, attrs = {}) => {
        const e = document.createElementNS(SVG, tag);
        for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, String(v));

        return e;
    };

    /** Call's planet: [kind, name] — MCP server, skill, subagent type or built-in tool group. */
    const satellite = c => {
        if (c.tool.startsWith('mcp__')) {
            const server = c.tool.split('__')[1];

            return server ? ['mcp', server] : null;
        }
        if (c.tool === 'Skill') return c.text ? ['skill', c.text] : null;
        if (c.tool === 'Agent' || c.tool === 'Task') return ['sub', (c.text || '').split(': ')[0] || 'agent'];
        const group = GROUPS.find(([, tools]) => tools.includes(c.tool))?.[0] ?? 'other';

        return [group, group];
    };

    /** Planet caption: skill with `/`, tool group (Bash or the translated group name), server and subagent — name from the data. */
    const label = (kind, name) => kind === 'skill' ? '/' + name : kind === 'exec' ? 'Bash' : GROUPS.some(([g]) => g === kind) || kind === 'other' ? T(kind) : name;

    /** Satellites from calls in the window: by `slot` (`kind|name`), with count, errors, last use and call list (newest first). */
    const collect = (data, now) => {
        const win = data.window || 1800;
        const sats = new Map();
        for (const s of data.sessions) {
            for (const a of s.agents) {
                for (const c of a.calls) {
                    if ((c.end ? sec(c.end) : now) < now - win) continue;
                    const k = satellite(c);
                    if (!k) continue;
                    const slot = k.join('|');
                    if (!sats.has(slot)) sats.set(slot, { slot, kind: k[0], name: k[1], count: 0, failed: 0, last: 0, calls: [] });
                    const e = sats.get(slot);
                    e.count++;
                    if (STATUSES.includes(c.status)) e.failed++;
                    e.last = Math.max(e.last, sec(c.ts));
                    e.calls.push({ ...c, session: s.id });
                }
            }
        }
        for (const e of sats.values()) e.calls.sort((a, b) => sec(b.ts) - sec(a.ts));

        return [...sats.values()].sort((a, b) => KIND_ORDER.indexOf(a.kind) - KIND_ORDER.indexOf(b.kind) || a.name.localeCompare(b.name));
    };

    /** Positions on an ellipse, from the top clockwise. */
    const place = list => list.map((e, i) => {
        const a = -Math.PI / 2 + 2 * Math.PI * i / list.length;

        return { ...e, x: CX + RX * Math.cos(a), y: CY + RY * Math.sin(a) };
    });

    const pulse = (stage, from, to) => {
        const dot = svg('circle', { r: 5, cx: from.x, cy: from.y, class: 'orbit-pulse', 'data-kind': to.kind });
        for (const [attr, a, b] of [['cx', from.x, to.x], ['cy', from.y, to.y]]) {
            dot.append(svg('animate', { attributeName: attr, from: a, to: b, dur: PULSE_MS + 'ms', fill: 'freeze' }));
        }
        stage.querySelector('.orbit-pulses').append(dot);
        setTimeout(() => dot.remove(), PULSE_MS + 50);
    };

    const uses = s => T('{n} uses in 30 min', { n: s.count }) + (s.failed ? ' · ' + T('errors {n}', { n: s.failed }) : '');

    const detail = (root, sat) => {
        const box = root.one('[viz-orbit-detail]');
        box.hidden = !sat;
        if (!sat) {
            box.replaceChildren();

            return;
        }
        const head = el('h3', null, label(sat.kind, sat.name));
        head.append(el('span', 'muted', ' · ' + uses(sat)));
        const ul = el('ul');
        for (const c of sat.calls.slice(0, DETAIL_LIMIT)) {
            const li = el('li', 'orbit-call');
            if (STATUSES.includes(c.status)) li.dataset.status = c.status;
            if (c.id) {
                li.dataset.ev = c.id;
                li.dataset.s = c.session;
            }
            const ms = c.end ? Date.parse(c.end) - Date.parse(c.ts) : null;
            li.append(el('span', null, c.tool.startsWith('mcp__') ? c.tool.split('__').slice(2).join('__') : c.tool), el('span', 'orbit-text', tail(c.text || '', 70)),
                el('span', 'muted', ms === null ? T('running') : ms >= 1000 ? (ms / 1000).toFixed(1) + ' s' : ms + ' ms'), el('span', 'muted', clock(c.ts)));
            li.title = (c.text || '') + (c.id ? '\n' + T('click = this call in the session log') : '');
            ul.append(li);
        }
        box.replaceChildren(head, ul);
    };

    const render = (root, feed) => {
        root.one('[viz-orbit-status]').textContent = feed.failed ? T('refresh not working') : '';
        const data = feed.data;
        if (!data) return;
        const now = feed.now();
        const stage = root.one('[viz-orbit-stage]');
        const sats = place(collect(data, now));
        const agents = data.sessions.flatMap(s => s.agents);
        const active = agents.filter(a => a.state === 'working' || a.state === 'delegated').length;
        const ids = () => new Set(agents.flatMap(a => a.calls.map(c => c.id)));
        root.one('[viz-orbit-verdict]').textContent = T('satellites {n} · uses {c} · errors {e}', {
            n: sats.length, c: sats.reduce((x, s) => x + s.count, 0), e: sats.reduce((x, s) => x + s.failed, 0),
        });
        if (!sats.length) {
            stage.replaceChildren(el('p', 'live-empty', T('No MCP, skill or subagent use in scope in the last 30 min.')));
            detail(root, null);
            root._seen = ids();

            return;
        }
        const max = Math.max(...sats.map(s => s.count));
        const sel = root._sel ? sats.find(s => s.slot === root._sel) : null;
        if (!sel) root._sel = null;
        const view = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'orbit-svg', role: 'img' });
        const defs = svg('defs');
        for (const kind of KIND_ORDER) {
            const grad = svg('radialGradient', { id: 'orbit-g-' + kind, cx: '35%', cy: '30%', r: '75%', class: 'orbit-g', 'data-kind': kind });
            for (const [offset, tone] of [['0%', 'light'], ['55%', 'base'], ['100%', 'dark']]) grad.append(svg('stop', { offset, class: 'orbit-stop-' + tone }));
            defs.append(grad);
        }
        view.append(defs, svg('ellipse', { cx: CX, cy: CY, rx: RX, ry: RY, class: 'orbit-ring' }));
        const links = svg('g');
        const nodes = svg('g');
        for (const s of sats) {
            links.append(svg('line', { x1: CX, y1: CY, x2: s.x, y2: s.y, class: 'orbit-link', 'data-fresh': now - s.last <= FRESH ? '1' : '0', 'data-kind': s.kind }));
            const r = 10 + 22 * Math.sqrt(s.count / max);
            const g = svg('g', { class: 'orbit-node', 'data-kind': s.kind, 'data-slot': s.slot, tabindex: 0 });
            if (s.failed) g.setAttribute('data-status', 'failed');
            if (root._sel && root._sel !== s.slot) g.setAttribute('data-dim', '');
            g.append(svg('circle', { cx: s.x, cy: s.y, r, fill: 'url(#orbit-g-' + s.kind + ')' }));
            const name = svg('text', { x: s.x, y: s.y + r + 14, 'text-anchor': 'middle', class: 'orbit-name' });
            name.textContent = tail(label(s.kind, s.name), 22);
            const n = svg('text', { x: s.x, y: s.y + 4, 'text-anchor': 'middle', class: 'orbit-count' });
            n.textContent = String(s.count);
            const title = svg('title');
            title.textContent = label(s.kind, s.name) + ' · ' + uses(s);
            g.append(name, n, title);
            nodes.append(g);
        }
        const core = svg('g', { class: 'orbit-core' });
        core.append(svg('circle', { cx: CX, cy: CY, r: 38 }));
        const t1 = svg('text', { x: CX, y: CY - 2, 'text-anchor': 'middle' });
        t1.textContent = T('agents');
        const t2 = svg('text', { x: CX, y: CY + 14, 'text-anchor': 'middle', class: 'orbit-sub' });
        t2.textContent = T('{n} active', { n: active });
        core.append(t1, t2);
        view.append(links, svg('g', { class: 'orbit-pulses' }), nodes, core);
        stage.replaceChildren(view);
        detail(root, sel);
        // pulses: calls not seen at the previous refresh; the first load only remembers
        const seen = root._seen;
        root._seen = ids();
        if (!seen) return;
        for (const s of sats) {
            for (const c of s.calls) {
                if (c.id && !seen.has(c.id)) pulse(stage, { x: CX, y: CY }, s);
            }
        }
    };

    const Init = root => {
        root.one('[viz-orbit-stage]').addEventListener('click', e => {
            const slot = e.target.closest('[data-slot]')?.getAttribute('data-slot');
            if (!slot) return;
            root._sel = root._sel === slot ? null : slot;
            if (root._feed?.data) render(root, root._feed);
        });
        // click on a call in the panel = that call in the session log
        root.one('[viz-orbit-detail]').addEventListener('click', e => {
            const b = e.target.closest('[data-ev]');
            const href = b && sessionLink(b.dataset.s, '&view=log&pev=' + encodeURIComponent(b.dataset.ev));
            if (href) location.href = href;
        });
        root._feed = VizFeed.Start(root, { url: root.attr('viz-orbit-url'), onUpdate: feed => render(root, feed) });
    };

    return { Init };
})();
