(() => {
    document.querySelectorAll('[data-dms-placeholder-editor]').forEach((editor) => {
        if (editor.dataset.initialized === 'true') return;
        editor.dataset.initialized = 'true';

        const body = editor.querySelector('[data-placeholder-rows]');
        const prefix = editor.dataset.namePrefix;
        const addButton = editor.querySelector('[data-placeholder-add]');
        const combination = editor.querySelector('input[name="send_setup[placeholder_combination]"]');
        const allCombination = editor.querySelector('input[name="send_setup[placeholder_all_combination]"]');

        const nextIndex = () => Array.from(body.querySelectorAll('[data-placeholder-row]'))
            .reduce((highest, row) => {
                const textarea = row.querySelector('textarea');
                const match = textarea?.name.match(/\[(\d+)\]\[data_text\]$/);
                return match ? Math.max(highest, Number(match[1]) + 1) : highest;
            }, 0);

        const makeRow = (index) => {
            const row = document.createElement('tr');
            row.dataset.placeholderRow = '';

            const valuesCell = document.createElement('td');
            const values = document.createElement('textarea');
            values.className = 'form-control form-control-sm';
            values.name = `${prefix}[${index}][data_text]`;
            values.rows = 2;
            values.maxLength = 50000;
            values.setAttribute('aria-label', `Placeholder ${index + 1} values`);
            values.placeholder = 'One value per line';
            valuesCell.append(values);

            const intervalCell = document.createElement('td');
            const interval = document.createElement('input');
            interval.className = 'form-control form-control-sm';
            interval.name = `${prefix}[${index}][rotate_every]`;
            interval.type = 'number';
            interval.min = '1';
            interval.max = '2147483647';
            interval.value = '1';
            interval.required = true;
            interval.setAttribute('aria-label', `Placeholder ${index + 1} rotate every`);
            intervalCell.append(interval);

            const actionCell = document.createElement('td');
            actionCell.className = 'text-right';
            const remove = document.createElement('button');
            remove.className = 'btn btn-sm btn-alt-danger';
            remove.type = 'button';
            remove.dataset.placeholderRemove = '';
            remove.setAttribute('aria-label', `Remove placeholder ${index + 1}`);
            remove.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
            actionCell.append(remove);

            row.append(valuesCell, intervalCell, actionCell);
            return row;
        };

        addButton.addEventListener('click', () => {
            if (body.querySelectorAll('[data-placeholder-row]').length >= 50) return;
            body.append(makeRow(nextIndex()));
        });

        body.addEventListener('click', (event) => {
            const button = event.target.closest('[data-placeholder-remove]');
            if (!button) return;

            const row = button.closest('[data-placeholder-row]');
            if (!row || !window.confirm('Are you sure to remove this row?')) return;

            row.remove();
        });

        combination.addEventListener('change', () => {
            if (combination.checked) allCombination.checked = false;
        });
        allCombination.addEventListener('change', () => {
            if (allCombination.checked) combination.checked = false;
        });
    });
})();
