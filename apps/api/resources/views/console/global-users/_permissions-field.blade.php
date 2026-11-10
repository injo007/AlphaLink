@php
    $fullAccessRole = in_array($selectedAccessRole, [\App\Models\User::ACCESS_ROLE_OWNER, \App\Models\User::ACCESS_ROLE_ADMIN], true);
@endphp

<section class="global-user-permissions" aria-labelledby="global-user-permissions-title">
    <header class="global-user-permissions-heading">
        <div>
            <span class="global-user-permissions-eyebrow">ACCOUNT ACCESS</span>
            <h2 id="global-user-permissions-title">Page permissions</h2>
            <p>Choose what this account can view and manage. Manage access also includes viewing.</p>
        </div>
        <span class="global-user-permissions-role" id="permission-role-summary">{{ \App\Models\User::ACCESS_ROLE_LABELS[$selectedAccessRole] ?? $selectedAccessRole }}</span>
    </header>

    <input type="hidden" name="permission_selection_submitted" value="1">

    <div class="global-user-permissions-full" id="permission-full-access" @if (! $fullAccessRole) hidden @endif role="status">
        <i class="fas fa-shield-alt" aria-hidden="true"></i>
        <div><strong>Full access</strong><span>Owner and Admin can use every workspace page. Only the Owner can transfer ownership and arrange shared navigation.</span></div>
    </div>

    <div class="global-user-permissions-custom" id="permission-custom-access" @if ($fullAccessRole) hidden @endif>
        <div class="global-user-permissions-toolbar">
            <p id="permission-state" role="status" aria-live="polite">@if ($permissionsAreRoleDefaults) Role defaults are selected. Change any permission for this account. @else Custom permissions are selected for this account. @endif</p>
            <div class="global-user-permissions-toolbar-actions">
                <button type="button" class="global-user-permissions-button" id="reset-role-permissions">Reset to role defaults</button>
                <button type="button" class="global-user-permissions-button global-user-permissions-mailer-preset" id="apply-mailer-permissions" hidden>Apply mailer sending access</button>
            </div>
        </div>

        <div class="global-user-permissions-grid">
            @foreach ($permissionGroups as $group => $details)
                @php($viewPermission = $group.'.view')
                @php($managePermission = $group.'.manage')
                <article class="global-user-permission-row">
                    <div class="global-user-permission-copy">
                        <h3>{{ $details['label'] }}</h3>
                        <p>{{ $details['description'] }}</p>
                    </div>
                    <div class="global-user-permission-actions">
                        <label for="permission-{{ $group }}-view" class="global-user-permission-choice">
                            <input id="permission-{{ $group }}-view" type="checkbox" name="roles[]" value="{{ $viewPermission }}" @checked(in_array($viewPermission, $selectedPermissions, true))>
                            <span>View</span>
                        </label>
                        @if ($details['manage'])
                            <label for="permission-{{ $group }}-manage" class="global-user-permission-choice">
                                <input id="permission-{{ $group }}-manage" type="checkbox" name="roles[]" value="{{ $managePermission }}" @checked(in_array($managePermission, $selectedPermissions, true))>
                                <span>Manage</span>
                            </label>
                        @else
                            <span class="global-user-permission-read-only">View only</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        <p class="global-user-permissions-note"><i class="fas fa-info-circle" aria-hidden="true"></i> User management stays with Owner and Admin. The Owner alone controls shared navigation.</p>
    </div>
</section>

<style>
    .global-user-permissions {
        --permission-ink: var(--co-ink, #20264d);
        --permission-muted: var(--co-muted, #68748f);
        --permission-border: var(--co-border, #dcdcf4);
        --permission-surface: var(--co-surface, #fff);
        --permission-soft: var(--co-page, #f7f6ff);
        --permission-accent: var(--co-purple, #873cff);
        margin: 1.25rem 0;
        overflow: hidden;
        border: 1px solid var(--permission-border);
        border-radius: 14px;
        color: var(--permission-ink);
        background: var(--permission-surface);
        box-shadow: 0 8px 24px rgba(47, 38, 108, .05);
    }
    .global-user-permissions-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.1rem;
        border-bottom: 1px solid var(--permission-border);
        background: linear-gradient(120deg, var(--permission-soft), var(--permission-surface) 68%);
    }
    .global-user-permissions-eyebrow {
        color: var(--permission-accent);
        font-size: .66rem;
        font-weight: 800;
        letter-spacing: .13em;
    }
    .global-user-permissions-heading h2 {
        margin: .18rem 0 .2rem;
        color: var(--permission-ink);
        font-size: 1.05rem;
        font-weight: 800;
    }
    .global-user-permissions-heading p,
    .global-user-permission-copy p {
        margin: 0;
        color: var(--permission-muted);
        font-size: .78rem;
        line-height: 1.5;
    }
    .global-user-permissions-role {
        flex: 0 0 auto;
        padding: .35rem .65rem;
        border: 1px solid var(--permission-border);
        border-radius: 999px;
        color: var(--permission-accent);
        background: var(--permission-surface);
        font-size: .7rem;
        font-weight: 800;
    }
    .global-user-permissions-custom { padding: .85rem 1rem 1rem; }
    .global-user-permissions-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        margin-bottom: .75rem;
    }
    .global-user-permissions-toolbar p { margin: 0; color: var(--permission-muted); font-size: .76rem; }
    .global-user-permissions-toolbar-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
    .global-user-permissions-button {
        min-height: 34px;
        padding: .4rem .68rem;
        border: 1px solid var(--permission-border);
        border-radius: 8px;
        color: var(--permission-ink);
        background: var(--permission-surface);
        font: inherit;
        font-size: .72rem;
        font-weight: 750;
        cursor: pointer;
        transition: border-color .15s ease, color .15s ease, background-color .15s ease;
    }
    .global-user-permissions-button:hover { border-color: var(--permission-accent); color: var(--permission-accent); }
    .global-user-permissions-button:focus-visible,
    .global-user-permission-choice input:focus-visible { outline: 3px solid rgba(135, 60, 255, .35); outline-offset: 2px; }
    .global-user-permissions-mailer-preset { border-color: rgba(135, 60, 255, .32); color: var(--permission-accent); }
    .global-user-permissions-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
    .global-user-permission-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        min-width: 0;
        min-height: 72px;
        padding: .7rem .8rem;
        border: 1px solid var(--permission-border);
        border-radius: 10px;
        background: var(--permission-surface);
    }
    .global-user-permission-copy { min-width: 0; }
    .global-user-permission-copy h3 { margin: 0 0 .18rem; color: var(--permission-ink); font-size: .78rem; font-weight: 800; }
    .global-user-permission-actions { display: flex; flex: 0 0 auto; align-items: center; gap: .65rem; }
    .global-user-permission-choice { display: inline-flex; align-items: center; gap: .32rem; margin: 0; color: var(--permission-ink); font-size: .7rem; font-weight: 700; cursor: pointer; }
    .global-user-permission-choice input { width: 15px; height: 15px; margin: 0; accent-color: var(--permission-accent); cursor: pointer; }
    .global-user-permission-read-only { color: var(--permission-muted); font-size: .67rem; white-space: nowrap; }
    .global-user-permissions-note { display: flex; gap: .4rem; margin: .8rem 0 0; color: var(--permission-muted); font-size: .7rem; line-height: 1.45; }
    .global-user-permissions-note i { padding-top: 2px; color: var(--permission-accent); }
    .global-user-permissions-full { display: flex; gap: .75rem; padding: 1rem; color: var(--permission-ink); background: var(--permission-soft); }
    .global-user-permissions-full > i { padding-top: .15rem; color: var(--permission-accent); }
    .global-user-permissions-full strong,
    .global-user-permissions-full span { display: block; }
    .global-user-permissions-full strong { margin-bottom: .2rem; font-size: .82rem; }
    .global-user-permissions-full span { color: var(--permission-muted); font-size: .76rem; line-height: 1.5; }
    @media (max-width: 720px) {
        .global-user-permissions-heading { flex-direction: column; }
        .global-user-permissions-toolbar { align-items: flex-start; flex-direction: column; }
        .global-user-permissions-grid { grid-template-columns: minmax(0, 1fr); }
        .global-user-permission-row { align-items: flex-start; flex-direction: column; }
        .global-user-permission-actions { flex-wrap: wrap; }
    }
    @media (prefers-reduced-motion: reduce) {
        .global-user-permissions-button { transition: none; }
    }
</style>

<script>
    (() => {
        const panel = document.querySelector('.global-user-permissions');
        const roleSelect = document.getElementById('access_role');
        const accountType = document.getElementById('type');
        if (!panel || !roleSelect) return;

        const fullAccess = document.getElementById('permission-full-access');
        const customAccess = document.getElementById('permission-custom-access');
        const roleSummary = document.getElementById('permission-role-summary');
        const status = document.getElementById('permission-state');
        const checkboxes = [...panel.querySelectorAll('input[name="roles[]"]')];
        const permissionDefaults = @json($permissionDefaults);
        const mailerPermissions = @json($mailerPermissions);
        const roleLabels = @json(\App\Models\User::ACCESS_ROLE_LABELS);
        const permissionsAreRoleDefaults = @json($permissionsAreRoleDefaults);
        const mailerPreset = document.getElementById('apply-mailer-permissions');

        function updateFullAccessState(role) {
            const hasFullAccess = role === 'owner' || role === 'admin';
            fullAccess.hidden = !hasFullAccess;
            customAccess.hidden = hasFullAccess;
            checkboxes.forEach((checkbox) => { checkbox.disabled = hasFullAccess; });
            roleSummary.textContent = roleLabels[role] || role;
            if (status) status.textContent = 'Role defaults are selected. Change any permission for this account.';
            updateMailerPreset(role);
        }

        function setPermissions(permissions, role) {
            const selected = new Set(permissions || []);
            checkboxes.forEach((checkbox) => { checkbox.checked = selected.has(checkbox.value); });
            updateFullAccessState(role);
            if (status) status.textContent = 'Permissions updated. Save the account to apply these choices.';
        }

        function updateMailerPreset(role) {
            if (!mailerPreset) return;
            const isMailer = accountType?.value === 'mailer';
            const isFullAccess = role === 'owner' || role === 'admin';
            mailerPreset.hidden = !isMailer || isFullAccess;
        }

        roleSelect.addEventListener('change', () => {
            const role = roleSelect.value;
            setPermissions(permissionDefaults[role] || [], role);
        });
        accountType?.addEventListener('change', () => updateMailerPreset(roleSelect.value));

        checkboxes.forEach((checkbox) => checkbox.addEventListener('change', () => {
            if (status) status.textContent = 'Custom permissions selected. Save the account to apply these choices.';
        }));

        document.getElementById('reset-role-permissions')?.addEventListener('click', () => {
            const role = roleSelect.value;
            setPermissions(permissionDefaults[role] || [], role);
        });

        mailerPreset?.addEventListener('click', () => {
            const role = roleSelect.value;
            const defaults = permissionDefaults[role] || [];
            setPermissions([...new Set([...defaults, ...mailerPermissions])], role);
        });

        updateFullAccessState(roleSelect.value);
        if (!permissionsAreRoleDefaults && status && roleSelect.value !== 'owner' && roleSelect.value !== 'admin') {
            status.textContent = 'Custom permissions are selected for this account.';
        }
    })();
</script>
