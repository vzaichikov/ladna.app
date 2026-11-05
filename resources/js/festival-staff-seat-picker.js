import { appendSeatGeometry } from './festival-seat-geometry';
const svgNamespace = 'http://www.w3.org/2000/svg';

function shape(name, attributes) {
    const node = document.createElementNS(svgNamespace, name);
    for (const [key, value] of Object.entries(attributes)) {
        node.setAttribute(key, value);
    }
    return node;
}

function initPicker(picker) {
    const form = picker.closest('form');
    const ticketType = form.elements.namedItem(picker.dataset.typeField);
    const floorField = picker.querySelector('[data-staff-seat-floor]');
    const selection = picker.querySelector('[data-staff-seat-selection]');
    const details = picker.querySelector('[data-staff-seat-details]');
    const stage = picker.querySelector('[data-staff-seat-stage]');
    const map = picker.querySelector('[data-staff-seat-map]');
    const prompt = selection.options[0].text;
    const chart = JSON.parse(picker.querySelector('[data-staff-seat-chart]').textContent);
    const floors = new Map((chart.floors || []).map((floor) => [floor.id, floor]));
    const assignedTypes = new Set(chart.types.map(String));
    let unavailable = new Set(chart.unavailable.map(String));
    let selectedId = selection.dataset.oldSeat;
    let zoom = 100;
    let availabilityRequest;

    for (const floor of floors.values()) {
        floorField.add(new Option(floor.label, floor.id));
    }
    const oldSeat = chart.seats.find((seat) => String(seat.id) === selectedId);
    if (oldSeat && floors.has(oldSeat.floor)) {
        floorField.value = oldSeat.floor;
    }

    const syncGuestNavigation = () => {
        document.querySelectorAll('[data-preserve-staff-seat], [data-festival-guest-selection] a[href]').forEach(link => {
            const url = new URL(link.href); if (selectedId) url.searchParams.set('seat_id', selectedId); else url.searchParams.delete('seat_id'); link.href = url.href;
        });
        document.querySelectorAll('[data-preserve-seat-input]').forEach(input => { input.value = selectedId; });
    };

    const render = () => {
        const typeId = ticketType.value;
        const floor = floors.get(floorField.value);
        const assigned = assignedTypes.has(typeId);
        picker.hidden = !assigned;
        selection.disabled = !assigned;
        selection.required = assigned;

        if (!assigned || !floor) {
            selectedId = '';
            selection.value = '';
            syncGuestNavigation();
            return;
        }

        const floorSeats = chart.seats.filter((seat) => seat.floor === floor.id);
        const available = (seat) => String(seat.type) === typeId && !unavailable.has(String(seat.id));
        const selectedSeat = floorSeats.find((seat) => String(seat.id) === selectedId && available(seat));
        selectedId = selectedSeat ? String(selectedSeat.id) : '';

        selection.replaceChildren(new Option(prompt, ''));
        for (const seat of floorSeats.filter(available)) {
            selection.add(new Option(seat.label, seat.id));
        }
        selection.value = selectedId;
        details.textContent = selectedSeat
            ? [selectedSeat.label, selectedSeat.accessibility, selectedSeat.sightline].filter(Boolean).join(' · ')
            : '';

        syncGuestNavigation();
        stage.textContent = floor.stage || '';
        stage.classList.toggle('hidden', !floor.stage);

        const { width, height } = floor;
        map.setAttribute('viewBox', `0 0 ${width} ${height}`);
        map.style.width = `${zoom}%`;
        map.replaceChildren();

        appendSeatGeometry(map, floor, floorSeats);

        const spacing = Math.sqrt(width * height / Math.max(1, floorSeats.length));
        const radius = Math.max(5, Math.min(16, Math.min(width, height) * 0.028, spacing * 0.15));
        for (const seat of floorSeats) {
            const enabled = available(seat);
            const id = String(seat.id);
            const x = seat.x * width / 10000;
            const y = seat.y * height / 10000;
            const circle = shape('circle', {
                cx: x, cy: y, r: radius,
                fill: id === selectedId ? '#047857' : enabled ? '#2563eb' : '#cbd5e1',
                stroke: id === selectedId ? '#064e3b' : '#fff',
                'stroke-width': id === selectedId ? 2 : 1,
            });
            const title = shape('title', {});
            title.textContent = seat.label;
            circle.append(title);
            if (enabled) {
                circle.style.cursor = 'pointer';
                circle.addEventListener('click', () => {
                    selectedId = id;
                    render();
                });
            }

            const number = shape('text', {
                x, y, 'font-size': radius,
                'text-anchor': 'middle', 'dominant-baseline': 'central',
                fill: enabled ? '#fff' : '#475569',
                'pointer-events': 'none',
            });
            number.textContent = seat.number;
            map.append(circle, number);
        }
    };

    const refresh = async () => {
        if (!picker.dataset.availabilityUrl) return;
        availabilityRequest?.abort();
        availabilityRequest = new AbortController();

        try {
            const response = await fetch(picker.dataset.availabilityUrl, {
                signal: availabilityRequest.signal,
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return;

            const data = await response.json();
            unavailable = new Set(data.unavailable.map(String));
            render();
        } catch {
            // A sale always verifies availability on the server.
        }
    };

    ticketType.addEventListener('change', () => {
        render();
        if (!picker.hidden) refresh();
    });
    floorField.addEventListener('change', render);
    selection.addEventListener('change', () => {
        selectedId = selection.value;
        render();
    });
    picker.querySelectorAll('[data-staff-seat-zoom]').forEach((button) => {
        button.addEventListener('click', () => {
            zoom = Math.min(400, Math.max(100, zoom + Number(button.dataset.staffSeatZoom) * 50));
            render();
        });
    });
    form.addEventListener('reset', () => {
        selectedId = '';
        queueMicrotask(render);
    });
    form.addEventListener('festival:refresh-staff-seats', refresh);
    render();
}

export function initFestivalStaffSeatPickers() {
    document.querySelectorAll('[data-festival-staff-seat-picker]:not([data-staff-seat-ready])').forEach((picker) => {
        picker.dataset.staffSeatReady = 'true';
        initPicker(picker);
    });
}
