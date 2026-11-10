/*
 * VizViews — wspólne kawałki widoków przeglądowych na feedzie (VizFeed, /feed.json): DOM przez textContent, kolory z tokenów,
 * dane granicy (katalogi z `sessions[].map` scalone między sesjami, wywołania bez ustalonych ścieżek) i werdykt granicy.
 * Widok: VizFeed.Start(root, { onUpdate }) + funkcje stąd + własne rysowanie.
 */
window.VizViews = (() => {

    const T = (key, vars) => Viz.T(key, vars);
    const OPS = ['read', 'look', 'write', 'exec'];
    const SESSION_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    const el = (tag, cls, text) => {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    };
    const css = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    /** Kolor z danych tylko w postaci #rrggbb; inaczej wyciszony. */
    const color = c => /^#[0-9a-f]{6}$/i.test(c || '') ? c : css('--muted');
    /** Ścieżka ucinana od lewej (widać katalog i jego rodzica). */
    const tail = (text, n) => text.length > n ? '…' + text.slice(-(n - 1)) : text;
    const total = r => OPS.reduce((x, k) => x + (r[k] || 0), 0);
    const sessionLink = (sid, extra = '') => SESSION_ID.test(sid) ? '/?s=' + encodeURIComponent(sid) + extra : null;
    const clock = iso => new Date(Date.parse(iso)).toLocaleTimeString(document.documentElement.lang || 'pl', { hour: '2-digit', minute: '2-digit' });

    /** Klucz katalogu na granicy (ten sam katalog w tej samej strefie z kilku sesji = jeden). */
    const dirKey = m => m.zone + '|' + (m.root ?? '') + '|' + m.dir.toLowerCase();

    /** Wywołania Bash bez ustalonych ścieżek w oknie feedu, z sesją. */
    const unknownCalls = (data, now) => data.sessions.flatMap(s => (s.boundary || [])
        .filter(b => b.kind === 'unknown' && now - Date.parse(b.ts) / 1000 <= data.window).map(b => ({ ...b, session: s })));

    /** Katalogi poza granicą: wykonane (> zatrzymanych) i tylko zatrzymane — po kluczu. */
    const outside = data => {
        const ran = new Set();
        const stopped = new Set();
        for (const s of data.sessions) {
            for (const m of s.map || []) {
                if (m.zone !== 'out') continue;
                if (total(m) > m.stopped) ran.add(dirKey(m));
                if (m.stopped > 0) stopped.add(dirKey(m));
            }
        }
        return { ran: ran.size, stopped: stopped.size };
    };

    /** Werdykt granicy w nagłówku widoku (`data-sev` + tekst). */
    const boundaryVerdict = (verdict, data, now) => {
        if (!data.boundary) {
            verdict.dataset.sev = 'info';
            verdict.textContent = T('boundary off');
            return;
        }
        const out = outside(data);
        const unknown = unknownCalls(data, now).length;
        verdict.dataset.sev = out.ran ? 'high' : unknown ? 'warn' : 'ok';
        verdict.textContent = out.ran ? T('Outside the boundary: {n} directories', { n: out.ran })
            : unknown ? T('Within the boundary, {n} calls with unknown paths', { n: unknown })
            : out.stopped ? T('Within the boundary — {n} attempts stopped', { n: out.stopped }) : T('All agents work within the boundary');
    };

    /** Typ subagenta do podpisu; typ ogólny (domyślny dla wszystkich, nic nie mówi) = pusty. */
    const GENERIC_AGENTS = ['general-purpose'];
    const role = a => GENERIC_AGENTS.includes(a.label) ? '' : a.label || '';

    /** Nazwa agenta do podpisu: sesja główna = tytuł sesji, subagent = typ (bez ogólnego) i opis zadania. */
    const agentName = (s, a) => a.kind === 'main' ? (s.title || s.id.slice(0, 8)) : [role(a), a.description].filter(Boolean).join(': ') || T('subagent');

    /** Sesje pogrupowane po projekcie (`projectKey`), w kolejności pierwszego wystąpienia — kolejność ustala widok, sortując sesje. */
    const byProject = sessions => {
        const groups = new Map();
        for (const s of sessions) {
            const key = s.projectKey || s.project;
            if (!groups.has(key)) groups.set(key, { key, project: s.project, color: s.projectColor, sessions: [] });
            groups.get(key).sessions.push(s);
        }

        return [...groups.values()];
    };

    /**
     * Sekcja drugorzędna (`<details>`, w szablonie bez `open` — domyślnie zwinięta): stan otwarcia w `localStorage` pod `key`.
     * Bez dostępu do pamięci — zostaje zwinięta, przełączanie działa tylko w tej karcie.
     */
    const remember = (details, key) => {
        if (!details) return;
        try { details.open = localStorage.getItem(key) === 'open'; } catch { /* bez pamięci: zwinięta */ }
        details.addEventListener('toggle', () => {
            try { localStorage.setItem(key, details.open ? 'open' : 'closed'); } catch { /* bez pamięci: tylko ta karta */ }
        });
    };

    return { T, OPS, el, css, color, tail, total, sessionLink, clock, dirKey, unknownCalls, outside, boundaryVerdict, role, agentName, byProject, remember };
})();
