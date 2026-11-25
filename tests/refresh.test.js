'use strict';

/*
 * Test of the session view refresh behaviour (public/assets/app.js: tick): node without dependencies, called by tests/run.php.
 * app.js is loaded with stubs of global objects (virtual clock, controlled fetch, hx, document); every clock
 * tick is made by the test, so nothing really waits. Output: "OK (N checks)" and exit code 0, or a list of errors and exit code 1.
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

/** World of stubs: clock, timer queue, `fetch` requests (scenario queue), `hx` calls, error message. */
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
    /** Advances the clock, running timers on the way (e.g. the request timeout). */
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
    /** Response scenarios in request order: {hang}, {reject}, {status, text}; without an entry — 200 with version "10". */
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
    /** One "beat" of the section interval (every POLL_MS) and finishing the promises. */
    world.tick = async () => {
        world.intervals[world.intervals.length - 1]();
        await flush();
    };

    return world;
};

(async () => {
    // 1. Hung connection: after the timeout a message, `_busy` released, the next request starts
    {
        const w = makeWorld();
        const section = w.section();
        w.Viz.Init(section);
        w.queue.push({ hang: true });
        await w.tick();
        check('hung request: still pending, no note before the limit', w.fetches.length === 1 && w.note.hidden === true);
        await w.tick();
        check('hung request: the next tick does not start a second request', w.fetches.length === 1);
        await w.advance(4_900);
        check('hung request: still waiting before the 5 s limit', w.fetches.length === 1 && w.note.hidden === true);
        await w.advance(200);
        check('hung request: error note after the 5 s limit', w.note.hidden === false);
        await w.tick();
        check('hung request: after the limit the next tick sends a new request', w.fetches.length === 2);
        check('hung request: successful refresh hides the note', w.note.hidden === true);
    }

    // 2. Network error and HTTP ≠ 2xx: a message; a successful refresh without a version change hides it
    {
        const w = makeWorld();
        w.Viz.Init(w.section());
        w.queue.push({ reject: true });
        await w.tick();
        check('network error: error note', w.note.hidden === false);
        await w.tick();
        check('after a network error the first successful request hides the note', w.note.hidden === true && w.fetches.length === 2);
        // the error response body looks like a new version: HTTP 500 must not be treated as a data change
        w.queue.push({ status: 500, text: '99' });
        await w.tick();
        check('HTTP 500: error note', w.note.hidden === false);
        check('HTTP 500: does not start a fragment fetch', w.hxCalls === 0);
        w.queue.push({ status: 200, text: '' });
        await w.tick();
        check('empty version: error note', w.note.hidden === false);
    }

    // 3. Version change: one fragment fetch, no overlap; a fragment without a section swap = error after 10 s and a retry
    {
        const w = makeWorld();
        const section = w.section();
        w.Viz.Init(section);
        w.queue.push({ text: '11' });
        await w.tick();
        check('version change: fragment fetched once', w.hxCalls === 1 && w.fetches.length === 1);
        await w.tick();
        await w.advance(1_000);
        await w.tick();
        check('fragment in flight: further ticks neither ask for the version nor fetch the fragment again', w.hxCalls === 1 && w.fetches.length === 1);
        check('fragment in flight: no error note', w.note.hidden === true);
        await w.advance(10_000);
        w.queue.push({ text: '11' });
        await w.tick();
        check('fragment without section swap within 10 s: error note', w.note.hidden === false);
        check('fragment without section swap: fetch retried', w.hxCalls === 2 && w.fetches.length === 2);
        w.Viz.Init(w.section());
        check('section swap (Init of the new section) hides the note', w.note.hidden === true);
    }

    // 4. Hidden tab and disabled "live" do not poll the server
    {
        const w = makeWorld();
        w.Viz.Init(w.section());
        w.hidden = true;
        await w.tick();
        check('hidden tab: no requests', w.fetches.length === 0);
        w.hidden = false;
        const off = w.section();
        off.flag = () => false;
        w.Viz.Init(off);
        await w.tick();
        check('without "live": no requests', w.fetches.length === 0);
    }

    // 5. A detached section stops polling and clears its interval
    {
        const w = makeWorld();
        const section = w.section();
        w.Viz.Init(section);
        section.isConnected = false;
        await w.tick();
        check('detached section: no requests', w.fetches.length === 0);
        check('detached section: clears its own interval', w.cleared.length === 1 && w.cleared[0] === section._timer);
    }

    if (failures.length) {
        console.log('FAIL (' + failures.length + ' of ' + checks + ' checks)\n  ' + failures.join('\n  '));
        process.exit(1);
    }
    console.log('OK (' + checks + ' checks)');
})().catch(error => {
    console.log('FAIL: test exception — ' + error.stack);
    process.exit(1);
});
