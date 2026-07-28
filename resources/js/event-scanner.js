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
    const reader = new BrowserMultiFormatReader();
    let controls = null;
    let lastValue = null;
    let lastScannedAt = 0;

    const showResult = (message, state = 'error') => {
        result.textContent = message;
        result.className = `mt-5 rounded-xl p-4 text-sm font-semibold ${['checked_in', 'checked_out'].includes(state) ? 'bg-emerald-50 text-emerald-900' : state === 'already_checked_in' ? 'bg-amber-50 text-amber-900' : 'bg-rose-50 text-rose-900'}`;
    };

    const submitCode = async (code, source) => {
        const response = await fetch(root.dataset.scanUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': root.dataset.csrfToken,
            },
            body: JSON.stringify({ code, source }),
        });
        const payload = await response.json();
        showResult(payload.message, payload.state);

        if (navigator.vibrate) {
            navigator.vibrate(payload.state === 'checked_in' ? 120 : [80, 60, 80]);
        }

        return payload;
    };

    const begin = async () => {
        controls?.stop();
        controls = await reader.decodeFromVideoDevice(camera.value || undefined, video, async (decoded) => {
            if (!decoded) {
                return;
            }

            const value = decoded.getText();
            const now = Date.now();

            if (value === lastValue && now - lastScannedAt < 2500) {
                return;
            }

            lastValue = value;
            lastScannedAt = now;
            await submitCode(value, 'qr');
        });

        if (controls.switchTorch) {
            torch.classList.remove('hidden');
        }
    };

    BrowserCodeReader.listVideoInputDevices().then((devices) => {
        camera.innerHTML = devices.map((device, index) => {
            const fallbackName = root.dataset.cameraNameTemplate.replace('__NUMBER__', index + 1);

            return `<option value="${device.deviceId}">${device.label || fallbackName}</option>`;
        }).join('');
    }).catch(() => showResult(root.dataset.cameraError));

    start.addEventListener('click', () => begin().catch(() => showResult(root.dataset.cameraError)));
    camera.addEventListener('change', () => begin().catch(() => showResult(root.dataset.cameraError)));
    torch.addEventListener('click', () => controls?.switchTorch?.());
    manual.addEventListener('submit', (event) => {
        event.preventDefault();
        const code = new FormData(manual).get('code');
        submitCode(code, 'manual').catch(() => showResult(root.dataset.requestError));
    });

    root.querySelectorAll('[data-door-checkin]').forEach((button) => {
        button.addEventListener('click', () => {
            button.disabled = true;
            submitCode(button.dataset.ticketCode, 'door_list')
                .then((payload) => {
                    if (payload.state === 'checked_in') {
                        window.location.reload();
                    }
                })
                .catch(() => showResult(root.dataset.requestError))
                .finally(() => {
                    button.disabled = false;
                });
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
