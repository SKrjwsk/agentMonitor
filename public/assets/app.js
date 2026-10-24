/*
 * Viz — interakcje widoku sesji (saradom: stan w atrybutach, eventy inline, init w miejscu).
 * Sygnatura: templates/session.php
 */
const Viz = (() => {

    const POLL_MS = 3000;

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
    };

    /** Podmienia fragment tylko, gdy źródło się zmieniło — nie gubi przewijania co 3 s. */
    const tick = section => {
        if (!section.isConnected) {
            clearInterval(section._timer);
            return;
        }
        if (!section.flag('viz-live') || document.hidden || section._busy) return;
        section._busy = true;
        fetch(section.attr('viz-version-url'), { cache: 'no-store' })
            .then(res => res.ok ? res.text() : '')
            .then(version => {
                if (version && version !== section.attr('viz-version')) hx(section);
            })
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

    /** Domyślnie ciemny; wybór jasnego zapamiętany w przeglądarce. Graf czyta kolory przy budowie, więc przeładowanie. */
    const Theme = () => {
        const light = document.documentElement.dataset.theme !== 'light';
        if (light) document.documentElement.dataset.theme = 'light'; else delete document.documentElement.dataset.theme;
        try { localStorage.setItem('viz-theme', light ? 'light' : 'dark'); } catch { /* bez pamięci */ }
        if (document.one('[viz-graph]')) location.reload();
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

    return { Init, Query, LiveToggle, Open, OpenCmd, Close, Go, Theme, Lang, Project, ProjectInit };
})();
