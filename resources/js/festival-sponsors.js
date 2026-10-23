import { createIcons, icons } from 'lucide';

export function initFestivalSponsors(scope = document) {
    const dialogs = scope.querySelectorAll('[data-festival-sponsor-dialog]');

    if (!dialogs.length) {
        return;
    }

    dialogs.forEach((dialog) => {
        if (dialog.dataset.sponsorInitialized) {
            return;
        }

        dialog.dataset.sponsorInitialized = 'true';
        let opener = null;
        let wasScrollLocked = false;

        scope.querySelectorAll(`[data-festival-sponsor-open="${dialog.id.replace('festival-sponsor-', '')}"]`).forEach((button) => {
            button.addEventListener('click', () => {
                if (dialog.open) {
                    return;
                }

                opener = button;
                wasScrollLocked = document.body.classList.contains('overflow-hidden');
                dialog.showModal();
                document.body.classList.add('overflow-hidden');
            });
        });

        dialog.querySelector('[data-festival-sponsor-close]')?.addEventListener('click', () => dialog.close());
        dialog.addEventListener('keydown', (event) => {
            if (event.key !== 'Tab') {
                return;
            }

            const focusable = [...dialog.querySelectorAll('button:not([disabled]), a[href], [tabindex="0"]')]
                .filter((element) => element.getClientRects().length > 0);
            const first = focusable[0];
            const last = focusable.at(-1);

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        });
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) {
                return;
            }

            const bounds = dialog.getBoundingClientRect();

            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
                dialog.close();
            }
        });
        dialog.addEventListener('close', () => {
            if (!wasScrollLocked) {
                document.body.classList.remove('overflow-hidden');
            }

            opener?.focus({ preventScroll: true });
        });
    });

    createIcons({ icons });
}
