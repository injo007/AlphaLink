(function () {
    'use strict';

    const initialize = () => {
        const trigger = document.getElementById('campaign-mix-open');
        const dialog = document.getElementById('campaign-mix-dialog');

        if (!trigger || !dialog || trigger.dataset.mixDialogReady === 'true') return;

        trigger.dataset.mixDialogReady = 'true';
        let returnFocusTo = null;

        trigger.addEventListener('click', () => {
            if (dialog.open) return;

            returnFocusTo = trigger;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
                dialog.dataset.campaignDialogFallback = 'true';
            }

            dialog.querySelector('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus();
        });

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                if (typeof dialog.close === 'function') dialog.close();
                else dialog.removeAttribute('open');
                returnFocusTo?.focus();
                return;
            }

            const closeButton = event.target.closest('[data-dialog-close="campaign-mix-dialog"]');
            if (!closeButton) return;

            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
            returnFocusTo?.focus();
        });

        dialog.addEventListener('close', () => returnFocusTo?.focus());

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || dialog.dataset.campaignDialogFallback !== 'true') return;
            dialog.removeAttribute('open');
            delete dialog.dataset.campaignDialogFallback;
            returnFocusTo?.focus();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
