@extends('layouts.admin-shell')

@section('title', 'Contacts')

@push('styles')
    <style>
        #contact-detail-dialog {
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.25));
            border-radius: 16px;
            color: var(--command-os-text, #1e293b);
            background: var(--command-os-card-bg, #ffffff);
            max-height: min(90vh, 48rem);
            max-width: 48rem;
            overflow: auto;
            padding: 1.5rem;
            width: min(92vw, 48rem);
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.35);
        }
        #contact-detail-dialog::backdrop {
            background: rgba(10, 14, 40, 0.7);
            backdrop-filter: blur(6px);
        }
        #contact-detail-dialog dl {
            display: grid;
            gap: 0.75rem 1.25rem;
            grid-template-columns: minmax(8rem, 0.35fr) minmax(0, 1fr);
            margin: 0;
            padding: 1rem;
            background: rgba(139, 92, 246, 0.03);
            border-radius: 12px;
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.1));
        }
        #contact-detail-dialog dt {
            font-weight: 700;
            font-size: 0.8125rem;
            color: var(--command-os-text-muted, #64748b);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        #contact-detail-dialog dd {
            margin: 0;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            font-size: 0.875rem;
            color: var(--command-os-text, #1e293b);
        }
        #contact-detail-dialog .contact-dialog-header {
            align-items: center;
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.15));
        }
        #contact-detail-dialog .contact-dialog-header h2 {
            font-size: 1.125rem;
            font-weight: 700;
            margin: 0;
            text-transform: uppercase;
        }
        html[data-command-theme='dark'] #contact-detail-dialog {
            background: #0d1238;
            color: #f8fafc;
            border-color: rgba(139, 92, 246, 0.3);
        }
        html[data-command-theme='dark'] #contact-detail-dialog dl {
            background: rgba(15, 23, 42, 0.5);
            border-color: rgba(139, 92, 246, 0.2);
        }
        html[data-command-theme='dark'] #contact-detail-dialog dd {
            color: #f1f5f9;
        }
        @media (max-width: 575px) {
            #contact-detail-dialog dl { grid-template-columns: 1fr; gap: 0.35rem; }
            #contact-detail-dialog dd { margin-bottom: 0.75rem; }
        }
    </style>
@endpush

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-address-book"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // INBOUND SUBMISSIONS</div>
                    <h1 class="command-os-report-hero-title">CONTACT SUBMISSIONS</h1>
                    <p class="command-os-report-hero-desc">Inbound user messages, contact details, captured network environments, and submission logs.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">SUBMISSIONS</span>
                </div>
            </div>
        </header>

        <!-- 2. Data Table Card -->
        <div class="command-os-table-card p-4">
            <div class="table-responsive">
                <table id="contactus" class="table table-sm table-hover table-striped command-os-table w-100">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">FULL NAME</th>
                            <th scope="col">EMAIL</th>
                            <th scope="col">PHONE</th>
                            <th scope="col">COUNTRY</th>
                            <th scope="col">CREATED AT</th>
                            <th scope="col">SEE MORE</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Contact Detail Modal Dialog -->
    <dialog id="contact-detail-dialog" class="command-modal-dialog" aria-labelledby="contact-detail-title" aria-describedby="contact-detail-description">
        <div class="contact-dialog-header">
            <h2 id="contact-detail-title"><i class="fas fa-info-circle mr-1 text-purple"></i> Contact Submission</h2>
            <button type="button" class="btn-command-action" id="contact-detail-close" aria-label="Close" style="padding: 4px 10px;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <p id="contact-detail-description" class="sr-only">Read-only details for the selected contact submission.</p>
        <dl>
            <dt>Full Name</dt><dd data-contact-field="full_name"></dd>
            <dt>Email</dt><dd data-contact-field="email"></dd>
            <dt>Phone</dt><dd data-contact-field="phone"></dd>
            <dt>Message</dt><dd data-contact-field="message"></dd>
            <dt>IP Address</dt><dd data-contact-field="ip"></dd>
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
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
                buttons: [
                    { extend: 'pageLength', className: 'btn-command-action' },
                    { text: '<i class="fas fa-sync mr-1"></i> REFRESH', className: 'btn-command-action', titleAttr: 'REFRESH', action: (event, datatable) => datatable.ajax.reload(null, false) },
                ],
                columns: [
                    { title: '#', data: 'id', render: (val, type) => type === 'display' ? `<span class="command-mono-badge">#${val}</span>` : val },
                    { title: 'FULL NAME', data: 'full_name', render: (val, type) => type === 'display' ? `<strong>${$.fn.dataTable.render.text().display(val)}</strong>` : val },
                    { title: 'EMAIL', data: 'email', render: $.fn.dataTable.render.text() },
                    { title: 'PHONE', data: 'phone', render: $.fn.dataTable.render.text() },
                    { title: 'COUNTRY', data: 'country', render: $.fn.dataTable.render.text() },
                    { title: 'CREATED AT', data: 'created_at', render: (val, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${$.fn.dataTable.render.text().display(val)}</span>` : val },
                    {
                        title: 'SEE MORE', data: null, orderable: false, searchable: false,
                        render: (data, type, row) => {
                            const id = $('<div>').text(String(row.id)).html();
                            return `<button type="button" data-id="${id}" class="btn-command-action contact-detail-open" style="padding: 3px 10px; font-size: 0.75rem;" aria-label="View contact submission ${id}"><i class="fas fa-eye mr-1" aria-hidden="true"></i> View</button>`;
                        },
                    },
                ],
                language: {
                    searchPlaceholder: 'Search contacts…',
                    emptyTable: 'No contact submissions are available for this workspace.'
                },
            });

            $('#contactus').on('click', '.contact-detail-open', function () {
                const row = table.row($(this).closest('tr')).data();
                if (!row || typeof dialog.showModal !== 'function') { return; }
                const value = name => name === 'is_mobile' ? (row[name] ? 'YES' : 'NO') : (row[name] ?? '');
                dialog.querySelectorAll('[data-contact-field]').forEach(field => {
                    field.textContent = value(field.dataset.contactField);
                });
                document.getElementById('contact-detail-title').innerHTML = `<i class="fas fa-info-circle mr-1 text-purple"></i> Contact Submission #${row.id}`;
                dialog.showModal();
            });
            document.getElementById('contact-detail-close').addEventListener('click', () => dialog.close());
        });
    </script>
@endpush
