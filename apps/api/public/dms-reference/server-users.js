window.jQuery(function () {
    const $ = window.jQuery;
    const form = document.querySelector('[data-update-url]');
    if (!form) return;

    const checkbox = $('input[name="checked_users"]');
    const errors = $('#validation_errors');
    const update = $('#update');
    const serverIds = JSON.parse(form.dataset.serverIds || '[]');

    $('#check').on('click', function (event) {
        event.preventDefault();
        const nextChecked = checkbox.filter(':checked').length !== checkbox.length;
        checkbox.prop('checked', nextChecked);
    });

    update.on('click', function (event) {
        event.preventDefault();
        errors.prop('hidden', true).empty();
        update.prop('disabled', true).html('UPDATING <span class="spinner-border spinner-border-sm"></span>');

        $.ajax({
            url: form.dataset.updateUrl,
            type: 'PUT',
            dataType: 'json',
            data: {
                servers: serverIds,
                users: checkbox.filter(':checked').map(function () { return $(this).val(); }).get(),
            },
        }).done(function (response) {
            const parentApp = window.parent?.App;
            const complete = () => window.parent?.closeModal?.();
            if (parentApp?.swalPromise) {
                parentApp.swalPromise({ icon: 'success', title: response.data, confirmButtonText: 'close' }).then(complete);
            } else if (window.Swal) {
                window.Swal.fire({ icon: 'success', title: response.data }).then(complete);
            } else {
                complete();
            }
        }).fail(function (response) {
            if (response.status === 422 && response.responseJSON?.errors) {
                errors.html(Object.values(response.responseJSON.errors).flat().map((message) => $('<div>').text(message).html()).join('<br>'));
                errors.prop('hidden', false);
                return;
            }
            const message = response.responseJSON?.message || 'Could not update allowed users.';
            errors.text(message).prop('hidden', false);
        }).always(function () {
            update.prop('disabled', false).text('UPDATE ALLOWED USERS');
        });
    });
});
