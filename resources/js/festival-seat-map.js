import { appendSeatGeometry, svgShape } from './festival-seat-geometry';

const colors = { free: '#2563eb', held: '#d97706', assigned: '#7c3aed', blocked: '#94a3b8', checked_in: '#047857', invalid: '#be123c', own: '#047857', neutral: '#cbd5e1' };

export function initFestivalSeatMap(root) {
    if (root.dataset.ready) return;
    root.dataset.ready = 'true';
    const find = name => root.querySelector(`[data-seat-map-${name}]`);
    const text = JSON.parse(find('text').textContent);
    let snapshot = JSON.parse(find('data').textContent);
    const floors = snapshot.floors ?? [];
    const seats = new Map(snapshot.seats.map(s => [String(s.id), s]));
    const nodes = new Map();
    const floorField = find('floor'), sectionField = find('section'), svg = find('canvas'), list = find('list');
    const own = root.dataset.mode === 'own';
    const storageKey = own ? null : `festival-seat-map:${location.pathname}`;
    let restored = {};
    try { restored = storageKey ? JSON.parse(sessionStorage.getItem(storageKey) || '{}') : {}; } catch { /* Storage is optional. */ }
    if (!restored || typeof restored !== 'object') restored = {};

    let selected = seats.has(String(restored.selected)) ? String(restored.selected) : '';
    let zoom = Number.isFinite(restored.zoom) ? Math.min(4, Math.max(1, restored.zoom)) : 1;
    let focusedSeat = restored.focusedSeat || '', pending = false, timer;
    const viewport = find('viewport');
    function saveState() {
        if (!storageKey) return;
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({
                floor: floorField.value, section: sectionField.value, zoom, selected, focusedSeat,
                scrollLeft: viewport.scrollLeft, scrollTop: viewport.scrollTop, listScrollTop: list.scrollTop,
            }));
        } catch { /* The map remains usable when storage is unavailable. */ }
    }
    window.addEventListener('pagehide', saveState);
    root.addEventListener('focusin', event => {
        if (event.target.dataset.seatId) focusedSeat = event.target.dataset.seatId;
    });

    function resize() {
        const floor = floors.find(f => f.id === floorField.value); if (!floor) return;
        const fitWidth = Math.min(viewport.clientWidth || floor.width, innerHeight * .7 * floor.width / floor.height);
        const width = `${Math.max(1, fitWidth * zoom)}px`;
        if (svg.style.width !== width) svg.style.width = width;
    }
    let resizeFrame;
    new ResizeObserver(() => {
        cancelAnimationFrame(resizeFrame);
        resizeFrame = requestAnimationFrame(resize);
    }).observe(viewport);
    floorField.replaceChildren(...floors.map(f => new Option(f.label, f.id)));
    const ownSeat = snapshot.seats.find(s => s.own);
    if (ownSeat) floorField.value = ownSeat.floor;
    if (floors.some(f => f.id === restored.floor)) floorField.value = restored.floor;
    const state = s => own ? (s.own ? 'own' : 'neutral') : s.admission === 'checked_in' || s.admission === 'invalid' ? s.admission : s.reservation;
    const describe = s => [s.label, !own && text[state(s)], s.accessibility, s.sightline].filter(Boolean).join(' · ');
    const floorSeats = () => [...seats.values()].filter(s => s.floor === floorField.value);
    function detail() {
        const s = seats.get(selected);
        find('details').textContent = s ? describe(s) : text.choose;
        for (const [key, href] of [['ticket', s?.ticket_href], ['issue', s?.sellable && s?.issue_href]]) {
            const a = find(key); a.hidden = !href; if (href) a.href = href;
        }
    }
    function select(id) {
        selected = String(id); paint(); detail();
        root.dispatchEvent(new CustomEvent('festival-seat-map:select', { bubbles: true, detail: { seatId: Number(id) } }));
    }
    function paint() {
        for (const [id, controls] of nodes) {
            const s = seats.get(id), color = colors[state(s)] ?? colors.neutral;
            controls.circle.setAttribute('fill', color);
            controls.circle.setAttribute('stroke', selected === id ? '#0f172a' : s.match === true ? '#f59e0b' : '#fff');
            controls.circle.setAttribute('stroke-width', selected === id || s.match === true ? 3 : 1);
            controls.title.textContent = describe(s);
            controls.button.setAttribute('aria-pressed', String(selected === id));
            controls.button.textContent = describe(s);
            controls.button.style.borderLeftColor = color;
        }
        const all = floorSeats();
        find('counts').textContent = own ? text.own + ': ' + all.filter(s => s.own).length :
            ['free', 'held', 'assigned', 'blocked'].map(k => `${text[k]}: ${all.filter(s => s.reservation === k).length}`).join(' · ') +
            ` · ${text.checked_in}: ${all.filter(s => s.admission === 'checked_in').length} / ${all.filter(s => ['checked_in', 'not_checked_in'].includes(s.admission)).length}` +
            (all.some(s => s.match !== undefined) ? ` · ${text.matches}: ${all.filter(s => s.match).length}` : '');
    }
    function draw() {
        const floor = floors.find(f => f.id === floorField.value); if (!floor) return;
        svg.replaceChildren(); list.replaceChildren(); nodes.clear();
        const all = floorSeats();
        svg.setAttribute('viewBox', `0 0 ${floor.width} ${floor.height}`);
        resize();
        appendSeatGeometry(svg, floor, all);
        if (floor.stage && !floor.blocks?.length) {
            const stage = svgShape('text', { x: floor.width / 2, y: 25, 'text-anchor': 'middle', fill: '#334155' }); stage.textContent = floor.stage; svg.append(stage);
        }
        const radius = Math.max(5, Math.min(16, Math.min(floor.width, floor.height) * .028, Math.sqrt(floor.width * floor.height / Math.max(1, all.length)) * .15));
        for (const s of all.filter(s => !sectionField.value || s.section === sectionField.value)) {
            const x = s.x * floor.width / 10000, y = s.y * floor.height / 10000;
            const group = svgShape('g', { 'data-seat-id': s.id, cursor: 'pointer' });
            const circle = svgShape('circle', { cx: x, cy: y, r: radius });
            const title = svgShape('title'); circle.append(title);
            const number = svgShape('text', { x, y, 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': radius, fill: '#fff', 'pointer-events': 'none' }); number.textContent = s.number;
            group.append(circle, number); group.addEventListener('click', () => select(s.id)); svg.append(group);
            const button = document.createElement('button'); button.type = 'button'; button.dataset.seatId = s.id;
            button.className = 'crm-focus block min-h-11 w-full rounded-lg border border-stone-200 border-l-4 bg-white p-3 text-left text-sm aria-pressed:ring-2 aria-pressed:ring-slate-800';
            button.addEventListener('click', () => select(s.id));
            if (!own || s.own) list.append(button);
            nodes.set(String(s.id), { circle, title, button });
        }
        paint(); detail();
    }
    function sections() {
        const before = sectionField.value;
        const values = [...new Set(floorSeats().map(s => s.section).filter(Boolean))];
        sectionField.replaceChildren(new Option(text.all_sections, ''), ...values.map(v => new Option(v, v)));
        if (values.includes(before)) sectionField.value = before;
    }
    floorField.addEventListener('change', () => { sections(); draw(); }); sectionField.addEventListener('change', draw);
    root.querySelectorAll('[data-seat-map-zoom]').forEach(b => b.addEventListener('click', () => {
        zoom = b.dataset.seatMapZoom === 'fit' ? 1 : Math.min(4, Math.max(1, zoom + Number(b.dataset.seatMapZoom) * .5)); resize();
    }));
    find('fullscreen').addEventListener('click', async () => {
        try { if (document.fullscreenElement) await document.exitFullscreen(); else await root.requestFullscreen(); } catch { /* The map remains usable without fullscreen. */ }
    });
    function updated(at) {
        find('status').textContent = `${text.updated}: ${new Date(at).toLocaleTimeString()}`;
        find('status').classList.remove('text-rose-700'); find('retry').hidden = true;
    }
    async function refresh() {
        if (!root.dataset.refreshUrl || pending || !root.isConnected) return;
        if (document.hidden) { timer = setTimeout(refresh, 5000); return; }
        clearTimeout(timer); pending = true;
        try {
            const response = await fetch(root.dataset.refreshUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('refresh');
            const payload = await response.json(), data = payload.hall ?? payload;
            for (const s of data.seats) {
                const previous = seats.get(String(s.id)); if (previous) Object.assign(previous, s);
            }
            snapshot = data; paint(); detail(); updated(data.updated_at);
            const parent = root.closest('[data-event-scanner]');
            if (payload.general_admission !== undefined && parent) {
                parent.querySelector('[data-hall-general]').textContent = payload.general_admission;
                for (const kind of ['participants', 'helpers']) parent.querySelector(`[data-hall-${kind}]`).textContent = `${payload[kind].passed}/${payload[kind].total}`;
            }

            root.dispatchEvent(new CustomEvent('festival-seat-map:updated', { bubbles: true, detail: payload }));
        } catch {
            find('status').textContent = `${text.stale} · ${text.updated}: ${new Date(snapshot.updated_at).toLocaleTimeString()}`;
            find('status').classList.add('text-rose-700'); find('retry').hidden = false;
        } finally { pending = false; if (root.isConnected) timer = setTimeout(refresh, 5000); }
    }
    find('retry').addEventListener('click', refresh);
    sections();
    if ([...sectionField.options].some(option => option.value === restored.section)) sectionField.value = restored.section;
    draw(); updated(snapshot.updated_at);
    requestAnimationFrame(() => {
        viewport.scrollLeft = Number(restored.scrollLeft) || 0;
        viewport.scrollTop = Number(restored.scrollTop) || 0;
        list.scrollTop = Number(restored.listScrollTop) || 0;
        nodes.get(String(focusedSeat))?.button.focus({ preventScroll: true });
    });
    if (root.dataset.refreshUrl) timer = setTimeout(refresh, 5000);
}

export function initFestivalSeatMaps() {
    document.querySelectorAll('[data-festival-seat-map-view]').forEach(initFestivalSeatMap);
}
