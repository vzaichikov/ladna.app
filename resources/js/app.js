import { createIcons, icons } from 'lucide';

let pendingDeleteForm = null;

function closeDeleteConfirmation(modal) {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    pendingDeleteForm = null;
}

document.addEventListener('DOMContentLoaded', () => {
    createIcons({ icons });

    const sidebar = document.querySelector('[data-sidebar]');
    const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');
    const openSidebarButton = document.querySelector('[data-sidebar-open]');
    const closeSidebarButton = document.querySelector('[data-sidebar-close]');

    const closeSidebar = () => {
        if (!sidebar || !sidebarBackdrop) {
            return;
        }

        sidebar.classList.add('-translate-x-full');
        sidebarBackdrop.classList.add('hidden');
    };

    const openSidebar = () => {
        if (!sidebar || !sidebarBackdrop) {
            return;
        }

        sidebar.classList.remove('-translate-x-full');
        sidebarBackdrop.classList.remove('hidden');
    };

    openSidebarButton?.addEventListener('click', openSidebar);
    closeSidebarButton?.addEventListener('click', closeSidebar);
    sidebarBackdrop?.addEventListener('click', closeSidebar);

    document.addEventListener('click', (event) => {
        const selectAllButton = event.target.closest('[data-select-all-directions]');

        if (!selectAllButton) {
            return;
        }

        const group = selectAllButton.closest('[data-activity-direction-group]');
        group?.querySelectorAll('[data-activity-direction-checkbox]').forEach((checkbox) => {
            checkbox.checked = true;
        });
    });

    document.addEventListener('click', (event) => {
        const selectAllButton = event.target.closest('[data-select-all-trainer-types]');

        if (!selectAllButton) {
            return;
        }

        const group = selectAllButton.closest('[data-trainer-type-group]');
        group?.querySelectorAll('[data-trainer-type-checkbox]').forEach((checkbox) => {
            checkbox.checked = true;
        });
    });

    const modal = document.getElementById('delete-confirmation-modal');
    const cancelButton = document.querySelector('[data-confirm-cancel]');
    const acceptButton = document.querySelector('[data-confirm-accept]');

    if (!modal || !cancelButton || !acceptButton) {
        return;
    }

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm-delete]');

        if (!form || form.dataset.confirmed === 'true') {
            return;
        }

        event.preventDefault();
        pendingDeleteForm = form;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        acceptButton.focus();
    });

    cancelButton.addEventListener('click', () => closeDeleteConfirmation(modal));

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeDeleteConfirmation(modal);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }

        if (event.key === 'Escape' && pendingDeleteForm) {
            closeDeleteConfirmation(modal);
        }
    });

    acceptButton.addEventListener('click', () => {
        if (!pendingDeleteForm) {
            return;
        }

        pendingDeleteForm.dataset.confirmed = 'true';
        pendingDeleteForm.requestSubmit();
    });
});
