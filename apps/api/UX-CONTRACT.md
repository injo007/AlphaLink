# AlphaLink UI Behavior Contract

## Product context

- **Audience:** Workspace owners, administrators, campaign operators, and privacy officers.
- **Primary jobs:** Sign in, choose an authorized workspace, review campaign revisions or audience-list state, and prepare server installation as an owner or administrator.
- **Target market(s):** Not selected. English is the initial interface language; no legal or jurisdictional policy is implied.
- **Accessibility target:** WCAG 2.2 AA baseline for semantics, keyboard access, visible focus, status names, and contrast.
- **Business evidence:** `../../audit/ALPHALINK_ADVANCED_REFERENCE_SYSTEM_2026-10-22.md` defines the intended architecture and the current no-sending boundary. The live API route groups and `EnsureWorkspaceMember` / `EnsureWorkspaceRole` middleware define implemented access behavior.

## Visual contract

- **Design system:** `DESIGN.md`.
- **Token ownership:** `resources/css/app.css` owns runtime variables; `DESIGN.md` documents their accepted values.
- **Themes:** Light only.
- **Locale:** English only. Dates follow the configured application timezone.

## Canonical UI Map

| Capability | Canonical owner | Source of truth | Allowed variants | Verification |
|---|---|---|---|---|
| Authentication form | Laravel session auth + native labeled form | `AuthController`, `config/auth.php` | Credentials and optional remember-me | Sign-in success/failure, session rotation, logout |
| Workspace authorization | Workspace membership query in `WorkspaceConsoleController` | `workspace_user` role plus API role map | Workspace-scoped read | Direct cross-workspace URL returns 404; unauthorized role returns 403 |
| Form | Native HTML form with Laravel validation and CSRF | Login and infrastructure Blade views + controllers | Sign-in, server/domain registration, installation preview | Keyboard submit, invalid input, throttling, role enforcement |
| Scrollbar | Global stylesheet in `resources/css/app.css` | `DESIGN.md` and CSS variables | Horizontal table overflow | Narrow viewport and keyboard focus |
| Read-only register | Server-rendered list/detail routes | Workspace relationships and existing API schema | Campaigns, audience lists | Empty, populated, pagination, tenant scoping |
| Installation preview | Server-rendered immutable plan | `InfrastructureManager`, installation plan record | All three PMTA versions and optional services | Blockers, selected options, workspace scope, no execution route |
| DMS bulk-install planner | DMS-admin Blade page and source-backed eligibility query | `DmsBulkInstallEligibility`, DMS `BulkInstallController::index` / `ServerInstallBulk` | PMTA 4.0/4.5/5.0, seven switches, per-server domain choice, APPLY to all rows | DMS source trace, empty-state browser comparison, local eligibility tests; remote INSTALL remains blocked |
| Toast / dialogs / table selection / date picker | Not present | Not applicable | None | Inline status and validation only |

## Workflow and state

- `/sign-in` authenticates an existing account. A generic error appears inline; repeated failures are rate-limited. Success regenerates the session and returns the user to the intended destination.
- `/app` redirects a single-workspace member to that workspace, lists multiple active memberships, or explains when no active workspace is assigned.
- Every workspace route resolves the workspace through the signed-in user's active membership. A non-member sees not-found; a member without the capability sees forbidden.
- Campaign and audience pages are read-only. Pagination state is in the URL. There is no create/edit/delete, send/schedule, export, or contact-address display in the browser UI.
- Owners and administrators can register a server with a public IP, provider and password or SSH key. The credential is encrypted in a separate row and is never rendered or returned. Registration starts in `pending_preflight` and does not contact the server. Test Connection is a separate SSH/SFTP login and never asks the operator for a host fingerprint. Read-only preflight automatically records the first SSH host key and rejects a later key change without requesting fingerprint input.
- A domain is selectable for an installation preview only after its `_alphalink-verify` TXT challenge has been observed. DNS verification checks current DNS; the app does not publish records.
- The installation form always includes PowerMTA. It offers 4.0, 4.5 and 5.0 as preview choices, shows their availability, and stores an immutable plan with explicit blockers. The current product has no execute route or remote installer. Tracking implies HTTPS in the preview; proxy selection adds a topology blocker.
- `/infrastructure/bulk-install` mirrors the DMS cloud bulk page. It lists workspace servers that are active, not installed, and match the owner's DMS username naming pattern; domains must be active, `free` or `new`, non-owned, and connected to an active domain provider. PMTA 4.0 is selected by default. Tracking, Proxy, IMAP, SPF, DKIM, DMARC and HTTPS default off. APPLY copies that row's selected domain to every remaining row; it does not select servers. In DMS, INSTALL drops rows without a domain and submits all remaining rows with these options, then polls the process log. AlphaLink currently has no installer/log endpoint and keeps INSTALL disabled. Username/provider mappings are not yet populated for existing accounts/domains. This page is not installation acceptance.
- Detail breadcrumbs use route hierarchy. Successful navigation uses normal browser history and links; no modal or client-side state needs restoration.
- Server-rendered routes show their final state without a loading skeleton. Empty lists explain whether data has not been recorded.
- Logout is a CSRF-protected POST that invalidates the session and rotates the CSRF token.

## Permission consequences

- Campaign routes are available to `owner`, `admin`, and `operator`, matching the current API.
- Audience-list routes are available to `owner`, `admin`, `operator`, and `privacy_officer`, matching the current API.
- Navigation visibility improves discoverability only; the controller enforces each role on direct access.
- Infrastructure routes are restricted to `owner` and `admin` in both browser and API controllers.
- The UI displays the current user's account identity but does not display contact email addresses or expose an export action.

## Verification

- PHP syntax and Laravel route registration for web routes.
- Vite production asset build.
- Browser review at desktop and narrow mobile widths when a runnable database-backed app is available.
- Static premium audit via the skill's `audit_project.py`.
