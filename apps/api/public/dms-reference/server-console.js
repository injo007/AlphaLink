(() => {
    const form = document.getElementById('server-console-form');
    const button = document.getElementById('run');
    const output = document.getElementById('result');

    if (!form || !button || !output) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        button.disabled = true;
        button.textContent = 'RUNNING';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-TOKEN': form.elements._token.value,
                },
                body: new URLSearchParams(new FormData(form)),
            });
            const payload = await response.json();

            if (!response.ok) {
                output.textContent = payload?.errors?.cmd?.[0] || payload?.message || 'Command failed.';
                return;
            }

            output.textContent = typeof payload === 'string' ? payload : String(payload ?? '');
        } catch {
            output.textContent = 'Console request failed.';
        } finally {
            button.disabled = false;
            button.textContent = 'RUN';
        }
    });
})();
