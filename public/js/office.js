(function () {
    'use strict';

    const CFG = window.KANTOOR;
    const DOG_NAME = CFG.dog || 'Yuki';
    const SIGN = CFG.sign || 'OFFICE';
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const ACCENT = '#B8F25A';
    const LABELS = { working: 'Working', waiting: 'Waiting for you', done: 'Done', error: 'Error', idle: 'Available', sleeping: 'Scheduled', offline: 'Gone home' };
    const SPEED = 3.2;
    const NONE = 'none';

    const canvas = document.getElementById('office');
    let ctx = canvas.getContext('2d');

    const state = {
        projects: new Map(),
        agents: new Map(),
        events: [],
        filter: 'all',
        selected: null,
        layout: null,
        runtime: new Map(),
        packets: [],
        clockOffset: 0,
        hover: null,
        day: 1,
        ui: 1,
        today: null,
        star: null,
        visits: [],
        alarms: [],
        usage: null,
        gauge: null,
    };

    const now = () => performance.now() / 1000;
    const reduceMotion = Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    const PORTRAITS = new Image();
    let portraitsReady = false;
    PORTRAITS.onload = () => { portraitsReady = true; };
    PORTRAITS.src = CFG.portraits;

    function clockHours() {
        const d = new Date();
        return { h: d.getHours() + d.getMinutes() / 60, dow: d.getDay(), date: d.toDateString() };
    }

    function isFridayDrinks() {
        const c = clockHours();
        return c.dow === 5 && c.h >= 16 && c.h < 19;
    }

    const SPRITE = { w: 44, h: 68 };
    const sprites = new Image();
    let spritesReady = false;
    sprites.onload = () => { spritesReady = true; };
    sprites.src = CFG.characters;
    const PROPS = new Image();
    let propsReady = false;
    PROPS.onload = () => { propsReady = true; };
    PROPS.src = CFG.props;
    const PROP = {"plant":[[3,8,34,56],[49,6,22,58],[84,7,32,57]],"dog":[[6,70,35,34],[54,72,35,32],[102,72,35,32],[154,68,28,36],[195,79,41,25],[245,70,38,34]],"desk":[[6,111,52,45],[70,110,52,46]],"bed":[[134,111,52,33]],"bowl":[[195,111,18,13]],"walk":[[10,176,38,40],[58,176,38,40],[106,176,38,40],[154,176,38,40],[202,176,38,40],[250,176,38,40],[298,176,38,40],[346,176,38,40]],"station":[[4,226,36,46]],"table":[[48,232,28,40]],"drinks":[[84,232,28,40]],"cooler":[[120,220,19,52]]};

    function drawProp(r, x, y, flip) {
        const w = r[2] / 2;
        const h = r[3] / 2;
        if (!flip) {
            ctx.drawImage(PROPS, r[0], r[1], r[2], r[3], x - w / 2, y - h, w, h);
            return;
        }
        ctx.save();
        ctx.translate(x, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(PROPS, r[0], r[1], r[2], r[3], -w / 2, y - h, w, h);
        ctx.restore();
    }

    function portraitStyle(el, avatar, size) {
        const index = ((avatar | 0) % 24 + 24) % 24;
        el.style.backgroundImage = `url(${CFG.portraits})`;
        el.style.backgroundPosition = `${-(index % 6) * size}px ${-Math.floor(index / 6) * size}px`;
    }

    function shade(hex, f) {
        const n = parseInt(hex.slice(1), 16);
        const c = (v) => Math.max(0, Math.min(255, Math.round(v * f)));
        return `rgb(${c((n >> 16) & 255)},${c((n >> 8) & 255)},${c(n & 255)})`;
    }

    const PROJECT_COLORS = ['#6FA8DC', '#E58F65', '#8BC48A', '#C792EA', '#F2C14E', '#5EC2B7', '#E57A9A', '#9AA5F0'];

    function hash2(a, b) {
        let h = Math.imul(a | 0, 374761393) + Math.imul(b | 0, 668265263) | 0;
        h = Math.imul(h ^ (h >>> 13), 1274126177);
        return ((h ^ (h >>> 16)) >>> 0) / 4294967295;
    }

    function hexRgb(hex) {
        const n = parseInt(hex.slice(1), 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    function mix(a, b, t) {
        const x = hexRgb(a);
        const y = hexRgb(b);
        const c = (i) => Math.round(x[i] + (y[i] - x[i]) * t);
        return `#${((1 << 24) | (c(0) << 16) | (c(1) << 8) | c(2)).toString(16).slice(1)}`;
    }

    function rgba(hex, a) {
        const c = hexRgb(hex);
        return `rgba(${c[0]},${c[1]},${c[2]},${a})`;
    }

    function projectColor(key) {
        if (key === NONE) return '#8C93A1';
        const order = [...state.projects.values()].sort((a, b) => (a.position - b.position) || (a.id - b.id));
        const index = order.findIndex((p) => String(p.id) === String(key));
        return PROJECT_COLORS[(index < 0 ? 0 : index) % PROJECT_COLORS.length];
    }

    function daylight() {
        const d = new Date();
        const h = d.getHours() + d.getMinutes() / 60;
        if (h < 6 || h >= 21.5) return 0;
        if (h < 8.5) return (h - 6) / 2.5;
        if (h < 18.5) return 1;
        return 1 - (h - 18.5) / 3;
    }

    function visibleAgents() {
        return [...state.agents.values()].filter((a) => a.status !== 'offline');
    }

    function projectKey(agent) {
        return agent.project_id && state.projects.has(agent.project_id) ? agent.project_id : NONE;
    }

    // ---------- layout ----------

    function computeLayout() {
        const groups = new Map();
        const sorted = visibleAgents().sort((a, b) => ((a.parent_id || a.id) - (b.parent_id || b.id)) || (a.id - b.id));

        for (const agent of sorted) {
            const key = projectKey(agent);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(agent);
        }

        const order = [...state.projects.values()].sort((a, b) => (a.position - b.position) || (a.id - b.id)).map((p) => p.id);
        if (groups.has(NONE)) order.push(NONE);

        const blocks = [];
        for (const key of order) {
            const members = groups.get(key);
            if (!members || !members.length) continue;
            const cols = Math.max(3, Math.min(6, Math.ceil(members.length / 2)));
            const pairs = Math.max(1, Math.ceil(members.length / (cols * 2)));
            blocks.push({ key, members, cols, pairs, w: 0.4 + cols * 1.15, d: pairs * 3.2 - 0.45 });
        }
        blocks.push({ key: 'boss', members: [], w: 5.2, d: 4.2 });

        const maxRow = Math.max(17, ...blocks.map((b) => b.w));
        const left = 1.6;
        const gapX = 1.4;
        const gapY = 2.0;
        let x = left;
        let y = 2.2;
        let rowDepth = 0;
        const rows = [];
        let row = [];

        for (const block of blocks) {
            if (x > left && x + block.w > left + maxRow) {
                rows.push(row);
                row = [];
                x = left;
                y += rowDepth + gapY;
                rowDepth = 0;
            }
            block.x = x;
            block.y = y;
            row.push(block);
            x += block.w + gapX;
            rowDepth = Math.max(rowDepth, block.d);
        }
        rows.push(row);

        const W = Math.max(20, Math.ceil(Math.max(...blocks.map((b) => b.x + b.w)) + 1.4));
        const D = Math.ceil(y + rowDepth + 1.4);
        const seats = new Map();
        const islands = [];

        for (const block of blocks) {
            if (block.key === 'boss') continue;
            const desks = [];
            block.members.forEach((agent, i) => {
                const pair = Math.floor(i / (block.cols * 2));
                const inPair = i % (block.cols * 2);
                const rowIndex = inPair < block.cols ? 0 : 1;
                const col = inPair % block.cols;
                const by = block.y + pair * 3.2;
                const dx = block.x + 0.2 + col * 1.15;
                const desk = { agentId: agent.id, row: rowIndex, x: dx, y: rowIndex === 0 ? by + 0.8 : by + 1.35 };
                const seat = { x: dx + 0.5, y: rowIndex === 0 ? by + 0.5 : by + 2.25, row: rowIndex, aisle: rowIndex === 0 ? by + 0.5 - 0.95 : by + 2.25 + 0.95 };
                desks.push(desk);
                seats.set(agent.id, seat);
            });
            const project = state.projects.get(block.key);
            islands.push({ key: block.key, name: project ? project.name : 'No project', x: block.x, y: block.y, w: block.w, d: block.d, desks });
        }

        const bossBlock = blocks.find((b) => b.key === 'boss');
        const boss = { x: bossBlock.x, y: bossBlock.y, w: bossBlock.w, d: bossBlock.d };
        boss.desk = { x: boss.x + boss.w - 2.2, y: boss.y + boss.d - 1.3 };
        boss.seat = { x: boss.desk.x + 0.7, y: boss.desk.y + 1.0 };
        boss.door = { x: boss.x + 1.0, y: boss.y };
        boss.lamp = { x: boss.desk.x - 0.45, y: boss.desk.y + 0.25 };
        boss.plant = { x: boss.x + 0.45, y: boss.y + boss.d - 0.45 };

        const OX = D * 16 + 24;
        const OY = 3 * 16 + 36;
        const LW = (W + D) * 16 + 48;
        const LH = (W + D) * 8 + OY + 24;

        const sign = W >= 16.5 ? 12.4 : null;
        const board = Math.min(W - 4, 4.9);
        const boardW = Math.max(2.8, Math.min(4.2, W - 0.6 - board));
        return { W, D, OX, OY, LW, LH, islands, seats, boss, lane: 0.8, board, boardW, sign, coffee: coffeeSpot(D), plaque: plaqueSpot(W, board, boardW, sign) };
    }

    const BOARD = { z0: 0.75, z1: 2.6 };

    function plaqueSpot(W, board, boardW, sign) {
        const taken = windowsOf(W).map((wx) => [wx - 0.15, wx + 2.15]);
        taken.push([board - 0.1, board + boardW + 0.1]);
        if (sign !== null) taken.push([sign - 0.3, sign + 3.2]);
        for (let x = W - 2.0; x >= 0.6; x -= 0.1) {
            if (taken.every(([a, b]) => x + 1.5 + 0.2 < a || x - 0.2 > b)) return x;
        }
        return null;
    }

    function applyLayout() {
        const layout = computeLayout();
        state.layout = layout;
        canvas.width = layout.LW * 2;
        canvas.height = layout.LH * 2;
        if (CFG.tv) fitCanvas();

        for (const agent of visibleAgents()) {
            const rt = runtime(agent);
            const seat = layout.seats.get(agent.id);
            if (!seat) continue;
            if (!rt.pos && agent.status === 'waiting') {
                rt.pos = queueSpot(agent);
                rt.mode = 'standing';
                continue;
            }
            if (!rt.pos || rt.mode === 'seated') {
                rt.pos = { x: seat.x, y: seat.y };
                rt.mode = 'seated';
            }
            syncPlacement(agent, rt);
        }

        for (const id of [...state.runtime.keys()]) {
            if (!state.agents.has(id) || state.agents.get(id).status === 'offline') state.runtime.delete(id);
        }
    }

    function runtime(agent) {
        if (!state.runtime.has(agent.id)) {
            state.runtime.set(agent.id, { pos: null, mode: 'seated', path: null, bubble: null, burst: 0, seed: (agent.id * 7.31) % 23 });
        }
        return state.runtime.get(agent.id);
    }

    function queueSpot(agent) {
        const L = state.layout;
        const waiting = visibleAgents().filter((a) => a.status === 'waiting').sort((a, b) => a.id - b.id);
        const index = Math.max(0, waiting.findIndex((a) => a.id === agent.id));
        const perRow = 4;
        return {
            x: L.boss.x + 0.8 + (index % perRow) * 0.75,
            y: L.boss.y + 1.4 + Math.floor(index / perRow) * 0.9,
        };
    }

    function route(from, to, fromAisle, toAisle) {
        const L = state.layout;
        if (Math.abs(fromAisle - toAisle) < 0.01) {
            return [[from.x, from.y], [from.x, fromAisle], [to.x, toAisle], [to.x, to.y]];
        }
        return [
            [from.x, from.y],
            [from.x, fromAisle],
            [L.lane, fromAisle],
            [L.lane, toAisle],
            [to.x, toAisle],
            [to.x, to.y],
        ];
    }

    function pathLength(path) {
        let total = 0;
        for (let i = 1; i < path.length; i++) total += Math.hypot(path[i][0] - path[i - 1][0], path[i][1] - path[i - 1][1]);
        return total;
    }

    function walk(rt, path, then) {
        rt.path = path;
        rt.pathStart = now();
        rt.pathDur = Math.max(0.6, pathLength(path) / SPEED);
        rt.mode = 'walking';
        rt.then = then;
    }

    function syncPlacement(agent, rt) {
        const L = state.layout;
        const seat = L.seats.get(agent.id);
        if (!seat || rt.mode === 'walking') return;

        if (rt.mode === 'visit' || rt.mode === 'lounge') {
            if (agent.status === 'waiting') {
                const spot = queueSpot(agent);
                const from = rt.away ? rt.away.aisle : rt.pos.y;
                rt.away = null;
                rt.carry = false;
                walk(rt, route(rt.pos, spot, from, L.boss.y - 0.7), 'standing');
                rt.target = spot;
            } else if (rt.mode === 'lounge' && agent.status !== 'idle' && agent.status !== 'done') {
                comeBack(agent, rt);
            }
            return;
        }

        if (agent.status === 'waiting' && rt.mode === 'seated') {
            const spot = queueSpot(agent);
            walk(rt, route(rt.pos, spot, seat.aisle, L.boss.y - 0.7), 'standing');
            rt.target = spot;
        } else if (agent.status === 'waiting' && rt.mode === 'standing') {
            const spot = queueSpot(agent);
            rt.pos = spot;
        } else if (agent.status !== 'waiting' && rt.mode === 'standing') {
            walk(rt, route(rt.pos, seat, L.boss.y - 0.7, seat.aisle), 'seated');
        } else if (rt.mode === 'seated') {
            rt.pos = { x: seat.x, y: seat.y };
        }
    }

    const CHAT = ['More coffee?', 'Nice deploy, by the way', 'Who finished the milk?', 'Tests were green!', `${DOG_NAME} is extra sweet today`, 'Busy day ahead?', 'Lovely cuppa', 'Just stretching my legs'];
    const CHAT_ALONE = ['Quick break', 'Lovely cuppa', 'Phew, time to breathe'];
    const CHAT_DRINKS = ['Cheers!', 'Have a great weekend!', 'More snacks?', 'What a week!', 'To all the green tests!', 'Whose round is it?'];

    function goAway(agent, rt, spot, aisle, kind, stay) {
        const seat = state.layout.seats.get(agent.id);
        if (!seat || rt.mode !== 'seated') return false;
        rt.away = { kind, aisle, until: now() + stay, slot: null };
        walk(rt, route(rt.pos, spot, seat.aisle, aisle), kind);
        return true;
    }

    function comeBack(agent, rt) {
        const seat = state.layout.seats.get(agent.id);
        const from = rt.away ? rt.away.aisle : rt.pos.y;
        rt.away = null;
        rt.carry = false;
        if (!seat) return;
        walk(rt, route(rt.pos, seat, from, seat.aisle), 'seated');
    }

    function lounge() {
        const L = state.layout;
        const t = { x: L.W - 2.2, y: L.D - 0.85 };
        return {
            table: t,
            station: { x: L.W - 0.85, y: L.D - 1.55 },
            cooler: { x: L.W - 0.8, y: L.D - 2.45 },
            spots: [[-0.6, -0.15], [0.6, -0.15], [-0.3, -0.45], [0.35, -0.45], [-0.45, 0.42], [0.45, 0.38]].map(([dx, dy]) => ({ x: t.x + dx, y: t.y + dy })),
        };
    }

    function loungeSpots() {
        return lounge().spots;
    }

    function inLounge() {
        return visibleAgents().filter((a) => {
            const rt = state.runtime.get(a.id);
            return rt && (rt.mode === 'lounge' || (rt.mode === 'walking' && rt.then === 'lounge'));
        });
    }

    let loungeCheck = 0;

    function planLounge(t) {
        if (t < loungeCheck) return;
        loungeCheck = t + 6;
        const L = state.layout;
        const spots = loungeSpots();
        const drinks = isFridayDrinks();
        const there = inLounge();
        const cap = drinks ? spots.length : Math.min(3, spots.length);
        if (there.length >= cap) return;
        if (!drinks && Math.random() > 0.3) return;
        const nowMs = Date.now();
        const free = visibleAgents().filter((a) => {
            const rt = state.runtime.get(a.id);
            return (a.status === 'idle' || a.status === 'done') && rt && rt.mode === 'seated' && L.seats.has(a.id)
                && a.status_changed_at && nowMs - Date.parse(a.status_changed_at) > (drinks ? 20000 : 240000);
        });
        if (!free.length) return;
        const taken = new Set(there.map((a) => (state.runtime.get(a.id).away || {}).slot));
        const slot = spots.findIndex((_, i) => !taken.has(i));
        if (slot < 0) return;
        const agent = free[Math.floor(Math.random() * free.length)];
        const rt = state.runtime.get(agent.id);
        if (goAway(agent, rt, spots[slot], spots[slot].y, 'lounge', drinks ? 900 : 45 + Math.random() * 75)) {
            rt.away.slot = slot;
            rt.nextChat = t + 3 + Math.random() * 4;
        }
    }

    function processVisits() {
        const L = state.layout;
        if (!L) return;
        for (const visit of state.visits.splice(0)) {
            const from = state.agents.get(visit.from);
            const toSeat = L.seats.get(visit.to);
            const fromSeat = from ? L.seats.get(from.id) : null;
            const rt = from ? state.runtime.get(from.id) : null;
            if (!from || !toSeat || !fromSeat || !rt || rt.mode !== 'seated' || from.status === 'waiting') continue;
            if (Math.abs(fromSeat.x - toSeat.x) < 0.01 && Math.abs(fromSeat.y - toSeat.y) < 0.01) continue;
            const spot = { x: Math.min(L.W - 1.2, toSeat.x + (toSeat.x > fromSeat.x ? -0.5 : 0.5)), y: toSeat.aisle };
            if (goAway(from, rt, spot, toSeat.aisle, 'visit', 3.5)) rt.carry = true;
        }
    }

    function stepRuntime(t) {
        if (state.layout) planLounge(t);
        const lounge = inLounge().filter((a) => state.runtime.get(a.id).mode === 'lounge');
        for (const agent of visibleAgents()) {
            const rt = runtime(agent);
            if ((rt.mode === 'visit' || rt.mode === 'lounge') && rt.away && t > rt.away.until) {
                comeBack(agent, rt);
                continue;
            }
            if (rt.mode === 'visit' && rt.carry) rt.carry = false;
            if (rt.mode === 'lounge' && t > (rt.nextChat || 0)) {
                rt.nextChat = t + 7 + Math.random() * 9;
                const lines = isFridayDrinks() ? CHAT_DRINKS : (lounge.length > 1 ? CHAT : CHAT_ALONE);
                if (Math.random() < 0.7) rt.bubble = { text: lines[Math.floor(Math.random() * lines.length)], until: t + 4 };
            }
            if (rt.mode !== 'walking' || !rt.path) continue;
            const p = Math.min(1, (t - rt.pathStart) / rt.pathDur);
            const at = pointOnPath(rt.path, p);
            rt.pos = { x: at.x, y: at.y };
            rt.dir = at;
            if (p >= 1) {
                rt.mode = rt.then;
                rt.path = null;
                syncPlacement(agent, rt);
            }
        }
    }

    function pointOnPath(path, p) {
        const lens = [];
        let total = 0;
        for (let i = 1; i < path.length; i++) {
            const l = Math.hypot(path[i][0] - path[i - 1][0], path[i][1] - path[i - 1][1]);
            lens.push(l);
            total += l;
        }
        let d = p * total;
        for (let i = 0; i < lens.length; i++) {
            if (d <= lens[i] || i === lens.length - 1) {
                const q = lens[i] === 0 ? 0 : Math.min(1, d / lens[i]);
                const a = path[i];
                const b = path[i + 1];
                return { x: a[0] + (b[0] - a[0]) * q, y: a[1] + (b[1] - a[1]) * q, dx: b[0] - a[0], dy: b[1] - a[1] };
            }
            d -= lens[i];
        }
        const e = path[path.length - 1];
        return { x: e[0], y: e[1], dx: 0, dy: 0 };
    }

    // ---------- drawing ----------

    function P(x, y, z) {
        const L = state.layout;
        return [L.OX + (x - y) * 16, L.OY + (x + y) * 8 - (z || 0) * 16];
    }

    function poly(pts, fill) {
        ctx.fillStyle = fill;
        ctx.beginPath();
        ctx.moveTo(pts[0][0], pts[0][1]);
        for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]);
        ctx.closePath();
        ctx.fill();
    }

    function box(x, y, z, w, d, h, top, left, right) {
        poly([P(x, y + d, z), P(x + w, y + d, z), P(x + w, y + d, z + h), P(x, y + d, z + h)], left);
        poly([P(x + w, y, z), P(x + w, y + d, z), P(x + w, y + d, z + h), P(x + w, y, z + h)], right);
        poly([P(x, y, z + h), P(x + w, y, z + h), P(x + w, y + d, z + h), P(x, y + d, z + h)], top);
    }

    const FONT = '"IBM Plex Sans", system-ui, -apple-system, sans-serif';

    function fs(css) {
        return Math.max(6, Math.round(css * state.ui * 2) / 2);
    }

    function text(s, x, y, size, color, align, weight) {
        ctx.font = `${weight || 600} ${size}px ${FONT}`;
        ctx.textAlign = align || 'center';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = color;
        ctx.fillText(s, x, y);
    }

    function pill(s, x, y, bg, fg, size) {
        const f = size || fs(11);
        ctx.font = `600 ${f}px ${FONT}`;
        const w = Math.ceil(ctx.measureText(s).width) + Math.round(f * 1.1);
        const h = Math.round(f + 5);
        const bx = Math.round(Math.max(2, Math.min(state.layout.LW - w - 2, x - w / 2)));
        const by = Math.round(y - h);
        ctx.fillStyle = bg;
        ctx.fillRect(bx + 1, by, w - 2, h);
        ctx.fillRect(bx, by + 1, w, h - 2);
        text(s, bx + w / 2, by + Math.round(h / 2 + f * 0.36), f, fg, 'center');
        return { bx, by, w, h };
    }

    function bubble(s, x, y, tone) {
        const bg = tone === 'waiting' ? '#DCE8FF' : tone === 'error' ? '#FFE1DD' : '#FBF6EA';
        const f = fs(11);
        ctx.font = `500 ${f}px ${FONT}`;
        const max = f * 26;
        let label = s;
        while (ctx.measureText(label).width > max && label.length > 4) label = label.slice(0, -2);
        if (label !== s) label = label.trimEnd() + '…';
        const w = Math.ceil(ctx.measureText(label).width) + Math.round(f * 1.2);
        const h = Math.round(f + 7);
        const bx = Math.round(Math.max(2, Math.min(state.layout.LW - w - 2, x - w / 2)));
        const by = Math.round(y - h - 4);
        ctx.fillStyle = '#1A1410';
        ctx.fillRect(bx - 1, by, w + 2, h);
        ctx.fillRect(bx, by - 1, w, h + 2);
        ctx.fillStyle = bg;
        ctx.fillRect(bx, by, w, h);
        const tx = Math.round(Math.max(bx + 3, Math.min(bx + w - 5, x)));
        ctx.fillRect(tx, by + h, 3, 1);
        ctx.fillRect(tx + 1, by + h + 1, 1, 2);
        text(label, bx + w / 2, by + Math.round(h / 2 + f * 0.36), f, '#1A1410', 'center', 500);
    }

    function windowsOf(W) {
        const out = [];
        for (let wx = 2.6; wx + 2 < W - 1; wx += 6.8) out.push(wx);
        return out;
    }

    function shelvesOf(D) {
        const out = [];
        for (let sy = 1.5; sy + 2.4 < D - 0.5; sy += 7.3) out.push(sy);
        return out;
    }

    function coffeeSpot(D) {
        const y0 = D - 3.3;
        if (y0 < 1.2) return null;
        const clock = Math.min(D - 1, 5.2);
        const clash = shelvesOf(D).some((s) => y0 - 0.6 < s + 2.5 && y0 + 1.95 > s - 0.1);
        return clash || (clock > y0 - 0.7 && clock < y0 + 2) ? null : y0;
    }

    function paintStatic() {
        const L = state.layout;
        const { W, D } = L;
        const day = state.day;
        const wall = mix('#23264A', '#444A78', day);
        const wallSide = mix('#1E2142', '#3A3F69', day);
        const panel = mix('#1B1E38', '#323758', day);
        const panelSide = mix('#171A31', '#2B2F4E', day);
        const rail = mix('#3E3A5C', '#7A7099', day);
        const cap = mix('#3A3E66', '#6E74A6', day);

        poly([P(0, D, 0), P(W, D, 0), P(W, D, -0.4), P(0, D, -0.4)], '#6A472B');
        poly([P(W, 0, 0), P(W, D, 0), P(W, D, -0.4), P(W, 0, -0.4)], '#7F5636');
        poly([P(0, D, -0.4), P(W, D, -0.4), P(W, D, -0.46), P(0, D, -0.46)], '#3E2918');
        poly([P(W, 0, -0.4), P(W, D, -0.4), P(W, D, -0.46), P(W, 0, -0.46)], '#4A3220');

        poly([P(0, 0, 0), P(W, 0, 0), P(W, 0, 3), P(0, 0, 3)], wall);
        poly([P(0, 0, 0), P(0, D, 0), P(0, D, 3), P(0, 0, 3)], wallSide);
        poly([P(0, 0, 0), P(W, 0, 0), P(W, 0, 1.0), P(0, 0, 1.0)], panel);
        poly([P(0, 0, 0), P(0, D, 0), P(0, D, 1.0), P(0, 0, 1.0)], panelSide);
        for (let px = 0.9; px < W - 0.4; px += 1.2) {
            poly([P(px, 0, 0.25), P(px + 0.04, 0, 0.25), P(px + 0.04, 0, 0.85), P(px, 0, 0.85)], 'rgba(0,0,0,0.12)');
        }
        for (let py = 0.9; py < D - 0.4; py += 1.2) {
            poly([P(0, py, 0.25), P(0, py + 0.04, 0.25), P(0, py + 0.04, 0.85), P(0, py, 0.85)], 'rgba(0,0,0,0.12)');
        }
        poly([P(0, 0, 1.0), P(W, 0, 1.0), P(W, 0, 1.07), P(0, 0, 1.07)], rail);
        poly([P(0, 0, 1.0), P(0, D, 1.0), P(0, D, 1.07), P(0, 0, 1.07)], shade(rail, 0.85));
        poly([P(0, 0, 0), P(W, 0, 0), P(W, 0, 0.16), P(0, 0, 0.16)], '#4A3524');
        poly([P(0, 0, 0), P(0, D, 0), P(0, D, 0.16), P(0, 0, 0.16)], '#3F2D1F');
        poly([P(-0.3, -0.3, 3), P(W, -0.3, 3), P(W, 0, 3), P(0, 0, 3)], cap);
        poly([P(-0.3, -0.3, 3), P(0, 0, 3), P(0, D, 3), P(-0.3, D, 3)], cap);
        poly([P(W, -0.3, 0), P(W, 0, 0), P(W, 0, 3), P(W, -0.3, 3)], shade(cap, 0.7));
        poly([P(-0.3, D, 0), P(0, D, 0), P(0, D, 3), P(-0.3, D, 3)], shade(cap, 0.6));

        const dusk = Math.max(0, 1 - Math.abs(day - 0.45) / 0.4);
        const sky = mix(mix('#0F1638', '#9ED3F3', day), '#F2A36B', dusk * 0.55);
        const skyTop = mix(mix('#090D26', '#6FB2E3', day), '#8E5A9E', dusk * 0.5);
        const skyline = mix('#060917', '#7AA2BF', day);
        windowsOf(W).forEach((wx, wi) => {
            poly([P(wx - 0.1, 0, 0.92), P(wx + 2.1, 0, 0.92), P(wx + 2.1, 0, 2.58), P(wx - 0.1, 0, 2.58)], '#4E3A28');
            poly([P(wx, 0, 1.0), P(wx + 2, 0, 1.0), P(wx + 2, 0, 2.5), P(wx, 0, 2.5)], sky);
            poly([P(wx, 0, 2.0), P(wx + 2, 0, 2.0), P(wx + 2, 0, 2.5), P(wx, 0, 2.5)], skyTop);
            if (day < 0.5) {
                ctx.fillStyle = `rgba(244,246,250,${(0.9 * (1 - day * 2)).toFixed(2)})`;
                for (let j = 0; j < 5; j++) {
                    const q = P(wx + 0.15 + hash2(wi, j) * 1.7, 0, 1.75 + hash2(j, wi + 9) * 0.7);
                    ctx.fillRect(Math.round(q[0]), Math.round(q[1]), 1, 1);
                }
                if (wi === 0) {
                    const m = P(wx + 1.45, 0, 2.3);
                    ctx.fillStyle = '#F4F1E8';
                    ctx.fillRect(Math.round(m[0]) - 1, Math.round(m[1]) - 2, 3, 4);
                    ctx.fillRect(Math.round(m[0]) - 2, Math.round(m[1]) - 1, 5, 2);
                }
            }
            for (let k = 0; k < 6; k++) {
                const bw = 2 / 6;
                const bx0 = wx + k * bw;
                const h = 0.15 + hash2(Math.round(wx * 10), k) * 0.5;
                poly([P(bx0, 0, 1.0), P(bx0 + bw, 0, 1.0), P(bx0 + bw, 0, 1.0 + h), P(bx0, 0, 1.0 + h)], k % 2 ? skyline : shade(skyline, 1.15));
                if (day < 0.6) {
                    ctx.fillStyle = `rgba(255,213,138,${(0.9 * (1 - day)).toFixed(2)})`;
                    for (let j = 0; j < 4; j++) {
                        const z = 1.08 + Math.floor(j / 2) * 0.14;
                        if (z > 1.0 + h - 0.06 || hash2(k * 7 + j, Math.round(wx * 3)) < 0.45) continue;
                        const q = P(bx0 + 0.07 + (j % 2) * 0.14, 0, z);
                        ctx.fillRect(Math.round(q[0]), Math.round(q[1]), 1, 1);
                    }
                }
            }
            poly([P(wx + 0.15, 0, 1.2), P(wx + 0.35, 0, 1.2), P(wx + 0.75, 0, 2.4), P(wx + 0.55, 0, 2.4)], 'rgba(255,255,255,0.10)');
            poly([P(wx + 0.95, 0, 1.0), P(wx + 1.05, 0, 1.0), P(wx + 1.05, 0, 2.5), P(wx + 0.95, 0, 2.5)], '#4E3A28');
            poly([P(wx, 0, 1.72), P(wx + 2, 0, 1.72), P(wx + 2, 0, 1.78), P(wx, 0, 1.78)], '#4E3A28');
            box(wx - 0.15, 0, 0.9, 2.3, 0.14, 0.05, '#8A6A4A', '#5E4430', '#6B4E36');
        });

        const wb = L.board;
        const bw = L.boardW;
        poly([P(wb - 0.08, 0, BOARD.z0 - 0.08), P(wb + bw + 0.08, 0, BOARD.z0 - 0.08), P(wb + bw + 0.08, 0, BOARD.z1 + 0.08), P(wb - 0.08, 0, BOARD.z1 + 0.08)], '#9AA0AE');
        poly([P(wb, 0, BOARD.z0), P(wb + bw, 0, BOARD.z0), P(wb + bw, 0, BOARD.z1), P(wb, 0, BOARD.z1)], '#EDEBE3');
        box(wb, 0, BOARD.z0 - 0.05, bw, 0.12, 0.04, '#B7BCC8', '#8E93A0', '#A2A7B4');

        for (let gx = 0; gx < W; gx++) {
            for (let gy = 0; gy < D; gy++) {
                const n = hash2(gx, gy);
                poly([P(gx, gy, 0), P(gx + 1, gy, 0), P(gx + 1, gy + 1, 0), P(gx, gy + 1, 0)], shade('#BE8D5B', 0.95 + n * 0.08));
                for (let r = 0; r < 3; r++) {
                    const y0 = gy + r / 3;
                    const tone = hash2(gx * 3 + r, gy * 5 + 1);
                    if (tone > 0.55) poly([P(gx, y0, 0), P(gx + 1, y0, 0), P(gx + 1, y0 + 1 / 3, 0), P(gx, y0 + 1 / 3, 0)], tone > 0.8 ? 'rgba(255,228,180,0.08)' : 'rgba(80,45,15,0.08)');
                    poly([P(gx, y0, 0), P(gx + 1, y0, 0), P(gx + 1, y0 + 0.03, 0), P(gx, y0 + 0.03, 0)], 'rgba(70,38,12,0.22)');
                    const e = gx + ((r * 0.37 + gy * 0.53 + n) % 1);
                    poly([P(e, y0, 0), P(e + 0.035, y0, 0), P(e + 0.035, y0 + 1 / 3, 0), P(e, y0 + 1 / 3, 0)], 'rgba(70,38,12,0.2)');
                }
            }
        }
        for (let k = 0; k < 4; k++) {
            const a = (0.18 - k * 0.04).toFixed(2);
            const s = k * 0.16;
            poly([P(0, s, 0), P(W, s, 0), P(W, s + 0.16, 0), P(0, s + 0.16, 0)], `rgba(25,12,4,${a})`);
            poly([P(s, 0, 0), P(s + 0.16, 0, 0), P(s + 0.16, D, 0), P(s, D, 0)], `rgba(25,12,4,${a})`);
        }

        const b = L.boss;
        poly([P(b.x, b.y, 0.01), P(b.x + b.w, b.y, 0.01), P(b.x + b.w, b.y + b.d, 0.01), P(b.x, b.y + b.d, 0.01)], '#4A3F63');
        for (let gx = 0; gx < Math.ceil(b.w * 2); gx++) {
            for (let gy = 0; gy < Math.ceil(b.d * 2); gy++) {
                if ((gx + gy) % 2) continue;
                const x0 = b.x + gx / 2;
                const y0 = b.y + gy / 2;
                const x1 = Math.min(b.x + b.w, x0 + 0.5);
                const y1 = Math.min(b.y + b.d, y0 + 0.5);
                poly([P(x0, y0, 0.012), P(x1, y0, 0.012), P(x1, y1, 0.012), P(x0, y1, 0.012)], 'rgba(255,255,255,0.035)');
            }
        }
        rug(b.desk.x - 0.4, b.desk.y - 0.45, b.x + b.w - 0.15, b.y + b.d - 0.15, '#7AA83A', 0.42);

        for (const island of L.islands) {
            const dim = state.filter !== 'all' && String(island.key) !== String(state.filter);
            rug(island.x - 0.2, island.y - 0.15, island.x + island.w + 0.1, island.y + island.d + 0.15, projectColor(island.key), dim ? 0.15 : 0.5);
        }

        const books = ['#B5482F', '#2F6649', '#2F4C7B', '#C98A1E', '#6A4C9C', '#8A6A45', '#D9C9A3'];
        for (const sy of shelvesOf(D)) {
            box(0.02, sy, 0, 0.42, 2.4, 2.05, '#6B4E35', '#4E3826', '#5C432D');
            for (let sh = 0; sh < 4; sh++) {
                const bz = 0.15 + sh * 0.47;
                poly([P(0.44, sy + 0.05, bz - 0.04), P(0.44, sy + 2.35, bz - 0.04), P(0.44, sy + 2.35, bz), P(0.44, sy + 0.05, bz)], '#3E2C1E');
                for (let k = 0; k < 11; k++) {
                    if (hash2(sh * 13 + k, Math.round(sy)) < 0.12) continue;
                    const by = sy + 0.1 + k * 0.2;
                    const bh = 0.24 + hash2(k, sh) * 0.1;
                    poly([P(0.44, by, bz), P(0.44, by + 0.16, bz), P(0.44, by + 0.16, bz + bh), P(0.44, by, bz + bh)], books[(k * 3 + sh) % books.length]);
                    poly([P(0.44, by, bz + bh * 0.7), P(0.44, by + 0.16, bz + bh * 0.7), P(0.44, by + 0.16, bz + bh * 0.76), P(0.44, by, bz + bh * 0.76)], 'rgba(255,255,255,0.18)');
                }
            }
        }

        if (L.coffee !== null) drawCoffee(L.coffee);
    }

    function rug(x0, y0, x1, y1, color, a) {
        poly([P(x0, y0, 0.015), P(x1, y0, 0.015), P(x1, y1, 0.015), P(x0, y1, 0.015)], rgba(mix(color, '#2A1C12', 0.5), a));
        const i = 0.12;
        const e = 0.05;
        const c = rgba(mix(color, '#FFFFFF', 0.15), Math.min(1, a * 1.3));
        poly([P(x0 + i, y0 + i, 0.02), P(x1 - i, y0 + i, 0.02), P(x1 - i, y0 + i + e, 0.02), P(x0 + i, y0 + i + e, 0.02)], c);
        poly([P(x0 + i, y1 - i - e, 0.02), P(x1 - i, y1 - i - e, 0.02), P(x1 - i, y1 - i, 0.02), P(x0 + i, y1 - i, 0.02)], c);
        poly([P(x0 + i, y0 + i, 0.02), P(x0 + i + e, y0 + i, 0.02), P(x0 + i + e, y1 - i, 0.02), P(x0 + i, y1 - i, 0.02)], c);
        poly([P(x1 - i - e, y0 + i, 0.02), P(x1 - i, y0 + i, 0.02), P(x1 - i, y1 - i, 0.02), P(x1 - i - e, y1 - i, 0.02)], c);
    }

    function drawCoffee(y0) {
        box(0.06, y0 - 0.6, 0, 0.42, 0.42, 0.95, '#E4E6EA', '#B5B9C2', '#CBCFD6');
        box(0.12, y0 - 0.54, 0.95, 0.3, 0.3, 0.38, '#A8DBF5', '#6FAED6', '#88C3E6');
        box(0.02, y0, 0, 0.62, 1.9, 0.85, '#D9D2C2', '#9C9485', '#B4AC9C');
        for (const dy of [0.63, 1.27]) {
            poly([P(0.64, y0 + dy, 0.08), P(0.64, y0 + dy + 0.03, 0.08), P(0.64, y0 + dy + 0.03, 0.78), P(0.64, y0 + dy, 0.78)], '#8A8274');
        }
        box(0.0, y0 - 0.02, 0.85, 0.68, 1.94, 0.06, '#8A6A4A', '#5E4430', '#6B4E36');
        box(0.08, y0 + 0.15, 0.91, 0.42, 0.45, 0.55, '#3A3A40', '#26262B', '#2F2F35');
        poly([P(0.5, y0 + 0.25, 1.25), P(0.5, y0 + 0.33, 1.25), P(0.5, y0 + 0.33, 1.31), P(0.5, y0 + 0.25, 1.31)], ACCENT);
        box(0.22, y0 + 0.82, 0.91, 0.13, 0.13, 0.13, '#F4F1E8', '#CFC9BA', '#E2DCCD');
        box(0.22, y0 + 1.05, 0.91, 0.13, 0.13, 0.13, '#E58F65', '#B86B47', '#CC7B55');
        box(0.15, y0 + 1.35, 0.91, 0.35, 0.4, 0.08, '#C98A1E', '#9A6A16', '#B07A1A');
    }

    let staticLayer = null;

    function drawStatic() {
        const L = state.layout;
        const key = `${Math.round(state.day * 24)}|${state.filter}`;
        if (!staticLayer || staticLayer.layout !== L || staticLayer.key !== key) {
            const layer = document.createElement('canvas');
            layer.width = canvas.width;
            layer.height = canvas.height;
            const main = ctx;
            ctx = layer.getContext('2d');
            ctx.setTransform(2, 0, 0, 2, 0, 0);
            ctx.imageSmoothingEnabled = false;
            try { paintStatic(); } finally { ctx = main; }
            staticLayer = { canvas: layer, layout: L, key };
        }
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.drawImage(staticLayer.canvas, 0, 0);
        ctx.setTransform(2, 0, 0, 2, 0, 0);
    }

    function wallText(s, wx, z, size, color, blur) {
        const o = P(wx, 0, z);
        ctx.save();
        ctx.setTransform(2, 1, 0, 2, o[0] * 2, o[1] * 2);
        ctx.font = `700 ${size}px ${FONT}`;
        ctx.letterSpacing = `${Math.round(size * 0.12)}px`;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        if (blur) { ctx.shadowColor = color; ctx.shadowBlur = blur; }
        ctx.fillStyle = color;
        ctx.fillText(s, 0, 0);
        ctx.restore();
    }

    function drawSign(t, night) {
        const L = state.layout;
        const lvl = neonLevel(t);
        const size = Math.min(15, Math.max(11, fs(15)));
        ctx.save();
        ctx.font = `700 ${size}px ${FONT}`;
        ctx.letterSpacing = `${Math.round(size * 0.12)}px`;
        const width = ctx.measureText(SIGN).width / 16;
        ctx.restore();
        const x0 = L.sign - 0.22;
        const x1 = L.sign + width + 0.12;
        const z0 = 1.9;
        const z1 = 2.05 + size * 0.72 / 16 + 0.14;
        poly([P(x0 - 0.04, 0, z0 - 0.04), P(x1 + 0.04, 0, z0 - 0.04), P(x1 + 0.04, 0, z1 + 0.04), P(x0 - 0.04, 0, z1 + 0.04)], '#3A3F55');
        poly([P(x0, 0, z0), P(x1, 0, z0), P(x1, 0, z1), P(x0, 0, z1)], '#0E1016');
        if (lvl > 0.5) {
            ctx.globalAlpha = 0.55 + 0.3 * night;
            wallText(SIGN, L.sign, 2.05, size, ACCENT, 6 + 8 * night);
            ctx.globalAlpha = 1;
            wallText(SIGN, L.sign, 2.05, size, mix(ACCENT, '#FFFFFF', 0.35), 0);
        } else {
            wallText(SIGN, L.sign, 2.05, size, shade(ACCENT, 0.4), 0);
        }
    }

    function neonLevel(t) {
        return hash2(Math.floor(t * 10), 3) < 0.03 ? 0.35 : 1;
    }

    function drawRoomLive(t) {
        const L = state.layout;
        const { D } = L;
        const day = state.day;
        const night = 1 - day;

        if (day > 0.02) {
            for (const wx of windowsOf(L.W)) {
                ctx.globalAlpha = (0.11 + 0.02 * Math.sin(t * 0.5)) * day;
                poly([P(wx, 0.12, 0), P(wx + 2, 0.12, 0), P(wx + 3.2, 3.4, 0), P(wx + 1.2, 3.4, 0)], '#FFD58A');
                ctx.globalAlpha = 1;
                for (let k = 0; k < 7; k++) {
                    const v = ((t * 0.04 + hash2(k, Math.round(wx))) % 1) * 3.2;
                    const u = (hash2(Math.round(wx), k) * 2 + Math.sin(t * 0.3 + k) * 0.15 + 2) % 2;
                    const z = (1 - v / 3.4) * 1.9 + 0.25 + Math.sin(t * 0.7 + k * 2) * 0.08;
                    const q = P(wx + u + v * (1.2 / 3.4), v, z);
                    const tw = 0.5 + 0.5 * Math.sin(t * 2 + k * 1.7);
                    ctx.fillStyle = `rgba(255,238,200,${(0.55 * day * tw).toFixed(2)})`;
                    ctx.fillRect(Math.round(q[0]), Math.round(q[1]), 1, 1);
                }
            }
        }

        const wb = L.board;
        const notes = ['#F5C46B', '#8DB8FF', '#FF8A80', '#7FE0A0', ACCENT];
        const usage = usageNow();
        if (usage) drawUsageBoard(usage, t);
        for (let i = 0; i < (usage ? 0 : 9); i++) {
            const nx = wb + 0.4 + (i % 3) * (L.boardW - 1.1) / 2;
            const nz = 2.3 - Math.floor(i / 3) * 0.5;
            poly([P(nx, 0, nz - 0.22), P(nx + 0.32, 0, nz - 0.22), P(nx + 0.32, 0, nz), P(nx, 0, nz)], notes[(i + Math.floor(t / 6)) % notes.length]);
            poly([P(nx + 0.05, 0, nz - 0.08), P(nx + 0.27, 0, nz - 0.08), P(nx + 0.27, 0, nz - 0.06), P(nx + 0.05, 0, nz - 0.06)], 'rgba(26,20,16,0.35)');
        }

        if (L.sign !== null) drawSign(t, night);

        if (night > 0.6 && !reduceMotion) {
            const wins = windowsOf(L.W);
            const cycle = Math.floor(t / 23);
            const ph = (t % 23) / 0.9;
            if (wins.length && ph < 1 && hash2(cycle, 11) < 0.6) {
                const wx = wins[Math.floor(hash2(cycle, 12) * wins.length) % wins.length];
                for (let k = 0; k < 6; k++) {
                    const u = ph - k * 0.04;
                    if (u < 0 || u > 1) continue;
                    const q = P(wx + 0.2 + u * 1.5, 0, 2.45 - u * 0.5);
                    ctx.fillStyle = `rgba(255,248,225,${((1 - k / 6) * 0.9).toFixed(2)})`;
                    ctx.fillRect(Math.round(q[0]), Math.round(q[1]), k ? 1 : 2, 1);
                }
            }
        }

        if (isFridayDrinks()) drawBunting(t);
        drawPlaque();

        const cl = P(0, Math.min(D - 1, 5.2), 2.3);
        const cx = Math.round(cl[0]);
        const cy = Math.round(cl[1]);
        ctx.fillStyle = '#4E3A28';
        ctx.fillRect(cx - 6, cy - 6, 12, 12);
        ctx.fillStyle = '#EDEBE3';
        ctx.fillRect(cx - 5, cy - 5, 10, 10);
        ctx.fillStyle = '#9AA0AE';
        for (let h = 0; h < 12; h += 3) {
            const a = h / 12 * Math.PI * 2;
            ctx.fillRect(Math.round(cx + Math.cos(a) * 4), Math.round(cy + Math.sin(a) * 4), 1, 1);
        }
        const d = new Date();
        const ha = ((d.getHours() % 12) + d.getMinutes() / 60) / 12 * Math.PI * 2 - Math.PI / 2;
        const ma = d.getMinutes() / 60 * Math.PI * 2 - Math.PI / 2;
        ctx.fillStyle = '#1A1410';
        for (let r = 1; r <= 3; r++) ctx.fillRect(Math.round(cx + Math.cos(ma) * r), Math.round(cy + Math.sin(ma) * r), 1, 1);
        for (let r = 1; r <= 2; r++) ctx.fillRect(Math.round(cx + Math.cos(ha) * r), Math.round(cy + Math.sin(ha) * r), 1, 1);
        ctx.fillStyle = '#D9483B';
        ctx.fillRect(cx, cy, 1, 1);

        if (L.coffee !== null) {
            for (let k = 0; k < 3; k++) {
                const ph = (t * 0.45 + k / 3) % 1;
                const q = P(0.29, L.coffee + 0.37, 1.5 + ph * 0.7);
                ctx.fillStyle = `rgba(255,255,255,${(0.45 * (1 - ph)).toFixed(2)})`;
                ctx.fillRect(Math.round(q[0] + Math.sin(ph * 6 + k * 2) * 1.5), Math.round(q[1]), 1, 2);
            }
        }
    }

    function usageNow() {
        const u = state.usage;
        if (!u || !u.week) return null;
        const t = Date.now();
        const resets = u.week.resets_at ? Date.parse(u.week.resets_at) : null;
        const fresh = resets !== null && resets <= t;
        const used = fresh ? 0 : Math.max(0, Math.min(100, u.week.used));
        const week = 7 * 24 * 3600 * 1000;
        const pace = resets !== null && !fresh ? Math.max(0, Math.min(100, (1 - (resets - t) / week) * 100)) : null;
        const age = u.reported_at ? (t - Date.parse(u.reported_at)) / 60000 : null;
        return { used, left: 100 - used, pace, resets: fresh ? null : resets, fresh, age, stale: age !== null && age > 180, five: u.five_hour ? u.five_hour.used : null };
    }

    function usageTone(used) {
        return used >= 90 ? '#C8372D' : used >= 75 ? '#D97A1E' : '#1F3A6B';
    }

    function usageReset(ms) {
        if (!ms) return null;
        const d = new Date(ms);
        const day = d.toLocaleDateString('en-GB', { weekday: 'short' }).replace('.', '');
        return `${day} ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
    }

    function usageAge(min) {
        if (min === null) return '';
        if (min < 2) return 'just now';
        if (min < 60) return `${Math.round(min)} min ago`;
        if (min < 48 * 60) return Math.round(min / 60) === 1 ? '1 hour ago' : `${Math.round(min / 60)} hours ago`;
        return `${Math.round(min / 1440)} days ago`;
    }

    function usageSummary(u) {
        const parts = [`Weekly tokens: ${u.used}% used, ${u.left}% left`];
        if (u.fresh) parts.push('new week started');
        else if (u.resets) parts.push(`resets ${usageReset(u.resets)}`);
        if (u.pace !== null) parts.push(u.used > u.pace + 3 ? `ahead of pace (${Math.round(u.pace)}% of the week has passed)` : `on track (${Math.round(u.pace)}% of the week has passed)`);
        if (u.five !== null) parts.push(`5-hour window: ${u.five}%`);
        parts.push(`updated ${usageAge(u.age)}`);
        return parts.join(' · ');
    }

    function drawUsageBoard(u, t) {
        const L = state.layout;
        const o = P(L.board, 0, BOARD.z1);
        const BW = L.boardW * 16;
        const BH = (BOARD.z1 - BOARD.z0) * 16;
        const PIX = '"Pixelify Sans", "IBM Plex Sans", system-ui, sans-serif';
        const ink = u.stale ? '#8E93A0' : '#1F3A6B';
        const tone = u.stale ? '#8E93A0' : usageTone(u.used);

        const g = state.gauge || (state.gauge = { v: u.left });
        g.v += (u.left - g.v) * (reduceMotion ? 1 : 0.06);
        const wobble = reduceMotion || u.stale ? 0 : Math.sin(t * 3.1) * 0.6 + Math.sin(t * 7.3) * 0.25;

        ctx.save();
        ctx.setTransform(2, 1, 0, 2, o[0] * 2, o[1] * 2);
        ctx.textBaseline = 'alphabetic';

        const split = Math.round(BW * 0.5);
        const r = Math.min(split / 2 - 3, BH - 11);
        const cx = 3 + r + 1;
        const cy = BH - 4.5;

        ctx.font = `700 4.4px ${FONT}`;
        ctx.letterSpacing = '0.7px';
        ctx.fillStyle = ink;
        ctx.textAlign = 'left';
        ctx.fillText('WEEK TANK', 3, 6);
        ctx.letterSpacing = '0px';

        const seg = 12;
        const thick = Math.max(3, r * 0.28);
        for (let i = 0; i < seg; i++) {
            const a0 = Math.PI + (i / seg) * Math.PI + 0.05;
            const a1 = Math.PI + ((i + 1) / seg) * Math.PI - 0.05;
            const f = i / (seg - 1);
            const lit = (i + 0.5) / seg * 100 <= g.v;
            const col = f < 0.2 ? '#D9483B' : f < 0.45 ? '#E5A23A' : '#4FA44A';
            ctx.beginPath();
            ctx.arc(cx, cy, r, a0, a1);
            ctx.arc(cx, cy, r - thick, a1, a0, true);
            ctx.closePath();
            ctx.fillStyle = lit && !u.stale ? col : 'rgba(31,58,107,0.13)';
            ctx.fill();
        }

        ctx.font = `700 4px ${FONT}`;
        ctx.textAlign = 'center';
        ctx.fillStyle = '#C8372D';
        ctx.fillText('E', cx - r + thick / 2, cy + 4.2);
        ctx.fillStyle = '#2F7D32';
        ctx.fillText('F', cx + r - thick / 2, cy + 4.2);

        if (u.pace !== null && !u.stale) {
            const pa = Math.PI + ((100 - u.pace) / 100) * Math.PI;
            ctx.save();
            ctx.translate(cx + Math.cos(pa) * (r + 1.8), cy + Math.sin(pa) * (r + 1.8));
            ctx.rotate(pa + Math.PI / 2);
            ctx.fillStyle = ink;
            ctx.beginPath();
            ctx.moveTo(0, 1.6);
            ctx.lineTo(-1.4, -0.8);
            ctx.lineTo(1.4, -0.8);
            ctx.closePath();
            ctx.fill();
            ctx.restore();
        }

        const na = Math.PI + (Math.max(0, Math.min(100, g.v + wobble)) / 100) * Math.PI;
        ctx.strokeStyle = '#1A1410';
        ctx.lineWidth = 1.2;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(cx + Math.cos(na) * (r - 1.5), cy + Math.sin(na) * (r - 1.5));
        ctx.stroke();
        ctx.fillStyle = '#1A1410';
        ctx.beginPath();
        ctx.arc(cx, cy, 1.8, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = tone;
        ctx.beginPath();
        ctx.arc(cx, cy, 0.8, 0, Math.PI * 2);
        ctx.fill();

        ctx.strokeStyle = 'rgba(31,58,107,0.22)';
        ctx.lineWidth = 0.5;
        ctx.beginPath();
        ctx.moveTo(split + 1.5, 4);
        ctx.lineTo(split + 1.5, BH - 3);
        ctx.stroke();

        const tx = BW - 3;
        ctx.textAlign = 'right';
        ctx.fillStyle = tone;
        ctx.font = `700 ${Math.round(BH * 0.42)}px ${PIX}`;
        ctx.fillText(`${u.used}%`, tx, BH * 0.42);
        ctx.font = `600 3.4px ${FONT}`;
        ctx.letterSpacing = '0.4px';
        ctx.fillStyle = ink;
        ctx.fillText('USED', tx, BH * 0.42 + 4.4);
        ctx.letterSpacing = '0px';
        ctx.font = `700 ${Math.round(BH * 0.18)}px ${PIX}`;
        ctx.fillStyle = u.stale ? ink : u.left <= 10 ? '#C8372D' : u.left <= 25 ? '#D97A1E' : '#2F7D32';
        ctx.fillText(`${u.left}% left`, tx, BH * 0.79);
        ctx.font = `500 3.4px ${FONT}`;
        ctx.fillStyle = '#6B7180';
        const foot = u.stale ? `updated ${usageAge(u.age)}` : u.fresh ? 'new week' : (u.resets ? `resets ${usageReset(u.resets)}` : '');
        ctx.fillText(foot, tx, BH - 1.8);

        ctx.restore();
    }

    function boardHit(e) {
        const L = state.layout;
        if (!L) return false;
        const r = canvas.getBoundingClientRect();
        const sx = (e.clientX - r.left) / r.width * L.LW;
        const sy = (e.clientY - r.top) / r.height * L.LH;
        const x = (sx - L.OX) / 16;
        const z = (L.OY + x * 8 - sy) / 16;
        return x >= L.board && x <= L.board + L.boardW && z >= BOARD.z0 && z <= BOARD.z1;
    }

    function drawBunting(t) {
        const L = state.layout;
        const cols = ['#F5C46B', '#E58F65', '#8DB8FF', '#7FE0A0', ACCENT, '#E57A9A'];
        const hooks = [];
        for (let x = 0.6; x < L.W - 0.5; x += 2.4) hooks.push(x);
        let k = 0;
        for (let i = 1; i < hooks.length; i++) {
            const a = hooks[i - 1];
            const b = hooks[i];
            let prev = null;
            for (let u = 0; u <= 1.0001; u += 0.125) {
                const x = a + (b - a) * u;
                const z = 2.86 - Math.sin(Math.PI * u) * 0.16;
                const q = P(x, 0.02, z);
                if (prev) {
                    ctx.strokeStyle = 'rgba(40,30,20,0.6)';
                    ctx.lineWidth = 0.6;
                    ctx.beginPath();
                    ctx.moveTo(prev[0], prev[1]);
                    ctx.lineTo(q[0], q[1]);
                    ctx.stroke();
                }
                if (u > 0.05 && u < 0.95) {
                    const sway = Math.sin(t * 2 + k) * 0.02;
                    poly([P(x - 0.11, 0.02, z), P(x + 0.11, 0.02, z), P(x + sway, 0.02, z - 0.26)], cols[k % cols.length]);
                    k++;
                }
                prev = q;
            }
        }
    }

    function wallTextCentered(s, wx, z, size, color, weight) {
        ctx.save();
        ctx.font = `${weight || 700} ${size}px ${FONT}`;
        const w = ctx.measureText(s).width / 16;
        ctx.restore();
        const o = P(wx - w / 2, 0, z);
        ctx.save();
        ctx.setTransform(2, 1, 0, 2, o[0] * 2, o[1] * 2);
        ctx.font = `${weight || 700} ${size}px ${FONT}`;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = color;
        ctx.fillText(s, 0, 0);
        ctx.restore();
    }

    function drawPlaque() {
        const L = state.layout;
        const star = state.star;
        if (L.plaque === null || !star) return;
        const x0 = L.plaque;
        poly([P(x0 - 0.05, 0, 1.12), P(x0 + 1.55, 0, 1.12), P(x0 + 1.55, 0, 2.62), P(x0 - 0.05, 0, 2.62)], 'rgba(0,0,0,0.25)');
        poly([P(x0, 0, 1.15), P(x0 + 1.5, 0, 1.15), P(x0 + 1.5, 0, 2.6), P(x0, 0, 2.6)], '#6B4E36');
        poly([P(x0 + 0.08, 0, 1.23), P(x0 + 1.42, 0, 1.23), P(x0 + 1.42, 0, 2.52), P(x0 + 0.08, 0, 2.52)], '#F4EEDC');
        poly([P(x0 + 0.08, 0, 2.26), P(x0 + 1.42, 0, 2.26), P(x0 + 1.42, 0, 2.52), P(x0 + 0.08, 0, 2.52)], '#C98A1E');
        wallTextCentered('MVP', x0 + 0.75, 2.31, 4, '#2A1C12', 700);
        if (portraitsReady) {
            const index = ((star.avatar | 0) % 24 + 24) % 24;
            const cw = PORTRAITS.naturalWidth / 6;
            const ch = PORTRAITS.naturalHeight / 4;
            const o = P(x0 + 0.33, 0, 2.18);
            ctx.save();
            ctx.setTransform(2, 1, 0, 2, o[0] * 2, o[1] * 2);
            ctx.imageSmoothingEnabled = false;
            ctx.drawImage(PORTRAITS, (index % 6) * cw, Math.floor(index / 6) * ch, cw, ch, 0, 0, 13.5, 13.5);
            ctx.restore();
        }
        wallTextCentered(star.name, x0 + 0.75, 1.3, 4, '#2A1C12', 600);
    }

    function drawNight() {
        const n = 1 - state.day;
        if (n < 0.02) return;
        const L = state.layout;
        const pts = [P(-0.3, -0.3, 3), P(L.W, -0.3, 3), P(L.W, 0, 3), P(L.W, 0, 0), P(L.W, L.D, -0.46), P(0, L.D, -0.46), P(-0.3, L.D, 0)];
        ctx.beginPath();
        ctx.moveTo(pts[0][0], pts[0][1]);
        for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]);
        ctx.closePath();
        ctx.fillStyle = `rgba(10,12,40,${(0.4 * n).toFixed(3)})`;
        ctx.fill();
    }

    function glow(x, y, r, color, a) {
        if (a <= 0.003) return;
        const g = ctx.createRadialGradient(x, y, 0, x, y, r);
        g.addColorStop(0, rgba(color, Math.min(1, a)));
        g.addColorStop(1, rgba(color, 0));
        ctx.fillStyle = g;
        ctx.fillRect(x - r, y - r, r * 2, r * 2);
    }

    function floorGlow(x, y, r, color, a) {
        ctx.save();
        ctx.translate(x, y);
        ctx.scale(1, 0.5);
        glow(0, 0, r, color, a);
        ctx.restore();
    }

    function drawGlows(t) {
        const L = state.layout;
        const n = 1 - state.day;
        ctx.save();
        ctx.globalCompositeOperation = 'lighter';
        for (const island of L.islands) {
            const dim = state.filter !== 'all' && String(island.key) !== String(state.filter);
            if (n > 0.02) {
                const c = P(island.x + island.w / 2, island.y + island.d / 2, 0);
                floorGlow(c[0], c[1], 22 + island.w * 7, '#FFB866', (dim ? 0.05 : 0.14) * n);
            }
            for (const desk of island.desks) {
                const agent = state.agents.get(desk.agentId);
                if (agent && n > 0.05) {
                    const lamp = P(desk.x + 0.5, desk.row === 0 ? desk.y + 0.25 : desk.y + 0.3, 0.42);
                    const home = agent.status !== 'waiting';
                    floorGlow(lamp[0], lamp[1], 15, '#FFD08A', (home ? 0.16 : 0.06) * n * (dim ? 0.3 : 1));
                }
                if (!agent || (agent.status !== 'working' && agent.status !== 'error')) continue;
                const m = P(desk.x + 0.51, desk.row === 0 ? desk.y + 0.38 : desk.y + 0.15, 0.62);
                const err = agent.status === 'error';
                const pulse = err ? (Math.floor(t * 6) % 2 ? 1 : 0.5) : 1;
                glow(m[0], m[1], err ? 16 : 13, err ? '#FF5A4A' : '#8FE3B0', (0.08 + 0.22 * n) * pulse * (dim ? 0.3 : 1));
            }
        }
        const b = L.boss;
        if (n > 0.02) {
            const lp = P(b.lamp.x, b.lamp.y, 0);
            floorGlow(lp[0], lp[1], 36, '#FFC27A', 0.2 * n);
            const lh = P(b.lamp.x, b.lamp.y, 1.2);
            glow(lh[0], lh[1] - 2, 9, '#FFDDA6', 0.45 * n);
        }
        const bm = P(b.desk.x + 0.67, b.desk.y + 0.15, 0.65);
        glow(bm[0], bm[1], 14, '#8DB8FF', 0.1 + 0.2 * n);
        if (L.sign !== null) {
            const s = P(L.sign + 1.1, 0.4, 1.9);
            floorGlow(s[0], s[1], 34, ACCENT, (0.03 + 0.12 * n) * neonLevel(t));
        }
        ctx.restore();
    }

    function botPos(t) {
        const L = state.layout;
        const x0 = 1.05;
        const y0 = 1.3;
        const x1 = L.W - 0.35;
        const y1 = L.D - 0.3;
        const pts = [[x0, y0], [x0, y1], [x1, y1], [x1, y0], [x0, y0]];
        const per = 2 * ((x1 - x0) + (y1 - y0));
        const run = per / 0.55;
        const tt = t % (run + 12);
        if (tt > run) return { x: x0, y: y0, docked: true };
        let d = tt * 0.55;
        for (let i = 1; i < pts.length; i++) {
            const l = Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]);
            if (d <= l) {
                const q = d / l;
                return { x: pts[i - 1][0] + (pts[i][0] - pts[i - 1][0]) * q, y: pts[i - 1][1] + (pts[i][1] - pts[i - 1][1]) * q };
            }
            d -= l;
        }
        return { x: x0, y: y0 };
    }

    function drawBot(pos, t) {
        const p = P(pos.x, pos.y, 0);
        const x = Math.round(p[0]);
        const y = Math.round(p[1]);
        ctx.fillStyle = 'rgba(30,18,8,0.35)';
        ctx.fillRect(x - 5, y - 1, 11, 3);
        ctx.fillStyle = '#23262E';
        ctx.fillRect(x - 5, y - 4, 11, 4);
        ctx.fillRect(x - 4, y - 5, 9, 6);
        ctx.fillStyle = '#454B59';
        ctx.fillRect(x - 4, y - 5, 9, 2);
        ctx.fillRect(x - 3, y - 6, 7, 1);
        ctx.fillStyle = pos.docked ? '#F5C46B' : (Math.floor(t * 2) % 2 ? ACCENT : '#4E6B2A');
        ctx.fillRect(x, y - 5, 2, 1);
    }

    function drawLamp(x, y) {
        const base = P(x, y, 0);
        const top = P(x, y, 1.15);
        const bx = Math.round(base[0]);
        const by = Math.round(base[1]);
        const ty = Math.round(top[1]);
        const on = state.day < 0.9;
        ctx.fillStyle = 'rgba(30,18,8,0.35)';
        ctx.fillRect(bx - 3, by - 1, 7, 2);
        ctx.fillStyle = '#2A2A30';
        ctx.fillRect(bx - 2, by - 2, 5, 2);
        ctx.fillRect(bx, ty, 1, by - ty);
        ctx.fillStyle = on ? '#FFE3B0' : '#E8DCC4';
        ctx.fillRect(bx - 4, ty - 5, 9, 5);
        ctx.fillRect(bx - 3, ty - 6, 7, 1);
        ctx.fillStyle = on ? '#F5C46B' : '#C9B895';
        ctx.fillRect(bx - 4, ty - 1, 9, 1);
    }

    function tag(label, x, y, color, active) {
        const f = fs(11);
        ctx.font = `600 ${f}px ${FONT}`;
        const tw = Math.ceil(ctx.measureText(label).width);
        const dot = Math.max(3, Math.round(f * 0.5));
        const pad = Math.round(f * 0.55);
        const w = tw + pad * 3 + dot;
        const h = Math.round(f + 7);
        const bx = Math.round(Math.max(2, Math.min(state.layout.LW - w - 2, x - w / 2)));
        const by = Math.round(y - h);
        const bg = active ? color : '#1B1F27';
        ctx.fillStyle = 'rgba(0,0,0,0.35)';
        ctx.fillRect(bx + 1, by + 2, w, h);
        ctx.fillStyle = bg;
        ctx.fillRect(bx + 1, by, w - 2, h);
        ctx.fillRect(bx, by + 1, w, h - 2);
        ctx.fillStyle = active ? 'rgba(255,255,255,0.25)' : 'rgba(255,255,255,0.06)';
        ctx.fillRect(bx + 1, by, w - 2, 1);
        ctx.fillStyle = active ? '#14161B' : color;
        ctx.fillRect(bx + pad, Math.round(by + (h - dot) / 2), dot, dot);
        text(label, bx + pad * 2 + dot, by + Math.round(h / 2 + f * 0.36), f, active ? '#14161B' : '#F4F6FA', 'left');
        const tx = Math.round(Math.max(bx + 3, Math.min(bx + w - 4, x)));
        ctx.fillStyle = bg;
        ctx.fillRect(tx - 1, by + h, 3, 1);
        ctx.fillRect(tx, by + h + 1, 1, 1);
    }

    function drawBossWalls() {
        const b = state.layout.boss;
        ctx.globalAlpha = 0.16;
        poly([P(b.x, b.y, 0), P(b.x + b.w, b.y, 0), P(b.x + b.w, b.y, 1.8), P(b.x, b.y, 1.8)], '#BFE3FF');
        poly([P(b.x, b.y, 0), P(b.x, b.y + b.d, 0), P(b.x, b.y + b.d, 1.8), P(b.x, b.y, 1.8)], '#BFE3FF');
        ctx.globalAlpha = 0.22;
        poly([P(b.x + 0.4, b.y, 0.5), P(b.x + 0.55, b.y, 0.5), P(b.x + 0.95, b.y, 1.5), P(b.x + 0.8, b.y, 1.5)], '#FFFFFF');
        poly([P(b.x, b.y + 1.6, 0.4), P(b.x, b.y + 1.75, 0.4), P(b.x, b.y + 2.15, 1.4), P(b.x, b.y + 2, 1.4)], '#FFFFFF');
        ctx.globalAlpha = 1;
        poly([P(b.door.x - 0.4, b.y, 0.02), P(b.door.x + 0.4, b.y, 0.02), P(b.door.x + 0.4, b.y, 1.75), P(b.door.x - 0.4, b.y, 1.75)], 'rgba(74,63,99,0.85)');
        for (let k = 0; k * 1.3 <= b.w + 0.01; k++) {
            const px = Math.min(b.x + b.w - 0.06, b.x + k * 1.3);
            if (Math.abs(px - b.door.x) < 0.5) continue;
            poly([P(px, b.y, 0), P(px + 0.06, b.y, 0), P(px + 0.06, b.y, 1.8), P(px, b.y, 1.8)], '#8A97B0');
        }
        for (let k = 1; k * 1.3 <= b.d + 0.01; k++) {
            const py = Math.min(b.y + b.d - 0.06, b.y + k * 1.3);
            poly([P(b.x, py, 0), P(b.x, py + 0.06, 0), P(b.x, py + 0.06, 1.8), P(b.x, py, 1.8)], '#7A87A0');
        }
        poly([P(b.door.x - 0.45, b.y, 1.75), P(b.door.x + 0.45, b.y, 1.75), P(b.door.x + 0.45, b.y, 1.8), P(b.door.x - 0.45, b.y, 1.8)], '#8A97B0');
        poly([P(b.x, b.y, 1.75), P(b.x + b.w, b.y, 1.75), P(b.x + b.w, b.y, 1.85), P(b.x, b.y, 1.85)], '#9AA6BE');
        poly([P(b.x, b.y, 1.75), P(b.x, b.y + b.d, 1.75), P(b.x, b.y + b.d, 1.85), P(b.x, b.y, 1.85)], '#8A97B0');
    }

    function drawDesk(desk, agent, t) {
        const dx = desk.x;
        const dy = desk.y;
        const mode = agent.status;
        const seed = (agent.id * 7.31) % 23;
        if (propsReady) {
            const r = PROP.desk[agent.id % 2];
            const left = P(dx, dy + 0.55, 0);
            const right = P(dx + 1, dy, 0);
            const bottom = P(dx + 1, dy + 0.55, 0);
            const w = right[0] - left[0] + 1;
            const h = Math.round(w * 0.78);
            ctx.drawImage(PROPS, r[0], r[1], r[2], r[3], Math.round(left[0]), Math.round(bottom[1]) - h + 1, w, h);
        } else {
            box(dx, dy, 0.38, 1.0, 0.55, 0.07, '#B07A47', '#7E5230', '#946139');
            box(dx + 0.05, dy + 0.05, 0, 0.06, 0.06, 0.38, '#5E3D24', '#4A301C', '#5E3D24');
            box(dx + 0.89, dy + 0.44, 0, 0.06, 0.06, 0.38, '#5E3D24', '#4A301C', '#5E3D24');
        }
        const lit = mode === 'working' || mode === 'error';
        if (desk.row === 0) {
            box(dx + 0.3, dy + 0.38, 0.45, 0.42, 0.1, 0.34, '#D8D2C0', '#B9B29E', '#C9C2AE');
            if (lit) {
                const pp = P(dx + 0.51, dy + 0.38, 0.62);
                ctx.fillStyle = mode === 'error' ? 'rgba(217,72,59,0.5)' : 'rgba(143,227,176,0.25)';
                ctx.fillRect(Math.round(pp[0]) - 6, Math.round(pp[1]) - 4, 12, 8);
            }
        } else {
            box(dx + 0.3, dy + 0.05, 0.45, 0.42, 0.1, 0.34, '#D8D2C0', '#C9C2AE', '#B9B29E');
            const yy = dy + 0.15;
            const base = mode === 'error' ? (Math.floor(t * 6) % 2 ? '#B33A2E' : '#5A1E1A') : (lit ? '#2E5E46' : '#1E2A24');
            poly([P(dx + 0.34, yy, 0.49), P(dx + 0.68, yy, 0.49), P(dx + 0.68, yy, 0.75), P(dx + 0.34, yy, 0.75)], base);
            if (mode === 'working') {
                ctx.fillStyle = '#8FE3B0';
                const rows = Math.floor(t * 3 + seed) % 4;
                for (let i = 0; i <= rows; i++) {
                    const p1 = P(dx + 0.38, yy, 0.71 - i * 0.05);
                    ctx.fillRect(Math.round(p1[0]), Math.round(p1[1]), 2 + ((i * 3 + agent.id) % 4), 1);
                }
            }
            box(dx + 0.3, dy + 0.3, 0.45, 0.4, 0.14, 0.02, '#3A3A40', '#2A2A30', '#323238');
        }
    }

    function drawPerson(agent, rt, t, selected, hovered) {
        const p = P(rt.pos.x, rt.pos.y, 0);
        const x = Math.round(p[0]);
        const y = Math.round(p[1]);
        const walking = rt.mode === 'walking';
        const seated = rt.mode === 'seated';
        const status = agent.status;
        const f = Math.floor(t * (walking ? 8 : 5) + rt.seed) % 2;
        const shake = !walking && status === 'error' ? Math.floor(t * 16) % 2 : 0;
        const bob = walking ? (f ? -1 : 0) : 0;
        const X = x + shake;
        const seat = state.layout.seats.get(agent.id);
        const back = seated ? seat && seat.row === 1 : (rt.dir ? (rt.dir.dx <= 0 && rt.dir.dy <= 0) : false);

        if (selected) {
            ctx.fillStyle = ACCENT;
            ctx.fillRect(x - 7, y + 1, 14, 1);
            ctx.fillRect(x - 9, y, 2, 1);
            ctx.fillRect(x + 7, y, 2, 1);
            ctx.fillRect(x - 7, y - 1, 14, 1);
        }
        if (hovered && !selected) {
            ctx.fillStyle = 'rgba(244,246,250,0.75)';
            ctx.fillRect(x - 7, y + 1, 14, 1);
            ctx.fillRect(x - 9, y, 2, 1);
            ctx.fillRect(x + 7, y, 2, 1);
            ctx.fillRect(x - 7, y - 1, 14, 1);
        }
        ctx.fillStyle = 'rgba(40,24,10,0.35)';
        ctx.fillRect(x - 5, y - 1, 10, 2);
        ctx.fillStyle = 'rgba(40,24,10,0.18)';
        ctx.fillRect(x - 6, y, 12, 1);

        const row = ((agent.avatar | 0) % 24 + 24) % 24;
        let up = back;
        let flip = false;
        if (!seated && rt.dir && (rt.dir.dx || rt.dir.dy)) {
            const sx = rt.dir.dx - rt.dir.dy;
            up = rt.dir.dx + rt.dir.dy < 0;
            flip = up ? sx > 0 : sx < 0;
        }
        const frame = (up ? 2 : 0) + (walking && f ? 1 : 0);
        const celebrating = rt.burst > now();
        const lift = celebrating ? (f ? 3 : 1) : (status === 'working' && seated ? f : 0);
        const crop = seated ? 0.62 : 1;
        const sh = Math.round(SPRITE.h * crop);
        const dh = sh / 2;
        const dw = SPRITE.w / 2;
        const foot = seated ? y - 7 : y + 1;
        const top = foot - dh - lift + bob;

        if (seated && !back) {
            ctx.fillStyle = '#373B48';
            ctx.fillRect(X - 6, y - 19, 12, 10);
        }
        if (seated) {
            ctx.fillStyle = '#2C2F3A';
            ctx.fillRect(X - 6, y - 9, 12, 3);
            ctx.fillRect(X - 1, y - 6, 2, 5);
        }

        if (spritesReady) {
            ctx.save();
            if (flip) {
                ctx.translate(X, 0);
                ctx.scale(-1, 1);
                ctx.translate(-X, 0);
            }
            if (status === 'error') ctx.filter = 'saturate(0.6)';
            ctx.drawImage(sprites, frame * SPRITE.w, row * SPRITE.h, SPRITE.w, sh, X - dw / 2, top, dw, dh);
            ctx.restore();
        }

        if (status === 'waiting' && !walking && !seated) {
            const wave = Math.floor(t * 2.5 + rt.seed) % 2;
            ctx.fillStyle = '#3A3F55';
            ctx.fillRect(X - 7 - wave, top + 6, 2, 7);
            ctx.fillStyle = '#E8C4A0';
            ctx.fillRect(X - 7 - wave, top + 3, 2, 3);
            ctx.fillRect(X - 8 - wave, top + 4, 1, 2);
        }
        if (walking && rt.carry) {
            ctx.fillStyle = '#C98A1E';
            ctx.fillRect(X + 4, top + 15, 7, 6);
            ctx.fillStyle = '#F5C46B';
            ctx.fillRect(X + 4, top + 15, 7, 1);
            ctx.fillStyle = '#FBF6EA';
            ctx.fillRect(X + 5, top + 14, 5, 1);
        }
        if (rt.mode === 'lounge') {
            if (isFridayDrinks()) {
                ctx.fillStyle = '#F5C46B';
                ctx.fillRect(X + 5, top + 15, 3, 5);
                ctx.fillStyle = '#FBF6EA';
                ctx.fillRect(X + 5, top + 14, 3, 1);
            } else {
                ctx.fillStyle = '#F4F1E8';
                ctx.fillRect(X + 5, top + 16, 3, 3);
                ctx.fillStyle = '#7A4A2A';
                ctx.fillRect(X + 5, top + 16, 3, 1);
                const ph = (t * 0.8 + rt.seed) % 1;
                ctx.fillStyle = `rgba(255,255,255,${(0.5 * (1 - ph)).toFixed(2)})`;
                ctx.fillRect(X + 6, Math.round(top + 14 - ph * 4), 1, 2);
            }
        }

        if (seated && back) {
            ctx.fillStyle = '#373B48';
            ctx.fillRect(X - 6, y - 20, 12, 12);
            ctx.fillStyle = '#2C2F3A';
            ctx.fillRect(X - 6, y - 20, 12, 1);
        }

        const hy = top + 3;
        const ty = top + 15;
        if (status === 'waiting' && !walking) {
            ctx.fillStyle = '#F4F6FA';
            ctx.fillRect(X + 6, ty - 8, 7, 6);
            ctx.fillStyle = '#3D6BB3';
            ctx.fillRect(X + 7, ty - 6, 5, 1);
            ctx.fillRect(X + 7, ty - 4, 3, 1);
        } else if ((status === 'idle' || status === 'done') && seated && !back && ((t * 0.07 + rt.seed) % 1) < 0.35) {
            ctx.fillStyle = '#F4F1E8';
            ctx.fillRect(X + 6, ty + 1, 3, 3);
            ctx.fillStyle = '#7A4A2A';
            ctx.fillRect(X + 6, ty + 1, 3, 1);
            const ph = (t * 0.8 + rt.seed) % 1;
            ctx.fillStyle = `rgba(255,255,255,${(0.5 * (1 - ph)).toFixed(2)})`;
            ctx.fillRect(X + 7, Math.round(ty - 1 - ph * 4), 1, 2);
        }

        const iy = hy - 4;
        rt.anchor = [X, iy - (selected ? 22 : 10)];
        if (status === 'waiting') {
            pill(agent.question ? '?' : '!', X, iy - (Math.floor(t * 3) % 2), '#3D6BB3', '#FFFFFF', agent.question ? 7 : 6);
        } else if (status === 'error') {
            pill('!', X, iy, Math.floor(t * 6) % 2 ? '#D9483B' : '#8A2A22', '#FFFFFF', 6);
            for (let k = 0; k < 5; k++) {
                const ph = (t * 0.55 + k / 5) % 1;
                const sz = Math.round(2 + ph * 3);
                ctx.fillStyle = `rgba(90,90,100,${((1 - ph) * 0.7).toFixed(2)})`;
                ctx.fillRect(Math.round(X - 6 + ((k * 4) % 12) + Math.sin(ph * 6 + k) * 2), Math.round(iy - 12 - ph * 18), sz, sz);
            }
        } else if (celebrating) {
            pill('ok', X, iy, '#2F8A55', '#FFFFFF', 5);
            const cols = ['#7FE0A0', '#F5C46B', '#8DB8FF', '#FF8A80', ACCENT];
            for (let k = 0; k < 8; k++) {
                const ph = (t * 0.9 + k / 8) % 1;
                ctx.globalAlpha = 1 - ph;
                ctx.fillStyle = cols[k % cols.length];
                ctx.fillRect(Math.round(X + Math.sin(k * 2.39) * (4 + ph * 10)), Math.round(iy - 10 - ph * 12 + ph * ph * 10), 1, 2);
            }
            ctx.globalAlpha = 1;
        } else if (status === 'sleeping') {
            for (let k = 0; k < 2; k++) {
                const ph = (t * 0.45 + k * 0.5) % 1;
                ctx.globalAlpha = 1 - ph;
                text('z', X + 6 + ph * 4, iy - ph * 9, 5 + ph * 3, '#3A3550', 'left');
            }
            ctx.globalAlpha = 1;
        } else if (status === 'working' && seated) {
            ctx.fillStyle = '#F5C46B';
            if (f) { ctx.fillRect(X + 7, ty + 1, 1, 1); ctx.fillRect(X + 9, ty + 3, 1, 1); }
            else { ctx.fillRect(X - 8, ty + 2, 1, 1); ctx.fillRect(X + 8, ty - 1, 1, 1); }
        }

        if (selected) {
            const pp = pill(agent.name, X, iy - 10, ACCENT, '#14161B');
            ctx.fillStyle = ACCENT;
            ctx.fillRect(X - 1, pp.by + pp.h, 3, 1);
            ctx.fillRect(X, pp.by + pp.h + 1, 1, 1);
        } else if (hovered) {
            pill(agent.name, X, iy - 10, '#F4F6FA', '#14161B');
        }
    }

    function drawPhone(t) {
        const b = state.layout.boss;
        const active = ringing().length > 0;
        const shake = active && !reduceMotion ? (Math.floor(t * 14) % 2 ? 0.02 : -0.02) : 0;
        const x = b.desk.x + 0.12 + shake;
        const y = b.desk.y + 0.12;
        box(x, y, 0.45, 0.3, 0.24, 0.07, '#2A2A30', '#1F1F24', '#26262B');
        box(x + 0.02, y + 0.02, 0.52, 0.26, 0.08, 0.05, active ? '#D9483B' : '#3A3A40', active ? '#8A2A22' : '#26262B', active ? '#B33A2E' : '#2F2F35');
        if (!active) return;
        const p = P(x + 0.15, y + 0.1, 0.62);
        const lit = Math.floor(t * 4) % 2;
        ctx.fillStyle = lit ? '#FF8A80' : '#D9483B';
        ctx.fillRect(Math.round(p[0]) - 1, Math.round(p[1]) - 1, 2, 2);
        if (!reduceMotion && Math.floor(t * 2) % 2) {
            ctx.fillStyle = '#FF8A80';
            for (const side of [-1, 1]) {
                for (let k = 1; k <= 2; k++) {
                    ctx.fillRect(Math.round(p[0] + side * (5 + k * 3)), Math.round(p[1] - 4 - k), 1, 3 + k);
                }
            }
        }
    }

    function drawBoss(t) {
        const b = state.layout.boss;
        box(b.desk.x, b.desk.y, 0.38, 1.4, 0.7, 0.07, '#8B5A33', '#6B4426', '#7A4E2C');
        box(b.desk.x + 0.4, b.desk.y + 0.05, 0.45, 0.55, 0.1, 0.4, '#2A2A30', '#1F1F24', '#26262B');
        const waiting = visibleAgents().some((a) => a.status === 'waiting');
        poly([P(b.desk.x + 0.45, b.desk.y + 0.15, 0.5), P(b.desk.x + 0.9, b.desk.y + 0.15, 0.5), P(b.desk.x + 0.9, b.desk.y + 0.15, 0.8), P(b.desk.x + 0.45, b.desk.y + 0.15, 0.8)], waiting ? (Math.floor(t * 3) % 2 ? '#8DB8FF' : '#5E86C8') : '#3D6BB3');
    }

    function drawBossPerson(t) {
        const b = state.layout.boss;
        const typing = !visibleAgents().some((a) => a.status === 'waiting');
        const p = P(b.seat.x, b.seat.y, 0);
        const x = Math.round(p[0]);
        const y = Math.round(p[1]);
        const f = typing ? Math.floor(t * 5) % 2 : 0;
        ctx.fillStyle = '#2C2F3A';
        ctx.fillRect(x - 6, y - 9, 12, 3);
        ctx.fillRect(x - 1, y - 6, 2, 5);
        if (spritesReady) {
            const sh = Math.round(SPRITE.h * 0.62);
            ctx.drawImage(sprites, 2 * SPRITE.w, 24 * SPRITE.h, SPRITE.w, sh, x - SPRITE.w / 4, y - 7 - sh / 2 - f, SPRITE.w / 2, sh / 2);
        }
        ctx.fillStyle = '#373B48';
        ctx.fillRect(x - 6, y - 20, 12, 12);
        ctx.fillStyle = '#2C2F3A';
        ctx.fillRect(x - 6, y - 20, 12, 1);
    }

    function drawIsland(island) {
        const p = P(island.x + island.w / 2, island.y + 0.2, 2.2);
        const dim = state.filter !== 'all' && String(island.key) !== String(state.filter);
        ctx.globalAlpha = dim ? 0.35 : 1;
        const alarm = ringing().some((a) => String(a.project_id) === String(island.key));
        const blink = alarm && Math.floor(now() * 3) % 2;
        tag((alarm ? '☎ ' : '') + island.name.toUpperCase(), p[0], p[1], blink ? '#D9483B' : projectColor(island.key), Boolean(blink) || String(island.key) === String(state.filter));
        ctx.globalAlpha = 1;
    }

    function drawFurniture(r, at) {
        if (!propsReady) return;
        const p = P(at.x, at.y, 0);
        ctx.fillStyle = 'rgba(40,24,10,0.28)';
        ctx.fillRect(Math.round(p[0]) - Math.round(r[2] / 4), Math.round(p[1]) - 1, Math.round(r[2] / 2), 2);
        drawProp(r, Math.round(p[0]), Math.round(p[1]) + 1, false);
    }

    function drawPlant(x, y, t) {
        const p = P(x, y, 0);
        if (propsReady) {
            ctx.fillStyle = 'rgba(40,24,10,0.3)';
            ctx.fillRect(Math.round(p[0]) - 7, Math.round(p[1]) - 1, 14, 2);
            drawProp(PROP.plant[Math.floor(hash2(Math.round(x * 10), Math.round(y * 10)) * 3) % 3], Math.round(p[0]), Math.round(p[1]) + 1, false);
            return;
        }
        box(x - 0.2, y - 0.2, 0, 0.4, 0.4, 0.3, '#A0553A', '#7A3E2A', '#8C4832');
        const q = P(x, y, 0.3);
        const sway = Math.round(Math.sin(t * 1.3 + x) * 0.6);
        ctx.fillStyle = '#2F6E3E';
        ctx.fillRect(Math.round(q[0]) - 5 + sway, Math.round(q[1]) - 14, 10, 12);
        ctx.fillStyle = '#3E8A4E';
        ctx.fillRect(Math.round(q[0]) - 3 + sway, Math.round(q[1]) - 18, 6, 6);
    }

    function drawPackets(t) {
        state.packets = state.packets.filter((pk) => t < pk.start + pk.dur + 0.8);
        for (const pk of state.packets) {
            const a = state.runtime.get(pk.from);
            const b = state.runtime.get(pk.to);
            if (!a || !b || !a.pos || !b.pos) continue;
            const p0 = P(a.pos.x, a.pos.y, 1.5);
            const p2 = P(b.pos.x, b.pos.y, 1.5);
            const dist = Math.hypot(p2[0] - p0[0], p2[1] - p0[1]);
            const p1 = [(p0[0] + p2[0]) / 2, Math.min(p0[1], p2[1]) - 14 - dist * 0.25];
            const q = Math.min(1, Math.max(0, (t - pk.start) / pk.dur));
            const fade = t > pk.start + pk.dur ? 1 - (t - pk.start - pk.dur) / 0.8 : 1;
            const bz = (u) => [(1 - u) * (1 - u) * p0[0] + 2 * (1 - u) * u * p1[0] + u * u * p2[0], (1 - u) * (1 - u) * p0[1] + 2 * (1 - u) * u * p1[1] + u * u * p2[1]];
            ctx.globalAlpha = Math.max(0, fade);
            const n = Math.max(8, Math.round(dist / 5));
            const off = (t * 3) % 1;
            for (let i = 0; i < n; i++) {
                const u = (i + off) / n;
                const pt = bz(u);
                ctx.fillStyle = u <= q ? ACCENT : 'rgba(184,242,90,0.25)';
                ctx.fillRect(Math.round(pt[0]), Math.round(pt[1]), u <= q ? 2 : 1, u <= q ? 2 : 1);
            }
            if (q < 1) {
                const e = q < 0.5 ? 2 * q * q : 1 - Math.pow(-2 * q + 2, 2) / 2;
                const ep = bz(e);
                ctx.fillStyle = '#FBF6EA';
                ctx.fillRect(Math.round(ep[0]) - 4, Math.round(ep[1]) - 3, 9, 6);
                ctx.fillStyle = '#C98A1E';
                ctx.fillRect(Math.round(ep[0]) - 4, Math.round(ep[1]) - 3, 9, 1);
                ctx.fillRect(Math.round(ep[0]), Math.round(ep[1]) - 2, 1, 2);
            }
            if (pk.label) {
                const mid = bz(0.5);
                pill(pk.label, mid[0], mid[1] - 4, '#14161B', ACCENT, fs(10));
            }
            ctx.globalAlpha = 1;
        }
    }

    function draw() {
        const L = state.layout;
        if (!L) return;
        const t = now();
        state.day = daylight();
        state.ui = L.LW / Math.max(1, canvas.clientWidth);
        stepRuntime(t);
        stepDog(t);

        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.setTransform(2, 0, 0, 2, 0, 0);
        ctx.imageSmoothingEnabled = false;
        drawStatic();
        drawRoomLive(t);
        drawBossWalls();
        drawDogBed();
        drawNight();

        const items = [];
        const agentsById = state.agents;
        for (const island of L.islands) {
            const dim = state.filter !== 'all' && String(island.key) !== String(state.filter);
            for (const desk of island.desks) {
                const agent = agentsById.get(desk.agentId);
                if (!agent) continue;
                items.push({ k: desk.x + 0.5 + desk.y + 0.275, dim, fn: () => drawDesk(desk, agent, t) });
            }
        }
        for (const agent of visibleAgents()) {
            const rt = state.runtime.get(agent.id);
            if (!rt || !rt.pos) continue;
            const dim = state.filter !== 'all' && String(projectKey(agent)) !== String(state.filter);
            items.push({ k: rt.pos.x + rt.pos.y, dim, fn: () => drawPerson(agent, rt, t, state.selected === agent.id, state.hover === agent.id) });
        }
        const b = L.boss;
        items.push({ k: b.desk.x + 0.7 + b.desk.y + 0.35, dim: false, fn: () => { drawBoss(t); drawPhone(t); } });
        items.push({ k: b.seat.x + b.seat.y, dim: false, fn: () => drawBossPerson(t) });
        items.push({ k: b.lamp.x + b.lamp.y, dim: false, fn: () => drawLamp(b.lamp.x, b.lamp.y) });
        items.push({ k: b.plant.x + b.plant.y + 0.4, dim: false, fn: () => drawPlant(b.plant.x, b.plant.y, t) });
        for (const island of L.islands) {
            if (island.desks.length < 4) continue;
            const dim = state.filter !== 'all' && String(island.key) !== String(state.filter);
            for (const pl of [[island.x - 0.4, island.y + 0.9], [island.x + island.w + 0.4, island.y + island.d - 0.5]]) {
                items.push({ k: pl[0] + pl[1] + 0.4, dim, fn: () => drawPlant(pl[0], pl[1], t) });
            }
        }
        const lg = lounge();
        const tableProp = isFridayDrinks() ? PROP.drinks[0] : PROP.table[0];
        items.push({ k: lg.station.x + lg.station.y + 0.3, dim: false, fn: () => drawFurniture(PROP.station[0], lg.station) });
        items.push({ k: lg.cooler.x + lg.cooler.y + 0.2, dim: false, fn: () => drawFurniture(PROP.cooler[0], lg.cooler) });
        items.push({ k: lg.table.x + lg.table.y, dim: false, fn: () => drawFurniture(tableProp, lg.table) });
        for (const pl of [[0.6, 0.6], [L.W - 0.7, 0.6], [0.6, L.D - 0.7], [L.W - 0.7, L.D - 3.3]]) {
            items.push({ k: pl[0] + pl[1] + 0.4, dim: false, fn: () => drawPlant(pl[0], pl[1], t) });
        }
        items.push({ k: dog.pos ? dog.pos.x + dog.pos.y : 0, dim: false, fn: () => drawDog(t) });
        const bot = botPos(t);
        items.push({ k: bot.x + bot.y, dim: false, fn: () => drawBot(bot, t) });
        items.sort((p, q) => p.k - q.k);
        for (const item of items) {
            ctx.globalAlpha = item.dim ? 0.3 : 1;
            item.fn();
            ctx.globalAlpha = 1;
        }

        drawGlows(t);
        for (const island of L.islands) drawIsland(island);
        const jp = P(b.seat.x, b.seat.y, 2.8);
        tag('YOU', jp[0], jp[1], ACCENT, true);
        const calls = ringing();
        if (calls.length) {
            const pp = P(b.desk.x + 0.3, b.desk.y + 0.3, 1.25);
            const label = calls.length === 1 ? `☎ ${calls[0].project}` : `☎ ${calls.length} alerts`;
            pill(label, pp[0], pp[1] - (Math.floor(t * 3) % 2), '#D9483B', '#FFFFFF', fs(10));
        }
        if (L.plaque !== null && state.star) {
            const sp = P(L.plaque + 0.75, 0, 2.66);
            pill(`★ ${state.star.name} · ${state.star.count} this week`, sp[0], sp[1] - 1, '#C98A1E', '#14161B', fs(9));
        }

        drawPackets(t);

        for (const agent of visibleAgents()) {
            const rt = state.runtime.get(agent.id);
            if (!rt || !rt.pos || !rt.bubble || rt.bubble.until < t) continue;
            if (state.filter !== 'all' && String(projectKey(agent)) !== String(state.filter)) continue;
            const p = P(rt.pos.x, rt.pos.y, 0);
            bubble(rt.bubble.text, p[0], p[1] - 30, agent.status);
        }
        if (dog.bark > t && dog.anchor) bubble(`${DOG_NAME}: ${dog.barkText}`, dog.anchor[0], dog.anchor[1] + 2, 'idle');
    }

    function loop() {
        stepCamera();
        draw();
        placePopover();
        requestAnimationFrame(loop);
    }

    function placePopover() {
        const pop = document.getElementById('detail');
        if (pop.hidden || !state.layout) return;
        const stage = document.getElementById('stage');
        const rt = state.runtime.get(state.selected);
        const sw = stage.clientWidth;
        const sh = stage.clientHeight;
        if (sw < 640 || !rt || !rt.anchor) {
            pop.classList.add('docked');
            pop.style.left = '';
            pop.style.top = '';
            return;
        }
        pop.classList.remove('docked');
        const scale = canvas.clientWidth / state.layout.LW;
        const ax = rt.anchor[0] * scale;
        const ay = rt.anchor[1] * scale;
        const pw = pop.offsetWidth;
        const ph = pop.offsetHeight;
        const gap = 18;
        let left = ax + gap;
        if (left + pw > sw - 12) left = ax - gap - pw;
        left = Math.max(12, Math.min(sw - pw - 12, left));
        const top = Math.max(12, Math.min(sh - ph - 12, ay - 24));
        pop.style.left = `${Math.round(left)}px`;
        pop.style.top = `${Math.round(top)}px`;
    }

    // ---------- data ----------

    function upsertProject(project) {
        if (project) state.projects.set(project.id, project);
    }

    function upsertAgent(agent, announce) {
        const before = state.agents.get(agent.id);
        state.agents.set(agent.id, agent);
        if (!announce) return;
        const rt = runtime(agent);
        if (agent.message && (!before || before.message !== agent.message || before.status !== agent.status)) {
            rt.bubble = { text: agent.message, until: now() + 6 };
        }
        if (agent.status === 'done' && (!before || before.status !== 'done')) {
            rt.burst = now() + 5;
            dog.wish = agent.id;
        }
    }

    function addEvent(event) {
        if (!event || state.events.some((e) => e.id === event.id)) return;
        state.events.unshift(event);
        state.events = state.events.slice(0, 40);
        if (event.type === 'handoff' && event.to_agent_id) {
            state.packets.push({ from: event.agent_id, to: event.to_agent_id, label: event.message, start: now(), dur: 2.4 });
            if (state.layout) state.visits.push({ from: event.agent_id, to: event.to_agent_id });
            focusOn(event.to_agent_id);
        }
    }

    async function loadState() {
        const response = await fetch(CFG.stateUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (response.status === 401 || response.status === 404 || response.redirected) {
            window.location.reload();
            return;
        }
        const data = await response.json();
        state.projects = new Map(data.projects.map((p) => [p.id, p]));
        state.today = data.today || null;
        state.star = data.star || null;
        state.alarms = data.alarms || [];
        state.usage = data.usage || null;
        const seen = new Set();
        for (const agent of data.agents) {
            upsertAgent(agent, state.layout !== null);
            seen.add(agent.id);
        }
        for (const id of [...state.agents.keys()]) if (!seen.has(id)) state.agents.delete(id);
        if (!state.layout) state.events = data.events;
        else for (const event of [...data.events].reverse()) addEvent(event);
        applyLayout();
        processVisits();
        render();
    }

    let refreshTimer = null;

    function refreshSoon() {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(() => { loadState().catch(() => {}); }, 1500);
    }

    function onChanged(payload) {
        (payload.projects || []).forEach(upsertProject);
        if ((payload.agents || []).some((a) => a.status === 'done' && (state.agents.get(a.id) || {}).status !== 'done')) refreshSoon();
        const changed = (payload.agents || []).filter((a) => (state.agents.get(a.id) || {}).status !== a.status && a.status !== 'idle' && a.status !== 'offline');
        const layoutKey = layoutSignature();
        (payload.agents || []).forEach((agent) => upsertAgent(agent, true));
        addEvent(payload.event);
        if (layoutSignature() !== layoutKey) applyLayout();
        else for (const agent of payload.agents || []) {
            const rt = state.runtime.get(agent.id);
            if (rt) syncPlacement(agent, rt);
        }
        processVisits();
        if (changed.length) focusOn(changed[changed.length - 1].id);
        render();
    }

    function layoutSignature() {
        return visibleAgents().map((a) => `${a.id}:${projectKey(a)}`).sort().join('|') + '#' + [...state.projects.keys()].join(',');
    }

    // ---------- DOM ----------

    function render() {
        const agents = visibleAgents();
        const count = (s) => agents.filter((a) => a.status === s).length;
        document.getElementById('count-working').textContent = count('working');
        document.getElementById('count-waiting').textContent = count('waiting');
        document.getElementById('stat-waiting').dataset.active = String(count('waiting') > 0);
        document.getElementById('count-error').textContent = count('error');

        const islands = state.layout ? state.layout.islands.length : 0;
        const done = state.today ? state.today.done : 0;
        const doneText = done ? ` ${done} ${done === 1 ? 'thing' : 'things'} delivered today.` : '';
        document.getElementById('summary').textContent = agents.length
            ? `${agents.length} ${agents.length === 1 ? 'agent' : 'agents'} in ${islands} ${islands === 1 ? 'island' : 'islands'}.${doneText}`
            : `Nobody's in yet.${doneText}`;
        document.getElementById('empty').hidden = agents.length > 0;

        renderChips(agents);
        renderCards(agents);
        renderFeed();
        renderDetail();
        renderToday();
        renderTvWait(agents);
        renderAlarms();
    }

    function ringing() {
        const nowMs = Date.now();
        return state.alarms.filter((a) => Date.parse(a.until) > nowMs);
    }

    function onAlarms(payload) {
        state.alarms = payload.alarms || [];
        if (payload.ringing) {
            dog.bark = now() + 2.5;
            dog.barkText = 'Woof! The phone!';
            focusSpot(() => { const b = state.layout.boss; return P(b.desk.x + 0.3, b.desk.y + 0.3, 0.9); });
        }
        renderAlarms();
    }

    function renderAlarms() {
        const el = document.getElementById('alarms');
        if (!el) return;
        const list = ringing();
        el.hidden = !list.length;
        if (!list.length) { el.replaceChildren(); return; }
        const rows = list.slice(0, CFG.tv ? 2 : 4).map((alarm) => {
            const row = document.createElement('div');
            row.className = 'alarm';
            const icon = document.createElement('span');
            icon.className = 'alarm-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '☎';
            const text = document.createElement('div');
            text.className = 'alarm-text';
            const title = document.createElement('strong');
            title.textContent = `${alarm.label} in ${alarm.project}`;
            const sub = document.createElement('span');
            sub.textContent = [alarm.short_id, alarm.level, alarm.times_seen > 1 ? `seen ${alarm.times_seen}×` : null, `since ${clock(alarm.rang_at)}`].filter(Boolean).join(' · ');
            text.append(title, sub);
            row.append(icon, text);
            if (!CFG.tv) {
                const actions = document.createElement('div');
                actions.className = 'alarm-actions';
                if (alarm.url) {
                    const link = document.createElement('a');
                    link.href = alarm.url;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = 'View alert';
                    actions.append(link);
                }
                const answer = document.createElement('button');
                answer.type = 'button';
                answer.textContent = 'Pick up';
                answer.addEventListener('click', () => answerAlarm(alarm.id, answer));
                actions.append(answer);
                row.append(actions);
            }
            return row;
        });
        if (!CFG.tv && list.length > 1) {
            const all = document.createElement('button');
            all.type = 'button';
            all.className = 'alarm-all';
            all.textContent = `Pick up all ${list.length}`;
            all.addEventListener('click', () => answerAlarm(null, all));
            rows.push(all);
        }
        el.replaceChildren(...rows);
    }

    async function answerAlarm(id, button) {
        button.disabled = true;
        try {
            const response = await fetch(`${CFG.alarmUrl}${id ? `/${id}` : ''}/answer`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(String(response.status));
            state.alarms = id ? state.alarms.filter((a) => a.id !== id) : [];
            renderAlarms();
        } catch (e) {
            button.disabled = false;
            button.textContent = 'Try again';
        }
    }

    function renderToday() {
        const el = document.getElementById('today');
        if (!el) return;
        const today = state.today;
        if (!today || !today.done) {
            const p = document.createElement('p');
            p.className = 'today-empty';
            p.textContent = 'Nothing delivered yet today.';
            el.replaceChildren(p);
            return;
        }
        const total = document.createElement('div');
        total.className = 'today-total';
        const n = document.createElement('strong');
        n.textContent = String(today.done);
        const label = document.createElement('span');
        label.textContent = `${today.done === 1 ? 'delivery' : 'deliveries'} across ${today.projects.length} ${today.projects.length === 1 ? 'project' : 'projects'}`;
        total.append(n, label);
        el.replaceChildren(total, ...today.projects.slice(0, 5).map((project) => {
            const box = document.createElement('div');
            box.className = 'today-project';
            const h = document.createElement('h3');
            const dot = document.createElement('span');
            dot.className = 'dot';
            dot.style.background = projectColor(project.project_id && state.projects.has(project.project_id) ? project.project_id : NONE);
            const count = document.createElement('small');
            count.textContent = String(project.count);
            h.append(dot, project.name, count);
            const ul = document.createElement('ul');
            for (const item of project.items.slice(0, 3)) {
                const li = document.createElement('li');
                const time = document.createElement('time');
                time.textContent = item.time;
                li.append(time, `${item.agent}: ${item.message || 'done'}`);
                li.title = `${item.agent}: ${item.message || 'done'}`;
                ul.append(li);
            }
            box.append(h, ul);
            return box;
        }));
    }

    function renderTvWait(agents) {
        const el = document.getElementById('tv-wait');
        if (!el) return;
        const waiting = agents.filter((a) => a.status === 'waiting')
            .sort((a, b) => ((b.question ? 1 : 0) - (a.question ? 1 : 0)) || String(a.status_changed_at || '').localeCompare(String(b.status_changed_at || '')));
        el.hidden = !waiting.length;
        if (!waiting.length) { el.replaceChildren(); return; }
        const head = document.createElement('div');
        head.className = 'tv-wait-head';
        const more = waiting.length > 3 ? `+${waiting.length - 3} more` : '';
        head.append(waiting.length === 1 ? '1 colleague is waiting for you' : `${waiting.length} colleagues are waiting for you`);
        if (more) {
            const extra = document.createElement('span');
            extra.textContent = more;
            head.append(extra);
        }
        el.replaceChildren(head, ...waiting.slice(0, 3).map((agent) => {
            const row = document.createElement('div');
            row.className = 'tv-wait-item';
            const portrait = document.createElement('span');
            portrait.className = 'portrait';
            portraitStyle(portrait, agent.avatar, 40);
            const body = document.createElement('div');
            body.className = 'tv-wait-text';
            const name = document.createElement('strong');
            const project = state.projects.get(agent.project_id);
            name.textContent = project ? `${agent.name} · ${project.name}` : agent.name;
            const msg = document.createElement('span');
            msg.textContent = agent.question ? agent.question.question : (agent.message || 'Needs you');
            body.append(name, msg);
            row.append(portrait, body);
            return row;
        }));
    }

    function renderChips(agents) {
        const chips = document.getElementById('chips');
        const items = [{ key: 'all', label: `All projects (${agents.length})` }];
        for (const island of state.layout ? state.layout.islands : []) {
            items.push({ key: island.key, label: island.name, count: island.desks.length });
        }
        if (state.filter !== 'all' && !items.some((i) => String(i.key) === String(state.filter))) state.filter = 'all';
        chips.replaceChildren(...items.map((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'chip';
            if (item.key !== 'all') {
                const dot = document.createElement('span');
                dot.className = 'dot';
                dot.style.background = projectColor(item.key);
                button.append(dot);
            }
            button.append(item.label);
            if (item.count !== undefined) {
                const count = document.createElement('span');
                count.className = 'chip-count';
                count.textContent = String(item.count);
                button.append(count);
            }
            button.setAttribute('aria-pressed', String(String(item.key) === String(state.filter)));
            button.addEventListener('click', () => { state.filter = item.key; render(); });
            return button;
        }));
    }

    function renderCards(agents) {
        const list = state.filter === 'all' ? agents : agents.filter((a) => String(projectKey(a)) === String(state.filter));
        const island = state.layout && state.layout.islands.find((i) => String(i.key) === String(state.filter));
        document.getElementById('list-title').textContent = island ? island.name : 'All agents';
        document.getElementById('list-count').textContent = String(list.length);
        const order = { waiting: 0, error: 1, working: 2, done: 3, idle: 4, sleeping: 5 };
        list.sort((a, b) => (order[a.status] - order[b.status]) || a.name.localeCompare(b.name));

        document.getElementById('cards').replaceChildren(...list.map((agent) => {
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'card';
            card.dataset.status = agent.status;
            card.style.setProperty('--project', projectColor(projectKey(agent)));
            card.setAttribute('aria-pressed', String(state.selected === agent.id));
            card.addEventListener('click', () => {
                select(agent.id);
                if (state.selected) document.getElementById('stage').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });

            const portrait = document.createElement('span');
            portrait.className = 'portrait';
            portrait.setAttribute('aria-hidden', 'true');
            portraitStyle(portrait, agent.avatar, 40);

            const body = document.createElement('span');
            body.className = 'card-body';
            const head = document.createElement('span');
            head.className = 'card-head';
            const name = document.createElement('span');
            name.className = 'card-name';
            name.textContent = agent.name;
            const pillEl = document.createElement('span');
            pillEl.className = 'pill';
            pillEl.dataset.status = agent.status;
            pillEl.textContent = agent.status_label || LABELS[agent.status];
            head.append(name);
            if (state.star && state.star.name === agent.name) {
                const star = document.createElement('span');
                star.className = 'card-star';
                star.textContent = '★';
                star.title = `Employee of the week: ${state.star.count} ${state.star.count === 1 ? 'delivery' : 'deliveries'}`;
                head.append(star);
            }
            head.append(pillEl);
            const role = document.createElement('span');
            role.className = 'card-role';
            const project = state.projects.get(agent.project_id);
            if (project) {
                const dot = document.createElement('span');
                dot.className = 'dot';
                dot.style.background = projectColor(project.id);
                role.append(dot);
            }
            role.append([agent.role, project ? project.name : null].filter(Boolean).join(' · '));
            const msg = document.createElement('span');
            msg.className = 'card-msg';
            msg.textContent = agent.question ? `Question: ${agent.question.question}` : (agent.message || (agent.last_seen_at ? `Last seen ${ago(agent.last_seen_at)}` : ''));
            if (agent.question) card.dataset.question = 'true';
            body.append(head, role, msg);
            card.append(portrait, body);
            return card;
        }));
    }

    function renderFeed() {
        const feed = document.getElementById('feed');
        if (!state.events.length) {
            const li = document.createElement('li');
            li.className = 'none';
            li.textContent = 'No activity yet.';
            feed.replaceChildren(li);
            return;
        }
        feed.replaceChildren(...state.events.slice(0, 14).map((event) => {
            const li = document.createElement('li');
            li.dataset.type = event.type;
            if (event.status) li.dataset.status = event.status;
            const dot = document.createElement('span');
            dot.className = 'feed-dot';
            dot.setAttribute('aria-hidden', 'true');
            const time = document.createElement('time');
            time.dateTime = event.created_at;
            time.textContent = clock(event.created_at);
            const span = document.createElement('span');
            span.textContent = event.text;
            li.append(dot, time, span);
            return li;
        }));
    }

    function renderDetail() {
        const panel = document.getElementById('detail');
        const agent = state.selected ? state.agents.get(state.selected) : null;
        if (!agent || agent.status === 'offline') {
            panel.hidden = true;
            panel.replaceChildren();
            delete panel.dataset.agent;
            return;
        }
        const typing = panel.contains(document.activeElement) && ['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName);
        if (panel.dataset.agent === String(agent.id) && typing && panel.dataset.question === String((agent.question || {}).id || '')) {
            const status = panel.querySelector('.pill');
            if (status) { status.dataset.status = agent.status; status.textContent = agent.status_label; }
            return;
        }
        const editing = panel.dataset.agent === String(agent.id) && !!panel.querySelector('details[open]');
        panel.hidden = false;
        panel.dataset.agent = String(agent.id);
        panel.dataset.question = String((agent.question || {}).id || '');
        panel.dataset.status = agent.status;

        const head = document.createElement('div');
        head.className = 'pop-head';
        const portrait = document.createElement('span');
        portrait.className = 'portrait';
        portrait.setAttribute('aria-hidden', 'true');
        portraitStyle(portrait, agent.avatar, 40);
        const titles = document.createElement('div');
        titles.className = 'pop-titles';
        const h = document.createElement('h2');
        h.id = 'pop-name';
        h.textContent = agent.name;
        const sub = document.createElement('span');
        sub.className = 'card-role';
        const project = state.projects.get(agent.project_id);
        sub.textContent = [agent.role, project ? project.name : null].filter(Boolean).join(' · ');
        titles.append(h, sub);
        const pillEl = document.createElement('span');
        pillEl.className = 'pill';
        pillEl.dataset.status = agent.status;
        pillEl.textContent = agent.status_label;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'pop-close';
        close.setAttribute('aria-label', 'Close');
        close.textContent = '×';
        close.addEventListener('click', () => select(null));
        head.append(portrait, titles, pillEl, close);

        const message = document.createElement('p');
        message.className = 'pop-msg';
        message.textContent = agent.message || 'No message.';

        const meta = document.createElement('p');
        meta.className = 'detail-meta';
        const source = { mcp: 'Skill or scheduled task', claude_code: 'Claude Code session', subagent: 'Subagent in Claude Code' }[agent.source] || agent.source;
        meta.textContent = `${source} · last seen ${agent.last_seen_at ? ago(agent.last_seen_at) : 'never'}`;

        const details = document.createElement('details');
        details.className = 'pop-edit';
        details.open = editing;
        const summary = document.createElement('summary');
        summary.textContent = 'Edit';
        const form = document.createElement('form');
        const datalistId = 'project-options';
        form.append(
            field('Name', 'name', agent.name),
            field('Role', 'role', agent.role || ''),
            field('Project', 'project', project ? project.name : '', datalistId),
        );
        const datalist = document.createElement('datalist');
        datalist.id = datalistId;
        for (const p of state.projects.values()) {
            const option = document.createElement('option');
            option.value = p.name;
            datalist.append(option);
        }
        const actions = document.createElement('div');
        actions.className = 'actions';
        const save = document.createElement('button');
        save.type = 'submit';
        save.className = 'btn';
        save.textContent = 'Save';
        actions.append(save);
        if (agent.status === 'error' || agent.status === 'waiting') {
            actions.append(actionButton('Release', () => patch(agent.id, { status: 'idle' })));
        }
        if (agent.source !== 'mcp') {
            actions.append(actionButton('Send home', () => patch(agent.id, { status: 'offline' })));
        }
        const note = document.createElement('p');
        note.className = 'form-msg';
        form.append(datalist, actions, note);
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const data = new FormData(form);
            patch(agent.id, { name: data.get('name'), role: data.get('role'), project: data.get('project') }, note);
        });
        details.append(summary, form);

        panel.replaceChildren(...[head, agent.question ? questionBlock(agent.question) : message, meta, details]);
        if (agent.question && !typing) {
            const input = panel.querySelector('textarea');
            if (input && panel.dataset.focus !== String(agent.question.id)) {
                panel.dataset.focus = String(agent.question.id);
                input.focus({ preventScroll: true });
            }
        }
    }

    function questionBlock(question) {
        const wrap = document.createElement('form');
        wrap.className = 'question';
        const label = document.createElement('p');
        label.className = 'question-label';
        label.textContent = `Question · ${clock(question.asked_at)}`;
        const text = document.createElement('p');
        text.className = 'question-text';
        text.textContent = question.question;
        const note = document.createElement('p');
        note.className = 'form-msg';
        const send = (answer) => answerQuestion(question.id, answer, wrap, note);
        wrap.append(label, text);

        if (question.options.length) {
            const options = document.createElement('div');
            options.className = 'actions';
            for (const option of question.options) {
                options.append(actionButton(option, () => send(option)));
            }
            wrap.append(options);
        }

        const field = document.createElement('div');
        field.className = 'field';
        const l = document.createElement('label');
        l.htmlFor = 'answer-text';
        l.textContent = question.options.length ? 'Or type your own answer' : 'Your answer';
        const input = document.createElement('textarea');
        input.id = 'answer-text';
        input.name = 'answer';
        input.rows = 3;
        input.maxLength = 500;
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); wrap.requestSubmit(); }
        });
        field.append(l, input);
        const actions = document.createElement('div');
        actions.className = 'actions';
        const submit = document.createElement('button');
        submit.type = 'submit';
        submit.className = 'btn';
        submit.textContent = 'Send answer';
        actions.append(submit);
        wrap.append(field, actions, note);
        wrap.addEventListener('submit', (e) => {
            e.preventDefault();
            const answer = input.value.trim();
            if (answer) send(answer);
        });
        return wrap;
    }

    async function answerQuestion(id, answer, form, note) {
        form.querySelectorAll('button, textarea').forEach((el) => { el.disabled = true; });
        note.textContent = 'Sending…';
        const response = await fetch(`${CFG.questionUrl}/${id}/answer`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ answer }),
        });
        if (!response.ok) {
            form.querySelectorAll('button, textarea').forEach((el) => { el.disabled = false; });
            note.textContent = response.status === 409 ? 'This question has already been answered or has expired.' : "Couldn't send your answer.";
            if (response.status === 409) loadState();
            return;
        }
        const data = await response.json();
        if (document.activeElement) document.activeElement.blur();
        onChanged({ agents: [data.agent] });
        const fresh = document.querySelector('#detail .pop-msg');
        if (fresh) fresh.textContent = 'Sent. The agent will pick up your answer as soon as it continues.';
    }

    function field(label, name, value, list) {
        const wrap = document.createElement('div');
        wrap.className = 'field';
        const id = `field-${name}`;
        const l = document.createElement('label');
        l.htmlFor = id;
        l.textContent = label;
        const input = document.createElement('input');
        input.id = id;
        input.name = name;
        input.value = value;
        input.maxLength = name === 'name' ? 40 : 80;
        if (list) input.setAttribute('list', list);
        wrap.append(l, input);
        return wrap;
    }

    function actionButton(label, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn secondary';
        button.textContent = label;
        button.addEventListener('click', onClick);
        return button;
    }

    async function patch(id, body, note) {
        if (note) note.textContent = 'Saving…';
        const response = await fetch(`${CFG.agentUrl}/${id}`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify(body),
        });
        if (!response.ok) {
            if (note) note.textContent = "Couldn't save.";
            return;
        }
        const data = await response.json();
        if (body.project !== undefined) await loadState();
        else onChanged({ agents: [data.agent] });
        const fresh = document.querySelector('#detail .form-msg');
        if (fresh) fresh.textContent = 'Saved.';
    }

    function select(id, keep) {
        state.selected = id === null || (!keep && state.selected === id) ? null : id;
        const agent = state.agents.get(id);
        if (agent && state.selected) {
            const rt = runtime(agent);
            if (agent.message) rt.bubble = { text: agent.message, until: now() + 4 };
        }
        render();
    }

    function clock(iso) {
        const d = new Date(iso);
        return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    }

    function ago(iso) {
        const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
        if (s < 60) return 'just now';
        if (s < 3600) return `${Math.floor(s / 60)} min ago`;
        if (s < 86400) return s < 7200 ? '1 hour ago' : `${Math.floor(s / 3600)} hours ago`;
        return s < 172800 ? '1 day ago' : `${Math.floor(s / 86400)} days ago`;
    }

    const dog = { pos: null, path: null, mode: 'lie', until: 0, face: 1, spot: null, wish: null, bark: 0, barkText: 'Woof!', layout: null, comfortId: null, comforted: {}, greetedOn: null, begged: 0 };
    const DOG_SPEED = 1.7;

    function dogHome() {
        const b = state.layout.boss;
        const entry = { x: b.door.x, y: b.y - 0.7 };
        const inside = [b.door.x, b.y + 0.5];
        return {
            bed: { x: b.x + 1.45, y: b.y + 3.45 },
            bowl: { x: b.x + 2.1, y: b.y + 3.8 },
            entry,
            inside,
        };
    }

    function dogSpot(kind, t) {
        const L = state.layout;
        const home = dogHome();
        if (kind === 'bed') {
            return { kind: 'bed', a: home.entry, tail: [home.inside, [home.bed.x, home.bed.y]], mode: 'lie', stay: 14 + hash2(Math.floor(t), 1) * 14 + (state.day < 0.3 ? 10 : 0) };
        }
        if (kind === 'bowl') {
            return { a: home.entry, tail: [home.inside, [home.bowl.x - 0.35, home.bowl.y - 0.05]], mode: 'drink', stay: 5 };
        }
        if (kind === 'comfort') {
            const seat = L.seats.get(dog.comfortId);
            if (!seat) return null;
            return { a: { x: Math.min(L.W - 1.2, seat.x + 0.5), y: seat.aisle }, tail: [], mode: 'lie', stay: 16, comfort: true };
        }
        if (kind === 'greet') {
            return { a: { x: home.entry.x + 0.6, y: home.entry.y }, tail: [], mode: 'sit', stay: 9, happy: true, say: 'Good morning!' };
        }
        if (kind === 'beg') {
            const t = lounge().table;
            return { a: { x: t.x + 0.05, y: t.y + 0.7 }, tail: [], mode: 'sit', stay: 8, happy: true, say: hash2(Math.floor(t), 31) < 0.5 ? 'Treat?' : "I've been good too!" };
        }
        if (kind === 'visit') {
            const seat = L.seats.get(dog.wish);
            if (!seat) return null;
            const spot = { x: Math.min(L.W - 1.2, seat.x + 0.55), y: seat.aisle };
            return { a: spot, tail: [], mode: 'sit', stay: 7, happy: true };
        }
        const lines = [1.3, L.D - 0.9, L.boss.y - 0.7];
        for (const island of L.islands) {
            const s = L.seats.get(island.desks[0] && island.desks[0].agentId);
            if (s && s.row === 0) lines.push(s.aisle);
        }
        const y = lines[Math.floor(hash2(Math.floor(t * 3), 5) * lines.length) % lines.length];
        const x = 1.3 + hash2(Math.floor(t * 7), 9) * (L.W - 2.6);
        return { a: { x, y }, tail: [], mode: hash2(Math.floor(t), 4) < 0.5 ? 'sit' : 'stand', stay: 3 + hash2(Math.floor(t), 6) * 4 };
    }

    function planDog(t) {
        let kind;
        const r = hash2(Math.floor(t * 13), 77);
        const clock = clockHours();
        const stuck = visibleAgents().find((a) => a.status === 'error' && state.layout.seats.has(a.id) && (dog.comforted[a.id] || -1e9) < t - 180);
        const lounging = inLounge().some((a) => state.runtime.get(a.id).mode === 'lounge');
        if (dog.wish !== null && state.layout.seats.has(dog.wish)) kind = 'visit';
        else if (stuck) {
            kind = 'comfort';
            dog.comfortId = stuck.id;
            dog.comforted[stuck.id] = t;
        } else if (clock.h >= 9 && clock.h < 10 && dog.greetedOn !== clock.date) {
            kind = 'greet';
            dog.greetedOn = clock.date;
        } else if (lounging && t - dog.begged > 50 && r < 0.6) {
            kind = 'beg';
            dog.begged = t;
        }
        else if (dog.spot && dog.spot.mode === 'lie') kind = r < 0.3 ? 'bowl' : 'wander';
        else if (r < (state.day < 0.3 ? 0.35 : 0.2)) kind = 'bed';
        else if (r < 0.62) {
            const seated = visibleAgents().filter((a) => state.layout.seats.has(a.id));
            if (seated.length) {
                dog.wish = seated[Math.floor(hash2(Math.floor(t), 21) * seated.length) % seated.length].id;
                kind = 'visit';
            } else kind = 'wander';
        } else if (r < 0.72) kind = 'bowl';
        else kind = 'wander';
        const next = dogSpot(kind, t) || dogSpot('wander', t);
        if (kind === 'visit') dog.wish = null;

        const cur = dog.spot;
        const path = [[dog.pos.x, dog.pos.y]];
        if (cur && next.tail.length && cur.tail.length && Math.abs(cur.a.x - next.a.x) < 0.01 && Math.abs(cur.a.y - next.a.y) < 0.01) {
            path.push(next.tail[next.tail.length - 1]);
            dog.path = path;
            dog.spot = next;
            dog.start = t;
            dog.dur = Math.max(0.4, pathLength(path) / DOG_SPEED);
            dog.mode = 'walk';
            return;
        }
        if (cur) for (const p of [...cur.tail].reverse().slice(1)) path.push(p);
        let fromA = cur ? cur.a : { x: dog.pos.x, y: dog.pos.y };
        if (!cur) {
            const b = state.layout.boss;
            if (dog.pos.x > b.x && dog.pos.x < b.x + b.w && dog.pos.y > b.y && dog.pos.y < b.y + b.d) {
                const home = dogHome();
                path.push(home.inside);
                fromA = home.entry;
            } else if (Math.abs(dog.pos.x - state.layout.lane) < 0.05) {
                fromA = { x: state.layout.lane, y: dog.pos.y };
            }
        }
        path.push([fromA.x, fromA.y]);
        if (Math.abs(fromA.y - next.a.y) > 0.01 || Math.abs(fromA.x - next.a.x) > 0.01) {
            if (Math.abs(fromA.y - next.a.y) > 0.01) {
                path.push([state.layout.lane, fromA.y], [state.layout.lane, next.a.y]);
            }
            path.push([next.a.x, next.a.y]);
        }
        for (const p of next.tail) path.push(p);
        const clean = path.filter((p, i) => i === 0 || Math.hypot(p[0] - path[i - 1][0], p[1] - path[i - 1][1]) > 0.001);
        dog.path = clean.length > 1 ? clean : null;
        dog.spot = next;
        if (dog.path) {
            dog.start = t;
            dog.dur = Math.max(0.4, pathLength(dog.path) / DOG_SPEED);
            dog.mode = 'walk';
        } else {
            dog.mode = next.mode;
            dog.until = t + next.stay;
        }
    }

    function stepDog(t) {
        const L = state.layout;
        if (dog.layout !== L) {
            const home = dogHome();
            const first = !dog.pos;
            const inBed = dog.mode === 'lie' && dog.spot && dog.spot.kind === 'bed';
            dog.layout = L;
            if (first || inBed) {
                const until = first ? t + 6 : dog.until;
                dog.pos = { x: home.bed.x, y: home.bed.y };
                dog.spot = dogSpot('bed', t);
                dog.mode = 'lie';
                dog.path = null;
                dog.until = until;
            } else {
                dog.spot = null;
                dog.path = null;
                dog.mode = 'stand';
                dog.until = t;
            }
        }
        if (dog.mode === 'walk') {
            const p = Math.min(1, (t - dog.start) / dog.dur);
            const at = pointOnPath(dog.path, p);
            const sx = at.dx - at.dy;
            if (Math.abs(sx) > 0.01) dog.face = sx > 0 ? 1 : -1;
            dog.pos = { x: at.x, y: at.y };
            if (p >= 1) {
                dog.path = null;
                dog.mode = dog.spot.mode;
                dog.until = t + dog.spot.stay;
                if (dog.spot.say) {
                    dog.bark = t + 3.5;
                    dog.barkText = dog.spot.say;
                }
            }
            return;
        }
        if (dog.wish !== null && dog.mode !== 'sit') dog.until = Math.min(dog.until, t + 1);
        if (t >= dog.until) planDog(t);
    }

    function drawDogBed() {
        const home = dogHome();
        const p = P(home.bed.x, home.bed.y, 0);
        const x = Math.round(p[0]);
        const y = Math.round(p[1]);
        const bp = P(home.bowl.x, home.bowl.y, 0);
        const bx = Math.round(bp[0]);
        const by = Math.round(bp[1]);
        ctx.fillStyle = 'rgba(20,10,4,0.3)';
        ctx.fillRect(x - 12, y - 2, 25, 5);
        ctx.fillRect(bx - 4, by - 1, 9, 2);
        if (propsReady) {
            drawProp(PROP.bed[0], x, y + 4, false);
            drawProp(PROP.bowl[0], bx, by + 1, false);
            return;
        }
        ctx.fillStyle = '#8C4A3A';
        ctx.fillRect(x - 12, y - 5, 25, 6);
        ctx.fillRect(x - 10, y - 7, 21, 9);
        ctx.fillStyle = '#B5655A';
        ctx.fillRect(x - 9, y - 5, 19, 5);
        ctx.fillStyle = '#3D6BB3';
        ctx.fillRect(bx - 4, by - 3, 9, 3);
    }

    function dogRects(mode, f, wag, sleeping) {
        const W = '#F6F4EE';
        const S = '#DCD6CA';
        const K = '#1A1410';
        const tail = wag ? (f ? -1 : 1) : 0;
        if (mode === 'lie') {
            return [
                [-7, -5, 11, 4, W], [-7, -2, 11, 1, S],
                [3, -8, 5, 5, W], [8, -6, 2, 2, W], [4, -10, 1, 2, W], [6, -10, 1, 2, W],
                [9, -6, 1, 1, K], [sleeping ? 5 : 6, -6, sleeping ? 2 : 1, 1, K],
                [5, -3, 5, 2, W],
                [-10, -4 + (wag ? tail : 0), 3, 2, W], [-11, -3, 2, 2, S],
            ];
        }
        if (mode === 'sit' || mode === 'drink') {
            const hd = mode === 'drink' ? 4 : 0;
            return [
                [-5, -7, 6, 7, W], [-5, -1, 6, 1, S],
                [0, -10, 4, 8, W], [1, -3, 1, 3, W], [3, -3, 1, 3, W],
                [1, -15 + hd, 5, 5, W], [6, -13 + hd, 2, 2, W], [2, -17 + hd, 1, 2, W], [4, -17 + hd, 1, 2, W],
                [7, -13 + hd, 1, 1, K], [4, -13 + hd, 1, 1, K],
                [-8, -2 + tail, 3, 2, W], [-9, -1 + tail, 2, 1, S],
            ];
        }
        const legA = mode === 'walk' ? (f ? 1 : 0) : 0;
        const legB = mode === 'walk' ? (f ? 0 : 1) : 0;
        return [
            [-6, -9, 10, 5, W], [-6, -5, 10, 1, S],
            [-5, -4, 1, 4 - legA, W], [-3, -4, 1, 4 - legB, S], [1, -4, 1, 4 - legB, W], [3, -4, 1, 4 - legA, S],
            [3, -13, 5, 5, W], [8, -11, 2, 2, W], [4, -15, 1, 2, W], [6, -15, 1, 2, W],
            [9, -11, 1, 1, K], [6, -11, 1, 1, K],
            [-9, -10 + tail, 3, 2, W], [-10, -9 + tail, 2, 3, S],
        ];
    }

    function drawDog(t) {
        if (!dog.pos) return;
        const p = P(dog.pos.x, dog.pos.y, 0);
        const x = Math.round(p[0]);
        const y = Math.round(p[1]);
        const mode = dog.mode;
        const comforting = Boolean(dog.spot && dog.spot.comfort && mode === 'lie');
        const sleeping = mode === 'lie' && state.day < 0.4 && !comforting;
        const barking = dog.bark > t;
        const wag = barking || mode === 'walk' || (dog.spot && dog.spot.happy) || mode === 'sit';
        const f = Math.floor(t * (mode === 'walk' ? 9 : 6)) % 2;
        const face = dog.face;
        ctx.fillStyle = 'rgba(40,24,10,0.3)';
        ctx.fillRect(x - 8, y - 1, 17, 2);
        if (propsReady) {
            if (mode === 'walk') {
                drawProp(PROP.walk[Math.floor(t * 11) % PROP.walk.length], x, y + 1, face < 0);
            } else {
                const frame = mode === 'sit' ? 3 : mode === 'lie' ? 4 : mode === 'drink' ? 5 : 0;
                const hop = wag && mode === 'sit' && barking ? f : 0;
                drawProp(PROP.dog[frame], x, y + 1 - hop, face < 0);
            }
        }
        const rects = propsReady ? [] : dogRects(mode, f, wag, sleeping);
        ctx.fillStyle = '#6B6255';
        for (const [rx, ry, rw, rh, c] of rects) {
            if (c === '#1A1410') continue;
            const ax = face > 0 ? x + rx : x - rx - rw;
            ctx.fillRect(ax - 1, y + ry - 1, rw + 2, rh + 2);
        }
        for (const [rx, ry, rw, rh, c] of rects) {
            ctx.fillStyle = c;
            const ax = face > 0 ? x + rx : x - rx - rw;
            ctx.fillRect(ax, y + ry, rw, rh);
        }
        const top = y - (mode === 'lie' ? 12 : mode === 'walk' ? 17 : 19);
        if (sleeping) {
            for (let k = 0; k < 2; k++) {
                const ph = (t * 0.4 + k * 0.5) % 1;
                ctx.globalAlpha = 1 - ph;
                text('z', x + face * 8 + ph * 4, top - ph * 8, fs(8) + ph * 2, '#F4F6FA', 'left');
            }
            ctx.globalAlpha = 1;
        } else if ((dog.spot && dog.spot.happy && mode === 'sit') || comforting) {
            const ph = (t * 0.8) % 1;
            ctx.globalAlpha = 1 - ph;
            ctx.fillStyle = '#FF8A80';
            const hx = x + face * 3;
            const hy = Math.round(top - ph * 6);
            ctx.fillRect(hx - 2, hy, 2, 2);
            ctx.fillRect(hx + 1, hy, 2, 2);
            ctx.fillRect(hx - 1, hy + 2, 3, 1);
            ctx.fillRect(hx, hy + 3, 1, 1);
            ctx.globalAlpha = 1;
        }
        dog.anchor = [x, top];
        if (state.dogHover && dog.bark <= t) pill(DOG_NAME, x, top - 2, '#F4F6FA', '#14161B');
    }

    function dogHit(wx, wy) {
        if (!dog.pos) return false;
        const p = P(dog.pos.x, dog.pos.y, 0);
        return Math.hypot(p[0] - wx, p[1] - 6 - wy) < 11;
    }

    function hitAgent(e, opts) {
        const L = state.layout;
        if (!L) return null;
        const r = canvas.getBoundingClientRect();
        const wx = (e.clientX - r.left) / r.width * L.LW;
        const wy = (e.clientY - r.top) / r.height * L.LH;
        let best = null;
        let bd = 14;
        if (opts && opts.dog && dogHit(wx, wy)) return 'dog';
        for (const agent of visibleAgents()) {
            const rt = state.runtime.get(agent.id);
            if (!rt || !rt.pos) continue;
            const p = P(rt.pos.x, rt.pos.y, 0);
            const d = Math.hypot(p[0] - wx, p[1] - 14 - wy);
            if (d < bd) { bd = d; best = agent.id; }
        }
        return best;
    }

    if (!CFG.tv) canvas.addEventListener('click', (e) => {
        const hit = hitAgent(e, { dog: true });
        if (hit === 'dog') {
            dog.bark = now() + 1.6;
            dog.barkText = 'Woof!';
            return;
        }
        select(hit);
    });
    if (!CFG.tv) canvas.addEventListener('mousemove', (e) => {
        const hit = hitAgent(e, { dog: true });
        canvas.style.cursor = hit ? 'pointer' : 'default';
        const usage = !hit && boardHit(e) ? usageNow() : null;
        canvas.title = usage ? usageSummary(usage) : '';
        const id = hit === 'dog' ? null : hit;
        state.dogHover = hit === 'dog';
        if (id !== state.hover) state.hover = id;
    });
    if (!CFG.tv) canvas.addEventListener('mouseleave', () => {
        state.hover = null;
        state.dogHover = false;
        canvas.style.cursor = 'default';
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && state.selected) select(null);
    });

    document.getElementById('stat-waiting').addEventListener('click', () => {
        const waiting = visibleAgents().filter((a) => a.status === 'waiting').sort((a, b) => (b.question ? 1 : 0) - (a.question ? 1 : 0) || a.id - b.id);
        if (!waiting.length) return;
        const index = waiting.findIndex((a) => a.id === state.selected);
        select(waiting[(index + 1) % waiting.length].id, true);
        document.getElementById('stage').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    const roster = document.getElementById('roster');
    new ResizeObserver(() => {
        roster.style.setProperty('--stage-h', `${document.getElementById('stage').offsetHeight}px`);
    }).observe(document.getElementById('stage'));

    const cam = { until: 0, next: 0 };

    function focusOn(agentId) {
        focusSpot(() => {
            const rt = state.runtime.get(agentId);
            return rt && rt.pos ? P(rt.pos.x, rt.pos.y, 0.9) : null;
        });
    }

    function focusSpot(where) {
        if (!CFG.tv || reduceMotion || !state.layout) return;
        const t = now();
        if (t < cam.next) return;
        setTimeout(() => {
            if (!state.layout) return;
            const p = where();
            if (!p) return;
            const fx = Math.min(0.85, Math.max(0.15, p[0] / state.layout.LW));
            const fy = Math.min(0.85, Math.max(0.15, p[1] / state.layout.LH));
            const tx = (0.5 - fx) * canvas.clientWidth;
            const ty = (0.5 - fy) * canvas.clientHeight;
            canvas.style.transformOrigin = `${(fx * 100).toFixed(2)}% ${(fy * 100).toFixed(2)}%`;
            canvas.style.transform = `translate(${tx.toFixed(1)}px, ${ty.toFixed(1)}px) scale(1.7)`;
            cam.until = now() + 7;
        }, 400);
        cam.next = t + 30;
    }

    function stepCamera() {
        if (cam.until && now() > cam.until) {
            canvas.style.transform = '';
            cam.until = 0;
        }
    }

    function fitCanvas() {
        if (!state.layout) return;
        const stage = document.getElementById('stage');
        const scale = Math.min(stage.clientWidth / state.layout.LW, stage.clientHeight / state.layout.LH);
        canvas.style.width = `${Math.floor(state.layout.LW * scale)}px`;
        canvas.style.height = `${Math.floor(state.layout.LH * scale)}px`;
    }

    function tvMode() {
        const clockEl = document.getElementById('tv-clock');
        const dateEl = document.getElementById('tv-date');
        const tick = () => {
            const d = new Date();
            clockEl.textContent = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            dateEl.textContent = d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
        };
        tick();
        setInterval(tick, 5000);

        new ResizeObserver(fitCanvas).observe(document.getElementById('stage'));

        const keepAwake = () => {
            if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') return;
            navigator.wakeLock.request('screen').catch(() => {});
        };
        keepAwake();
        document.addEventListener('visibilitychange', keepAwake);

        let pointerTimer = null;
        document.addEventListener('mousemove', () => {
            document.body.classList.add('pointer');
            clearTimeout(pointerTimer);
            pointerTimer = setTimeout(() => document.body.classList.remove('pointer'), 2500);
        });

        const root = document.documentElement;
        const fsButton = document.getElementById('tv-fullscreen');
        const isFull = () => Boolean(document.fullscreenElement || document.webkitFullscreenElement);
        const enter = root.requestFullscreen || root.webkitRequestFullscreen;
        const exit = document.exitFullscreen || document.webkitExitFullscreen;
        let hintTimer = null;
        const showHint = () => {
            if (!enter || isFull()) { fsButton.hidden = true; return; }
            fsButton.hidden = false;
            clearTimeout(hintTimer);
            hintTimer = setTimeout(() => { fsButton.hidden = true; }, 12000);
        };
        const toggle = () => {
            const result = isFull() ? exit.call(document) : enter.call(root);
            if (result && result.catch) result.catch(() => {});
        };
        fsButton.addEventListener('click', (e) => { e.stopPropagation(); toggle(); });
        document.addEventListener('click', toggle);
        document.addEventListener('keydown', (e) => {
            if (e.target === fsButton) return;
            if (e.key === 'Enter' || e.key === 'f' || e.keyCode === 13) toggle();
            else showHint();
        });
        document.addEventListener('fullscreenchange', showHint);
        document.addEventListener('webkitfullscreenchange', showHint);
        showHint();
        fsButton.focus();

        setTimeout(() => window.location.reload(), 6 * 3600 * 1000);
    }

    function connect() {
        const live = document.getElementById('live');
        const set = (s, label) => { live.dataset.state = s; live.textContent = label; };
        if (!window.Pusher || !CFG.reverb.key) {
            set('down', 'no live connection');
            return;
        }
        const pusher = new window.Pusher(CFG.reverb.key, {
            wsHost: CFG.reverb.host,
            wsPort: CFG.reverb.port,
            wssPort: CFG.reverb.port,
            forceTLS: CFG.reverb.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            cluster: '',
            disableStats: true,
            channelAuthorization: { endpoint: CFG.authUrl || '/broadcasting/auth', headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' } },
        });
        pusher.connection.bind('state_change', ({ current }) => {
            if (current === 'connected') { set('live', 'live'); loadState(); }
            else if (current === 'connecting') set('connecting', 'connecting…');
            else set('down', 'connection lost');
        });
        const channel = pusher.subscribe('private-office');
        channel.bind('office.changed', onChanged);
        channel.bind('office.alarms', onAlarms);
        channel.bind('office.usage', (payload) => { state.usage = payload.usage || null; });
    }

    loadState().then(() => {
        loop();
        connect();
        if (CFG.tv) tvMode();
        setInterval(loadState, 30000);
        setInterval(() => { renderCards(visibleAgents()); renderAlarms(); }, 60000);
    });
})();
