/*
 * VizOffice — wirtualne biuro: agenci sesji jako postacie w stylu Minecrafta (three.js, bez assetów — tekstury rysowane w kodzie).
 * Sygnatura: templates/office.php. Stan agentów: GET /office.json (src/Office/OfficeState.php).
 * Moduł ES (three.js nie ma już buildu UMD), ładowany dynamicznie tylko w zakładce Office; API wystawione jako globalny VizOffice.
 */
import * as THREE from '/assets/vendor/three-0.183.2/three.module.min.js';

window.VizOffice = (() => {

    /** Autorefresh: co 1 s pełne pobranie stanu (jak przycisk „↻ refresh”); kolejne żądanie dopiero po zakończeniu poprzedniego (`root._busy`). */
    const POLL_MS = 1000;
    /** Stan bez odpowiedzi tak długo = błąd odświeżania; bez limitu zawieszone połączenie zostawiłoby `root._busy` na stałe. */
    const FETCH_TIMEOUT_MS = 5000;
    /** Jeden piksel skina. Postać ma proporcje Minecrafta: głowa 8, tułów 12, ręce i nogi 12 pikseli. */
    const P = 1 / 16;
    const SLOT_X = 3.2;
    const SLOT_Z = 3.4;
    /** Szybki chód: animacja dojścia nie może trwać dłużej niż stan, który pokazuje. */
    const WALK_SPEED = 5;
    const HIPS_SEATED = 0.6;
    const HIPS_STANDING = 0.75;

    const STATE_COLORS = { working: '#5fd35f', delegated: '#5fa8ff', idle: '#e0c050', stuck: '#ff4040' };
    const SCREEN_COLORS = { delegated: '#1d3b66', idle: '#2a3340', stuck: '#5a0d0d', off: '#050505' };
    /** Kolory sesji: wełna z Minecrafta, przydzielane kolejno (hash dawałby czasem dwa podobne kolory przy kilku sesjach). */
    const SESSION_COLORS = ['#f9801d', '#3ab3da', '#c74ebd', '#fed83d', '#80c71f', '#f38baa', '#169c9c', '#8932b8', '#3c44aa', '#835432', '#5e7c16', '#b02e26', '#9d9d97', '#474f52', '#f9fffe', '#1d1d21'];

    /** Krój postaci per rodzina modelu (OfficeState::persona). */
    const PERSONAS = {
        // stażystka: długie włosy z kucykiem, różowa bluzka, identyfikator na smyczy
        haiku: { skin: '#f1c27d', hair: '#d9a441', eyes: '#3c8d2f', shirt: '#e85d9e', pants: '#6fa8dc', shoes: '#f0f0f0',
            hairStyle: 'long', sleeves: 4, top: 'lanyard', ponytail: true, cheeks: true, scale: 0.9, desk: 'plant' },
        // junior: bluza z kapturem, słuchawki, energetyk na biurku
        sonnet: { skin: '#d9a066', hair: '#4a2f1b', eyes: '#5a3a1a', shirt: '#2e8b57', pants: '#3b3b4f', shoes: '#222222',
            hairStyle: 'short', sleeves: 10, top: 'hoodie', headphones: true, scale: 1, desk: 'can' },
        // senior nerd: broda, okulary, flanela na koszulce, kubek kawy
        opus: { skin: '#e0b088', hair: '#2b1d14', eyes: '#2a4a8a', shirt: '#b03a2e', pants: '#2c3e66', shoes: '#3b2a1a',
            hairStyle: 'receding', sleeves: 10, top: 'flannel', beard: '#3d2817', glasses: true, scale: 1.05, desk: 'mug' },
        default: { skin: '#c58b62', hair: '#3b2414', eyes: '#3a5fcd', shirt: '#00a8a8', pants: '#3c3cac', shoes: '#555555',
            hairStyle: 'short', sleeves: 4, top: 'plain', stubble: '#5a3a26', scale: 1, desk: null },
    };

    const TOOL_VERBS = { Read: 'reading', Edit: 'editing', Write: 'writing', NotebookEdit: 'editing', MultiEdit: 'editing', Bash: '$',
        Grep: 'searching', Glob: 'looking for', Agent: 'delegating', Task: 'delegating', SendMessage: 'messaging',
        WebSearch: 'googling', WebFetch: 'browsing', TodoWrite: 'planning' };

    // ---- tekstury rysowane w kodzie (canvas, piksele bez wygładzania) ----

    const rng = seed => () => {
        seed = (seed + 0x6D2B79F5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };

    const hash = text => [...text].reduce((h, c) => (Math.imul(h, 31) + c.charCodeAt(0)) | 0, 7);

    /** Kolor rozjaśniony / przyciemniony o k (−1…1). */
    const shade = (hex, k) => {
        const c = new THREE.Color(hex);
        return '#' + (k >= 0 ? c.lerp(new THREE.Color('#ffffff'), k) : c.lerp(new THREE.Color('#000000'), -k)).getHexString();
    };

    const texture = (w, h, paint, repeat = false, after = null) => {
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const g = canvas.getContext('2d');
        paint((x, y, color) => { g.fillStyle = color; g.fillRect(x, y, 1, 1); }, w, h);
        after?.(g);
        const t = new THREE.CanvasTexture(canvas);
        t.magFilter = THREE.NearestFilter;
        t.minFilter = THREE.NearestFilter;
        t.generateMipmaps = false;
        t.colorSpace = THREE.SRGBColorSpace;
        if (repeat) t.wrapS = t.wrapT = THREE.RepeatWrapping;
        return t;
    };

    /** Wypełnienie kolorem z lekkim szumem — piksele jak w Minecrafcie. */
    const grain = (px, w, h, color, rnd, amount = 0.06) => {
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) px(x, y, shade(color, (rnd() - 0.5) * amount * 2));
    };

    const OAK = { wood: ['#b8945f', '#a88654', '#9c7f4e', '#af8c59'], line: '#6b5130', seam: '#7a5c36' };
    const BIRCH = { wood: ['#d7cb8d', '#cfc283', '#c4b77a', '#dccf95'], line: '#a39662', seam: '#b0a26c' };

    const planks = (rnd, kind = OAK) => texture(16, 16, (px) => {
        for (let y = 0; y < 16; y++) for (let x = 0; x < 16; x++) px(x, y, kind.wood[Math.floor(rnd() * kind.wood.length)]);
        for (let row = 0; row < 4; row++) {
            for (let x = 0; x < 16; x++) px(x, row * 4 + 3, kind.line);
            const seam = (row * 7 + 3) % 16;
            for (let y = row * 4; y < row * 4 + 3; y++) px(seam, y, kind.seam);
        }
    }, true);

    const stoneBricks = rnd => texture(16, 16, (px) => {
        grain(px, 16, 16, '#8a8a8a', rnd, 0.1);
        for (let x = 0; x < 16; x++) { px(x, 7, '#5e5e5e'); px(x, 15, '#5e5e5e'); }
        for (let y = 0; y < 7; y++) px(7, y, '#5e5e5e');
        for (let y = 8; y < 15; y++) px(15, y, '#5e5e5e');
    }, true);

    const bookshelf = rnd => texture(16, 16, (px) => {
        grain(px, 16, 16, '#a88654', rnd);
        const books = ['#7a1f1f', '#1f4e7a', '#2f6b2f', '#7a5a1f', '#5a2f7a', '#2f6b6b'];
        for (const top of [2, 9]) {
            let x = 1;
            while (x < 15) {
                const color = books[Math.floor(rnd() * books.length)];
                const width = rnd() < 0.5 ? 1 : 2;
                const height = 4 + Math.floor(rnd() * 2);
                for (let i = 0; i < width && x + i < 15; i++) {
                    for (let y = top + 5 - height; y < top + 5; y++) px(x + i, y, color);
                    px(x + i, top + 5 - height, shade(color, 0.3));
                }
                x += width;
            }
        }
        for (let x = 0; x < 16; x++) { px(x, 0, '#6b5130'); px(x, 7, '#6b5130'); px(x, 8, '#8a6a3d'); px(x, 15, '#6b5130'); }
    });

    const door = rnd => texture(16, 32, (px) => {
        grain(px, 16, 32, '#4a3220', rnd, 0.08);
        for (let y = 0; y < 32; y++) { px(0, y, '#2e1f13'); px(15, y, '#2e1f13'); }
        for (let x = 0; x < 16; x++) { px(x, 0, '#2e1f13'); px(x, 31, '#2e1f13'); px(x, 15, '#2e1f13'); }
        for (let y = 3; y < 12; y++) for (let x = 3; x < 13; x++) px(x, y, x === 8 || y === 7 ? '#2e1f13' : '#9fc9e8');
        px(12, 18, '#c9a227');
        px(12, 19, '#c9a227');
    });

    const windowGlass = () => texture(16, 16, (px) => {
        for (let y = 0; y < 16; y++) for (let x = 0; x < 16; x++) {
            const frame = x === 0 || y === 0 || x === 15 || y === 15 || x === 7 || x === 8 || y === 7 || y === 8;
            px(x, y, frame ? '#e8e8e8' : (x + y) % 9 === 0 ? '#d6ecff' : '#9fd0f5');
        }
    });

    const codeScreen = rnd => {
        const t = texture(32, 32, (px) => {
            for (let y = 0; y < 32; y++) for (let x = 0; x < 32; x++) px(x, y, '#0b1a10');
            const colors = ['#5fd35f', '#7fe0ff', '#f0c060', '#d0d0d0', '#c080ff'];
            for (let y = 1; y < 32; y += 2) {
                let x = 1 + Math.floor(rnd() * 4) * 2;
                const end = x + 4 + Math.floor(rnd() * 20);
                while (x < Math.min(end, 31)) {
                    const color = colors[Math.floor(rnd() * colors.length)];
                    const len = 2 + Math.floor(rnd() * 5);
                    for (let i = 0; i < len && x < 31; i++, x++) px(x, y, color);
                    x++;
                }
            }
        });
        t.wrapS = t.wrapT = THREE.RepeatWrapping;
        return t;
    };

    // ---- skin postaci: każda ściana każdej części malowana osobno ----

    const fill = (px, w, h, color, rnd) => grain(px, w, h, color, rnd, 0.05);

    /** Głowa 8×8×8. `face`: front / back / top / bottom / side; `frontLeft` = przód postaci po lewej stronie tekstury ściany bocznej. */
    const paintHead = (p, face, frontLeft) => (px, w, h, rnd) => {
        if (face === 'top') return fill(px, w, h, p.hair, rnd);
        fill(px, w, h, p.skin, rnd);
        if (face === 'bottom') return;
        const hair = (x, y) => px(x, y, shade(p.hair, (rnd() - 0.5) * 0.12));
        if (face === 'back') {
            const rows = p.hairStyle === 'long' ? 8 : 6;
            for (let y = 0; y < rows; y++) for (let x = 0; x < 8; x++) hair(x, y);
            return;
        }
        if (face === 'side') {
            // u = odległość od przodu twarzy (0 = przód)
            const at = (u, y, color) => px(frontLeft ? u : 7 - u, y, color);
            for (let u = 0; u < 8; u++) {
                const rows = p.hairStyle === 'long' ? (u < 1 ? 3 : 8) : p.hairStyle === 'receding' ? (u < 3 ? 1 : 3) : (u < 2 ? 2 : 3);
                for (let y = 0; y < rows; y++) at(u, y, shade(p.hair, (rnd() - 0.5) * 0.12));
            }
            if (p.hairStyle !== 'long') at(4, 4, shade(p.skin, -0.15));
            if (p.beard) for (let u = 0; u < 4; u++) for (let y = u < 2 ? 3 : 4; y < 8; y++) at(u, y, shade(p.beard, (rnd() - 0.5) * 0.15));
            if (p.glasses) for (let u = 0; u < 4; u++) at(u, 4, '#1d1d1d');
            return;
        }
        // przód: włosy, oczy, usta, dodatki
        if (p.hairStyle === 'receding') {
            for (let x = 0; x < 8; x++) hair(x, 0);
            hair(0, 1); hair(7, 1);
        } else {
            for (let x = 0; x < 8; x++) { hair(x, 0); hair(x, 1); }
            hair(0, 2); hair(7, 2);
            if (p.hairStyle === 'long') {
                hair(1, 2); hair(6, 2);
                for (let y = 3; y < 8; y++) { hair(0, y); hair(7, y); }
            }
        }
        px(1, 4, '#ffffff'); px(2, 4, p.eyes); px(5, 4, p.eyes); px(6, 4, '#ffffff');
        px(3, 6, shade(p.skin, -0.35)); px(4, 6, shade(p.skin, -0.35));
        if (p.cheeks) { px(1, 5, '#f4a6a6'); px(6, 5, '#f4a6a6'); }
        if (p.stubble) for (let x = 2; x < 6; x++) px(x, 7, p.stubble);
        if (p.beard) {
            for (let y = 5; y < 8; y++) for (let x = 0; x < 8; x++) px(x, y, shade(p.beard, (rnd() - 0.5) * 0.15));
            px(3, 6, '#5a2d22'); px(4, 6, '#5a2d22');
        }
        if (p.glasses) {
            for (const x of [1, 2, 5, 6]) { px(x, 3, '#1d1d1d'); px(x, 5, '#1d1d1d'); }
            for (const x of [0, 3, 4, 7]) px(x, 4, '#1d1d1d');
            px(1, 4, '#cde8ff'); px(6, 4, '#cde8ff');
        }
    };

    /** Tułów 8×12×4. */
    const paintBody = (p, face) => (px, w, h, rnd) => {
        if (face === 'bottom') return fill(px, w, h, p.pants, rnd);
        const dark = shade(p.shirt, -0.45);
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
            const check = p.top === 'flannel' && ((x % 4 < 2) !== (y % 4 < 2));
            px(x, y, shade(check ? dark : p.shirt, (rnd() - 0.5) * 0.08));
        }
        if (face === 'top') return;
        if (face === 'front') {
            if (p.top === 'flannel') for (let y = 0; y < h; y++) { px(3, y, '#e8e8e8'); px(4, y, '#e8e8e8'); }
            if (p.top === 'plain') { px(3, 0, p.skin); px(4, 0, p.skin); }
            if (p.top === 'hoodie') {
                for (let x = 1; x < 7; x++) px(x, 0, dark);
                for (let y = 1; y < 5; y++) { px(3, y, '#f0f0f0'); px(4, y, '#f0f0f0'); }
                for (let y = 7; y < 11; y++) for (let x = 1; x < 7; x++) px(x, y, shade(p.shirt, -0.2));
            }
            if (p.top === 'lanyard') {
                px(2, 0, '#2050c0'); px(5, 0, '#2050c0'); px(2, 1, '#2050c0'); px(5, 1, '#2050c0');
                px(3, 2, '#2050c0'); px(4, 2, '#2050c0'); px(3, 3, '#2050c0'); px(4, 3, '#2050c0');
                for (let y = 4; y < 7; y++) for (let x = 3; x < 6; x++) px(x, y, '#ffffff');
                px(4, 5, '#2050c0');
            }
        }
        if (face === 'back' && p.top === 'hoodie') for (let y = 0; y < 3; y++) for (let x = 1; x < 7; x++) px(x, y, dark);
        for (let x = 0; x < w; x++) px(x, h - 1, shade(p.pants, -0.3));
    };

    /** Ręka 4×12×4: rękaw (`sleeves` pikseli od góry), dalej skóra. */
    const paintArm = (p, face) => (px, w, h, rnd) => {
        if (face === 'top') return fill(px, w, h, p.shirt, rnd);
        if (face === 'bottom') return fill(px, w, h, p.skin, rnd);
        fill(px, w, h, p.skin, rnd);
        const dark = shade(p.shirt, -0.45);
        for (let y = 0; y < p.sleeves; y++) for (let x = 0; x < w; x++) {
            const check = p.top === 'flannel' && ((x % 4 < 2) !== (y % 4 < 2));
            px(x, y, shade(check ? dark : p.shirt, (rnd() - 0.5) * 0.08));
        }
    };

    /** Noga 4×12×4: spodnie, buty w dwóch ostatnich rzędach. */
    const paintLeg = (p, face) => (px, w, h, rnd) => {
        if (face === 'top') return fill(px, w, h, p.pants, rnd);
        if (face === 'bottom') return fill(px, w, h, p.shoes, rnd);
        fill(px, w, h, p.pants, rnd);
        for (let y = h - 2; y < h; y++) for (let x = 0; x < w; x++) px(x, y, shade(p.shoes, (rnd() - 0.5) * 0.08));
    };

    const materialCache = new Map();

    /**
     * Sześć materiałów dla prostopadłościanu w kolejności BoxGeometry: +x, −x, +y, −y, +z (przód), −z (tył).
     * `shirt` (kolor projektu) nadpisuje kolor koszulki persony — tylko tułów i ręce; materiały są wspólne dla postaci o tej samej personie i koszulce.
     */
    const skinMaterials = (persona, part, dims, painter, shirt) => {
        const key = persona + ':' + part + (shirt ? ':' + shirt : '');
        if (materialCache.has(key)) return materialCache.get(key);
        const p = shirt ? { ...PERSONAS[persona], shirt } : PERSONAS[persona];
        const [w, h, d] = dims;
        const faces = [['side', d, h, true], ['side', d, h, false], ['top', w, d], ['bottom', w, d], ['front', w, h], ['back', w, h]];
        const mats = faces.map(([face, fw, fh, frontLeft], i) => {
            const rnd = rng(hash(key) + i);
            return new THREE.MeshLambertMaterial({ map: texture(fw, fh, (px) => painter(p, face, frontLeft)(px, fw, fh, rnd)) });
        });
        materialCache.set(key, mats);
        return mats;
    };

    const box = (w, h, d, material) => new THREE.Mesh(new THREE.BoxGeometry(w, h, d), material);
    const solid = color => new THREE.MeshLambertMaterial({ color });

    // ---- postać ----

    /**
     * Hierarchia: root (pozycja na podłodze, obrót) → hips (wysokość bioder) → torso (pochylenie) → głowa, ręce; nogi przy biodrach.
     * Osie: postać patrzy w +z; rotation.x ujemne podnosi ręce/nogi do przodu, dodatnie pochyla tułów i głowę do przodu.
     */
    const buildCharacter = (persona, shirt) => {
        const p = PERSONAS[persona];
        const root = new THREE.Group();
        const hips = new THREE.Group();
        const torso = new THREE.Group();
        root.add(hips);
        hips.add(torso);

        const body = box(8 * P, 12 * P, 4 * P, skinMaterials(persona, 'body', [8, 12, 4], paintBody, shirt));
        body.position.y = 6 * P;
        torso.add(body);

        const head = new THREE.Group();
        head.position.y = 12 * P;
        torso.add(head);
        const skull = box(8 * P, 8 * P, 8 * P, skinMaterials(persona, 'head', [8, 8, 8], paintHead));
        skull.position.y = 4 * P;
        head.add(skull);
        if (p.ponytail) {
            const tail = box(2 * P, 7 * P, 2 * P, solid(shade(p.hair, -0.1)));
            tail.position.set(0, 1.5 * P, -5 * P);
            tail.rotation.x = -0.35;
            head.add(tail);
            head.userData.tail = tail;
        }
        if (p.headphones) {
            const band = solid('#2b2b2b');
            const cup = solid('#c0392b');
            const top = box(10 * P, 1 * P, 2 * P, band);
            top.position.y = 8.5 * P;
            head.add(top);
            for (const side of [-1, 1]) {
                const strap = box(1 * P, 4 * P, 2 * P, band);
                strap.position.set(side * 4.5 * P, 6.5 * P, 0);
                head.add(strap);
                const ear = box(1.5 * P, 3 * P, 3 * P, cup);
                ear.position.set(side * 4.6 * P, 3.5 * P, 0);
                head.add(ear);
            }
        }
        if (p.beard) {
            const chin = box(8 * P, 2 * P, 1.5 * P, solid(p.beard));
            chin.position.set(0, -0.6 * P, 3.6 * P);
            head.add(chin);
        }
        // kotwica podpisu nad głową
        const anchor = new THREE.Object3D();
        anchor.position.y = (p.headphones ? 10 : 9) * P;
        head.add(anchor);

        const limb = (part, painter, x, y, offset, parent) => {
            const pivot = new THREE.Group();
            pivot.position.set(x, y, 0);
            const mesh = box(4 * P, 12 * P, 4 * P, skinMaterials(persona, part, [4, 12, 4], painter, part === 'arm' ? shirt : undefined));
            mesh.position.y = offset;
            pivot.add(mesh);
            parent.add(pivot);
            return pivot;
        };
        // prawa ręka postaci jest po stronie −x (postać patrzy w +z)
        const armR = limb('arm', paintArm, -6 * P, 10 * P, -4 * P, torso);
        const armL = limb('arm', paintArm, 6 * P, 10 * P, -4 * P, torso);
        const legR = limb('leg', paintLeg, -2 * P, 0, -6 * P, hips);
        const legL = limb('leg', paintLeg, 2 * P, 0, -6 * P, hips);

        // łopata w prawej ręce — widoczna tylko przy kopaniu za murem; ostrze skierowane w dół, przed postać
        const shovel = new THREE.Group();
        const handle = box(1 * P, 12 * P, 1 * P, solid('#8a6a3d'));
        handle.position.y = -2 * P;
        const blade = box(3 * P, 4 * P, 1 * P, solid('#c8c8c8'));
        blade.position.y = -9 * P;
        shovel.add(handle, blade);
        shovel.position.set(0, -10 * P, 1 * P);
        shovel.rotation.x = Math.PI / 2;
        shovel.visible = false;
        armR.add(shovel);
        // dłoń: stąd lecą pieniądze
        const hand = new THREE.Object3D();
        hand.position.y = -10 * P;
        armR.add(hand);

        root.rotation.order = 'YXZ';
        root.scale.setScalar(p.scale);
        return { root, hips, torso, head, armR, armL, legR, legL, anchor, shovel, hand };
    };

    const deskProp = kind => {
        const g = new THREE.Group();
        if (kind === 'mug') {
            const mug = box(3 * P, 4 * P, 3 * P, solid('#f2f2f2'));
            mug.position.y = 2 * P;
            const stripe = box(3.1 * P, 1 * P, 3.1 * P, solid('#c0392b'));
            stripe.position.y = 2.5 * P;
            const handle = box(1 * P, 2 * P, 1 * P, solid('#f2f2f2'));
            handle.position.set(2 * P, 2 * P, 0);
            g.add(mug, stripe, handle);
        } else if (kind === 'can') {
            const can = box(2 * P, 4 * P, 2 * P, solid('#1f1f1f'));
            can.position.y = 2 * P;
            const logo = box(2.1 * P, 2 * P, 2.1 * P, solid('#7cfc00'));
            logo.position.y = 2 * P;
            g.add(can, logo);
        } else if (kind === 'plant') {
            const pot = box(3 * P, 3 * P, 3 * P, solid('#a0522d'));
            pot.position.y = 1.5 * P;
            const leaves = box(4 * P, 4 * P, 4 * P, solid('#3f9b3f'));
            leaves.position.y = 5 * P;
            const flower = box(1.5 * P, 1.5 * P, 1.5 * P, solid('#ff69b4'));
            flower.position.set(1 * P, 7.5 * P, 1 * P);
            g.add(pot, leaves, flower);
        }
        return g;
    };

    // ---- biuro ----

    /** Pas trawy za prawym murem — tam chodzą agenci, którzy wyszli poza granicę. */
    const OUTSIDE = 6;
    const WALL_HIGH = 3;
    const WALL_LOW = 1;
    const MAX_PARTICLES = 500;
    /** Monety: jedna za każde `coinUsd` wydane od poprzedniego odświeżenia, rozłożone na czas do następnego (min. 3/s, maks. 15/s). */
    const COIN_RATE = [3, 15];
    const COIN_QUEUE = 40;
    /** Rzut kopertą: obrót do adresata, zamach, wyrzut w połowie, powrót; lot łukiem; notka u adresata. */
    const THROW_TIME = 1.0;
    const FLIGHT_TIME = 0.9;
    const NOTE_MS = 5000;
    /** Wiadomość, której adresata jeszcze nie ma w biurze (subagent wchodzi), czeka tyle; potem przepada. */
    const PENDING_MS = 10000;
    const MAX_HOLES = 40;
    /** Pas archiwum przy tylnym murze: szafy na akta = pliki Claude Code (~/.claude, /tmp/claude-*), nadal w biurze. */
    const ARCHIVE_DEPTH = 1.6;
    /** Wspinanie na lewy mur: 1,4 s w górę, 0,5 s spadania, reszta — siedzi oszołomiony. */
    const CLIMB_CYCLE = 3.2;
    /** Wyjście z biura: macha na miejscu, potem powoli znika (bez chodzenia do drzwi). */
    const BYE_WAVE = 1.5;
    const BYE_FADE = 1.2;

    const cabinet = rnd => texture(16, 24, (px) => {
        grain(px, 16, 24, '#9aa1a8', rnd, 0.05);
        for (const top of [1, 9, 17]) {
            for (let x = 1; x < 15; x++) { px(x, top, '#6d747b'); px(x, top + 6, '#6d747b'); }
            for (let y = top; y < top + 7; y++) { px(1, y, '#6d747b'); px(14, y, '#6d747b'); }
            for (let x = 6; x < 10; x++) px(x, top + 2, '#2f3337');
            for (let x = 5; x < 11; x++) px(x, top + 4, '#f0e6c8');
        }
    });

    /** Tabliczka jak znak z Minecrafta: dębowa deska z napisem. */
    const sign = (rnd, lines) => texture(144, 60, (px) => {
        const oak = ['#b8945f', '#a88654', '#af8c59'];
        for (let y = 0; y < 60; y++) for (let x = 0; x < 144; x++) px(x, y, oak[Math.floor(rnd() * oak.length)]);
        for (let x = 0; x < 144; x++) { px(x, 0, '#6b5130'); px(x, 59, '#6b5130'); }
        for (let y = 0; y < 60; y++) { px(0, y, '#6b5130'); px(143, y, '#6b5130'); }
    }, false, (g) => {
        g.fillStyle = '#2b1d14';
        g.textAlign = 'center';
        g.font = 'bold 20px monospace';
        g.fillText(lines[0], 72, 26);
        g.font = '11px monospace';
        g.fillText(lines[1], 72, 46);
    });

    const grassTop = rnd => texture(16, 16, (px) => {
        const greens = ['#5d9b3a', '#6aaa45', '#4f8a31', '#74b44c', '#5a9637'];
        for (let y = 0; y < 16; y++) for (let x = 0; x < 16; x++) px(x, y, greens[Math.floor(rnd() * greens.length)]);
    }, true);

    const dirt = rnd => texture(16, 16, (px) => {
        const browns = ['#7a5534', '#6b4a2b', '#86603c', '#5e3f24', '#8f6a45'];
        for (let y = 0; y < 16; y++) for (let x = 0; x < 16; x++) px(x, y, browns[Math.floor(rnd() * browns.length)]);
    });

    const mossy = rnd => texture(16, 16, (px) => {
        grain(px, 16, 16, '#8a8a8a', rnd, 0.1);
        for (let x = 0; x < 16; x++) { px(x, 7, '#5e5e5e'); px(x, 15, '#5e5e5e'); }
        for (let y = 0; y < 7; y++) px(7, y, '#5e5e5e');
        for (let y = 8; y < 15; y++) px(15, y, '#5e5e5e');
        for (let i = 0; i < 40; i++) px(Math.floor(rnd() * 16), Math.floor(rnd() * 16), rnd() < 0.5 ? '#4f7a32' : '#5e8f3a');
    });

    const shared = {};

    const sharedTextures = () => {
        if (shared.ready) return shared;
        const rnd = rng(42);
        shared.planks = planks(rnd);
        shared.birch = planks(rnd, BIRCH);
        shared.stone = stoneBricks(rnd);
        shared.mossy = mossy(rnd);
        shared.grass = grassTop(rnd);
        shared.dirt = dirt(rnd);
        shared.bookshelf = bookshelf(rnd);
        shared.door = door(rnd);
        shared.window = windowGlass();
        shared.code = codeScreen(rnd);
        shared.cabinet = cabinet(rnd);
        shared.sign = sign(rnd, ['ARCHIVE', '~/.claude  /tmp/claude-*']);
        shared.ready = true;
        return shared;
    };

    const repeated = (base, x, y) => {
        const t = base.clone();
        t.repeat.set(x, y);
        t.needsUpdate = true;
        return t;
    };

    /** Biurko z krzesłem i monitorem; środek układu = miejsce siedzącej postaci, która patrzy w +z. */
    const buildDesk = tex => {
        const g = new THREE.Group();
        // blat z brzozy — dąb podłogi zlewałby się z biurkiem
        const wood = new THREE.MeshLambertMaterial({ map: tex.birch });
        const top = box(1.8, 0.06, 0.9, wood);
        top.position.set(0, 0.8, 0.9);
        g.add(top);
        for (const [x, z] of [[-0.82, 0.5], [0.82, 0.5], [-0.82, 1.3], [0.82, 1.3]]) {
            const leg = box(0.08, 0.77, 0.08, wood);
            leg.position.set(x, 0.385, z);
            g.add(leg);
        }
        const plastic = solid('#2b2b2b');
        const stand = box(0.08, 0.14, 0.08, plastic);
        stand.position.set(0, 0.9, 1.2);
        const screenMat = new THREE.MeshBasicMaterial({ color: SCREEN_COLORS.off });
        // ekran patrzy na postać (−z), obudowa i dioda stanu — w stronę kamery
        const monitor = new THREE.Mesh(new THREE.BoxGeometry(0.8, 0.5, 0.05), [plastic, plastic, plastic, plastic, plastic, screenMat]);
        monitor.position.set(0, 1.2, 1.2);
        const led = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.06, 0.02), new THREE.MeshBasicMaterial({ color: SCREEN_COLORS.off }));
        led.position.set(0.32, 1.0, 1.235);
        const keyboard = box(0.6, 0.03, 0.18, solid('#d8d8d8'));
        keyboard.position.set(0, 0.845, 0.62);
        g.add(stand, monitor, led, keyboard);

        const fabric = solid('#3f3f3f');
        const seat = box(0.7, 0.08, 0.7, fabric);
        seat.position.set(0, 0.44, -0.05);
        const back = box(0.7, 0.7, 0.08, fabric);
        back.position.set(0, 0.8, -0.42);
        const stem = box(0.08, 0.4, 0.08, plastic);
        stem.position.set(0, 0.2, -0.05);
        const base = box(0.6, 0.05, 0.6, plastic);
        base.position.set(0, 0.025, -0.05);
        g.add(seat, back, stem, base);

        const propSpot = new THREE.Object3D();
        propSpot.position.set(0.6, 0.83, 0.75);
        g.add(propSpot);
        return { group: g, screenMat, led, propSpot };
    };

    /**
     * Pokój na `rows` × `cols` biurek (rząd = sesja) otoczony murem grubości jednego bloku: tylny i lewy mur wysokie (okna, drzwi dla subagentów),
     * przedni i prawy niskie z blankami — kamera widzi przez nie do środka i na trawę za prawym murem (poza granicą).
     * Ruch postaci tylko korytarzami: rzędy za krzesłami (`aisles`) i kolumny między biurkami (`gaps`), więc nikt nie idzie przez blat.
     */
    const buildRoom = (rows, cols) => {
        const tex = sharedTextures();
        // wnętrze ma całkowitą liczbę bloków, żeby mur leżał w siatce Minecrafta
        const width = Math.ceil(cols * SLOT_X + 2.4);
        const depth = Math.ceil(rows * SLOT_Z + 2.6 + ARCHIVE_DEPTH);
        const hw = width / 2;
        const hd = depth / 2;
        const group = new THREE.Group();

        const floor = new THREE.Mesh(new THREE.PlaneGeometry(width, depth), new THREE.MeshLambertMaterial({ map: repeated(tex.planks, width, depth) }));
        floor.rotation.x = -Math.PI / 2;
        group.add(floor);
        // trawa daleko poza kadr — jej brzeg ginie w mgle w kolorze tła
        const gw = width + 2 + OUTSIDE * 2 + 60;
        const gd = depth + 2 + OUTSIDE * 2 + 60;
        const grass = new THREE.Mesh(new THREE.PlaneGeometry(gw, gd), new THREE.MeshLambertMaterial({ map: repeated(tex.grass, gw, gd) }));
        grass.rotation.x = -Math.PI / 2;
        grass.position.y = -0.002;
        group.add(grass);

        // rząd biurek = jedna sesja (sesja główna + jej subagenci); dywan pod rzędem w kolorze sesji
        const desks = [];
        const centersX = [];
        const rowsZ = [];
        const rugs = [];
        for (let i = 0; i < rows * cols; i++) {
            const col = i % cols;
            const row = Math.floor(i / cols);
            const desk = buildDesk(tex);
            desk.group.position.set((col - (cols - 1) / 2) * SLOT_X + 0.5, 0, (row - (rows - 1) / 2) * SLOT_Z + 0.2 + ARCHIVE_DEPTH / 2);
            group.add(desk.group);
            desks.push(desk);
            if (row === 0) centersX.push(desk.group.position.x);
            if (col === 0) rowsZ.push(desk.group.position.z);
        }
        for (const z of rowsZ) {
            // tylko lekki odcień na deskach podłogi — dywan nie może zagłuszać postaci
            const rug = new THREE.Mesh(new THREE.PlaneGeometry(cols * SLOT_X - 0.6, 2.2),
                new THREE.MeshLambertMaterial({ color: '#ffffff', transparent: true, opacity: 0.22, depthWrite: false }));
            rug.rotation.x = -Math.PI / 2;
            rug.position.set(centersX.reduce((a, x) => a + x, 0) / cols, 0.003, z + 0.45);
            rug.visible = false;
            group.add(rug);
            rugs.push(rug);
        }
        const aisles = rowsZ.map(z => z - 1.3).concat([rowsZ[rowsZ.length - 1] + 2.0]);
        const gaps = [-hw + 0.6, hw - 0.6, ...centersX.flatMap(x => [x - SLOT_X / 2, x + SLOT_X / 2])]
            .filter(x => x > -hw + 0.5 && x < hw - 0.5);
        // drzwi w prawym (niskim) murze przy przednim korytarzu — od strony kamery i trawy, żeby widać było wejścia i wyjścia
        const frontAisle = aisles[aisles.length - 1];
        const doorZ = Math.min(hd - 0.5, Math.round(frontAisle - 0.5 + hd) - hd + 0.5);

        // mur z bloków: kamienna cegła, część z mchem
        const blocks = [];
        const wall = (x, z, height, crenel) => {
            for (let y = 0; y < height; y++) blocks.push([x, y, z]);
            if (crenel) blocks.push([x, height, z]);
        };
        // okna: po dwa bloki tylnego muru (indeksy pierwszego bloku), na 1/4 i 3/4 długości
        const windows = [Math.floor((width + 2) / 4), Math.floor(3 * (width + 2) / 4) - 1];
        for (let i = 0; i < width + 2; i++) {
            const x = -hw - 0.5 + i;
            for (let y = 0; y < WALL_HIGH; y++) {
                if (y === 1 && windows.some(w => i === w || i === w + 1)) continue;
                blocks.push([x, y, -hd - 0.5]);
            }
            wall(x, hd + 0.5, WALL_LOW, i % 2 === 0);
        }
        for (let i = 0; i < depth; i++) {
            const z = -hd + 0.5 + i;
            for (let y = 0; y < WALL_HIGH; y++) blocks.push([-hw - 0.5, y, z]);
            if (Math.abs(z - doorZ) > 0.01) wall(hw + 0.5, z, WALL_LOW, i % 2 === 0);
        }
        const rnd = rng(7);
        const plain = blocks.filter(() => rnd() > 0.15);
        const moss = blocks.filter(b => !plain.includes(b));
        const cube = new THREE.BoxGeometry(1, 1, 1);
        const m = new THREE.Matrix4();
        for (const [list, map] of [[plain, tex.stone], [moss, tex.mossy]]) {
            const mesh = new THREE.InstancedMesh(cube, new THREE.MeshLambertMaterial({ map }), list.length);
            list.forEach(([x, y, z], i) => mesh.setMatrixAt(i, m.makeTranslation(x, y + 0.5, z)));
            group.add(mesh);
        }
        const glass = new THREE.MeshLambertMaterial({ map: tex.window, emissive: '#3a6f9a' });
        for (const w of windows) {
            const win = new THREE.Mesh(new THREE.BoxGeometry(2, 1, 0.2), glass);
            win.position.set(-hw + w, 1.5, -hd - 0.5);
            group.add(win);
        }
        // otwarte drzwi: skrzydło odchylone na zewnątrz, słupki po bokach przejścia
        const doorMesh = new THREE.Mesh(new THREE.BoxGeometry(1, 2, 0.12), new THREE.MeshLambertMaterial({ map: tex.door }));
        doorMesh.position.set(hw + 1.5, 1, doorZ - 0.44);
        group.add(doorMesh);
        for (const side of [-1, 1]) {
            const post = new THREE.Mesh(new THREE.BoxGeometry(1, 2.5, 0.25), new THREE.MeshLambertMaterial({ map: tex.planks }));
            post.position.set(hw + 0.5, 1.25, doorZ + side * 0.62);
            group.add(post);
        }
        const lintel = new THREE.Mesh(new THREE.BoxGeometry(1, 0.3, 1.5), new THREE.MeshLambertMaterial({ map: tex.planks }));
        lintel.position.set(hw + 0.5, 2.35, doorZ);
        group.add(lintel);

        // archiwum: rząd szaf na akta wzdłuż tylnego muru (regały z książkami po prawej też do niego należą)
        const cabinetMats = [solid('#8a9097'), solid('#8a9097'), solid('#b0b6bc'), solid('#8a9097'), new THREE.MeshLambertMaterial({ map: tex.cabinet }), solid('#8a9097')];
        const archiveSpots = [];
        for (let x = -hw + 1.5; x <= hw - 3.5; x += 1) {
            const cab = new THREE.Mesh(new THREE.BoxGeometry(0.9, 1.35, 0.7), cabinetMats);
            cab.position.set(x, 0.675, -hd + 0.36);
            group.add(cab);
            archiveSpots.push(x);
        }
        const signMesh = new THREE.Mesh(new THREE.PlaneGeometry(2.4, 1), new THREE.MeshLambertMaterial({ map: tex.sign }));
        signMesh.scale.y = 2.4 / (144 / 60);
        signMesh.position.set(archiveSpots.length ? (archiveSpots[0] + archiveSpots[archiveSpots.length - 1]) / 2 : 0, 2.35, -hd + 0.02);
        group.add(signMesh);
        const archiveZ = -hd + 1.25;

        const shelfMats = [solid('#8a6a3d'), solid('#8a6a3d'), solid('#a88654'), solid('#a88654'), new THREE.MeshLambertMaterial({ map: tex.bookshelf }), solid('#8a6a3d')];
        for (let i = 0; i < 2; i++) for (let level = 0; level < 2; level++) {
            const shelf = new THREE.Mesh(new THREE.BoxGeometry(1, 1, 0.5), shelfMats);
            shelf.position.set(hw - 0.5 - i, 0.5 + level, -hd + 0.25);
            group.add(shelf);
        }

        return {
            group, desks, rugs, rows, cols, width, depth, gaps, archiveSpots, archiveZ,
            aisles: [archiveZ, ...aisles],
            doorIn: new THREE.Vector3(hw - 0.6, 0, doorZ),
            doorOut: new THREE.Vector3(hw + 1.8, 0, doorZ),
            farOut: new THREE.Vector3(hw + OUTSIDE - 0.3, 0, doorZ),
            // korytarze pod lewym murem (bez rzędu archiwum, żeby nie stać w szafach)
            wallAisles: aisles,
            wallX: hw + 0.5,
            // pod wysokim lewym murem: napieranie (trwa) i wspinanie ze spadaniem (zablokowane)
            leftX: -hw + 0.32,
            outside: { x0: hw + 1.8, x1: hw + OUTSIDE - 0.5, z0: -hd + 0.3, z1: hd - 0.3 },
        };
    };

    const disposeTree = obj => obj.traverse(o => {
        o.geometry?.dispose();
        [].concat(o.material || []).forEach(m => {
            if (m.map && !Object.values(shared).includes(m.map)) m.map.dispose();
            m.dispose();
        });
    });

    // ---- ruch: cel wynika ze stanu agenta, droga — z korytarzy ----

    const V = (x, z) => new THREE.Vector3(x, 0, z);
    const nearest = (list, v) => list.reduce((best, x) => Math.abs(x - v) < Math.abs(best - v) ? x : best, list[0]);

    /**
     * Czego postać chce teraz: pracuje = siedzi i klepie w klawiaturę; czeka (na usera / na subagentów) = stoi przy biurku;
     * próba wyjścia poza granicę = wspina się na lewy mur i spada; wykonane poza granicą = kopie na trawie; nieaktywny = macha i znika na miejscu (`bye`).
     */
    const desired = c => {
        if (c.agent.boundary?.kind === 'outside') return 'outside';
        if (c.agent.boundary) return 'climb';
        if (c.agent.archive && c.agent.state === 'working') return 'archive';
        if (c.agent.state === 'idle' || c.agent.state === 'delegated') return 'stand';
        return 'seat';
    };

    /** Droga po korytarzach: poziomo tylko rzędem (`aisles`), pionowo tylko kolumną (`gaps`). */
    const route = (room, from, to) => {
        if (Math.abs(from.z - to.z) < 0.05) return [to];
        const gx = nearest(room.gaps, from.x);
        return [V(gx, from.z), V(gx, to.z), to];
    };

    /** Biurko postaci: rząd = sesja, kolumna = miejsce w sesji (0 = sesja główna). */
    const deskOf = (room, c) => room.desks[c.row * room.cols + c.col];
    const aisleOf = (room, c) => deskOf(room, c).group.position.z - 1.3;

    /** Miejsce obok biurka (po prawej, między blatem a kolumną przejścia) — tu stoi ten, kto czeka. */
    const standSpot = (room, c) => {
        const p = deskOf(room, c).group.position;
        return V(p.x + 1.2, p.z + 0.2);
    };

    const randomOutside = room => {
        const o = room.outside;
        return V(o.x0 + Math.random() * (o.x1 - o.x0), o.z0 + Math.random() * (o.z1 - o.z0));
    };

    const plan = (office, c, goal) => {
        const room = office.room;
        const pos = V(c.root.position.x, c.root.position.z);
        const chair = deskOf(room, c).group.position;
        const pts = [];
        if (c.mode === 'climb') {
            c.root.rotation.x = 0;
            c.root.position.y = 0;
        }
        if (c.mode === 'seated') pts.push(V(chair.x, aisleOf(room, c)));
        const out = pos.x > room.wallX;
        // z trawy do środka zawsze drzwiami (także nowy agent)
        if (out && goal !== 'outside') pts.push(room.doorOut, room.doorIn);
        const from = pts.length ? pts[pts.length - 1] : pos;
        if (goal === 'seat') pts.push(...route(room, from, V(chair.x, aisleOf(room, c))), V(chair.x, chair.z));
        if (goal === 'stand') {
            const spot = standSpot(room, c);
            pts.push(...route(room, from, V(spot.x, aisleOf(room, c))), spot);
        }
        if (goal === 'archive') pts.push(...route(room, from, V(room.archiveSpots[(c.row * room.cols + c.col) % room.archiveSpots.length], room.archiveZ)));
        if (goal === 'climb') pts.push(...route(room, from, V(room.leftX, nearest(room.wallAisles, from.z))));
        if (goal === 'outside') {
            // wykonane poza granicą (user się zgodził) — wychodzi zwyczajnie drzwiami i kopie w trawie
            if (!out) pts.push(...route(room, from, room.doorIn), room.doorOut);
            pts.push(randomOutside(room));
        }
        c.prop?.removeFromParent();
        c.path = pts;
        c.goal = goal;
        c.mode = 'walking';
        c.shovel.visible = false;
    };

    const arrive = (office, c) => {
        if (c.goal === 'seat') return seat(office, c);
        if (c.goal === 'climb') {
            c.mode = 'climb';
            c.root.rotation.y = -Math.PI / 2;
            c.base = c.root.position.x;
            c.cycle = 0;
            return;
        }
        if (c.goal === 'archive') {
            c.mode = 'file';
            c.root.rotation.y = Math.PI;
            return;
        }
        if (c.goal === 'outside') {
            c.mode = 'dig';
            c.timer = 3 + Math.random() * 4;
            c.shovel.visible = true;
            c.root.rotation.y = Math.random() * Math.PI * 2;
            addHole(office, c);
            return;
        }
        // stand: przodem do kamery, żeby podniesiona ręka była widoczna
        c.mode = 'stand';
        c.root.rotation.y = 0;
    };

    const walk = (c, dt) => {
        const next = c.path[0];
        const pos = c.root.position;
        const dx = next.x - pos.x;
        const dz = next.z - pos.z;
        const dist = Math.hypot(dx, dz);
        const step = WALK_SPEED * dt;
        if (dist <= step) {
            pos.set(next.x, 0, next.z);
            c.path.shift();
            return c.path.length === 0;
        }
        pos.x += dx / dist * step;
        pos.z += dz / dist * step;
        c.root.rotation.y = Math.atan2(dx, dz);
        return false;
    };

    /** Pożegnanie: własne kopie materiałów (te z `materialCache` dzielą wszystkie postacie danej persony), żeby wygasić tylko tę postać. */
    const startBye = c => {
        c.mode = 'bye';
        c.goal = 'leave';
        c.path = [];
        c.timer = 0;
        c.shovel.visible = false;
        c.root.position.y = 0;
        c.root.rotation.set(0, 0, 0);
        c.fadeMats = [];
        const own = m => {
            const copy = m.clone();
            copy.transparent = true;
            c.fadeMats.push(copy);
            return copy;
        };
        for (const part of [c.root, c.prop]) {
            part?.traverse(o => {
                if (o.isMesh) o.material = Array.isArray(o.material) ? o.material.map(own) : own(o.material);
            });
        }
    };

    const bye = (office, c, dt) => {
        if (c.mode !== 'bye') startBye(c);
        c.timer += dt;
        const alpha = 1 - Math.min(1, Math.max(0, (c.timer - BYE_WAVE) / BYE_FADE));
        for (const m of c.fadeMats) m.opacity = alpha;
        c.label.el.style.opacity = alpha;
        if (alpha <= 0) remove(office, c.agent.id);
    };

    const behave = (office, c, dt, t) => {
        if (c.leaving) return bye(office, c, dt);
        const goal = desired(c);
        if (goal !== c.goal) plan(office, c, goal);
        if (c.mode === 'walking') {
            if (walk(c, dt)) arrive(office, c);
            return;
        }
        if (c.mode === 'climb') {
            // cykl: wspina się po murze → spada → siedzi oszołomiony → od nowa
            c.cycle = (c.cycle + dt) % CLIMB_CYCLE;
            const k = c.cycle;
            if (k < 1.4) {
                c.root.position.set(c.base, k / 1.4 * 1.6, c.root.position.z);
            } else if (k < 1.9) {
                const f = (k - 1.4) / 0.5;
                c.root.position.set(c.base + f * 0.7, Math.max(0, 1.6 * (1 - f * f)), c.root.position.z);
            } else {
                c.root.position.y = 0;
                if (k > CLIMB_CYCLE - 0.4) c.root.position.x = c.base + 0.7 * (CLIMB_CYCLE - k) / 0.4;
            }
            return;
        }
        if (c.mode === 'dig') {
            c.timer -= dt;
            if ((c.dirtAcc = (c.dirtAcc || 0) + dt) > 0.35) {
                c.dirtAcc = 0;
                spawnDirt(office, c);
            }
            if (c.timer <= 0) {
                plan(office, c, c.goal);
            }
        }
    };

    // ---- poza: kąty stawów zależne od stanu i czynności ----

    const pose = (c, t) => {
        const a = c.agent;
        const seed = c.phase;
        const stand = { torso: 0, headX: 0, headY: 0, headZ: 0, armRX: 0, armRZ: 0, armLX: 0, armLZ: 0, legRX: 0, legLX: 0 };
        if (c.mode === 'bye') return { ...stand, armRX: -2.9, armRZ: -0.35 + Math.sin(t * 12 + seed) * 0.35 };
        if (c.mode === 'walking') {
            const swing = Math.sin(t * 15 + seed) * 0.7;
            return { ...stand, armRX: swing, armLX: -swing, legRX: -swing, legLX: swing };
        }
        if (c.mode === 'dig') {
            const swing = Math.sin(t * 5 + seed);
            return { ...stand, torso: 0.3 + swing * 0.1, headX: 0.3, armRX: -0.7 + swing * 0.6, armLX: -0.9 + swing * 0.5, legRX: 0.1, legLX: -0.1 };
        }
        if (c.mode === 'climb') {
            const k = c.cycle;
            if (k < 1.4) {
                const reach = Math.sin(t * 9 + seed) * 0.45;
                return { ...stand, torso: 0.15, headX: -0.4, armRX: -2.8 + reach, armLX: -2.8 - reach, legRX: -0.6 - reach, legLX: -0.6 + reach };
            }
            if (k < 1.9) return { ...stand, torso: -0.5, headX: -0.3, armRX: -2.6, armLX: -2.6, armRZ: -0.8, armLZ: 0.8, legRX: -0.9, legLX: -0.4 };
            return { ...stand, torso: -0.15, headY: Math.sin(t * 7) * 0.4, headZ: 0.2, armRX: -0.3, armLX: -0.3, legRX: -Math.PI / 2, legLX: -Math.PI / 2 };
        }
        if (c.mode === 'file') {
            // przegląda akta w szufladzie
            const flip = Math.sin(t * 4 + seed);
            return { ...stand, torso: 0.15, headX: 0.35, headY: flip * 0.15, armRX: -1.2 + flip * 0.25, armLX: -1.1 - flip * 0.2 };
        }
        if (c.mode === 'stand') {
            // czeka na usera: podniesiona ręka, jakby się zgłaszał; czeka na subagentów: stoi i zerka na ich biurka
            if (a.state === 'idle') return { ...stand, headX: -0.1, armRX: -3.05 + Math.sin(t * 4 + seed) * 0.08, armRZ: -0.15 };
            return { ...stand, headY: Math.sin(t * 0.6 + seed) * 0.6, armRZ: -0.05, armLZ: 0.05 };
        }
        const seated = { ...stand, headX: 0.1, armRX: -1.0, armLX: -1.0, legRX: -Math.PI / 2, legLX: -Math.PI / 2 };
        if (a.state === 'stuck') return { ...seated, headY: Math.sin(t * 6) * 0.35, armRX: -3.0, armRZ: -0.4, armLX: -3.0, armLZ: 0.4 };
        if (a.state !== 'working') return seated;
        // pracuje: szybkie, wyraźne klepanie w klawiaturę — jedna poza dla każdej pracy, żeby od razu było widać, kto pracuje
        return { ...seated, headX: 0.15 + Math.sin(t * 4 + seed) * 0.04,
            armRX: -1.05 + Math.sin(t * 28 + seed) * 0.22, armLX: -1.05 + Math.sin(t * 28 + seed + Math.PI) * 0.22 };
    };

    const animate = (c, dt, t) => {
        const target = c.throwing ? throwPose(c, pose(c, t)) : pose(c, t);
        const k = 1 - Math.exp(-dt * 10);
        const lerp = (obj, axis, value) => { obj.rotation[axis] += (value - obj.rotation[axis]) * k; };
        lerp(c.torso, 'x', target.torso);
        lerp(c.head, 'x', target.headX);
        lerp(c.head, 'y', target.headY);
        lerp(c.head, 'z', target.headZ);
        lerp(c.armR, 'x', target.armRX);
        lerp(c.armR, 'z', target.armRZ);
        lerp(c.armL, 'x', target.armLX);
        lerp(c.armL, 'z', target.armLZ);
        lerp(c.legR, 'x', target.legRX);
        lerp(c.legL, 'x', target.legLX);
        const dazed = c.mode === 'climb' && c.cycle >= 1.9;
        const hipsY = c.mode === 'seated' ? HIPS_SEATED : dazed ? 0.13 : HIPS_STANDING;
        c.hips.position.y += (hipsY - c.hips.position.y) * k;
        if (c.head.userData.tail) c.head.userData.tail.rotation.z = Math.sin(t * 2 + c.phase) * 0.15;
    };

    // ---- cząsteczki: złote monety (koszt), grudki ziemi (kopanie) ----

    const particleKinds = () => ({
        coin: [new THREE.BoxGeometry(3 * P, 1 * P, 3 * P), new THREE.MeshLambertMaterial({ color: '#f5c542', emissive: '#5a4300' })],
        dirt: [new THREE.BoxGeometry(2 * P, 2 * P, 2 * P), new THREE.MeshLambertMaterial({ color: '#6b4a2b' })],
        envelope: [new THREE.BoxGeometry(5 * P, 0.6 * P, 3.5 * P), new THREE.MeshLambertMaterial({ color: '#f4f1e6', emissive: '#3a3830' })],
    });

    const spawn = (office, kind, at, vel, life) => {
        if (office.particles.length >= MAX_PARTICLES) return;
        const [geo, mat] = office.kinds[kind];
        const mesh = new THREE.Mesh(geo, mat);
        mesh.position.copy(at);
        office.scene.add(mesh);
        office.particles.push({ mesh, vel, life, spin: (Math.random() - 0.5) * 12 });
    };

    const spawnMoney = (office, c) => {
        const at = c.hand.getWorldPosition(new THREE.Vector3());
        const angle = Math.random() * Math.PI * 2;
        const speed = 0.8 + Math.random() * 1.4;
        spawn(office, 'coin', at, new THREE.Vector3(Math.cos(angle) * speed, 3 + Math.random() * 2, Math.sin(angle) * speed), 1.2 + Math.random() * 0.6);
    };

    // ---- wiadomości: nadawca odwraca się i rzuca kopertą do adresata ----

    const MESSAGE_KINDS = { task: 'task', result: 'result', send: 'message', peer: 'from another session' };

    /** Nowe wiadomości z /office.json; pierwsze wczytanie tylko zapamiętuje id (bez odtwarzania starych). */
    const receiveMessages = (office, messages, replay) => {
        const now = Date.now();
        for (const m of messages) {
            if (office.msgSeen.has(m.id)) continue;
            office.msgSeen.set(m.id, now);
            if (replay) office.pending.push({ ...m, until: now + PENDING_MS });
        }
        // serwer trzyma wiadomości 30 s — starsze id nie wrócą
        for (const [id, at] of office.msgSeen) if (now - at > 120000) office.msgSeen.delete(id);
    };

    /** Oczekujące wiadomości: adresat w biurze → do kolejki rzutów nadawcy (albo lot od drzwi, gdy nadawcy nie ma). */
    const dispatchMessages = office => {
        const now = Date.now();
        office.pending = office.pending.filter(m => {
            const to = office.chars.get(m.to);
            if (!to) return now < m.until;
            const from = m.from ? office.chars.get(m.from) : null;
            if (from) from.throws.push(m);
            else launch(office, office.room.farOut.clone().setY(1.2), m);
            return false;
        });
    };

    const chest = c => c.root.position.clone().setY(1.1);

    const launch = (office, at, m) => {
        const mesh = new THREE.Mesh(...office.kinds.envelope);
        mesh.position.copy(at);
        office.scene.add(mesh);
        office.flights.push({ mesh, from: at.clone(), to: m.to, last: null, t: 0, m });
    };

    /** Rzut: obrót do adresata, zamach prawą ręką, koperta wylatuje w połowie; po rzucie powrót do poprzedniego kierunku. */
    const throwStep = (office, c, dt) => {
        if (!c.throwing && c.throws.length) {
            const m = c.throws.shift();
            c.throwing = { m, t: 0, yawBack: c.root.rotation.y, launched: false };
        }
        const th = c.throwing;
        if (!th) return;
        th.t += dt;
        const target = office.chars.get(th.m.to);
        const turn = c.mode === 'seated' || c.mode === 'stand' || c.mode === 'file';
        if (turn && target && th.t < THROW_TIME * 0.8) {
            const yaw = Math.atan2(target.root.position.x - c.root.position.x, target.root.position.z - c.root.position.z);
            let d = yaw - c.root.rotation.y;
            d = Math.atan2(Math.sin(d), Math.cos(d));
            c.root.rotation.y += d * (1 - Math.exp(-dt * 12));
        } else if (turn) {
            let d = th.yawBack - c.root.rotation.y;
            d = Math.atan2(Math.sin(d), Math.cos(d));
            c.root.rotation.y += d * (1 - Math.exp(-dt * 12));
        }
        if (!th.launched && th.t >= THROW_TIME * 0.45) {
            th.launched = true;
            launch(office, c.hand.getWorldPosition(new THREE.Vector3()), th.m);
        }
        if (th.t >= THROW_TIME) {
            if (turn) c.root.rotation.y = th.yawBack;
            c.throwing = null;
        }
    };

    /** Zamach ręką przy rzucie: do tyłu nad głowę, potem w przód. */
    const throwPose = (c, target) => {
        const f = c.throwing.t / THROW_TIME;
        if (f < 0.45) return { ...target, armRX: -3.0, armRZ: -0.2 };
        if (f < 0.8) return { ...target, armRX: -1.3, armRZ: 0 };
        return target;
    };

    /** Lot łukiem do bieżącej pozycji adresata (idzie — koperta go goni); po wylądowaniu notka nad adresatem. */
    const flightsStep = (office, dt) => {
        office.flights = office.flights.filter(f => {
            f.t += dt / FLIGHT_TIME;
            const target = office.chars.get(f.to);
            if (target) f.last = chest(target);
            const end = f.last || f.from;
            const k = Math.min(1, f.t);
            f.mesh.position.lerpVectors(f.from, end, k);
            f.mesh.position.y += Math.sin(k * Math.PI) * 1.4;
            f.mesh.rotation.y += dt * 9;
            f.mesh.rotation.z = Math.sin(k * Math.PI * 2) * 0.4;
            if (k < 1) return true;
            office.scene.remove(f.mesh);
            if (target) target.note = { text: '✉ ' + MESSAGE_KINDS[f.m.kind] + (f.m.text ? ': ' + f.m.text : ''), until: Date.now() + NOTE_MS };
            return false;
        });
    };

    const spawnDirt = (office, c) => {
        const at = c.root.position.clone();
        const dir = c.root.rotation.y;
        at.x += Math.sin(dir) * 0.45;
        at.z += Math.cos(dir) * 0.45;
        at.y = 0.1;
        for (let i = 0; i < 4; i++) {
            spawn(office, 'dirt', at.clone(), new THREE.Vector3(Math.sin(dir) * -1.2 + (Math.random() - 0.5), 2 + Math.random() * 1.5, Math.cos(dir) * -1.2 + (Math.random() - 0.5)), 1.2);
        }
    };

    /** Dołek w trawie przed kopiącym — zostaje (najstarsze znikają po MAX_HOLES). */
    const addHole = (office, c) => {
        const at = c.root.position.clone();
        at.x += Math.sin(c.root.rotation.y) * 0.45;
        at.z += Math.cos(c.root.rotation.y) * 0.45;
        const hole = new THREE.Mesh(office.holeGeo, office.holeMat);
        hole.rotation.x = -Math.PI / 2;
        hole.position.set(Math.round(at.x * 4) / 4, 0.004, Math.round(at.z * 4) / 4);
        office.scene.add(hole);
        office.holes.push(hole);
        if (office.holes.length > MAX_HOLES) office.scene.remove(office.holes.shift());
    };

    const particlesStep = (office, dt) => {
        office.particles = office.particles.filter(p => {
            p.life -= dt;
            if (p.life <= 0) {
                office.scene.remove(p.mesh);
                return false;
            }
            if (p.mesh.position.y > 0.03 || p.vel.y > 0) {
                p.vel.y -= 9.8 * dt;
                p.mesh.position.addScaledVector(p.vel, dt);
                p.mesh.rotation.x += p.spin * dt;
                p.mesh.rotation.y += p.spin * dt;
                if (p.mesh.position.y < 0.03) {
                    p.mesh.position.y = 0.03;
                    p.vel.set(0, 0, 0);
                    p.mesh.rotation.set(0, p.mesh.rotation.y, 0);
                }
            }
            if (p.life < 0.5) p.mesh.scale.setScalar(p.life / 0.5);
            return true;
        });
    };

    // ---- podpisy ----

    const elapsed = seconds => seconds < 60 ? Math.round(seconds) + 's' : seconds < 3600 ? Math.round(seconds / 60) + 'm' : Math.round(seconds / 3600) + 'h';

    /** Dymek w trzech częściach [początek, cel, koniec]: przy braku miejsca zwęża się tylko cel (`{text, path}` z serwera), czas zostaje. */
    const bubbleParts = (c, now) => {
        const a = c.agent;
        if (c.leaving) return ['bye 👋', null, ''];
        if (a.boundary?.kind === 'outside') return ['⛏ outside the boundary:', a.boundary.target, ''];
        if (a.boundary?.kind === 'stopped') return ['🧱 blocked, can’t climb out:', a.boundary.target, ''];
        if (a.boundary?.kind === 'trying') return ['🧱 trying to get out:', a.boundary.target, ''];
        const since = '· ' + elapsed(Math.max(0, (now - Date.parse(a.since)) / 1000));
        const blocked = a.blocked ? '⛔ blocked (' + a.blocked + ') · ' : '';
        switch (a.state) {
            case 'working':
                if (a.tool) return [blocked + (TOOL_VERBS[a.tool] || a.tool), a.activity, since];
                if (a.after) return [blocked + 'working · after ' + (TOOL_VERBS[a.after.tool] || a.after.tool), a.after.activity, since];
                return [blocked + 'working', null, since];
            case 'delegated': return ['waiting for ' + a.waitingFor + (a.waitingFor === 1 ? ' subagent' : ' subagents'), null, since];
            case 'idle': return [(a.interrupted ? 'interrupted · ' : '') + 'waiting for you', null, since];
            case 'stuck': return [a.stuck === 'limit' ? '⚠ API limit' : '⚠ API error', null, ''];
            default: return [a.state, null, ''];
        }
    };

    /** Podpis = link do sesji agenta: [kwadracik sesji, dymek], pod spodem nazwa i model. Identyfikator sesji to UUID z serwera. */
    const makeLabel = (office, a) => {
        const el = document.createElement('a');
        el.setAttribute('viz-office-label', '');
        el.href = '/?s=' + encodeURIComponent(a.session);
        const row = document.createElement('div');
        row.setAttribute('viz-office-label-row', '');
        const dot = document.createElement('i');
        dot.setAttribute('viz-office-label-dot', '');
        const bubble = document.createElement('div');
        bubble.setAttribute('viz-office-label-bubble', '');
        const head = document.createElement('span');
        // ścieżka: `direction: rtl` w CSS ucina od lewej, <bdi> trzyma kolejność znaków
        const target = document.createElement('span');
        target.setAttribute('viz-office-label-target', '');
        const targetText = document.createElement('bdi');
        target.append(targetText);
        const tail = document.createElement('span');
        bubble.append(head, target, tail);
        const name = document.createElement('div');
        name.setAttribute('viz-office-label-name', '');
        const note = document.createElement('div');
        note.setAttribute('viz-office-label-note', '');
        note.hidden = true;
        row.append(dot, bubble);
        el.append(note, row, name);
        office.labels.append(el);
        return { el, dot, name, bubble, head, target, targetText, tail, note, text: '' };
    };

    const updateLabel = (office, c, now) => {
        const a = c.agent;
        const burn = a.burn >= office.burnFrom ? ' · 💸 $' + a.burn.toFixed(2) + '/5m' : '';
        const name = (a.kind === 'main' ? 'main' : a.label) + (a.modelName ? ' · ' + a.modelName : '') + burn;
        if (c.label.name.textContent !== name) c.label.name.textContent = name;
        const session = office.sessions.get(a.session);
        c.label.el.title = (session?.title || '') + (a.description ? ' — ' + a.description : '');
        c.label.dot.style.background = session?.color || '';
        const [head, target, tail] = bubbleParts(c, now);
        const text = head + '\n' + (target?.path ? '/' : '') + (target?.text || '') + '\n' + tail;
        if (c.label.text !== text) {
            c.label.text = text;
            c.label.head.textContent = head;
            c.label.targetText.textContent = target?.text || '';
            c.label.target.hidden = !target?.text;
            c.label.target.toggleAttribute('viz-office-label-path', !!target?.path);
            c.label.tail.textContent = tail;
            c.label.tail.hidden = !tail;
        }
        const state = c.leaving ? 'leaving' : a.boundary ? 'stuck' : a.state;
        c.label.el.setAttribute('viz-office-state', state);
        const note = c.note && c.note.until > Date.now() ? c.note.text : '';
        if (c.label.note.textContent !== note) c.label.note.textContent = note;
        c.label.note.hidden = !note;
    };

    // ---- scena ----

    const Init = root => {
        const box = root.one('[viz-office-canvas]');
        const renderer = new THREE.WebGLRenderer({ antialias: true });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        box.append(renderer.domElement);
        const scene = new THREE.Scene();
        // tło i mgła w kolorze tła aplikacji (motyw jasny/ciemny) — trawa na brzegach rozmywa się w tło zamiast urywać na niebie
        const bg = new THREE.Color(getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#16181c');
        scene.background = bg;
        scene.fog = new THREE.Fog(bg, 20, 60);
        scene.add(new THREE.HemisphereLight('#dfefff', '#6b5130', 1.6));
        const sun = new THREE.DirectionalLight('#fff4d6', 1.8);
        sun.position.set(5, 10, 7);
        scene.add(sun);
        const camera = new THREE.PerspectiveCamera(35, 1, 0.1, 300);
        const tex = sharedTextures();

        const office = {
            renderer, scene, camera, box,
            labels: root.one('[viz-office-labels]'),
            room: null,
            chars: new Map(),
            // sesje w biurze: id → { proj, row, color, title, project }; kolor trzyma się sesji, dopóki ktoś z niej jest w biurze
            sessions: new Map(),
            // projekty (katalog sesji) → { row, name, cwd }; rząd trzyma się projektu, dopóki jest w biurze któraś jego sesja
            projects: new Map(),
            status: root.one('[viz-office-status]'),
            // ostatnie odświeżenie się nie udało (serwer wyłączony, zła odpowiedź) — scena pokazuje nieaktualny stan, więc status to mówi
            failed: false,
            shift: 0,
            legend: root.one('[viz-office-legend]'),
            side: root.one('[viz-office-side]'),
            // cel kamery przesunięty w prawo: widać biuro i trawę za prawym murem (poza granicą)
            orbit: { az: 0.55, el: 0.72, r: 12, target: new THREE.Vector3(2.5, 0.6, 0) },
            started: performance.now(),
            last: performance.now(),
            offset: 0,
            burnFrom: Infinity,
            loaded: false,
            size: [0, 0],
            particles: [],
            kinds: particleKinds(),
            // wiadomości: id już widziane (→ czas), czekające na adresata, koperty w locie; ostatni znany koszt agenta (także po wyjściu z biura)
            msgSeen: new Map(),
            pending: [],
            flights: [],
            spent: new Map(),
            coinUsd: 0.01,
            holes: [],
            holeGeo: new THREE.PlaneGeometry(0.5, 0.5),
            holeMat: new THREE.MeshLambertMaterial({ map: tex.dirt }),
        };
        root._office = office;
        loadCamera(office);
        ensureRoom(office, 2, 3);
        loop(root);
        refresh(root, true);
        root._timer = setInterval(() => refresh(root, false), POLL_MS);
    };

    /** Pokój rośnie, gdy brakuje rzędu albo kolumny; nie maleje w trakcie oglądania (biurka nie skaczą przy każdym wyjściu). */
    const ensureRoom = (office, rows, cols) => {
        const room = office.room;
        if (room && room.rows >= rows && room.cols >= cols) return;
        if (room) {
            for (const c of office.chars.values()) c.prop?.removeFromParent();
            office.scene.remove(room.group);
            disposeTree(room.group);
        }
        office.room = buildRoom(Math.max(rows, room?.rows ?? 0), Math.max(cols, room?.cols ?? 0));
        office.scene.add(office.room.group);
        paintRugs(office);
        // zapamiętana kamera usera ma pierwszeństwo przed dopasowaniem do wielkości pokoju
        if (!office.cameraSaved) office.orbit.r = 11 + Math.max(office.room.width, office.room.depth) * 1.0;
        // przebudowa przesuwa biurka: siedzący przesiadają się od razu, reszta planuje drogę od nowa
        for (const c of office.chars.values()) {
            if (c.mode === 'seated') seat(office, c);
            else c.goal = null;
        }
    };

    const seat = (office, c) => {
        const desk = deskOf(office.room, c);
        c.root.position.set(desk.group.position.x, 0, desk.group.position.z);
        c.root.rotation.y = 0;
        c.mode = 'seated';
        c.goal = 'seat';
        c.path = [];
        c.shovel.visible = false;
        if (c.prop) desk.propSpot.add(c.prop);
    };

    /**
     * Nowa sesja: pierwszy wolny kolor; rząd = projekt (katalog sesji) — sesja projektu, który już jest w biurze, siada w jego rzędzie,
     * nowy projekt dostaje pierwszy wolny rząd.
     */
    const addSession = (office, s) => {
        const used = [...office.sessions.values()];
        const color = SESSION_COLORS.find(col => !used.some(u => u.color === col)) || SESSION_COLORS[office.sessions.size % SESSION_COLORS.length];
        const proj = s.cwd || s.id;
        if (!office.projects.has(proj)) {
            const rows = [...office.projects.values()];
            let row = 0;
            while (rows.some(p => p.row === row)) row++;
            office.projects.set(proj, { row, name: s.project, cwd: s.cwd || '', shirt: s.projectColor || '' });
        }
        // `color` = kolor sesji (kwadracik nad postacią); `shirt` = kolor projektu z OfficeState::projectColor (koszulki, dywan, legenda)
        office.sessions.set(s.id, { proj, row: office.projects.get(proj).row, color, shirt: s.projectColor || '', title: s.title, project: s.project });
    };

    /** Miejsce w rzędzie projektu: pierwsze wolne (sesje projektu dzielą rząd). */
    const freeCol = (office, row) => {
        const taken = new Set([...office.chars.values()].filter(c => !c.leaving && c.row === row).map(c => c.col));
        let col = 0;
        while (taken.has(col)) col++;
        return col;
    };

    /** Dywan rzędu w kolorze projektu (jak koszulki; bez podpisu — projekt i sesje są w legendzie); bez koloru projektu — kolor pierwszej sesji. */
    const paintRugs = office => {
        office.room.rugs.forEach((rug, row) => {
            const entry = [...office.sessions].find(([, u]) => u.row === row);
            rug.visible = !!entry;
            if (entry) rug.material.color.set(entry[1].shirt || entry[1].color);
        });
    };

    /** Legenda „Projects” na lewym boku: projekt (kolejność rzędów), pod nim jego sesje — kwadracik, tytuł, liczba agentów; klik = sesja. Tylko textContent. */
    const renderLegend = office => {
        const counts = new Map();
        for (const c of office.chars.values()) if (!c.leaving) counts.set(c.agent.session, (counts.get(c.agent.session) || 0) + 1);
        const groups = [...office.projects].sort(([, a], [, b]) => a.row - b.row).map(([proj, p]) => {
            const sessions = [...office.sessions].filter(([id, s]) => s.proj === proj && counts.has(id));
            if (!sessions.length) return null;
            const group = document.createElement('div');
            group.setAttribute('viz-office-project', '');
            const name = document.createElement('span');
            name.setAttribute('viz-office-project-name', '');
            name.textContent = p.name || p.cwd;
            name.title = p.cwd;
            // nagłówek projektu: kolor koszulek agentów projektu + nazwa
            const head = document.createElement('div');
            head.setAttribute('viz-office-project-head', '');
            const shirt = document.createElement('i');
            shirt.setAttribute('viz-office-project-dot', '');
            shirt.style.background = p.shirt || 'transparent';
            head.append(shirt, name);
            group.append(head, ...sessions.map(([id, s]) => {
                const a = document.createElement('a');
                a.setAttribute('viz-office-session', '');
                a.href = '/?s=' + encodeURIComponent(id);
                const dot = document.createElement('i');
                dot.setAttribute('viz-office-session-dot', '');
                dot.style.background = s.color;
                const title = document.createElement('span');
                title.textContent = s.title || id.slice(0, 8);
                const meta = document.createElement('span');
                meta.className = 'muted';
                const n = counts.get(id);
                meta.textContent = n + (n === 1 ? ' agent' : ' agents');
                a.append(dot, title, meta);
                return a;
            }));
            return group;
        });
        office.legend.replaceChildren(...groups.filter(Boolean));
    };

    const apply = (root, data) => {
        const office = root._office;
        office.offset = data.now ? Date.parse(data.now) - Date.now() : 0;
        office.burnFrom = data.burnFrom ?? Infinity;
        office.coinUsd = data.coinUsd || office.coinUsd;
        const agents = data.agents || [];
        root.one('[viz-office-empty]').hidden = agents.length > 0;
        for (const s of data.sessions || []) {
            const known = office.sessions.get(s.id);
            if (known) known.title = s.title;
            else addSession(office, s);
        }
        const seen = new Set();
        for (const a of agents) {
            seen.add(a.id);
            // monety = koszt od poprzedniego odświeżenia; agent widziany pierwszy raz po wczytaniu strony — jego ostatnie 5 min
            const before = office.spent.get(a.id) ?? (office.loaded ? a.spent - a.burn : a.spent);
            office.spent.set(a.id, a.spent);
            const coins = Math.max(0, a.spent - before) / office.coinUsd;
            const c = office.chars.get(a.id);
            if (c && !c.leaving) {
                c.agent = a;
                c.coins = Math.min(COIN_QUEUE, c.coins + coins);
                continue;
            }
            // agent wrócił do pracy w trakcie wychodzenia — siada od nowa przy wolnym biurku
            if (c) remove(office, a.id);
            if (!office.sessions.has(a.session)) addSession(office, { id: a.session, title: '', project: '' });
            const row = office.sessions.get(a.session).row;
            const col = freeCol(office, row);
            ensureRoom(office, row + 1, col + 1);
            const persona = PERSONAS[a.persona] ? a.persona : 'default';
            const desk = PERSONAS[persona].desk;
            const char = { ...buildCharacter(persona, office.sessions.get(a.session).shirt || undefined), agent: a, row, col, persona, phase: (hash(a.id) % 100) / 10, mode: 'new', goal: null, path: [],
                prop: desk ? deskProp(desk) : null, label: makeLabel(office, a), moneyAcc: 0, coins: Math.min(COIN_QUEUE, coins), throws: [], throwing: null, note: null, leaving: false };
            office.scene.add(char.root);
            office.chars.set(a.id, char);
            if (!office.loaded) {
                // pierwszy obraz: każdy już siedzi przy swoim biurku, dalej rusza się według stanu
                seat(office, char);
            } else {
                char.root.position.copy(office.room.farOut);
                char.hips.position.y = HIPS_STANDING;
            }
        }
        for (const [id, c] of office.chars) {
            if (seen.has(id) || c.leaving) continue;
            if (!office.loaded) {
                remove(office, id);
                continue;
            }
            // przestał być aktywny (subagent skończył, sesja ucichła): macha i znika na miejscu
            c.leaving = true;
        }
        receiveMessages(office, data.messages || [], office.loaded);
        office.loaded = true;
        paintRugs(office);
        renderLegend(office);
    };

    const remove = (office, id) => {
        const c = office.chars.get(id);
        if (!c) return;
        office.scene.remove(c.root);
        c.prop?.removeFromParent();
        c.fadeMats?.forEach(m => m.dispose());
        c.label.el.remove();
        office.chars.delete(id);
        // ostatni z sesji wyszedł: zwalnia kolor; ostatnia sesja projektu — także rząd
        if (![...office.chars.values()].some(o => o.agent.session === c.agent.session)) {
            const proj = office.sessions.get(c.agent.session)?.proj;
            office.sessions.delete(c.agent.session);
            if (![...office.sessions.values()].some(u => u.proj === proj)) office.projects.delete(proj);
            paintRugs(office);
            renderLegend(office);
        }
    };

    const refresh = (root, force) => {
        const office = root._office;
        if (!root.isConnected) return destroy(root);
        // ukryta karta nie odpytuje serwera (przycisk działa zawsze)
        if ((document.hidden && !force) || root._busy) return;
        root._busy = true;
        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), FETCH_TIMEOUT_MS);
        fetch(root.attr('viz-office-url'), { cache: 'no-store', signal: abort.signal })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                office.failed = false;
                if (!data || !root.isConnected) return;
                apply(root, data);
            })
            // serwer wyłączony, zrestartowany albo bez odpowiedzi w FETCH_TIMEOUT_MS: scena zostaje, następna próba za POLL_MS
            .catch(() => { office.failed = true; })
            .finally(() => {
                clearTimeout(timer);
                root._busy = false;
            });
    };

    const screenState = (office, index) => {
        for (const c of office.chars.values()) {
            if (c.row * office.room.cols + c.col === index && !c.leaving && c.mode !== 'new') return c.agent.state;
        }
        return 'off';
    };

    const loop = root => {
        const office = root._office;
        const v = new THREE.Vector3();
        const frame = () => {
            if (!root.isConnected) return destroy(root);
            root._raf = requestAnimationFrame(frame);
            const tick = performance.now();
            const dt = Math.min((tick - office.last) / 1000, 0.1);
            const t = (tick - office.started) / 1000;
            office.last = tick;
            const w = office.box.clientWidth;
            const h = office.box.clientHeight;
            // legendy (projekty, statusy) zajmują lewy bok — środek sceny przesunięty w prawo o ich szerokość (widok z szerszej klatki)
            const shift = office.side.offsetWidth ? office.side.offsetWidth + 16 : 0;
            if (w !== office.size[0] || h !== office.size[1] || shift !== office.shift) {
                office.size = [w, h];
                office.shift = shift;
                office.renderer.setSize(w, h);
                office.camera.aspect = w / Math.max(1, h);
                if (shift > 0) office.camera.setViewOffset(w + shift, h, 0, 0, w, h);
                else office.camera.clearViewOffset();
                office.camera.updateProjectionMatrix();
            }
            const o = office.orbit;
            office.camera.position.set(
                o.target.x + o.r * Math.sin(o.az) * Math.cos(o.el),
                o.target.y + o.r * Math.sin(o.el),
                o.target.z + o.r * Math.cos(o.az) * Math.cos(o.el),
            );
            office.camera.lookAt(o.target);
            office.scene.fog.near = o.r * 1.15;
            office.scene.fog.far = o.r * 2.1;

            shared.code.offset.y += dt * 0.25;
            office.room.desks.forEach((desk, slot) => {
                const state = screenState(office, slot);
                if (desk.state === state) return;
                desk.state = state;
                desk.screenMat.map = state === 'working' ? shared.code : null;
                desk.screenMat.color.set(state === 'working' ? '#ffffff' : SCREEN_COLORS[state] || SCREEN_COLORS.off);
                desk.screenMat.needsUpdate = true;
                desk.led.material.color.set(STATE_COLORS[state] || SCREEN_COLORS.off);
            });

            const now = Date.now() + office.offset;
            for (const c of [...office.chars.values()]) {
                behave(office, c, dt, t);
                if (!office.chars.has(c.agent.id)) continue;
                throwStep(office, c, dt);
                animate(c, dt, t);
                if (c.coins >= 1) {
                    // kolejka monet schodzi w tempie, które opróżnia ją do następnego odświeżenia
                    c.moneyAcc += dt * Math.min(COIN_RATE[1], Math.max(COIN_RATE[0], c.coins / (POLL_MS / 1000)));
                    while (c.moneyAcc >= 1 && c.coins >= 1) {
                        c.moneyAcc -= 1;
                        c.coins -= 1;
                        spawnMoney(office, c);
                    }
                } else {
                    c.moneyAcc = 0;
                }
            }
            if (office.pending.length) dispatchMessages(office);
            flightsStep(office, dt);
            particlesStep(office, dt);
            office.renderer.render(office.scene, office.camera);

            // przy odświeżaniu co sekundę „zaktualizowano N s temu” nic nie mówi — komunikat tylko przy błędzie
            const status = office.failed ? 'refresh not working' : '';
            if (office.status && office.status.textContent !== status) office.status.textContent = status;
            for (const c of office.chars.values()) {
                updateLabel(office, c, now);
                c.anchor.getWorldPosition(v);
                v.project(office.camera);
                const visible = v.z < 1 && Math.abs(v.x) < 1.2 && Math.abs(v.y) < 1.2;
                c.label.el.hidden = !visible;
                if (visible) c.label.el.style.transform = `translate(${(v.x + 1) / 2 * w}px, ${(1 - v.y) / 2 * h}px) translate(-50%, -100%)`;
            }
        };
        frame();
    };

    const destroy = root => {
        const office = root._office;
        if (!office || office.destroyed) return;
        office.destroyed = true;
        clearInterval(root._timer);
        cancelAnimationFrame(root._raf);
        office.scene.traverse(o => {
            o.geometry?.dispose();
            [].concat(o.material || []).forEach(m => m.dispose());
        });
        Object.values(office.kinds).forEach(([geo, mat]) => { geo.dispose(); mat.dispose(); });
        materialCache.forEach(mats => mats.forEach(m => { m.map?.dispose(); m.dispose(); }));
        materialCache.clear();
        Object.values(shared).forEach(t => t?.isTexture && t.dispose());
        shared.ready = false;
        office.renderer.dispose();
    };

    // ---- kamera: przeciągnięcie = obrót, kółko = zoom (eventy inline w templates/office.php) ----

    const space = el => el.closest('[viz-office]');

    const CAMERA_KEY = 'viz-office-camera';

    /** Ustawienie kamery usera przetrwa odświeżenie strony (wygoda per przeglądarka). */
    const loadCamera = office => {
        try {
            const c = JSON.parse(localStorage.getItem(CAMERA_KEY) || 'null');
            if (!c || ![c.az, c.el, c.r, c.x, c.z].every(Number.isFinite)) return;
            Object.assign(office.orbit, { az: c.az, el: c.el, r: c.r });
            office.orbit.target.set(c.x, office.orbit.target.y, c.z);
            office.cameraSaved = true;
        } catch { /* bez pamięci */ }
    };

    const saveCamera = office => {
        const o = office.orbit;
        office.cameraSaved = true;
        try { localStorage.setItem(CAMERA_KEY, JSON.stringify({ az: o.az, el: o.el, r: o.r, x: o.target.x, z: o.target.z })); } catch { /* bez pamięci */ }
    };

    /** Lewy przycisk = obrót; prawy albo Shift = przesuwanie planszy po podłodze. */
    const Down = (el, event) => {
        const root = space(el);
        if (!root?._office) return;
        el.setPointerCapture(event.pointerId);
        const o = root._office.orbit;
        root._drag = { x: event.clientX, y: event.clientY, az: o.az, el: o.el, pan: event.button === 2 || event.shiftKey, target: o.target.clone() };
    };

    const Move = (el, event) => {
        const root = space(el);
        const drag = root?._drag;
        if (!drag) return;
        const o = root._office.orbit;
        const dx = event.clientX - drag.x;
        const dy = event.clientY - drag.y;
        if (drag.pan) {
            // w prawo/lewo wzdłuż ekranu, góra/dół = w głąb sceny (rzut na podłogę)
            const k = o.r * 0.0016;
            const right = [Math.cos(o.az), -Math.sin(o.az)];
            const forward = [-Math.sin(o.az), -Math.cos(o.az)];
            o.target.x = Math.max(-50, Math.min(50, drag.target.x - right[0] * dx * k + forward[0] * dy * k));
            o.target.z = Math.max(-50, Math.min(50, drag.target.z - right[1] * dx * k + forward[1] * dy * k));
            return;
        }
        o.az = drag.az - dx * 0.008;
        o.el = Math.min(1.45, Math.max(0.12, drag.el + dy * 0.006));
    };

    const Up = el => {
        const root = space(el);
        if (!root?._drag) return;
        root._drag = null;
        saveCamera(root._office);
    };

    const Wheel = (el, event) => {
        const root = space(el);
        if (!root?._office) return;
        event.preventDefault();
        const o = root._office.orbit;
        o.r = Math.min(80, Math.max(4, o.r * Math.exp(event.deltaY * 0.001)));
        saveCamera(root._office);
    };

    /** Ręczne odświeżenie (przycisk w pasku biura): pobiera stan od razu, bez sprawdzania wersji. */
    const Refresh = el => {
        const root = space(el);
        if (root?._office) refresh(root, true);
    };

    return { Init, Down, Move, Up, Wheel, Refresh };
})();
