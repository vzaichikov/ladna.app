function dot(color) {
    const element = document.createElement('span');
    element.className = 'inline-block size-3 shrink-0 rounded-full border border-slate-500';
    element.style.backgroundColor = color;
    return element;
}

export function initFestivalAssignedAdmissionType(form = document.querySelector('[data-assigned-admission-form]')) {
    if (!form) return;

    const deliveryMode = form.querySelector('[name=delivery_mode]');
    const inventory = form.querySelector('[name=inventory]');
    if (!deliveryMode || !inventory) return;

    const sync = () => {
        const assigned = deliveryMode.value === 'assigned_venue';
        form.querySelector('[data-assigned-inventory-hint]').hidden = !assigned;
        inventory.required = !assigned;
        inventory.disabled = assigned;
    };

    deliveryMode.addEventListener('change', sync);
    sync();
}

export function initFestivalHallLayout(root = document.querySelector('[data-hall-layout-editor]')) {
    if (!root) return;

    const { state, text, types, palette, maxSeats, routes, csrf } = JSON.parse(root.querySelector('[data-hall-config]').textContent);
    const el = (name) => root.querySelector(`[data-hall-${name}]`);
    const actions = Object.fromEntries([...root.querySelectorAll('[data-hall-action]')].map((button) => [button.dataset.hallAction, button]));
    const properties = Object.fromEntries([...root.querySelectorAll('[data-hall-property]')].map((input) => [input.dataset.hallProperty, input]));
    const typeMap = new Map(types.map((type, index) => [type.id, { ...type, color: palette[index % palette.length] }]));
    const selected = new Set();
    const map = el('map');
    const viewport = el('scroll');
    const fullscreen = document.getElementById(actions.fullscreen.getAttribute('aria-controls'));
    const tools = [...root.querySelectorAll('[data-hall-tool]')];
    const history = [];
    const future = [];
    let seatNodes = new Map();
    let groupNodes = new Map();
    let blockNodes = new Map();
    let selectedBlock = null;
    let defaultType = types.find((type) => type.active)?.id ?? null;
    let floorId = state.floors[0]?.id;
    let section = '';
    let preview = false;
    let tool = 'select';
    let gesture = null;
    let busy = false;
    let dirty = false;

    const currentFloor = () => state.floors.find((floor) => floor.id === floorId);
    const floorSeats = () => state.seats.filter((seat) => seat.floor_key === floorId);
    const floorSections = () => [...new Set([...(currentFloor()?.sections ?? []), ...floorSeats().map((seat) => seat.section)])];
    const visibleSeats = () => floorSeats().filter((seat) => !section || seat.section === section);
    const seatType = (seat) => typeMap.get(seat.festival_admission_type_id);
    const canEdit = () => !state.frozen && !state.has_holds && !preview;
    const count = (key, value) => text[key].replace(':count', value);
    const groupKey = (seat) => JSON.stringify([seat.section, seat.group_kind, seat.group_label]);
    const clamp = (value, min = 0, max = 10000) => Math.min(max, Math.max(min, value));
    const snapshot = () => ({
        floors: structuredClone(state.floors),
        seats: state.seats.map((seat) => ({ ...seat })),
        floorId,
    });

    function checkpoint(before = snapshot()) {
        history.push(before);
        if (history.length > 30) history.shift();
        future.length = 0;
        actions.undo.disabled = false;
        actions.redo.disabled = true;
        dirty = true;
    }

    function restore(from, to) {
        if (!canEdit() || !from.length) return;
        to.push(snapshot());
        const previous = from.pop();
        state.floors = previous.floors;
        state.seats = previous.seats;
        floorId = previous.floorId;
        section = '';
        selected.clear();
        selectedBlock = null;
        dirty = true;
        render();
    }

    function feedback(message = '', error = false) {
        const node = el('feedback');
        node.hidden = !message;
        node.textContent = message;
        node.classList.toggle('text-rose-800', error);
        node.classList.toggle('bg-rose-50', error);
    }

    function seatLabel(seat) {
        return [seat.section, `${text[seat.group_kind]} ${seat.group_label}`, `${text.seat_number} ${seat.seat_number}`,
            seatType(seat)?.price, seat.accessibility, seat.sightline, seat.unavailable ? text.unavailable : (seat.is_blocked ? text.blocked : text.available)]
            .filter(Boolean).join(' · ');
    }

    function setPosition(element, { x, y }) {
        element.style.left = `${x / 100}%`;
        element.style.top = `${y / 100}%`;
    }

    function renderPoints() {
        seatNodes = new Map();
        groupNodes = new Map();
        const points = document.createDocumentFragment();
        const groups = new Map();
        for (const seat of visibleSeats()) {
            const button = document.createElement('button');
            const isSelected = selected.has(seat);
            const key = groupKey(seat);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(seat);
            button.type = 'button';
            button.className = 'absolute z-10 grid size-5 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full border border-white text-[9px] font-bold text-white shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900';
            setPosition(button, seat);
            button.style.backgroundColor = seat.unavailable ? '#64748b' : (seat.is_blocked ? '#e2e8f0' : (seatType(seat)?.color || seat.color || '#64748b'));
            if (seat.is_blocked && !seat.unavailable) {
                button.classList.replace('text-white', 'text-slate-700');
            }
            button.classList.toggle('cursor-move', canEdit() && tool === 'select');
            button.classList.toggle('ring-2', isSelected);
            button.classList.toggle('ring-offset-2', isSelected);
            button.classList.toggle('ring-amber-500', isSelected);
            button.textContent = seat.unavailable || seat.is_blocked ? '×' : seat.seat_number;
            button.title = seatLabel(seat);
            button.setAttribute('aria-label', button.title);
            button.setAttribute('aria-pressed', String(isSelected));
            button.addEventListener('click', (event) => {
                if (event.detail !== 0) return;
                choose([seat], event.shiftKey);
                [...seatNodes].find(([, item]) => item === seat)?.[0].focus({ preventScroll: true });
            });
            seatNodes.set(button, seat);
            points.append(button);
        }
        el('points').replaceChildren(points);

        const labels = document.createDocumentFragment();
        for (const seats of groups.values()) {
            const [example] = seats;
            if (seats.length < 2 && example.group_kind === 'row') continue;
            const label = document.createElement('button');
            const left = seats.reduce((first, seat) => seat.x < first.x ? seat : first);
            const isTable = example.group_kind === 'table';
            const center = { x: seats.reduce((sum, seat) => sum + seat.x, 0) / seats.length, y: seats.reduce((sum, seat) => sum + seat.y, 0) / seats.length };
            const position = isTable ? center : { x: Math.max(0, left.x - 500), y: left.y };
            label.type = 'button';
            label.textContent = example.group_label;
            label.setAttribute('aria-label', `${text[example.group_kind]} ${example.group_label} · ${example.section}`);
            label.className = 'absolute -translate-x-1/2 -translate-y-1/2 border border-slate-300 bg-slate-100 text-xs font-bold text-slate-700 focus-visible:outline-2 focus-visible:outline-indigo-600';
            if (isTable) {
                label.classList.add('rounded-full');
                const bounds = map.getBoundingClientRect();
                const radius = Math.min(...seats.map((seat) => Math.hypot((seat.x - center.x) * bounds.width / 10000, (seat.y - center.y) * bounds.height / 10000)));
                const diameter = clamp(radius * 1.35, 24, 110);
                label.style.width = `${diameter}px`;
                label.style.height = `${diameter}px`;
            } else {
                label.classList.add('rounded-md', 'px-1.5', 'py-0.5');
            }
            setPosition(label, position);
            label.addEventListener('click', (event) => {
                if (event.detail !== 0) return;
                choose(seats, event.shiftKey);
                [...groupNodes].find(([, group]) => group.includes(example))?.[0].focus({ preventScroll: true });
            });
            groupNodes.set(label, seats);
            labels.append(label);
        }
        el('groups').replaceChildren(labels);
        renderBlocks();
    }

    function positionBlock(node, block) {
        setPosition(node, block);
        node.style.width = `${block.width / 100}%`;
        node.style.height = `${block.height / 100}%`;
    }

    function renderBlocks() {
        blockNodes = new Map();
        el('blocks').replaceChildren(...(currentFloor()?.blocks ?? []).flatMap((block) => {
            const node = document.createElement('button');
            node.type = 'button';
            node.dataset.hallBlock = block.id;
            node.className = 'absolute flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-lg border-2 p-1 text-center text-xs font-semibold leading-tight break-words focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';
            node.classList.add(...(block.kind === 'stage' ? ['bg-slate-700', 'border-slate-800', 'text-white']
                : block.kind === 'bar' ? ['bg-slate-200', 'border-slate-400', 'text-slate-800']
                    : ['bg-slate-100', 'border-slate-300', 'text-slate-600']));
            node.classList.toggle('ring-2', selectedBlock === block);
            node.classList.toggle('ring-amber-500', selectedBlock === block);
            node.setAttribute('aria-pressed', String(selectedBlock === block));
            node.setAttribute('aria-label', `${text[block.kind]} · ${block.label}`);
            node.textContent = block.label;
            positionBlock(node, block);
            const bounds = map.getBoundingClientRect();
            node.style.fontSize = `${clamp(Math.min(bounds.height * block.height / 10000 * 0.35,
                bounds.width * block.width / 10000 / Math.max(1, block.label.length) * 1.5), 10, 22)}px`;
            node.addEventListener('click', (event) => {
                if (event.detail !== 0) return;
                chooseBlock(block);
                map.focus({ preventScroll: true });
            });
            blockNodes.set(node, block);
            if (selectedBlock !== block || !canEdit()) return [node];
            const handle = document.createElement('button');
            handle.type = 'button';
            handle.tabIndex = -1;
            handle.dataset.hallResize = '';
            handle.className = 'absolute z-30 size-4 -translate-x-1/2 -translate-y-1/2 cursor-se-resize rounded border-2 border-white bg-amber-500 shadow';
            handle.setAttribute('aria-label', text.resize_block);
            setPosition(handle, { x: block.x + block.width / 2, y: block.y + block.height / 2 });
            blockNodes.set(handle, block);
            return [node, handle];
        }));
        el('block-list-panel').hidden = !canEdit() || !currentFloor();
        el('block-list').replaceChildren(...(currentFloor()?.blocks ?? []).map((block) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.hallBlockSelect = block.id;
            button.className = 'flex w-full items-center justify-between gap-2 rounded-lg border border-transparent px-2.5 py-2 text-left text-xs font-medium text-slate-700 hover:bg-slate-50 aria-pressed:border-amber-300 aria-pressed:bg-amber-50';
            button.setAttribute('aria-pressed', String(selectedBlock === block));
            const label = document.createElement('span');
            label.textContent = block.label;
            const kind = document.createElement('span');
            kind.className = 'shrink-0 text-slate-500';
            kind.textContent = text[block.kind];
            button.append(label, kind);
            button.addEventListener('click', () => {
                chooseBlock(block);
                el('inspector').scrollTo({ top: 0 });
                map.focus({ preventScroll: true });
            });
            return button;
        }));
    }

    function chooseBlock(block) {
        if (preview) return;
        selected.clear();
        selectedBlock = block;
        renderInspector();
        renderPoints();
    }

    function choose(seats, extend = false) {
        if (preview) {
            if (seats.length) feedback(seatLabel(seats[0]));
            return;
        }
        selectedBlock = null;
        if (!extend) selected.clear();
        for (const seat of seats) {
            if (extend && selected.has(seat)) selected.delete(seat);
            else selected.add(seat);
        }
        renderInspector();
        renderPoints();
    }

    function renderInspector() {
        el('selection').textContent = selectedBlock ? selectedBlock.label : count('selection', selected.size);
        el('selection-hint').hidden = Boolean(selected.size || selectedBlock);
        el('seat-pricing').hidden = !canEdit() || Boolean(selectedBlock);
        el('block-panel').hidden = !canEdit() || !selectedBlock;
        for (const input of root.querySelectorAll('[data-hall-block-property]')) {
            const key = input.dataset.hallBlockProperty;
            input.value = selectedBlock ? (['width', 'height'].includes(key) ? selectedBlock[key] / 100 : selectedBlock[key]) : '';
        }
        properties.section.replaceChildren(new Option(text.choose_section, ''), ...floorSections().map((name) => new Option(name, name)));
        const example = selected.values().next().value;
        const seats = [...selected];
        for (const [field, input] of Object.entries(properties)) {
            const value = example?.[field] ?? '';
            input.value = seats.every((seat) => (seat[field] ?? '') === value) ? value : '';
            input.disabled = !selected.size || (input.hasAttribute('data-hall-single') && selected.size > 1);
        }

        el('properties-panel').hidden = !canEdit() || !selected.size;
        el('selection-actions').hidden = !selected.size || preview;
        el('floor-panel').hidden = !canEdit() || !currentFloor();
        actions.clear.hidden = !selected.size && !selectedBlock;
        actions.block.disabled = !seats.some((seat) => !seat.unavailable && !seat.is_blocked);
        actions.unblock.disabled = !seats.some((seat) => !seat.unavailable && seat.is_blocked);
        el('type-hint').textContent = selected.size ? text.apply_type_hint : text.default_type_hint;
        el('ticket-types').replaceChildren(...[...typeMap.values()].map((type) => {
            const button = document.createElement('button');
            const active = seats.length ? seats.every((seat) => seat.festival_admission_type_id === type.id) : defaultType === type.id;
            button.type = 'button';
            button.className = 'flex items-center gap-2 rounded-lg border border-slate-200 px-2.5 py-2 text-left text-xs font-medium text-slate-800 hover:bg-slate-50 aria-pressed:border-amber-500 aria-pressed:bg-amber-50 disabled:opacity-50';
            button.setAttribute('aria-pressed', String(active));
            button.dataset.hallType = type.id;
            button.disabled = !type.active;
            button.append(dot(type.color), document.createTextNode(`${type.name} · ${type.price}`));
            button.addEventListener('click', () => {
                defaultType = type.id;
                if (seats.length) {
                    checkpoint();
                    seats.forEach((seat) => { seat.festival_admission_type_id = type.id; });
                }
                render();
                el('ticket-types').querySelector(`[data-hall-type="${type.id}"]`)?.focus({ preventScroll: true });
            });
            return button;
        }));
    }

    function renderPalette() {
        const colors = [...new Set(floorSeats().map((seat) => seat.color).filter(Boolean))];
        el('imported-colors').hidden = !colors.length;
        el('colors').replaceChildren(...colors.map((color) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'inline-flex items-center gap-1 rounded-md border border-slate-200 px-2 py-1.5 font-mono text-xs text-slate-700 hover:border-slate-500';
            button.append(dot(color), document.createTextNode(color));
            button.addEventListener('click', () => {
                const seats = floorSeats().filter((seat) => seat.color === color);
                if (section && seats.some((seat) => seat.section !== section)) {
                    section = '';
                    render();
                }
                choose(seats);
            });
            return button;
        }));
    }

    function renderSections() {
        const sections = floorSections();
        if (!sections.includes(section)) section = '';
        el('sections').hidden = sections.length < 2;
        el('sections').replaceChildren(...['', ...sections].map((name) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = name || text.all_sections;
            button.className = 'rounded-md px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 aria-pressed:bg-slate-200';
            button.setAttribute('aria-pressed', String(name === section));
            button.addEventListener('click', () => {
                section = name;
                selected.clear();
                selectedBlock = null;
                render();
                el('sections').querySelector('[aria-pressed=true]')?.focus({ preventScroll: true });
            });
            return button;
        }));
        el('section-panel').hidden = !canEdit() || !currentFloor();
        el('section-list').replaceChildren(...sections.map((name) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'flex w-full items-center justify-between gap-2 rounded-lg border border-transparent px-2.5 py-2 text-left text-xs font-medium text-slate-700 hover:bg-slate-50 aria-pressed:border-amber-300 aria-pressed:bg-amber-50';
            button.dataset.hallSection = name;
            button.setAttribute('aria-pressed', String(section === name));
            const label = document.createElement('span');
            label.textContent = name;
            const total = document.createElement('span');
            total.className = 'rounded bg-slate-100 px-1.5 py-0.5 tabular-nums text-slate-500';
            total.textContent = floorSeats().filter((seat) => seat.section === name).length;
            button.append(label, total);
            button.addEventListener('click', () => {
                section = name;
                selected.clear();
                selectedBlock = null;
                render();
                el('section-name').focus({ preventScroll: true });
            });
            return button;
        }));
        el('section-editor').hidden = !section;
        el('section-name').value = section;
        el('section-removal').hidden = true;
        sectionError();
        el('section-hint').textContent = section ? text.section_draw_hint : text.sections_hint;
        actions['section-add'].disabled = sections.length >= 80;
        actions['section-remove'].disabled = sections.length === 1 && floorSeats().some((seat) => seat.section === section);
    }

    function render() {
        const floor = currentFloor();
        renderSections();
        el('floors').replaceChildren(...state.floors.map((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = item.label;
            button.className = 'rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 aria-pressed:border-amber-300 aria-pressed:bg-amber-50 aria-pressed:text-slate-950';
            button.setAttribute('aria-pressed', String(item.id === floorId));
            button.addEventListener('click', () => {
                floorId = item.id;
                section = '';
                selected.clear();
                selectedBlock = null;
                el('import-note').textContent = '';
                render();
                [...el('floors').children].find((node) => node.getAttribute('aria-pressed') === 'true')?.focus({ preventScroll: true });
            });
            return button;
        }));
        el('floor-name').value = floor?.label ?? '';
        el('stage-name').value = floor?.stage ?? '';
        el('legacy-stage').hidden = !floor?.stage;
        el('count').textContent = count('seat_count', floorSeats().length);
        el('empty').hidden = Boolean(floor);
        map.hidden = !floor;
        map.style.aspectRatio = floor ? `${floor.width} / ${floor.height}` : '';
        map.style.backgroundImage = floor?.background ? `url("${floor.background}")` : '';
        map.style.touchAction = 'none';
        map.style.cursor = tool === 'pan' ? 'grab' : (tool === 'select' ? 'default' : 'crosshair');
        el('stage').textContent = floor?.stage || '';
        el('stage').hidden = !floor?.stage;
        el('status').textContent = state.enabled ? text.on : text.off;
        el('notice').textContent = state.has_holds ? text.active_holds : (state.frozen ? text.history : '');
        el('notice').hidden = !el('notice').textContent;
        el('preview-note').hidden = !preview;
        el('inspector').hidden = preview;
        el('workspace').classList.toggle('xl:grid-cols-[minmax(0,1fr)_19rem]', !preview);
        el('preview-label').textContent = preview ? text.edit : text.preview;
        actions.mode.textContent = state.enabled ? text.disable : (state.frozen ? text.enable : text.save_and_enable);
        actions.mode.hidden = preview;
        actions.mode.disabled = busy || state.has_holds;
        actions.save.disabled = busy;
        actions.import.disabled = busy;
        actions['floor-add'].disabled = state.floors.length >= 12;
        actions.undo.disabled = !history.length;
        actions.redo.disabled = !future.length;
        root.querySelectorAll('[data-hall-structure]').forEach((node) => { node.hidden = !canEdit(); });
        el('properties-panel').hidden = !canEdit() || !selected.size;
        el('floor-panel').hidden = !canEdit() || !floor;
        for (const button of tools) {
            button.setAttribute('aria-pressed', String(button.dataset.hallTool === tool));
            button.disabled = !floor;
        }
        const countControl = ['row', 'table'].includes(tool) && Boolean(floor) && canEdit();
        el('count-control').hidden = !countControl;
        el('hint').textContent = preview ? text.preview_copy : (floor ? text[`tool_${tool}`] : text.empty_map);

        el('legend').replaceChildren(...[...typeMap.values()].map((type) => {
            const item = document.createElement('li');
            item.className = 'flex items-center gap-1.5';
            item.append(dot(type.color), document.createTextNode(`${type.name} · ${type.price}`));
            return item;
        }), ...[[text.blocked, '#e2e8f0'], [text.unavailable, '#64748b']].map(([label, color]) => {
            const item = document.createElement('li');
            item.className = 'flex items-center gap-1.5';
            item.append(dot(color), document.createTextNode(label));
            return item;
        }));

        renderInspector();
        el('section-panel').hidden = !canEdit() || !floor;
        renderPalette();
        sizeMap();
        renderPoints();
    }

    async function send(path, method, data) {
        if (busy) return null;
        const form = data instanceof FormData;
        busy = true;
        root.inert = true;
        feedback();
        render();

        try {
            const response = await fetch(routes[path], {
                method,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    ...(!form && { 'Content-Type': 'application/json' }),
                },
                body: form ? data : JSON.stringify(data),
            });
            if (!response.headers.get('content-type')?.includes('application/json')) {
                throw new Error(text.generic_error);
            }
            const result = await response.json();
            if (!response.ok) {
                throw new Error(Object.values(result.errors ?? {}).flat().join(' ') || result.message || text.generic_error);
            }
            return result;
        } catch (error) {
            feedback(error.message || text.generic_error, true);
            return null;
        } finally {
            busy = false;
            root.inert = false;
            render();
        }
    }

    function load(result) {
        if (!result) return;
        Object.assign(state, result);
        if (!currentFloor()) floorId = state.floors[0]?.id;
        selected.clear();
        selectedBlock = null;
        tool = 'select';
        history.length = 0;
        future.length = 0;
        dirty = false;
        feedback(text.saved);
        render();
    }

    function save(publish) {
        return send('save', 'PUT', {
            publish,
            floors: state.floors,
            seats: state.seats.map(({ color, unavailable, ...seat }) => seat),
        });
    }

    function addFloor() {
        if (state.floors.length >= 12) return null;
        const id = crypto.randomUUID();
        let label = text.default_floor;
        let suffix = 2;
        while (state.floors.some((floor) => floor.label === label)) {
            label = `${text.floor} ${suffix++}`;
        }
        const floor = { id, label, width: 900, height: 600, stage: null, background: null, sections: [text.default_section], blocks: [] };
        state.floors.push(floor);
        floorId = id;
        section = '';
        selected.clear();
        selectedBlock = null;
        dirty = true;
        return floor;
    }

    function insertSeat(floor, position, extra = {}) {
        if (state.seats.length >= maxSeats) {
            feedback(text.too_many_seats, true);
            return null;
        }
        const seat = {
            floor_key: floor.id,
            section: section || floorSections()[0] || text.default_section,
            group_kind: 'row',
            group_label: 'A',
            seat_number: String(floorSeats().length + 1),
            festival_admission_type_id: defaultType,
            accessibility: null,
            sightline: null,
            is_blocked: false,
            ...position,
            ...extra,
        };
        floor.sections = [...new Set([...(floor.sections ?? []), ...floorSeats().map((item) => item.section), seat.section])];
        state.seats.push(seat);
        dirty = true;
        return seat;
    }

    function pointAt(event) {
        const bounds = map.getBoundingClientRect();
        return {
            x: clamp(Math.round((event.clientX - bounds.left) / bounds.width * 10000)),
            y: clamp(Math.round((event.clientY - bounds.top) / bounds.height * 10000)),
        };
    }

    function positions(start, end, number, kind) {
        if (kind === 'table') {
            const bounds = map.getBoundingClientRect();
            const radiusX = Math.min(2300, 730 + number * 45);
            const radiusY = Math.min(3300, Math.round(radiusX * bounds.width / bounds.height));
            const centerX = clamp(start.x, radiusX, 10000 - radiusX);
            const centerY = clamp(start.y, radiusY, 10000 - radiusY);
            return Array.from({ length: number }, (_, index) => {
                const angle = 2 * Math.PI * index / number - Math.PI / 2;
                return { x: Math.round(centerX + radiusX * Math.cos(angle)), y: Math.round(centerY + radiusY * Math.sin(angle)) };
            });
        }
        return Array.from({ length: number }, (_, index) => ({
            x: Math.round(start.x + (end.x - start.x) * index / (number - 1)),
            y: Math.round(start.y + (end.y - start.y) * index / (number - 1)),
        }));
    }

    function nextGroupLabel(kind) {
        const existing = new Set(floorSeats().filter((seat) => seat.group_kind === kind).map((seat) => seat.group_label));
        let number = 1;
        let label;
        do {
            label = kind === 'row' && number <= 26 ? String.fromCharCode(64 + number) : String(number);
            number++;
        } while (existing.has(label));
        return label;
    }

    function addSeats(points, kind) {
        if (!points.length) return;
        if (state.seats.length + points.length > maxSeats) {
            feedback(text.too_many_seats, true);
            return;
        }
        const group = kind === 'seat' ? 'row' : kind;
        const groupLabel = nextGroupLabel(group);
        checkpoint();
        selected.clear();
        selectedBlock = null;
        points.forEach((point, index) => selected.add(insertSeat(currentFloor(), point, {
            group_kind: group, group_label: groupLabel, seat_number: String(index + 1),
        })));
        tool = 'select';
        render();
    }

    function addBlock(position, kind, end = null) {
        const floor = currentFloor();
        if (!canEdit() || !floor || (floor.blocks?.length ?? 0) >= 80) return;
        let width = end ? Math.max(300, Math.abs(end.x - position.x)) : 2400;
        let height = end ? Math.max(300, Math.abs(end.y - position.y)) : 900;
        const x = clamp(end ? Math.round((position.x + end.x) / 2) : position.x, Math.ceil(width / 2), Math.floor(10000 - width / 2));
        const y = clamp(end ? Math.round((position.y + end.y) / 2) : position.y, Math.ceil(height / 2), Math.floor(10000 - height / 2));
        checkpoint();
        const block = { id: crypto.randomUUID(), kind, label: text[kind], x, y, width, height };
        floor.blocks ??= [];
        floor.blocks.push(block);
        tool = 'select';
        selected.clear();
        selectedBlock = block;
        render();
    }

    function selectTool(name) {
        if (name !== 'select') {
            selected.clear();
            selectedBlock = null;
        }
        tool = name;
        el('row-count').max = name === 'table' ? 16 : 80;
        if (name === 'table' && Number(el('row-count').value) > 16) el('row-count').value = 8;
        render();
    }

    function drawingCount() {
        return clamp(Math.trunc(Number(el('row-count').value) || 2), 2, Number(el('row-count').max));
    }

    function previewPoints(points) {
        el('overlay').replaceChildren(...points.map((point) => {
            const ghost = document.createElement('span');
            ghost.className = 'absolute size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-dashed border-amber-600 bg-amber-100';
            setPosition(ghost, point);
            return ghost;
        }));
    }

    function marquee(start, end) {
        const box = document.createElement('span');
        box.className = 'absolute border border-indigo-500 bg-indigo-100/50';
        setPosition(box, { x: Math.min(start.x, end.x), y: Math.min(start.y, end.y) });
        box.style.width = `${Math.abs(end.x - start.x) / 100}%`;
        box.style.height = `${Math.abs(end.y - start.y) / 100}%`;
        el('overlay').replaceChildren(box);
    }

    function shiftSelection(origins, delta) {
        if (!origins.size) return;
        const points = [...origins.values()];
        const dx = clamp(delta.x, -Math.min(...points.map(({ x }) => x)), 10000 - Math.max(...points.map(({ x }) => x)));
        const dy = clamp(delta.y, -Math.min(...points.map(({ y }) => y)), 10000 - Math.max(...points.map(({ y }) => y)));
        for (const [seat, position] of origins) {
            seat.x = position.x + dx;
            seat.y = position.y + dy;
        }
    }

    function sizeMap() {
        const floor = currentFloor();
        if (!floor) return;
        const padding = parseFloat(getComputedStyle(viewport).paddingLeft) * 2;
        const stageHeight = floor.stage ? el('stage').offsetHeight + 12 : 0;
        const width = Math.min(viewport.clientWidth - padding, (viewport.clientHeight - padding - stageHeight) * floor.width / floor.height);
        map.style.width = `${Math.max(224, width) * Number(el('zoom').value) / 100}px`;
    }

    new ResizeObserver(() => { sizeMap(); renderPoints(); }).observe(viewport);

    tools.forEach((button) => button.addEventListener('click', () => selectTool(button.dataset.hallTool)));
    for (const [name, property] of [['floor-name', 'label'], ['stage-name', 'stage']]) {
        el(name).addEventListener('change', ({ target }) => {
            const floor = currentFloor();
            const value = target.value.trim();
            if (!canEdit() || !floor || (property === 'label' && !value)) {
                target.value = floor?.[property] ?? '';
                return;
            }
            if (floor[property] === value) return;
            checkpoint();
            floor[property] = value;
            if (property === 'label') {
                el('floors').querySelector('[aria-pressed=true]').textContent = value;
            } else {
                el('stage').textContent = value;
                el('stage').hidden = !value;
            }
        });
    }
    el('zoom').addEventListener('input', ({ target }) => {
        sizeMap();
        el('zoom-value').textContent = `${target.value}%`;
        renderPoints();
    });

    map.addEventListener('pointerdown', (event) => {
        if (!currentFloor() || event.button !== 0 || gesture) return;
        const target = event.target.closest('button');
        const seat = seatNodes.get(target);
        const block = blockNodes.get(target);
        const hit = groupNodes.get(target) ?? (seat ? [seat] : []);
        const mode = tool === 'pan' ? 'pan' : (canEdit() ? tool : 'select');
        const extend = event.shiftKey || event.ctrlKey || event.metaKey;
        let moving = [];
        if (mode === 'select' && hit.length && !extend && canEdit()) {
            moving = hit.every((item) => selected.has(item)) ? [...selected] : hit;
        }
        gesture = {
            pointer: event.pointerId, mode, hit, extend, start: pointAt(event),
            client: { x: event.clientX, y: event.clientY },
            scroll: { x: viewport.scrollLeft, y: viewport.scrollTop },
            origins: new Map(moving.map((item) => [item, { x: item.x, y: item.y }])),
            before: moving.length || block ? snapshot() : null,
            block: mode === 'select' ? block : null,
            blockOrigin: block ? { ...block } : null,
            resizing: Boolean(event.target.closest('[data-hall-resize]')),
            labels: new Map([...groupNodes].filter(([, seats]) => moving.length && seats.every((seat) => moving.includes(seat)))
                .map(([node]) => [node, { x: parseFloat(node.style.left) * 100, y: parseFloat(node.style.top) * 100 }])),
            moved: false,
        };
        event.preventDefault();
        map.focus({ preventScroll: true });
        map.setPointerCapture(event.pointerId);
    });

    map.addEventListener('pointermove', (event) => {
        const position = pointAt(event);
        if (!gesture) {
            if (canEdit() && event.pointerType !== 'touch' && (tool === 'seat' || tool === 'table')) {
                previewPoints(tool === 'table' ? positions(position, position, drawingCount(), tool) : [position]);
            }
            return;
        }
        if (gesture.pointer !== event.pointerId) return;
        const { mode, start, client, scroll, origins, hit, labels, block, blockOrigin, resizing } = gesture;
        const dx = event.clientX - client.x;
        const dy = event.clientY - client.y;
        gesture.moved ||= Math.hypot(dx, dy) > 4;
        if (!gesture.moved) return;
        if (mode === 'pan') {
            viewport.scrollLeft = scroll.x - dx;
            viewport.scrollTop = scroll.y - dy;
        } else if (mode === 'row') {
            previewPoints(positions(start, position, drawingCount(), mode));
        } else if (['stage', 'bar', 'area'].includes(mode)) {
            marquee(start, position);
        } else if (mode === 'select' && block && canEdit()) {
            if (resizing) {
                const left = blockOrigin.x - blockOrigin.width / 2;
                const top = blockOrigin.y - blockOrigin.height / 2;
                block.width = Math.round(clamp(blockOrigin.width + position.x - start.x, 100, 10000 - left));
                block.height = Math.round(clamp(blockOrigin.height + position.y - start.y, 100, 10000 - top));
                block.x = Math.round(left + block.width / 2);
                block.y = Math.round(top + block.height / 2);
                block.width = Math.min(block.width, 2 * Math.min(block.x, 10000 - block.x));
                block.height = Math.min(block.height, 2 * Math.min(block.y, 10000 - block.y));
            } else {
                block.x = clamp(blockOrigin.x + position.x - start.x, Math.ceil(block.width / 2), Math.floor(10000 - block.width / 2));
                block.y = clamp(blockOrigin.y + position.y - start.y, Math.ceil(block.height / 2), Math.floor(10000 - block.height / 2));
            }
            positionBlock(targetBlockNode(block), block);
            const handle = el('blocks').querySelector('[data-hall-resize]');
            if (handle) setPosition(handle, { x: block.x + block.width / 2, y: block.y + block.height / 2 });
        } else if (mode === 'select' && origins.size) {
            selected.clear();
            origins.forEach((_, seat) => selected.add(seat));
            shiftSelection(origins, { x: position.x - start.x, y: position.y - start.y });
            const [first, origin] = origins.entries().next().value;
            for (const [node, seat] of seatNodes) {
                if (origins.has(seat)) setPosition(node, seat);
            }
            for (const [node, label] of labels) {
                setPosition(node, { x: label.x + first.x - origin.x, y: label.y + first.y - origin.y });
            }
        } else if (mode === 'select' && !hit.length && !preview) {
            marquee(start, position);
        }
    });

    function endGesture(event, cancelled = false) {
        if (!gesture || gesture.pointer !== event.pointerId) return;
        const { mode, hit, start, extend, moved, origins, before, block, blockOrigin } = gesture;
        gesture = null;
        el('overlay').replaceChildren();
        if (cancelled) {
            for (const [seat, origin] of origins) Object.assign(seat, origin);
            if (block) Object.assign(block, blockOrigin);
            render();
            return;
        }
        const end = pointAt(event);
        if (mode === 'pan') return;
        if (mode === 'seat' && !moved) addSeats([end], mode);
        if (mode === 'table' && !moved) addSeats(positions(end, end, drawingCount(), mode), mode);
        if (mode === 'row' && moved) addSeats(positions(start, end, drawingCount(), mode), mode);
        if (['stage', 'bar', 'area'].includes(mode)) addBlock(start, mode, moved ? end : null);
        map.focus({ preventScroll: true });
        if (mode !== 'select') return;
        if (block) {
            if (moved && canEdit()) checkpoint(before);
            chooseBlock(block);
        } else if (moved && origins.size) {
            checkpoint(before);
            render();
        } else if (moved && !hit.length && !preview) {
            choose(visibleSeats().filter(({ x, y }) => x >= Math.min(start.x, end.x) && x <= Math.max(start.x, end.x)
                && y >= Math.min(start.y, end.y) && y <= Math.max(start.y, end.y)), extend);
        } else if (!moved) {
            choose(hit, extend);
        }
    }

    function targetBlockNode(block) {
        return [...blockNodes].find(([, item]) => item === block)?.[0];
    }

    map.addEventListener('pointerup', (event) => endGesture(event));
    map.addEventListener('pointercancel', (event) => endGesture(event, true));
    map.addEventListener('pointerleave', () => { if (!gesture) el('overlay').replaceChildren(); });

    actions.preview.addEventListener('click', () => {
        preview = !preview;
        tool = 'select';
        selected.clear();
        selectedBlock = null;
        feedback();
        render();
    });
    actions.save.addEventListener('click', async () => { load(await save(false)); });
    actions.mode.addEventListener('click', async () => {
        if (state.enabled && dirty) {
            const saved = await save(false);
            if (!saved) return;
            load(saved);
        }
        load(await (state.enabled || state.frozen ? send('mode', 'PATCH', { enabled: !state.enabled }) : save(true)));
    });
    for (const action of ['floor-add', 'start']) {
        actions[action].addEventListener('click', () => {
            if (!canEdit() || state.floors.length >= 12) return;
            checkpoint();
            addFloor();
            render();
        });
    }
    actions['floor-remove'].addEventListener('click', () => {
        if (!canEdit() || !currentFloor() || !window.confirm(text.remove_floor_confirm)) return;
        checkpoint();
        state.floors = state.floors.filter((floor) => floor.id !== floorId);
        state.seats = state.seats.filter((seat) => seat.floor_key !== floorId);
        floorId = state.floors[0]?.id;
        selected.clear();
        selectedBlock = null;
        render();
    });
    actions.clear.addEventListener('click', () => choose([]));
    actions.undo.addEventListener('click', () => restore(history, future));
    actions.redo.addEventListener('click', () => restore(future, history));

    for (const [field, input] of Object.entries(properties)) {
        input.addEventListener('change', () => {
            if (!canEdit() || !selected.size) return;
            const value = input.value.trim();
            const optional = ['accessibility', 'sightline'].includes(field);
            if (!value && !optional) {
                input.value = selected.values().next().value[field] ?? '';
                return;
            }
            checkpoint();
            selected.forEach((seat) => { seat[field] = value || null; });
            if (field === 'section') {
                section = value;
                renderSections();
            }
            renderPoints();
        });
    }

    function deleteSelection() {
        if (!canEdit() || (!selected.size && !selectedBlock)) return;
        checkpoint();
        state.seats = state.seats.filter((seat) => !selected.has(seat));
        if (selectedBlock) currentFloor().blocks = currentFloor().blocks.filter((block) => block !== selectedBlock);
        selected.clear();
        selectedBlock = null;
        render();
    }
    actions['seat-delete'].addEventListener('click', deleteSelection);
    actions['block-delete'].addEventListener('click', deleteSelection);

    root.querySelectorAll('[data-hall-block-property]').forEach((input) => {
        input.addEventListener('change', () => {
            if (!canEdit() || !selectedBlock) return;
            const key = input.dataset.hallBlockProperty;
            const value = input.value.trim();
            if (!value) return renderInspector();
            checkpoint();
            if (key === 'width' || key === 'height') {
                const coordinate = key === 'width' ? 'x' : 'y';
                selectedBlock[key] = clamp(Math.round(Number(value) * 100) || 100, 100, 10000);
                const half = Math.ceil(selectedBlock[key] / 2);
                selectedBlock[coordinate] = clamp(selectedBlock[coordinate], half, 10000 - half);
            } else {
                selectedBlock[key] = value;
            }
            renderInspector();
            renderPoints();
        });
    });

    actions['section-add'].addEventListener('click', () => {
        if (!canEdit() || !currentFloor() || floorSections().length >= 80) return;
        const names = floorSections();
        let name = text.new_section;
        let suffix = 2;
        while (names.some((item) => item.toLocaleLowerCase() === name.toLocaleLowerCase())) name = `${text.new_section} ${suffix++}`;
        checkpoint();
        currentFloor().sections = [...names, name];
        section = name;
        selected.clear();
        selectedBlock = null;
        render();
        el('section-name').focus();
        el('section-name').select();
    });

    function renameSection() {
        if (!canEdit() || !section) return;
        const name = el('section-name').value.trim();
        if (name === section) return;
        if (!name || floorSections().some((item) => item !== section && item.toLocaleLowerCase() === name.toLocaleLowerCase())) {
            sectionError(text.duplicate_sections);
            return;
        }
        checkpoint();
        currentFloor().sections = floorSections().map((item) => item === section ? name : item);
        floorSeats().filter((seat) => seat.section === section).forEach((seat) => { seat.section = name; });
        section = name;
        feedback();
        render();
    }
    actions['section-rename'].addEventListener('click', renameSection);
    el('section-name').addEventListener('input', () => sectionError());
    el('section-name').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { event.preventDefault(); renameSection(); }
    });

    function removeSection() {
        if (!canEdit() || !section) return;
        const occupied = floorSeats().some((seat) => seat.section === section);
        const target = el('section-target').value;
        if (occupied && (!target || target === section || !floorSections().includes(target))) return;
        const targetGroups = new Set(floorSeats().filter((seat) => seat.section === target)
            .map((seat) => JSON.stringify([seat.group_kind, seat.group_label.toLocaleLowerCase()])));
        if (floorSeats().some((seat) => seat.section === section && targetGroups.has(JSON.stringify([seat.group_kind, seat.group_label.toLocaleLowerCase()])))) {
            sectionError(text.section_group_conflict);
            return;
        }
        checkpoint();
        currentFloor().sections = floorSections().filter((name) => name !== section);
        floorSeats().filter((seat) => seat.section === section).forEach((seat) => { seat.section = target; });
        section = '';
        selected.clear();
        selectedBlock = null;
        render();
    }
    actions['section-remove'].addEventListener('click', () => {
        if (!canEdit() || !section) return;
        if (!floorSeats().some((seat) => seat.section === section)) return removeSection();
        const remaining = floorSections().filter((name) => name !== section);
        if (!remaining.length) return;
        el('section-target').replaceChildren(...remaining.map((name) => new Option(name, name)));
        el('section-removal').hidden = false;
    });
    actions['section-remove-confirm'].addEventListener('click', removeSection);
    actions['section-remove-cancel'].addEventListener('click', () => { el('section-removal').hidden = true; });

    function sectionError(message = '') {
        el('section-error').textContent = message;
        el('section-error').hidden = !message;
    }

    function moveEditor(expanded) {
        const { scrollLeft, scrollTop } = viewport;
        root.toggleAttribute('data-hall-expanded', expanded);
        if (expanded) {
            fullscreen.append(root);
            fullscreen.showModal();
        } else {
            fullscreen.after(root);
        }
        actions.fullscreen.setAttribute('aria-expanded', String(expanded));
        el('fullscreen-label').textContent = expanded ? text.exit_fullscreen : text.fullscreen;
        el('expand-icon').hidden = expanded;
        el('collapse-icon').hidden = !expanded;
        renderPoints();
        viewport.scrollTo(scrollLeft, scrollTop);
        actions.fullscreen.focus({ preventScroll: true });
    }

    actions.fullscreen.addEventListener('click', () => {
        if (fullscreen.open) fullscreen.close();
        else moveEditor(true);
    });
    fullscreen.addEventListener('close', () => moveEditor(false));

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && fullscreen.open) return;
        if (!canEdit() || event.target.closest('input, select, textarea') || gesture) return;
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            if (event.shiftKey) restore(future, history);
            else restore(history, future);
        }
        if (event.key === 'Escape') {
            tool = 'select';
            choose([]);
            render();
        }
        if (!map.contains(event.target) || (!selected.size && !selectedBlock)) return;
        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            deleteSelection();
            map.focus();
        }
        const steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
        const step = steps[event.key];
        if (!step) return;
        event.preventDefault();
        checkpoint();
        const distance = event.shiftKey ? 300 : 75;
        if (selectedBlock) {
            const halfWidth = Math.ceil(selectedBlock.width / 2);
            const halfHeight = Math.ceil(selectedBlock.height / 2);
            selectedBlock.x = clamp(selectedBlock.x + step[0] * distance, halfWidth, 10000 - halfWidth);
            selectedBlock.y = clamp(selectedBlock.y + step[1] * distance, halfHeight, 10000 - halfHeight);
        } else {
            shiftSelection(new Map([...selected].map((seat) => [seat, { x: seat.x, y: seat.y }])), { x: step[0] * distance, y: step[1] * distance });
        }
        render();
        map.focus({ preventScroll: true });
    });

    async function blockSeats(blocked) {
        const seats = [...selected].filter((seat) => !seat.unavailable);
        if (!canEdit()) {
            const ids = seats.map((seat) => seat.id).filter(Boolean);
            if (ids.length) load(await send('block', 'PATCH', { blocked, seat_ids: ids }));
            return;
        }
        if (!seats.length) return;
        checkpoint();
        seats.forEach((seat) => { seat.is_blocked = blocked; });
        render();
    }
    actions.block.addEventListener('click', () => { blockSeats(true); });
    actions.unblock.addEventListener('click', () => { blockSeats(false); });

    actions.import.addEventListener('click', async () => {
        const input = el('import-file');
        const file = input.files[0];
        if (!file || !canEdit()) return;
        if (floorSeats().length && !window.confirm(text.import_replace)) return;
        const form = new FormData();
        form.append('floor_image', file);
        const result = await send('import', 'POST', form);
        if (!result) return;
        if (state.seats.length - floorSeats().length + result.seats.length > maxSeats) {
            feedback(text.too_many_seats, true);
            return;
        }

        checkpoint();
        const floor = currentFloor() ?? addFloor();
        if (!floor) return;
        state.seats = state.seats.filter((seat) => seat.floor_key !== floorId);
        selected.clear();
        selectedBlock = null;
        section = '';
        Object.assign(floor, { width: result.width, height: result.height, background: result.background });
        result.seats.forEach(({ x, y, color }) => { insertSeat(floor, { x, y }, { color, festival_admission_type_id: null }); });
        el('import-note').textContent = [count('import_count', result.seats.length), ...Object.entries(result.warnings).map(([key, number]) => count(key, number))].join(' ');
        input.value = '';
        dirty = true;
        render();
    });

    render();
}
