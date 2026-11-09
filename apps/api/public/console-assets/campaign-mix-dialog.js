(function () {
    'use strict';

    const initialize = () => {
        const trigger = document.getElementById('campaign-mix-open');
        const dialog = document.getElementById('campaign-mix-dialog');

        if (!trigger || !dialog || trigger.dataset.mixDialogReady === 'true') return;

        trigger.dataset.mixDialogReady = 'true';
        let returnFocusTo = null;

        const restoreTrigger = () => {
            trigger.setAttribute('aria-expanded', 'false');
            returnFocusTo?.focus();
        };

        trigger.addEventListener('click', () => {
            if (dialog.open) return;

            returnFocusTo = trigger;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
                dialog.dataset.campaignDialogFallback = 'true';
            }
            trigger.setAttribute('aria-expanded', 'true');

            (dialog.querySelector('#mix-name:not([disabled])')
                || dialog.querySelector('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])'))?.focus();
        });

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                if (typeof dialog.close === 'function') dialog.close();
                else {
                    dialog.removeAttribute('open');
                    restoreTrigger();
                }
                return;
            }

            const closeButton = event.target.closest('[data-dialog-close="campaign-mix-dialog"]');
            if (!closeButton) return;

            if (typeof dialog.close === 'function') dialog.close();
            else {
                dialog.removeAttribute('open');
                restoreTrigger();
            }
        });

        dialog.addEventListener('close', restoreTrigger);

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || dialog.dataset.campaignDialogFallback !== 'true') return;
            dialog.removeAttribute('open');
            delete dialog.dataset.campaignDialogFallback;
            restoreTrigger();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
