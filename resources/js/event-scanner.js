import { BrowserMultiFormatReader, BrowserCodeReader } from '@zxing/browser';

export function initEventScanner() {
    const root = document.querySelector('[data-event-scanner]');

    if (!root) {
        return;
    }

    const video = root.querySelector('[data-scanner-video]');
    const camera = root.querySelector('[data-scanner-camera]');
    const start = root.querySelector('[data-scanner-start]');
    const torch = root.querySelector('[data-scanner-torch]');
    const manual = root.querySelector('[data-scanner-manual]');
    const result = root.querySelector('[data-scanner-result]');
    const modal = root.querySelector('[data-scanner-modal]');
    const modalPanel = modal?.querySelector('[data-scanner-modal-panel]');
    const modalTitle = modal?.querySelector('[data-scanner-modal-title]');
    const modalMessage = modal?.querySelector('[data-scanner-modal-message]');
    const modalDetails = modal?.querySelector('[data-scanner-modal-details]');
    const modalCustomerRow = modal?.querySelector('[data-scanner-modal-customer-row]');
    const modalCustomer = modal?.querySelector('[data-scanner-modal-customer]');
    const modalTypeRow = modal?.querySelector('[data-scanner-modal-type-row]');
    const modalType = modal?.querySelector('[data-scanner-modal-type]');
    const modalCodeRow = modal?.querySelector('[data-scanner-modal-code-row]');
    const modalCode = modal?.querySelector('[data-scanner-modal-code]');
    const modalCheckedInRow = modal?.querySelector('[data-scanner-modal-checked-in-row]');
    const modalCheckedIn = modal?.querySelector('[data-scanner-modal-checked-in]');
    const modalIconShell = modal?.querySelector('[data-scanner-modal-icon-shell]');
    const modalConfirm = modal?.querySelector('[data-scanner-modal-confirm]');
    const modalConfirmLabel = modal?.querySelector('[data-scanner-modal-confirm-label]');
    const modalDismissButtons = [...(modal?.querySelectorAll('[data-scanner-modal-dismiss]') ?? [])];
    const reader = new BrowserMultiFormatReader();
    let controls = null;
    let lastValue = null;
    let lastScannedAt = 0;
    let torchEnabled = false;
    let requestInProgress = false;
    let modalOpen = false;
    let modalBusy = false;
    let modalOpener = null;
    let pendingConfirmation = null;
    let reloadAfterModalClose = false;

    const toneClasses = {
        success: {
            panel: 'border-emerald-400',
            icon: 'bg-emerald-50 text-emerald-700',
        },
        danger: {
            panel: 'border-rose-500',
            icon: 'bg-rose-50 text-rose-700',
        },
        warning: {
            panel: 'border-amber-400',
            icon: 'bg-amber-50 text-amber-700',
        },
    };
    const panelToneClasses = Object.values(toneClasses).map((tone) => tone.panel);
    const iconToneClasses = Object.values(toneClasses).flatMap((tone) => tone.icon.split(' '));

    const showResult = (message, state = 'error') => {
        result.textContent = message;
        result.className = `mt-5 rounded-xl p-4 text-sm font-semibold ${['checked_in', 'checked_out'].includes(state) ? 'bg-emerald-50 text-emerald-900' : state === 'already_checked_in' ? 'bg-amber-50 text-amber-900' : 'bg-rose-50 text-rose-900'}`;
    };

    const updateTorchButton = (enabled) => {
        torchEnabled = enabled;
        torch.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        torch.textContent = enabled ? root.dataset.torchDisable : root.dataset.torchEnable;
    };

    const applyModalTone = (tone) => {
        const selectedTone = toneClasses[tone] ?? toneClasses.warning;

        modalPanel?.classList.remove(...panelToneClasses);
        modalPanel?.classList.add(selectedTone.panel);
        modalIconShell?.classList.remove(...iconToneClasses);
        modalIconShell?.classList.add(...selectedTone.icon.split(' '));
        modal?.querySelectorAll('[data-scanner-modal-icon]').forEach((icon) => {
            icon.classList.toggle('hidden', icon.dataset.scannerModalIcon !== tone);
        });
    };

    const updateModalDetails = (ticket = null, checkedInAt = null) => {
        const hasTicket = Boolean(ticket);

        modalDetails?.classList.toggle('hidden', !hasTicket);

        if (!hasTicket) {
            return;
        }

        const detailValues = [
            [modalCustomerRow, modalCustomer, ticket.customer],
            [modalTypeRow, modalType, ticket.type],
            [modalCodeRow, modalCode, ticket.code],
            [modalCheckedInRow, modalCheckedIn, checkedInAt],
        ];

        detailValues.forEach(([row, valueElement, value]) => {
            if (row) {
                row.hidden = !value;
            }

            if (valueElement) {
                valueElement.textContent = value || '';
            }
        });
    };

    const setModalBusy = (busy) => {
        modalBusy = busy;
        modalDismissButtons.forEach((button) => {
            button.disabled = busy;
        });

        if (modalConfirm) {
            modalConfirm.disabled = busy;
        }

        if (modalConfirmLabel) {
            modalConfirmLabel.textContent = busy ? modal.dataset.confirmingLabel : modal.dataset.confirmLabel;
        }
    };

    const renderModal = ({ tone, title, message, ticket = null, checkedInAt = null, confirmation = null }) => {
        applyModalTone(tone);
        modalTitle.textContent = title;
        modalMessage.textContent = message;
        updateModalDetails(ticket, checkedInAt);
        pendingConfirmation = confirmation;

        if (modalConfirm) {
            modalConfirm.hidden = !confirmation;
        }

        setModalBusy(false);
    };

    const openModal = (options) => {
        if (!modalOpen) {
            modalOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
            modalOpen = true;
        }

        renderModal(options);
        requestAnimationFrame(() => {
            (options.confirmation ? modalConfirm : modalDismissButtons.at(-1))?.focus();
        });
    };

    const closeModal = () => {
        if (!modalOpen || modalBusy) {
            return;
        }

        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
        modalOpen = false;
        pendingConfirmation = null;
        lastScannedAt = Date.now();

        if (reloadAfterModalClose) {
            window.location.reload();
            return;
        }

        modalOpener?.focus();
        modalOpener = null;
    };

    const requestTicket = async (code, source, confirm = false) => {
        const response = await fetch(root.dataset.scanUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': root.dataset.csrfToken,
            },
            body: JSON.stringify({ code, source, confirm }),
        });
        const contentType = response.headers.get('content-type') || '';

        if (!contentType.includes('application/json')) {
            throw new Error('Scanner response was not JSON.');
        }

        const payload = await response.json();

        if (!payload?.state) {
            throw new Error('Scanner response did not include a state.');
        }

        return payload;
    };

    const presentPayload = (payload, code, source) => {
        reloadAfterModalClose = payload.state === 'checked_in' && source === 'door_list';

        if (payload.state === 'awaiting_confirmation') {
            navigator.vibrate?.(80);
            openModal({
                tone: 'success',
                title: modal.dataset.readyTitle,
                message: payload.message,
                ticket: payload.ticket,
                confirmation: { code, source },
            });
            return;
        }

        if (payload.state === 'checked_in') {
            navigator.vibrate?.(120);
            openModal({
                tone: 'success',
                title: modal.dataset.confirmedTitle,
                message: payload.message,
                ticket: payload.ticket,
            });
            return;
        }

        if (payload.state === 'already_checked_in') {
            navigator.vibrate?.([80, 60, 80]);
            openModal({
                tone: 'danger',
                title: modal.dataset.duplicateTitle,
                message: payload.message,
                ticket: payload.ticket,
                checkedInAt: payload.checked_in_at_label,
            });
            return;
        }

        navigator.vibrate?.([60, 50, 60]);
        openModal({
            tone: 'warning',
            title: modal.dataset.warningTitle,
            message: payload.message || root.dataset.requestError,
        });
    };

    const previewTicket = async (code, source) => {
        try {
            presentPayload(await requestTicket(code, source), code, source);
        } catch {
            presentPayload({ state: 'request_error', message: root.dataset.requestError }, code, source);
        }
    };

    const loadCameras = async (selectedDeviceId = '') => {
        const devices = await BrowserCodeReader.listVideoInputDevices();
        const options = document.createDocumentFragment();
        const automaticOption = document.createElement('option');

        automaticOption.value = '';
        automaticOption.textContent = root.dataset.cameraAutomatic;
        options.append(automaticOption);

        devices.filter((device) => device.deviceId).forEach((device, index) => {
            const option = document.createElement('option');
            const fallbackName = root.dataset.cameraNameTemplate.replace('__NUMBER__', index + 1);

            option.value = device.deviceId;
            option.textContent = device.label || fallbackName;
            options.append(option);
        });

        camera.replaceChildren(options);

        if ([...camera.options].some((option) => option.value === selectedDeviceId)) {
            camera.value = selectedDeviceId;
        }
    };

    const begin = async () => {
        await controls?.stop?.();
        controls = null;
        torch.classList.add('hidden');
        updateTorchButton(false);
        controls = await reader.decodeFromVideoDevice(camera.value || undefined, video, async (decoded) => {
            if (!decoded || modalOpen || requestInProgress) {
                return;
            }

            const value = decoded.getText();
            const now = Date.now();

            if (value === lastValue && now - lastScannedAt < 2500) {
                return;
            }

            lastValue = value;
            lastScannedAt = now;
            requestInProgress = true;

            try {
                await previewTicket(value, 'qr');
            } finally {
                requestInProgress = false;
            }
        });

        const activeDeviceId = video.srcObject?.getVideoTracks()[0]?.getSettings().deviceId || '';
        await loadCameras(activeDeviceId);

        if (controls.switchTorch) {
            torch.classList.remove('hidden');
        }
    };

    loadCameras().catch(() => showResult(root.dataset.cameraError));

    start.addEventListener('click', () => begin().catch(() => showResult(root.dataset.cameraError)));
    camera.addEventListener('change', () => begin().catch(() => showResult(root.dataset.cameraError)));
    torch.addEventListener('click', async () => {
        if (!controls?.switchTorch) {
            return;
        }

        try {
            const enabled = !torchEnabled;

            await controls.switchTorch(enabled);
            updateTorchButton(enabled);
        } catch {
            showResult(root.dataset.cameraError);
        }
    });
    manual.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (requestInProgress || modalOpen) {
            return;
        }

        requestInProgress = true;

        try {
            await previewTicket(new FormData(manual).get('code'), 'manual');
        } finally {
            requestInProgress = false;
        }
    });

    modalDismissButtons.forEach((button) => button.addEventListener('click', closeModal));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
    modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...modal.querySelectorAll('button:not([disabled]):not([hidden]):not(.hidden)')];

        if (focusable.length === 0) {
            return;
        }

        const first = focusable[0];
        const last = focusable.at(-1);

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    modalConfirm.addEventListener('click', async () => {
        if (!pendingConfirmation || modalBusy) {
            return;
        }

        const confirmation = pendingConfirmation;
        setModalBusy(true);

        try {
            presentPayload(await requestTicket(confirmation.code, confirmation.source, true), confirmation.code, confirmation.source);
        } catch {
            presentPayload({ state: 'request_error', message: root.dataset.requestError }, confirmation.code, confirmation.source);
        }
    });

    root.querySelectorAll('[data-door-checkin]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (requestInProgress || modalOpen) {
                return;
            }

            button.disabled = true;
            requestInProgress = true;

            try {
                await previewTicket(button.dataset.ticketCode, 'door_list');
            } finally {
                requestInProgress = false;
                button.disabled = false;
            }
        });
    });

    root.querySelectorAll('[data-door-checkout]').forEach((button) => {
        button.addEventListener('click', async () => {
            const reason = window.prompt(root.dataset.checkOutReason);

            if (!reason?.trim()) {
                return;
            }

            button.disabled = true;

            try {
                const response = await fetch(button.dataset.checkoutUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': root.dataset.csrfToken,
                    },
                    body: JSON.stringify({ reason: reason.trim() }),
                });
                const payload = await response.json();
                showResult(payload.message, payload.state);

                if (payload.state === 'checked_out') {
                    window.location.reload();
                }
            } catch {
                showResult(root.dataset.requestError);
            } finally {
                button.disabled = false;
            }
        });
    });
}
