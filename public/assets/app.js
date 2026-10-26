/*
 * Viz — interakcje widoku sesji (wzorzec atrybutów: stan w atrybutach, eventy inline, init w miejscu).
 * Sygnatura: templates/session.php
 */
const Viz = (() => {

    /**
     * Tłumaczenie tekstów interfejsu: kluczem jest tekst angielski, słownik wstawia serwer (layout.php, `#viz-i18n`, pusty dla EN).
     * Składnia: T("English text") albo T("{n} files", { n: 3 }). Brak klucza = tekst angielski. Słownik czytany raz, przy pierwszym użyciu.
     */
    let dict = null;
    const T = (key, vars) => {
        if (dict === null) {
            try { dict = JSON.parse(document.getElementById('viz-i18n')?.textContent || '{}') || {}; } catch { dict = {}; }
        }
        const text = Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
        return vars ? text.replace(/\{(\w+)\}/g, (m, name) => name in vars ? String(vars[name]) : m) : text;
    };

    /** Co tyle ms zapytanie o wersję źródła (`/version`, kilka ms); fragment jest pobierany tylko przy jej zmianie. */
    const POLL_MS = 1000;
    /** Fragment w drodze: Hx nie zwraca obietnicy, więc do tej chwili (albo do podmiany sekcji) nie zaczynamy kolejnego. */
    const FRAGMENT_WAIT_MS = 10000;
    /** Zapytanie o wersję bez odpowiedzi tak długo = błąd odświeżania; bez limitu zawieszone połączenie zostawiłoby `_busy` na stałe. */
    const FETCH_TIMEOUT_MS = 5000;

    /** Tekst odpowiedzi; HTTP ≠ 2xx, błąd sieci i przekroczenie limitu czasu odrzucają obietnicę. */
    const fetchText = url => {
        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), FETCH_TIMEOUT_MS);
        return fetch(url, { cache: 'no-store', signal: abort.signal })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .finally(() => clearTimeout(timer));
    };

    /** Komunikat „refresh not working” (layout.php) — pokazany po nieudanym odświeżeniu, znika przy pierwszym udanym. */
    const refreshFailed = failed => {
        const note = document.querySelector('[viz-refresh-error]');
        if (note) note.hidden = !failed;
    };

    const space = el => el.up('[viz-session]');

    /** Parametry przekazywane przy odświeżeniu fragmentu — stan widoku przechodzi przez serwer. */
    const Query = section => ({
        pick: section.attr('viz-pick') || '',
        pev: section.attr('viz-pev') || '',
        pcmd: section.attr('viz-pcmd') || '',
        live: section.flag('viz-live') ? '1' : '0',
        st: Math.round(section.one('[viz-pane="list"]')?.scrollTop || 0),
        se: Math.round(section.one('[viz-pane="drawer"]')?.scrollTop || 0),
        sx: Math.round(section.one('[viz-pane="tl"]')?.scrollLeft || 0),
        sy: Math.round(section.one('[viz-pane="tl"]')?.scrollTop || 0),
        sr: Math.round(section.one('[viz-pane="rank"]')?.scrollTop || 0),
        find: section.one('[filter-query]')?.value.trim() || '',
    });

    const Init = section => {
        // nowa sekcja = fragment właśnie przyszedł, więc odświeżanie działa
        refreshFailed(false);
        const list = section.one('[viz-pane="list"]');
        const drawer = section.one('[viz-pane="drawer"]');
        if (list) list.scrollTop = +section.attr('viz-st') || 0;
        if (drawer) drawer.scrollTop = +section.attr('viz-se') || 0;
        const rank = section.one('[viz-pane="rank"]');
        if (rank) rank.scrollTop = +section.attr('viz-sr') || 0;
        const tl = section.one('[viz-pane="tl"]');
        if (tl) {
            tl.scrollLeft = +section.attr('viz-sx') || 0;
            tl.scrollTop = +section.attr('viz-sy') || 0;
        }
        section._timer = setInterval(() => tick(section), POLL_MS);
        if (section.attr('viz-view') && !section.flag('viz-all')) {
            try { localStorage.setItem('viz-view', section.attr('viz-view')); } catch { /* bez pamięci */ }
        }
    };

    /**
     * Podmienia fragment tylko, gdy źródło się zmieniło — przy odświeżaniu co sekundę bez zmiany danych DOM stoi w miejscu
     * (przewinięcie, zaznaczenie tekstu i rozwinięte elementy zostają). Nowe żądanie dopiero po zakończeniu poprzedniego:
     * zapytanie o wersję (`_busy`, z limitem czasu) i pobranie fragmentu (`_fragmentUntil`; po podmianie sekcja jest nowym elementem).
     * Fragment, który nie podmienił sekcji w FRAGMENT_WAIT_MS, to błąd odświeżania (Hx nie zgłasza błędów) — kolejna próba przy następnym ticku.
     * Błąd (sieć, HTTP, limit czasu, brak podmiany) pokazuje komunikat; pierwsze udane odświeżenie albo podmiana sekcji go chowa.
     */
    const tick = section => {
        if (!section.isConnected) {
            clearInterval(section._timer);
            return;
        }
        if (!section.flag('viz-live') || document.hidden || section._busy) return;
        if (section._fragmentUntil) {
            if (Date.now() < section._fragmentUntil) return;
            section._fragmentUntil = 0;
            refreshFailed(true);
        }
        section._busy = true;
        fetchText(section.attr('viz-version-url'))
            .then(version => {
                if (!version) throw new Error('empty version');
                if (version === section.attr('viz-version')) {
                    refreshFailed(false);
                    return;
                }
                section._fragmentUntil = Date.now() + FRAGMENT_WAIT_MS;
                hx(section);
            })
            .catch(() => refreshFailed(true))
            .finally(() => { section._busy = false; });
    };

    const LiveToggle = input => {
        space(input).flag('viz-live', input.checked);
    };

    /**
     * Klik w plik (viz-file) albo wywołanie (viz-ev): panel szczegółów po prawej, renderowany przez serwer.
     * Drugi klik w to samo zamyka panel.
     */
    const Open = (el, event) => {
        // link, rozwijanie albo inny przycisk w wierszu robi swoje; sam element z viz-file/viz-ev może być przyciskiem
        const own = event?.target.closest('a, summary, button');
        if (own && own !== el) return;
        event?.stopPropagation();
        const section = space(el);
        const file = el.attr('viz-file');
        const ev = el.attr('viz-ev');
        const same = file ? section.attr('viz-pick') === file : section.attr('viz-pev') === ev;
        section.attr('viz-pick', !same && file ? file : null);
        section.attr('viz-pev', !same && !file ? ev : null);
        swap(section);
    };

    /** Wszystkie sesje: klik w program = panel z podkomendami, potokami i sesjami. */
    const OpenCmd = (el, event) => {
        event?.stopPropagation();
        const section = space(el);
        const program = el.attr('viz-cmd');
        section.attr('viz-pcmd', section.attr('viz-pcmd') === program ? null : program);
        section.attr('viz-pick', null);
        swap(section);
    };

    const Close = el => {
        const section = space(el);
        section.attr('viz-pick', null);
        section.attr('viz-pev', null);
        // w sesji pcmd to filtr listy komend, nie panel — zostaje
        if (section.flag('viz-all')) section.attr('viz-pcmd', null);
        swap(section);
    };

    /** Nowa zawartość drawera zaczyna się od góry. */
    const swap = section => {
        const drawer = section.one('[viz-pane="drawer"]');
        if (drawer) drawer.scrollTop = 0;
        hx(section);
    };

    /** Filtry w <select>: wartość opcji to gotowy URL z serwera; tylko ścieżki tej aplikacji. */
    const Go = select => {
        if (select.value.startsWith('/?')) location.href = select.value;
    };

    document.addEventListener('keydown', event => {
        if (event.code !== 'Escape') return;
        const section = document.querySelector('[viz-session][viz-pick], [viz-session][viz-pev], [viz-session][viz-all][viz-pcmd]');
        if (section && section.one('[viz-pane="drawer"]')) Close(section);
    });

    /** Język interfejsu: ciasteczko viz-lang czyta serwer (Viz\Web\Lang), więc przeładowanie. */
    const Lang = () => {
        const next = document.documentElement.lang === 'pl' ? 'en' : 'pl';
        document.cookie = 'viz-lang=' + next + '; path=/; max-age=31536000; SameSite=Lax';
        location.reload();
    };

    /** Domyślnie ciemny; wybór jasnego zapamiętany w przeglądarce. Graf i biuro czytają kolory przy budowie, więc przeładowanie. */
    const Theme = () => {
        const light = document.documentElement.dataset.theme !== 'light';
        if (light) document.documentElement.dataset.theme = 'light'; else delete document.documentElement.dataset.theme;
        try { localStorage.setItem('viz-theme', light ? 'light' : 'dark'); } catch { /* bez pamięci */ }
        if (document.one('[viz-graph], [viz-office]')) location.reload();
    };

    /** Lewy panel: tylko sesje wybranego projektu; wybór zapamiętany w przeglądarce. */
    const showProject = (aside, key) => {
        aside.all('li[viz-project]').forEach(li => li.show(key === '' || li.attr('viz-project') === key));
    };

    const Project = select => {
        try { localStorage.setItem('viz-project', select.value); } catch { /* bez pamięci */ }
        showProject(select.up('aside'), select.value);
    };

    /** Otwarta sesja zawsze widoczna: gdy zapamiętany projekt ją ukrywa, wybór przechodzi na jej projekt. */
    const ProjectInit = aside => {
        const select = aside.one('.sessions-project select');
        let key = '';
        try { key = localStorage.getItem('viz-project') || ''; } catch { /* bez pamięci */ }
        const current = aside.one('li[viz-current]')?.attr('viz-project');
        if (key && current && current !== key) key = current;
        if (![...select.options].some(o => o.value === key)) key = '';
        select.value = key;
        Project(select);
    };

    /**
     * Ostatnio otwarta zakładka sesji (`viz-view`, zapamiętana przy Init sekcji) — link sesji w lewym panelu otwiera ją od razu,
     * żeby przeglądać kolejne sesje w tym samym widoku (np. Office) bez przeklikiwania.
     */
    const SessionLink = link => {
        let view = '';
        try { view = localStorage.getItem('viz-view') || ''; } catch { /* bez pamięci */ }
        if (view === '' || view === 'read') return;
        const url = new URL(link.href);
        url.searchParams.set('view', view);
        link.href = url.pathname + url.search;
    };

    return { T, Init, Query, LiveToggle, Open, OpenCmd, Close, Go, Theme, Lang, Project, ProjectInit, SessionLink };
})();
