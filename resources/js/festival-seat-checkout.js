import { appendSeatGeometry, svgShape } from './festival-seat-geometry';
const colors = ['#E11D48', '#2563EB', '#059669', '#D97706', '#7C3AED', '#0891B2'];

export function initFestivalSeatCheckout(root = document) {
    root.querySelectorAll('[data-festival-seat-picker]').forEach((picker) => {
        if (picker.dataset.seatPickerReady === 'true') return;
        picker.dataset.seatPickerReady = 'true';
        const form = picker.closest('form');
        const element = (name) => picker.querySelector(`[data-festival-seat-${name}]`);
        const floor = element('floor');
        const section = element('section');
        const map = element('map');
        const viewport = element('viewport');
        const list = element('list');
        const editor = element('editor');
        const checkoutId = element('checkout-id');
        const quoteInput = element('quote');
        const quoteTotal = element('quote-total');
        const fields = element('fields');
        const message = element('message');
        const retry = element('retry');
        const continueButton = element('continue');
        const details = [...form.querySelectorAll('[data-festival-seat-details]')];
        const format = new Intl.NumberFormat(form.dataset.locale, {
            style: 'currency', currency: form.dataset.currency, currencyDisplay: 'symbol',
        });
        const money = (cents) => format.format(cents / 100);
        const promoInput = form.querySelector('[data-promo-code-hidden]');
        const markerControls = new Map();
        const floorMarkerDiameters = new Map();
        const quantityInputs = [...form.querySelectorAll('[data-festival-exclusive-ticket="false"] [data-event-ticket-quantity]')];
        const cartKey = `festival-seat-cart:${form.action}`;
        let floors = [];
        let seats = new Map();
        let types = new Map();
        let selected = new Set();
        let conflicts = new Set();
        let hold = null;
        let deadline = 0;
        let quote = null;
        let editing = true;
        let listView = false;
        let zoom = 1;
        let markerDiameter = 24;
        let floorWidth = 1200;
        let mapReady = false;
        let saving = false;
        let mapRequest;
        let quoteTimer;
        let quoteRequest;
        let retryAction;
        const stateChange = () => form.dispatchEvent(new Event('festival-seats:change'));

        function showMessage(text = '', action = null) {
            message.textContent = text;
            message.hidden = text === '';
            retryAction = action;
            retry.hidden = !action;
        }

        const responseError = (payload) => Object.values(payload?.errors ?? {}).flat()[0]
            || payload?.message || picker.dataset.networkError;

        function seatLabel(seat, includeFloor = false) {
            return [
                includeFloor ? floors.find(({ id }) => id === seat.floor)?.label : null,
                seat.section,
                seat.group,
                picker.dataset.seatLabel.replace(':number', seat.number),
            ].filter(Boolean).join(' · ');
        }

        function seatDetails(seat) {
            return [
                seat.accessibility && `${picker.dataset.accessibilityLabel} · ${seat.accessibility}`,
                seat.sightline && `${picker.dataset.sightlineLabel} · ${seat.sightline}`,
            ].filter(Boolean).join(' · ');
        }

        function seatPrice(seat) {
            return Number(quote?.unit_prices_cents?.[seat.type_id] ?? types.get(seat.type_id)?.price_cents ?? 0);
        }

        function selectedSeats() {
            return [...selected].map((id) => seats.get(id)).filter(Boolean);
        }

        function paintMarkers() {
            markerControls.forEach((buttons, id) => {
                const seat = seats.get(id);
                if (!seat) return;
                const chosen = selected.has(id);
                const unavailable = !seat.available || saving;
                buttons.forEach((button) => {
                    button.disabled = unavailable;
                    button.dataset.state = chosen ? 'selected' : conflicts.has(id) ? 'conflict' : unavailable ? 'unavailable' : 'available';
                    button.setAttribute('aria-pressed', String(chosen));
                    if (button.hasAttribute('data-seat-marker')) {
                        button.textContent = chosen ? '✓' : seat.available ? seat.number : '×';
                    }
                });
            });
        }

        function toggleSeat(id) {
            const seat = seats.get(id);
            if (!seat?.available || saving) return;
            if (selected.has(id)) {
                selected.delete(id);
            } else {
                const sameType = selectedSeats().filter((choice) => choice.type_id === seat.type_id).length;
                if (selected.size >= 20 || sameType >= types.get(seat.type_id).max_per_order) {
                    showMessage(picker.dataset.limitMessage);
                    return;
                }
                selected.add(id);
            }
            conflicts.delete(id);
            showMessage();
            paintMarkers();
            syncCart();
        }

        function makeMarker(seat, mapMarker) {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.seatId = String(seat.id);
            const color = types.get(seat.type_id)?.color ?? colors[0];
            button.style.setProperty('--seat-color', color);
            const seatName = seatLabel(seat, true);
            const extra = seatDetails(seat);
            const price = money(seatPrice(seat));
            const description = [seatName, price, extra].filter(Boolean).join(' · ');
            button.title = description;
            if (mapMarker) {
                button.className = 'festival-seat-marker';
                button.dataset.seatMarker = '';
                button.style.left = `${seat.x / 100}%`;
                button.style.top = `${seat.y / 100}%`;
                button.setAttribute('aria-label', description);
                button.tabIndex = -1;
            } else {
                button.className = 'flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-start text-sm text-slate-950 outline-offset-1 data-[state=selected]:border-(--seat-color) data-[state=selected]:ring-1 data-[state=selected]:ring-(--seat-color) data-[state=unavailable]:opacity-50 data-[state=conflict]:border-rose-700';
                const label = document.createElement('span');
                label.textContent = seatLabel(seat);
                const priceTag = document.createElement('span');
                priceTag.className = 'shrink-0 font-semibold tabular-nums';
                priceTag.textContent = price;
                button.append(label, priceTag);
                if (extra) {
                    const notes = document.createElement('span');
                    notes.className = 'block text-xs text-slate-500';
                    notes.textContent = extra;
                    label.append(notes);
                }
            }
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                if (mapMarker && button.getBoundingClientRect().width < 24 && zoom < 8) {
                    zoomToSeat(seat);
                    return;
                }
                toggleSeat(seat.id);
            });
            const controls = markerControls.get(seat.id) ?? [];
            controls.push(button);
            markerControls.set(seat.id, controls);
            return button;
        }

        function changeZoom(delta, anchor = null) {
            const center = anchor ?? {
                x: (viewport.scrollLeft + viewport.clientWidth / 2) / (map.clientWidth || 1),
                y: (viewport.scrollTop + viewport.clientHeight / 2) / (map.clientHeight || 1),
            };
            zoom = Math.min(8, Math.max(1, zoom + delta));
            map.style.width = `${zoom * 100}%`;
            if (viewport.clientWidth > 0) {
                viewport.scrollLeft = zoom === 1 ? 0 : center.x * map.clientWidth - viewport.clientWidth / 2;
                viewport.scrollTop = zoom === 1 ? 0 : center.y * map.clientHeight - viewport.clientHeight / 2;
            }
            element('zoom-in').disabled = zoom === 8;
            element('zoom-out').disabled = zoom === 1;
            element('zoom-fit').disabled = zoom === 1;
            map.dataset.detail = String(markerDiameter * map.clientWidth / floorWidth >= 14);
        }

        function zoomToSeat(seat) {
            const targetZoom = 28 * floorWidth / (markerDiameter * viewport.clientWidth);
            changeZoom(targetZoom - zoom, { x: seat.x / 10000, y: seat.y / 10000 });
        }

        function paintMap() {
            const selectedFloor = floors.find(({ id }) => id === floor.value);
            if (!selectedFloor) return;
            const allFloorSeats = [...seats.values()].filter((seat) => seat.floor === selectedFloor.id);
            const floorSeats = allFloorSeats.filter((seat) => section.value === '' || seat.section === section.value);
            floorWidth = selectedFloor.width;
            if (!floorMarkerDiameters.has(selectedFloor.id)) {
                let diameter = 32;
                for (let i = 0; i < allFloorSeats.length; i++) {
                    for (let j = i + 1; j < allFloorSeats.length; j++) {
                        const a = allFloorSeats[i], b = allFloorSeats[j];
                        const distance = Math.max(Math.abs(a.x - b.x) * floorWidth / 10000,
                            Math.abs(a.y - b.y) * selectedFloor.height / 10000);
                        if (distance > 0) diameter = Math.min(diameter, distance * .8);
                    }
                }
                floorMarkerDiameters.set(selectedFloor.id, diameter);
            }
            markerDiameter = floorMarkerDiameters.get(selectedFloor.id);
            map.style.setProperty('--seat-size', `${markerDiameter / floorWidth * 100}cqw`);
            const activeSeat = document.activeElement?.dataset?.seatId;
            const focusList = list.contains(document.activeElement);
            markerControls.clear();
            map.replaceChildren();
            list.replaceChildren();
            element('empty').hidden = floorSeats.length > 0;
            element('stage').textContent = selectedFloor.stage || element('stage').dataset.fallback;
            element('stage').hidden = listView || (!selectedFloor.stage && Array.isArray(selectedFloor.blocks));
            map.style.aspectRatio = `${selectedFloor.width} / ${selectedFloor.height}`;
            const geometry = svgShape('svg', { viewBox: `0 0 ${selectedFloor.width} ${selectedFloor.height}`, 'aria-hidden': 'true' });
            geometry.classList.add('pointer-events-none', 'absolute', 'inset-0', 'h-full', 'w-full');
            appendSeatGeometry(geometry, selectedFloor, floorSeats);
            map.append(geometry);
            map.append(...floorSeats.map((seat) => makeMarker(seat, true)));
            list.append(...floorSeats.map((seat) => {
                const row = document.createElement('div');
                row.className = 'pb-1.5';
                row.append(makeMarker(seat, false));
                return row;
            }));
            const legend = [...new Set(floorSeats.map(({ type_id }) => type_id))].map((id) => {
                const type = types.get(id);
                const entry = document.createElement('span');
                entry.className = 'inline-flex items-center gap-1.5';
                const dot = document.createElement('span');
                dot.className = 'size-3 rounded-full';
                dot.setAttribute('aria-hidden', 'true');
                dot.style.backgroundColor = type.color;
                entry.append(dot, document.createTextNode(`${type.name} · ${money(type.price_cents)}`));
                return entry;
            });
            element('legend').replaceChildren(...legend);
            paintMarkers();
            changeZoom(0);
            if (activeSeat && focusList) markerControls.get(Number(activeSeat))?.[1]?.focus({ preventScroll: true });
        }

        function updateSectionOptions() {
            const last = section.value;
            const sections = [...new Set([...seats.values()]
                .filter((seat) => seat.floor === floor.value)
                .map((seat) => seat.section).filter(Boolean))];
            section.replaceChildren(new Option(picker.dataset.allSections, ''), ...sections.map((label) => new Option(label, label)));
            if (sections.includes(last)) section.value = last;
        }

        function showView(useList) {
            listView = useList;
            element('viewport').hidden = listView;
            list.hidden = !listView;
            element('zoom').hidden = listView;
            element('map-help').hidden = listView;
            element('stage').hidden = listView || (!floors.find(({ id }) => id === floor.value)?.stage
                && Array.isArray(floors.find(({ id }) => id === floor.value)?.blocks));
            element('map-toggle').setAttribute('aria-pressed', String(!listView));
            element('list-toggle').setAttribute('aria-pressed', String(listView));
            if (!listView) changeZoom(0);
        }

        function syncCart() {
            const choices = selectedSeats();
            const active = !editing && hold !== null && choices.length > 0;
            const hasQuantityOptions = picker.dataset.hasQuantityOptions === 'true';
            const blocked = editing ? choices.length > 0 || hold !== null : active && !quote;
            editor.hidden = !mapReady || !editing;
            element('summary').hidden = choices.length === 0;
            element('summary-actions').hidden = !active;
            element('timer').hidden = hold === null;
            details.forEach((panel) => { panel.hidden = editing && (blocked || !hasQuantityOptions); });
            continueButton.disabled = saving || (choices.length === 0 && hold === null);

            const estimates = choices.reduce((sum, seat) => sum + seatPrice(seat), 0);
            const entries = choices.map((seat) => {
                const row = document.createElement('li');
                row.className = 'flex flex-wrap justify-between gap-1';
                const label = document.createElement('span');
                label.textContent = seatLabel(seat, true);
                const extra = seatDetails(seat);
                if (extra) {
                    const note = document.createElement('span');
                    note.className = 'block text-xs festival-muted';
                    note.textContent = extra;
                    label.append(note);
                }
                const price = document.createElement('span');
                price.className = 'shrink-0 tabular-nums';
                price.textContent = money(seatPrice(seat));
                row.append(label, price);
                return row;
            });
            element('cart').replaceChildren(...entries);
            fields.replaceChildren(...(active ? choices.map((seat) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'seat_ids[]';
                input.value = String(seat.id);
                return input;
            }) : []));
            checkoutId.disabled = !active;
            checkoutId.value = active ? hold.checkout_id : '';
            quoteInput.disabled = !active || !quote;
            quoteInput.value = active ? quote?.seat_quote ?? '' : '';
            quoteTotal.disabled = !active || !quote;
            quoteTotal.value = active ? quote?.total_cents ?? '' : '';
            form.festivalSeats = {
                count: active ? choices.length : 0,
                estimatedSubtotal: active ? estimates : 0,
                quote: active ? quote : null,
                blocked,
            };
            stateChange();
            element('estimate').textContent = money(estimates + (form.festivalGeneralSubtotalCents ?? 0));
            element('selection-count').textContent = `${picker.dataset.selectedLabel}: ${choices.length}`;
        }

        function applyHold(data, restoreSelection = true) {
            hold = data;
            deadline = data ? performance.now() + data.remaining_seconds * 1000 : 0;
            if (data) paintCountdown(data.remaining_seconds);
            if (restoreSelection) {
                selected = new Set(data?.seat_ids ?? []);
                editing = !data;
            }
        }

        function storeQuantities() {
            try {
                if (!hold) {
                    window.sessionStorage.removeItem(cartKey);
                    return;
                }

                const items = Object.fromEntries(quantityInputs.map((input) => [input.name, input.value]));
                window.sessionStorage.setItem(cartKey, JSON.stringify({ checkoutId: hold.checkout_id, items }));
            } catch {
                // The server still restores the seats when browser storage is unavailable.
            }
        }

        function restoreQuantities() {
            try {
                const cart = JSON.parse(window.sessionStorage.getItem(cartKey) ?? 'null');
                if (cart?.checkoutId !== hold?.checkout_id) return;
                quantityInputs.forEach((input) => {
                    if (!input.disabled && typeof cart.items[input.name] === 'string') {
                        input.value = cart.items[input.name];
                    }
                });
            } catch {
                // Leave the quantities from the checkout form in place.
            }
        }

        function clearOnlineTickets() {
            form.querySelectorAll('[data-festival-exclusive-ticket="true"] [data-event-ticket-quantity]').forEach((input) => {
                input.value = '0';
            });
        }

        async function updateMap(initial = false, force = false) {
            if ((mapRequest || saving) && !force) return;
            mapRequest?.abort();
            const request = new AbortController();
            mapRequest = request;
            try {
                const url = new URL(picker.dataset.mapUrl, window.location.href);
                if (!initial) url.searchParams.set('availability_only', '1');
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' }, cache: 'no-store', signal: request.signal,
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(responseError(data));
                if (request.signal.aborted || (saving && !force)) return;

                seats = new Map(data.seats.map((seat) => [seat.id, seat]));
                types = new Map(data.types.map((type, index) => [type.id, { ...type, color: colors[index % colors.length] }]));
                if (initial) {
                    floors = data.floors;
                    floor.replaceChildren(...floors.map(({ id, label }) => new Option(label, id)));
                    applyHold(data.hold);
                    if (hold) {
                        clearOnlineTickets();
                        restoreQuantities();
                    }
                } else if (hold && !data.hold && !editing) {
                    expireHold();
                }
                conflicts = new Set([...conflicts].filter((id) => !seats.get(id)?.available));
                const lost = [...selected].filter((id) => !seats.get(id)?.available);
                if (lost.length > 0) {
                    lost.forEach((id) => {
                        selected.delete(id);
                        conflicts.add(id);
                    });
                    if (editing) showMessage(picker.dataset.unavailableMessage);
                }
                updateSectionOptions();
                paintMap();
                mapReady = true;
                element('loading').hidden = true;
                syncCart();
                changeZoom(0);
                if (initial) form.dispatchEvent(new Event('promotion-input-changed'));
            } catch (error) {
                if (initial && error.name !== 'AbortError') {
                    element('loading').hidden = true;
                    showMessage(error.message || picker.dataset.networkError, () => updateMap(true));
                }
            } finally {
                if (mapRequest === request) mapRequest = null;
            }
        }

        async function saveHold(seatIds) {
            if (saving) return;
            saving = true;
            quoteRequest?.abort();
            showMessage();
            paintMarkers();
            syncCart();

            try {
                const response = await fetch(picker.dataset.holdUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.elements._token.value,
                    },
                    body: JSON.stringify({ seat_ids: seatIds }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    if (response.status === 422) {
                        await updateMap(false, true);
                        showMessage(responseError(data));
                        return;
                    }
                    throw new Error(responseError(data));
                }

                applyHold(data.hold);
                quote = null;
                if (hold) clearOnlineTickets();
                syncCart();
                form.dispatchEvent(new Event('promotion-input-changed'));
                if (hold) {
                    picker.scrollIntoView({ block: 'start' });
                    form.elements.buyer_name.focus({ preventScroll: true });
                } else {
                    floor.focus();
                }
            } catch (error) {
                showMessage(error.message || picker.dataset.networkError);
            } finally {
                saving = false;
                paintMarkers();
                syncCart();
            }
        }

        function acceptQuote(data) {
            quote = data;
            showMessage();
            syncCart();
        }

        async function fetchQuote() {
            if (!hold || editing || promoInput?.value) return;
            quoteRequest?.abort();
            const request = new AbortController();
            quoteRequest = request;
            showMessage(picker.dataset.quoteWait);
            try {
                const response = await fetch(picker.dataset.quoteUrl, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { Accept: 'application/json' },
                    signal: request.signal,
                });
                const data = await response.json().catch(() => ({}));
                if (request.signal.aborted || !hold || editing || promoInput?.value) return;
                if (!response.ok) {
                    showMessage(responseError(data), fetchQuote);
                    return;
                }
                acceptQuote(data);
            } catch (error) {
                if (error.name !== 'AbortError') showMessage(picker.dataset.networkError, fetchQuote);
            }
        }

        function scheduleQuote() {
            quoteRequest?.abort();
            window.clearTimeout(quoteTimer);
            storeQuantities();
            quote = null;
            syncCart();
            if (hold && !editing && !promoInput?.value) {
                quoteTimer = window.setTimeout(fetchQuote, 150);
            }
        }

        function expireHold() {
            const hadHold = hold !== null;
            applyHold(null, false);
            editing = true;
            quote = null;
            quoteRequest?.abort();
            syncCart();
            if (hadHold) {
                showMessage(picker.dataset.expiredMessage);
                void updateMap();
            }
        }

        function paintCountdown(remaining) {
            element('countdown').textContent = `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`;
        }

        function updateTimer() {
            if (!hold) return;
            const remaining = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
            if (!remaining) {
                expireHold();
                return;
            }
            paintCountdown(remaining);
        }

        element('stage').dataset.fallback = element('stage').textContent.trim();
        continueButton.addEventListener('click', () => saveHold([...selected]));
        element('release').addEventListener('click', () => saveHold([]));
        element('edit').addEventListener('click', () => {
            editing = true;
            quote = null;
            syncCart();
            floor.focus();
            void updateMap();
        });
        floor.addEventListener('change', () => {
            section.value = '';
            changeZoom(1 - zoom);
            updateSectionOptions();
            paintMap();
        });
        section.addEventListener('change', () => {
            paintMap();
            const visible = [...seats.values()].filter((seat) => seat.floor === floor.value && seat.section === section.value);
            if (visible.length) {
                changeZoom(2 - zoom, {
                    x: visible.reduce((sum, seat) => sum + seat.x, 0) / visible.length / 10000,
                    y: visible.reduce((sum, seat) => sum + seat.y, 0) / visible.length / 10000,
                });
            } else changeZoom(1 - zoom);
        });
        element('zoom-in').addEventListener('click', () => changeZoom(zoom * .5));
        element('zoom-out').addEventListener('click', () => changeZoom(-zoom / 3));
        element('zoom-fit').addEventListener('click', () => changeZoom(1 - zoom));
        map.addEventListener('click', (event) => {
            if (markerDiameter * map.clientWidth / floorWidth >= 24 || zoom === 8) return;
            const bounds = map.getBoundingClientRect();
            zoomToSeat({ x: (event.clientX - bounds.left) / bounds.width * 10000, y: (event.clientY - bounds.top) / bounds.height * 10000 });
        });
        new ResizeObserver(() => {
            map.dataset.detail = String(markerDiameter * map.clientWidth / floorWidth >= 14);
        }).observe(viewport);
        element('map-toggle').addEventListener('click', () => showView(false));
        element('list-toggle').addEventListener('click', () => showView(true));
        retry.addEventListener('click', () => retryAction?.());
        form.addEventListener('promotion-input-changed', scheduleQuote);
        form.addEventListener('promo-code:quote', (event) => {
            if (event.detail.quote?.seat_quote && hold && !editing) {
                quoteRequest?.abort();
                acceptQuote(event.detail.quote);
            } else if (hold && !editing) {
                scheduleQuote();
            }
        });
        form.addEventListener('submit', (event) => {
            if (event.submitter?.hasAttribute('formnovalidate')) return;
            if (form.festivalSeats?.blocked || (hold && performance.now() >= deadline)) {
                event.preventDefault();
                if (hold && performance.now() >= deadline) expireHold();
            }
        });
        window.setInterval(updateTimer, 1000);
        window.setInterval(() => {
            if (mapReady && editing && !document.hidden) void updateMap();
        }, 15000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                updateTimer();
                if (mapReady && editing) void updateMap();
            }
        });
        changeZoom(0);
        syncCart();
        void updateMap(true);
    });
}
