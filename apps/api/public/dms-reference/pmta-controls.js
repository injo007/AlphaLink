(() => {
    const dialog = ({ title, description = '', inputLabel = '', value = '', confirmLabel = 'Continue', danger = false, showCancel = true }) => new Promise((resolve) => {
        const element = document.createElement('dialog');
        element.className = 'pmta-control-dialog';
        const form = document.createElement('form');
        form.method = 'dialog';
        const heading = document.createElement('h2');
        heading.className = 'h5 mb-2';
        heading.textContent = title;
        form.append(heading);
        if (description) {
            const copy = document.createElement('p');
            copy.className = 'text-muted';
            copy.textContent = description;
            form.append(copy);
        }
        let input = null;
        if (inputLabel) {
            const label = document.createElement('label');
            label.className = 'd-block mb-2';
            label.textContent = inputLabel;
            input = document.createElement('textarea');
            input.className = 'form-control';
            input.rows = 6;
            input.value = value;
            label.append(input);
            form.append(label);
        }
        const actions = document.createElement('div');
        actions.className = 'd-flex justify-content-end mt-3';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-alt-secondary mr-2';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', () => element.close());
        const confirm = document.createElement('button');
        confirm.type = 'submit';
        confirm.className = danger ? 'btn btn-danger' : 'btn btn-primary';
        confirm.textContent = confirmLabel;
        if (showCancel) {
            actions.append(cancel);
        }
        actions.append(confirm);
        form.append(actions);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            element.returnValue = 'confirm';
            element.close();
        });
        element.addEventListener('close', () => {
            resolve(element.returnValue === 'confirm' ? (input ? input.value : true) : null);
            element.remove();
        }, { once: true });
        element.append(form);
        document.body.append(element);
        element.showModal();
        (input || (showCancel ? cancel : confirm)).focus();
    });

    window.PmtaControls = {
        confirm: async (title, description, confirmLabel = 'Continue', danger = false) => (await dialog({ title, description, confirmLabel, danger })) === true,
        alert: async (title, description) => dialog({ title, description, confirmLabel: 'OK', showCancel: false }),
        prompt: async (title, inputLabel) => dialog({ title, inputLabel, confirmLabel: 'Search' }),
    };
})();
