@extends('layouts.dms-admin')

@section('title', 'Contacts - DMS')

@push('styles')
    <style>
        #contact-detail-dialog {
            border: 1px solid #d5dce1;
            border-radius: .35rem;
            color: #343a40;
            max-height: min(90vh, 48rem);
            max-width: 48rem;
            overflow: auto;
            padding: 1.25rem;
            width: min(92vw, 48rem);
        }
        #contact-detail-dialog::backdrop { background: rgba(27, 33, 40, .55); }
        #contact-detail-dialog dl { display: grid; gap: .5rem 1rem; grid-template-columns: minmax(8rem, .4fr) minmax(0, 1fr); margin: 0; }
        #contact-detail-dialog dt { font-weight: 600; }
        #contact-detail-dialog dd { margin: 0; overflow-wrap: anywhere; white-space: pre-wrap; }
        #contact-detail-dialog .contact-dialog-header { align-items: center; display: flex; justify-content: space-between; margin-bottom: 1rem; }
        #contact-detail-dialog .contact-dialog-header h2 { font-size: 1.15rem; margin: 0; }
        @media (max-width: 575px) {
            #contact-detail-dialog dl { grid-template-columns: 1fr; gap: .15rem; }
            #contact-detail-dialog dd { margin-bottom: .6rem; }
        }
    </style>
@endpush

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">contact us</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="table-responsive">
                <table id="contactus" class="table table-sm table-hover table-striped">
                    <thead><tr><th scope="col">#</th><th scope="col">full name</th><th scope="col">email</th><th scope="col">phone</th><th scope="col">country</th><th scope="col">CREATED AT</th><th scope="col">SEE MORE</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <dialog id="contact-detail-dialog" aria-labelledby="contact-detail-title" aria-describedby="contact-detail-description">
        <div class="contact-dialog-header">
            <h2 id="contact-detail-title">Contact submission</h2>
            <button type="button" class="btn btn-sm btn-alt-secondary" id="contact-detail-close">Close</button>
        </div>
        <p id="contact-detail-description" class="sr-only">Read-only details for the selected contact submission.</p>
        <dl>
            <dt>Full Name</dt><dd data-contact-field="full_name"></dd>
            <dt>Email</dt><dd data-contact-field="email"></dd>
            <dt>Phone</dt><dd data-contact-field="phone"></dd>
            <dt>Message</dt><dd data-contact-field="message"></dd>
            <dt>IP</dt><dd data-contact-field="ip"></dd>
            <dt>Country</dt><dd data-contact-field="country"></dd>
            <dt>State</dt><dd data-contact-field="state"></dd>
            <dt>City</dt><dd data-contact-field="city"></dd>
            <dt>Device</dt><dd data-contact-field="device"></dd>
            <dt>Platform</dt><dd data-contact-field="platform"></dd>
            <dt>Browser</dt><dd data-contact-field="browser"></dd>
            <dt>Is Mobile</dt><dd data-contact-field="is_mobile"></dd>
        </dl>
    </dialog>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const dialog = document.getElementById('contact-detail-dialog');
            const table = $('#contactus').DataTable({
                ajax: @json(route('console.miscellaneous.contacts', $workspace->id)),
                serverSide: true,
                buttons: [
                    { extend: 'pageLength' },
                    { text: '<i class="fas fa-sync"></i>', titleAttr: 'REFRESH', action: (event, datatable) => datatable.ajax.reload(null, false) },
                ],
                columns: [
                    { title: '#', data: 'id', render: $.fn.dataTable.render.text() },
                    { title: 'full name', data: 'full_name', render: $.fn.dataTable.render.text() },
                    { title: 'email', data: 'email', render: $.fn.dataTable.render.text() },
                    { title: 'phone', data: 'phone', render: $.fn.dataTable.render.text() },
                    { title: 'country', data: 'country', render: $.fn.dataTable.render.text() },
                    { title: 'CREATED AT', data: 'created_at', render: $.fn.dataTable.render.text() },
                    {
                        title: 'SEE MORE', data: null, orderable: false, searchable: false,
                        render: (data, type, row) => {
                            const id = $('<div>').text(String(row.id)).html();
                            return `<button type="button" data-id="${id}" class="btn btn-sm btn-primary contact-detail-open" aria-label="View contact submission ${id}"><i class="fas fa-eye" aria-hidden="true"></i></button>`;
                        },
                    },
                ],
                language: { emptyTable: 'No contact submissions are available for this workspace.' },
            });

            $('#contactus').on('click', '.contact-detail-open', function () {
                const row = table.row($(this).closest('tr')).data();
                if (!row || typeof dialog.showModal !== 'function') { return; }
                const value = name => name === 'is_mobile' ? (row[name] ? 'YES' : 'NO') : (row[name] ?? '');
                dialog.querySelectorAll('[data-contact-field]').forEach(field => {
                    field.textContent = value(field.dataset.contactField);
                });
                document.getElementById('contact-detail-title').textContent = `More Info ${row.id}`;
                dialog.showModal();
            });
            document.getElementById('contact-detail-close').addEventListener('click', () => dialog.close());
        });
    </script>
@endpush
