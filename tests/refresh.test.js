'use strict';

/*
 * Test zachowania odświeżania widoków sesji (public/assets/app.js: tick): node bez zależności, wywoływany przez tests/run.php.
 * app.js jest ładowany z atrapami globalnych obiektów (wirtualny zegar, sterowany fetch, hx, document); każde odliczanie
 * zegara robi test, więc nic nie czeka naprawdę. Wyjście: „OK (N sprawdzeń)” i kod 0 albo lista błędów i kod 1.
 */

const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/app.js'), 'utf8');
const failures = [];
let checks = 0;
const check = (name, ok, detail = '') => {
    checks++;
    if (!ok) failures.push(name + (detail ? ' — ' + detail : ''));
};
const flush = () => new Promise(resolve => setImmediate(resolve));

/** Świat atrap: zegar, kolejka timerów, zapytania `fetch` (kolejka scenariuszy), wywołania `hx`, komunikat błędu. */
const makeWorld = () => {
    const world = { now: 1_000_000, timers: [], seq: 0, fetches: [], queue: [], hxCalls: 0, intervals: [], cleared: [], note: { hidden: true }, hidden: false };
    const setTimeoutFake = (fn, ms) => {
        const id = ++world.seq;
        world.timers.push({ id, at: world.now + ms, fn });
        return id;
    };
    const clearTimeoutFake = id => {
        world.timers = world.timers.filter(t => t.id !== id);
    };
    /** Przesuwa zegar, uruchamiając po drodze timery (np. limit czasu zapytania). */
    world.advance = async ms => {
        const end = world.now + ms;
        for (;;) {
            const due = world.timers.filter(t => t.at <= end).sort((a, b) => a.at - b.at)[0];
            if (!due) break;
            world.now = due.at;
            world.timers = world.timers.filter(t => t !== due);
            due.fn();
            await flush();
        }
        world.now = end;
        await flush();
    };
    /** Scenariusze odpowiedzi w kolejności zapytań: {hang}, {reject}, {status, text}; bez wpisu — 200 z wersją „10”. */
    const fetchFake = (url, options) => {
        world.fetches.push(url);
        const step = world.queue.shift() || {};
        return new Promise((resolve, reject) => {
            if (step.hang) {
                options.signal.addEventListener('abort', () => reject(new Error('aborted')));
                return;
            }
            if (step.reject) {
                reject(new Error('network'));
                return;
            }
            const status = step.status || 200;
            resolve({ ok: status < 400, status, text: () => Promise.resolve(step.text ?? '10') });
        });
    };
    const documentFake = {
        get hidden() {
            return world.hidden;
        },
        querySelector: selector => (selector === '[viz-refresh-error]' ? world.note : null),
        addEventListener() {},
    };
    const intervalFake = fn => {
        world.intervals.push(fn);
        return world.intervals.length;
    };
    const factory = new Function('hx', 'fetch', 'document', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'localStorage', 'location', 'Date',
        source + '\nreturn Viz;');
    world.Viz = factory(() => { world.hxCalls++; }, fetchFake, documentFake, setTimeoutFake, clearTimeoutFake, intervalFake, id => { world.cleared.push(id); }, { setItem() {} }, {}, { now: () => world.now });
    world.section = () => ({
        isConnected: true,
        attr: name => ({ 'viz-version': '10', 'viz-version-url': '/version?s=x', 'viz-view': 'read' })[name] ?? null,
        flag: name => name === 'viz-live',
        one: () => null,
    });
    /** Jedno „uderzenie” interwału sekcji (co POLL_MS) i dokończenie obietnic. */
    world.tick = async () => {
        world.intervals[world.intervals.length - 1]();
        await flush();
    };

    return world;
};

(async () => {
    // 1. Zawieszone połączenie: po limicie czasu komunikat, `_busy` zwolnione, następne zapytanie rusza
    {
        const w = makeWorld();
        const section = w.section();
        w.Viz.Init(section);
        w.queue.push({ hang: true });
        await w.tick();
        check('zawieszone zapytanie: trwa, bez komunikatu przed limitem', w.fetches.length === 1 && w.note.hidden === true);
        await w.tick();
        check('zawieszone zapytanie: kolejny tick nie zaczyna drugiego zapytania', w.fetches.length === 1);
        await w.advance(4_900);
        check('zawieszone zapytanie: przed limitem 5 s nadal czeka', w.fetches.length === 1 && w.note.hidden === true);
        await w.advance(200);
        check('zawieszone zapytanie: po limicie 5 s komunikat błędu', w.note.hidden === false);
        await w.tick();
        check('zawieszone zapytanie: po limicie następny tick wysyła nowe zapytanie', w.fetches.length === 2);
        check('zawieszone zapytanie: udane odświeżenie chowa komunikat', w.note.hidden === true);
    }

    // 2. Błąd sieci i HTTP ≠ 2xx: komunikat; udane odświeżenie bez zmiany wersji go chowa
    {
        const w = makeWorld();
        w.Viz.Init(w.section());
        w.queue.push({ reject: true });
        await w.tick();
        check('błąd sieci: komunikat błędu', w.note.hidden === false);
        await w.tick();
        check('po błędzie sieci pierwsze udane zapytanie chowa komunikat', w.note.hidden === true && w.fetches.length === 2);
        // treść odpowiedzi błędu wygląda jak nowa wersja: HTTP 500 nie może być potraktowany jak zmiana danych
        w.queue.push({ status: 500, text: '99' });
        await w.tick();
        check('HTTP 500: komunikat błędu', w.note.hidden === false);
        check('HTTP 500: nie uruchamia pobrania fragmentu', w.hxCalls === 0);
        w.queue.push({ status: 200, text: '' });
        await w.tick();
        check('pusta wersja: komunikat błędu', w.note.hidden === false);
    }

    // 3. Zmiana wersji: jedno pobranie fragmentu, bez nakładania się; fragment bez podmiany sekcji = błąd po 10 s i ponowna próba
    {
        const w = makeWorld();
        const section = w.section();
        w.Viz.Init(section);
        w.queue.push({ text: '11' });
        await w.tick();
        check('zmiana wersji: fragment pobrany raz', w.hxCalls === 1 && w.fetches.length === 1);
        await w.tick();
        await w.advance(1_000);
        await w.tick();
        check('fragment w drodze: kolejne ticki nie pytają o wersję ani nie pobierają fragmentu drugi raz', w.hxCalls === 1 && w.fetches.length === 1);
        check('fragment w drodze: brak komunikatu błędu', w.note.hidden === true);
        await w.advance(10_000);
        w.queue.push({ text: '11' });
        await w.tick();
        check('fragment bez podmiany sekcji w 10 s: komunikat błędu', w.note.hidden === false);
        check('fragment bez podmiany sekcji: ponowna próba pobrania', w.hxCalls === 2 && w.fetches.length === 2);
        w.Viz.Init(w.section());
        check('podmiana sekcji (Init nowej sekcji) chowa komunikat', w.note.hidden === true);
    }

    // 4. Ukryta karta i wyłączone „live” nie odpytują serwera
    {
        const w = makeWorld();
        w.Viz.Init(w.section());
        w.hidden = true;
        await w.tick();
        check('ukryta karta: bez zapytań', w.fetches.length === 0);
        w.hidden = false;
        const off = w.section();
        off.flag = () => false;
        w.Viz.Init(off);
        await w.tick();
        check('bez „live”: bez zapytań', w.fetches.length === 0);
    }

    // 5. Odłączona sekcja przestaje odpytywać i czyści swój interwał
    {
        const w = makeWorld();
        const section = w.section();
        w.Viz.Init(section);
        section.isConnected = false;
        await w.tick();
        check('odłączona sekcja: bez zapytań', w.fetches.length === 0);
        check('odłączona sekcja: czyści własny interwał', w.cleared.length === 1 && w.cleared[0] === section._timer);
    }

    if (failures.length) {
        console.log('FAIL (' + failures.length + ' z ' + checks + ' sprawdzeń)\n  ' + failures.join('\n  '));
        process.exit(1);
    }
    console.log('OK (' + checks + ' sprawdzeń)');
})().catch(error => {
    console.log('FAIL: wyjątek testu — ' + error.stack);
    process.exit(1);
});
