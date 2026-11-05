# AlphaLink Command OS migration ledger

**Owner:** Luna implements the UI slices; Sol coordinates the roadmap and audits each gate.
**Objective:** Move AlphaLink to the user-supplied Command OS visual system without losing an existing route, page, action, permission boundary, workspace rule, backend behavior, or operational safety gate.
**Current phase:** Phase 1 — React foundation and dashboard, awaiting Ubuntu browser validation.
**Status:** `IN_PROGRESS` — route/source inventory and dashboard implementation are recorded; authenticated browser checks on the Ubuntu deployment and full baseline gates remain open.

This ledger covers the current AlphaLink application. It does not treat archived comparison notes as instructions or as proof of present runtime behavior. Current product rules come from `ALPHALINK_MASTER_SPEC.md`, `ALPHALINK_PROGRESS.md`, `apps/api/DESIGN.md`, `apps/api/UX-CONTRACT.md`, current code, and tests. Dated matrix and audit material supplies evidence with its recorded limitations.

## Baseline snapshot

Captured from branch `codex/alphalink-identity-reconstruction`, commit `a03ee64`, on 2026-11-05. The runtime route inventory is generated from `php artisan route:list --json` and filters to AlphaLink `App\\` actions.

| Measure | Baseline |
|---|---:|
| AlphaLink application routes | 783 |
| Web routes | 743 |
| JSON API routes | 40 |
| Framework/vendor routes excluded from the migration inventory | 31 |
| Blade views in `resources/views/console` | 136 |
| Blade views in `resources/views` | 145 |
| Named AlphaLink routes | 746 |
| Unnamed AlphaLink routes | 37 |
| `clean.*` compatibility route names | 360 |
| Current Git worktree | Existing untracked `Alphalink_crendentials.txt` and `log_DMS/`; preserved and not read or changed for this task |

The route-by-route source of truth is [`COMMAND_OS_ROUTE_INVENTORY.csv`](COMMAND_OS_ROUTE_INVENTORY.csv). It records every AlphaLink runtime route with method, URI, name, module, controller action, middleware, existing renderer, React migration state, evidence pointer, and risk-review band. Framework/vendor routes are excluded. A new route or alias must be added to this inventory when introduced; existing URLs and aliases remain until an explicitly verified replacement plan covers them.

### Existing frontend and requested stack

The console is Laravel Blade with vanilla page JavaScript, built by Vite. At the captured commit, the installed direct versions are Vite 8.3.5, Tailwind CSS 4.3.3, and `laravel-vite-plugin` 3.2.0. React, TypeScript, React Router, shadcn/Radix, Lucide, XYFlow, MapLibre, ECharts, TanStack Table/Query, Zustand, and Motion are not configured as application dependencies. Tailwind v4 is already active through `@tailwindcss/vite`.

The API is not yet a complete presentation contract for the console. The JSON API provides public health/contact/tracking endpoints; Sanctum identity/workspace access; workspace-scoped audience, contact, consent/suppression, campaign draft/revision, and infrastructure slices. Most operational screens still submit to or render through Laravel web routes. Therefore the migration will retain Laravel as the authority and use route-backed Blade data initially. If a React workflow requires new JSON, add only the narrow, authorized presentation endpoint for that workflow; do not duplicate domain logic in TypeScript.

### Current route families and view coverage

All families, including small subfamilies and compatibility aliases, are in the CSV. Counts below are Blade view files by current `console` directory, not feature-completion claims.

| Current page family | Blade views |
|---|---:|
| Infrastructure | 41 |
| Campaigns | 13 |
| Tools | 11 |
| Warmups | 8 |
| Offers | 7 |
| Analytics | 6 |
| App settings | 6 |
| Drops | 6 |
| PMTA | 6 |
| Miscellaneous | 5 |
| Audiences | 4 |
| Global users | 4 |
| Sponsors | 4 |
| Workspace users | 4 |
| Global tests | 3 |
| Mailer drafts | 3 |
| Campaign negatives | 2 |
| Other console entry/workspace views | 2 |

The menu source is `apps/api/resources/views/layouts/admin-shell.blade.php`. It has nested groups, role-sensitive entries, and several labels with no `href` (for example GSend Send, Campaign Logs, and Tools placeholders). Those entries must be traced to a working route or explicitly recorded as an existing placeholder before the menu is redesigned. The simplified five-link workspace shell in `layouts/console.blade.php` is a separate presentation path and must also remain represented.

## Security and behavior invariants

These are migration gates, not visual preferences.

1. All private web routes keep Laravel session authentication, active-account checks, and session protection. Workspace resolution and direct route authorization remain server-enforced.
2. A non-member cannot learn that a workspace exists; `EnsureWorkspaceMember` returns 404. Role failures remain 403 where that is the established contract.
3. Navigation visibility is convenience only. React must not become the authorization boundary; every action continues through its current controller, policy, middleware, or service gate.
4. Existing CSRF behavior, throttles, idempotency keys, route names, redirects, form validation, safe failure states, and workspace scope are preserved.
5. Infrastructure, PMTA, send, provider, DNS, destructive, and account-management actions keep their existing confirmations, readiness checks, role checks, and default-off execution gates.
6. Never replace observed values with screenshot sample data. Empty/loading/error states stay honest and workspace-scoped.

For the dashboard, `GET /dashboard/main` resolves the active workspace and then calls `WorkspaceAnalyticsConsoleController::index`. That controller authorizes `owner`, `admin`, `operator`, and `privacy_officer`; its daily aggregates are workspace/user/role/date scoped and cached. The route middleware and controller authorization both remain in place.

The JSON API has additional role distinctions recorded in `routes/api.php`: workspace members are verified; audience reads allow owner/admin/operator/privacy-officer while audience writes exclude privacy officers; suppression writes allow owner/admin/privacy-officer; campaign operations allow owner/admin/operator; infrastructure operations allow owner/admin. Preserve these distinctions when a React surface consumes the API.

### User authorization and release path

The user's latest reaffirmation (2026-11-05) explicitly authorizes the full redesign and upgrade journey, including use of available root/sudo privileges for the work, installation and updating of the approved frontend stack and required application/system packages, pushing every audited and verified phase to GitHub, and deploying each verified phase to the Ubuntu AlphaLink SaaS. Continue this release path throughout the journey without asking for the same authorization again. This supersedes the earlier planning-only clause in the active Codex Goal. Authorization does not establish that a credential, OS privilege, GitHub write permission, or trusted server identity is actually available; verify those operational prerequisites before use.

For every phase, finish its implementation, audit, automated checks, and release artifact before pushing the reviewed branch; then deploy that verified phase through the Ubuntu release path and verify it in the real SaaS before signing off. Preserve the release procedure's backup, health-check, and rollback gates, and never edit the active server checkout in place. Install the required versions and updates for the approved frontend stack and application compatibility, after confirming the existing PHP/Node/application versions and Ubuntu deployment method. Assess broader operating-system updates against production service dependencies and the release plan before applying them. The production SSH host identity must be verified against a trusted source before running privileged server commands. Do not access or modify the separate historical DMS service, and never include credential values from local or server environments in commits, logs, or reports.

The active system Goal cannot be edited through the available goal controls while it is unfinished. Do not mark it complete or replace it to work around that limitation. This migration ledger is the controlling project record for the user's updated authorization, active phase, and release gates; the Codex Goal remains active until the redesign and its completion criteria are actually finished.

### Implementation and verification checkpoint (2026-11-05)

The first dashboard slice now mounts React + TypeScript at the existing `/dashboard/main` route, using the existing Laravel/Vite build and authenticated workspace-scoped controller data. Existing Blade navigation and all other page renderers remain in place. The React dashboard has semantic light/dark tokens, responsive layout, seven metrics, an ECharts delivery/open trend, role-filtered quick actions linked only to existing GET workflows, explicit GEO/relationship-data coverage states, a theme preference, and the existing five-minute fragment refresh URL. The screenshot's `Track a Link` action is visibly and accessibly marked unavailable because no safe standalone GET page exists. No screenshot sample metric values are included.

Files changed and the current phase scope are visible in the Git worktree. The route inventory is a baseline inventory; dashboard React state is recorded there. This does not claim parity for the other 782 routes or complete Phase 0 browser evidence.

| Check | Result |
|---|---|
| Focused `WorkspaceAnalyticsDashboardTest.php` | PASS — 22 tests, 316 assertions (PHP 8.5 container; in-memory SQLite), including role-filtered actions in rendered page and refresh payloads |
| `npm run typecheck` | PASS |
| `npm run build` | PASS — React dashboard entry is split from the lazy ECharts module; the ECharts chunk is 512.42 kB minified (173 kB gzip), above Vite's 500 kB advisory |
| Production/configured database | NOT USED |
| Authenticated Ubuntu browser review | NOT RUN — user requested to skip the Mint-host preview and use the Ubuntu SaaS version; review the deployed Command OS at desktop and 390px in both themes before Phase 1 sign-off |
| GitHub push | PASS — repository token permission was verified without exposing its value; reviewed commit `9b5271f` was pushed to `injo007/AlphaLink:codex/alphalink-identity-reconstruction` |
| Ubuntu release | AUTHORIZED — installer path reviewed; SSH to `baseheavy.shop` is blocked until ED25519 fingerprint `SHA256:cdBVpRiU4H3sBL4f3cwu/htb/QjIZlRdiLIvOvZMle4` is verified through a trusted server-side source |

#### E-DASH-39 shell permission and drawer follow-up (2026-11-05)

The dashboard shell header now shows its Campaigns and Global Test shortcuts only to workspace `owner`, `admin`, and `operator` roles; `privacy_officer` no longer sees links that the destinations reject. Server-side route authorization is unchanged. These guards apply only to Command OS dashboard routes, so non-dashboard shell shortcuts remain as before. Dashboard mobile navigation now uses a real backdrop, accessible dialog semantics while open, inert header/main/footer, focus entry and wrapping that skips hidden submenu links, Escape/backdrop/close dismissal, and focus restoration. Crossing to the desktop breakpoint closes modal/inert/backdrop/ARIA state and clears OneUI's mobile `.sidebar-o-xs` state without restoring focus to the mobile opener or changing the desktop `.sidebar-o` state. The JS scope is `apps/api/public/console-assets/command-os-drawer.js`, loaded only from the dashboard Blade shell; the existing Blade shell remains the route and navigation-label source.

Changed files for this follow-up: `apps/api/resources/views/layouts/admin-shell.blade.php`, `apps/api/resources/css/command-os.css`, `apps/api/resources/js/command-os.tsx` (removes the earlier dashboard-only click/Escape toggle now owned by the shell controller), `apps/api/public/console-assets/command-os-drawer.js` (new), `apps/api/tests/Feature/WorkspaceAnalyticsDashboardTest.php`, `apps/api/tests/Feature/AlphaLinkCampaignSendNavigationTest.php`, `apps/api/tests/js/command-os-drawer.test.js` (new), `apps/api/UX-CONTRACT.md`, `docs/implementation/COMMAND_OS_MIGRATION_LEDGER.md`, `docs/implementation/PARITY_REPORT.md`, `docs/reference/EVIDENCE_REGISTER.md`, and `docs/reference/FEATURE_MATRIX.md`. No routes, backend logic, dependencies, or module destinations changed.

| Check | Result |
|---|---|
| `WorkspaceAnalyticsDashboardTest.php` | PASS — 23 tests, 335 assertions; owner/admin/operator header shortcut destinations and privacy-officer absence are asserted in rendered dashboard HTML |
| `AlphaLinkCampaignSendNavigationTest.php` | PASS — 9 tests, 85 assertions; campaign and Global Test shortcuts remain on the non-dashboard campaign page, with no stale drawer ARIA state on its opener |
| `GlobalTestsWorkflowTest.php` | PASS — 8 tests, 45 assertions |
| Existing server navigation case | PASS — 1 test, 78 assertions |
| Drawer/campaign-register/dashboard-refresh Node suites | PASS — 7 tests; open, hidden submenu exclusion, focus trap, Escape/backdrop close, OneUI cleanup, breakpoint transition, no focus jump, campaign register, dashboard refresh |
| Changed PHP files Pint; TypeScript typecheck; Vite build; JS syntax; `git diff --check` | PASS for the touched PHP files; typecheck/build/syntax/diff checks pass. A full-repository Pint scan still reports one existing style issue in unchanged `bootstrap/providers.php`; the build retains the known 512.42 kB minified ECharts advisory |
| Premium strict static audit | One finding remains: `affordance.actionless-button` at `resources/js/command-os.tsx:362`. This is the intentional focusable `aria-disabled` Track a Link control; its visible reason is connected by `aria-describedby`. The exact unavailable behavior is documented in `apps/api/UX-CONTRACT.md`; do not remove this accessible state to silence the static false positive. |

Current blockers: the saved local app cannot provide authenticated browser verification because the listener/database connection is unavailable and its APP_KEY is empty. Reviewed commit `9b5271f` is pushed to GitHub. Ubuntu release is blocked pending trusted verification of the recorded production host fingerprint; deployment has not been run. E-DASH-40 corrects the desktop compact-rail navigation and role visibility in code, but production hover/focus/keyboard and responsive browser verification remains open. Phase 1 remains **TESTING**, not complete; matching production desktop/mobile screenshots, both themes, keyboard behavior in the deployed browser, refresh/session behavior, and complete route-family parity remain open.

### Ubuntu production baseline (2026-11-05)

The authenticated production route `https://baseheavy.shop/dashboard/main` was opened read-only in the Codex in-app browser. The current viewport was 1229 × 892 pixels. A viewport screenshot and accessibility tree were captured in this audit run; no links, forms, or business controls were activated. The screenshot was visually inspected, but could not be exported to the local audit folder because the browser refused the local data-URL download path. Treat this as a live visual observation, not a retained screenshot artifact or final visual QA.

The live page still renders the legacy dashboard. It uses a narrow icon-only sidebar, a small icon toolbar, a gray/white card layout, four today-oriented summary tiles, a separate row of four 30-day engagement tiles, revenue breakdown panels, MTD/YTD charts, and an event table. The captured viewport has no greeting/search/date-filter row, seven-card summary row, GEO map, relationship graph, quick-action row, or visible theme toggle from the target screenshot. Module groups and existing campaign/PMTA/global-test destinations remain in the page shell. The dashboard also presents attribution and data-freshness caveats; the redesign must preserve them. Its “YTD” summary line is explicitly documented as using the prior day's saved count, so period semantics need resolution before labels are carried over.

This baseline confirms that the Command OS code is not deployed to production. Matching-viewport desktop comparison, full-page captures, 390px review, theme review, keyboard/focus, and refresh/session checks remain outstanding after the reviewed branch can be pushed and released.

The host PHP CLI is PHP 8.3 and lacks the DOM, XMLWriter, and `pdo_pgsql` extensions needed by this application. For reproducible automated verification, the existing `alphalink-php85-tests:tmp` image runs PHP 8.5 with the required extensions. An isolated preview used a separately migrated `/tmp` SQLite database and synthetic `Local Design Preview` workspace; it did not read `.env`, credentials, or the configured application database. That preview is stopped and will not be used for acceptance review, per the user's request to review the Ubuntu SaaS version.

## Command OS visual target and current gaps

The attached reference is a desktop screenshot with a light dashboard above a dark dashboard. Each state has a fixed left navigation rail, top search/action bar, seven KPI cards, a two-series performance chart, GEO deliverability panel, relationship graph, and quick-action row. Reference dimensions are 1672 × 941 pixels; the two theme examples occupy approximately equal vertical halves. The screenshot's metric values, rates, user name, notification counts, and trend points are illustrative content and must never enter production UI as real data.

The current `apps/api/DESIGN.md` defines a light-only cream/forest/rust theme and says glass panels, dark mode, and decorative charts are absent. The user explicitly chose the screenshot as the new visual direction, so this design contract must be reconciled with the screenshot when the first React token slice is implemented. Existing legacy pages keep their current tokens until migrated; the new React surface gets a single documented token owner and a complete light/dark semantic mapping.

Current evidence and gaps:

| Screenshot feature | AlphaLink baseline | Migration decision |
|---|---|---|
| Shell, navigation, user/workspace context | Multiple Blade shells and a larger role-aware admin menu exist | New React shell must accept the existing authorized route list and preserve all route destinations, including modules hidden beneath nested groups. |
| KPI cards | Main dashboard already has delivery, open, click, lead, attributed lead revenue, unsubscribe, and bounce inputs in some scopes | Use only verified controller data. Define each label, period, source, role scope, and freshness. Preserve caveats for imported PMTA feedback and attributed revenue. |
| Date filter | Main trend chart covers the last 30 days/month; the headline cards currently show today and the prior day. Prior-day values are labeled YTD in current output. | Do not bind “Last 30 Days” to today-only cards. Phase 0 must settle honest per-card periods or provide the range totals from already-authorized aggregate inputs. Any copy correction is a user-visible change and is recorded. |
| GEO deliverability map | Geo targeting and country fields exist, but no verified deliverability-by-country aggregation or map view is present | Do not render invented rates. First trace actual country, delivery, and denominator data; keep the map as a later gated capability until valid aggregates exist. |
| Neural/marketing graph | No graph visualization or verified cross-module relationship endpoint is present | Define which persisted relationships are meaningful and permission-safe before building graph nodes or edges. Use actual relationships only. |
| Search anywhere | No verified global cross-module search contract found in the shell/routes inspected | Defer global search until searchable entities, result permissions, query handling, and URLs are defined. |
| AI Assistant | No assistant route or service is part of the current frontend stack/API inventory | Requires a separate product and backend security contract; do not ship a decorative assistant action. |
| Quick actions | Campaign, Drop, Warmup, and list workflows exist; there is no verified global “track link” capability in the current API surface | Link only to existing role-authorized workflows. Map each action to its current route and safe state before showing it. |
| Light/dark mode | Current design contract is light-only | Add semantic tokens for both themes and verify persisted theme preference without changing user/business data. |

## Phases and completion gates

Each phase is a small vertical slice. The implementation owner records changed routes, preserved behavior, executed checks, rendered evidence, and unresolved items before advancing.

| Phase | Scope | Acceptance gate | Status |
|---|---|---|---|
| 0. Evidence baseline | Route inventory, view families, middleware/roles, data/API boundaries, current theme and dashboard contracts | Inventory has one row per current AlphaLink runtime route; module ownership and major data/permission contracts are documented; screenshot-only capability gaps are explicit; baseline source/build and browser status are recorded | IN_PROGRESS — static route/source inventory and contracts captured; legacy authenticated browser baseline and complete route-by-route action evidence remain open |
| 1. React foundation and dashboard | React + TypeScript entry within existing Laravel/Vite app; shared Command OS shell; light/dark tokens; dashboard as first migrated workflow | Existing `/dashboard/main` URL/auth/workspace scope remains; nav contains current authorized route destinations; screenshot-matched desktop comparison plus 390px narrow viewport; both themes; focus/keyboard; empty/error/refresh states; real controller data only; dashboard role-scope and metric tests pass | TESTING — automated checks pass; visual, responsive, keyboard, session-expiry, and production-route checks remain open |
| 2. Shared list/form patterns | Shared table, search, filters, forms, pagination, confirmations, notifications | One selected low-risk CRUD/list flow has equivalent validation, persistence, permissions, loading/empty/error behavior, deep links, and keyboard behavior in React and Laravel | NOT_STARTED |
| 3. Operational module waves | Campaigns, audiences, Drops, warmups, tracking, offers, tools, users, settings, infrastructure, PMTA | Migrate complete workflows by risk. For every route/action, compare old behavior with the React surface; retain existing route names and controller/service gates; execute targeted tests and browser verification; record evidence in this ledger and applicable feature docs | NOT_STARTED |
| 4. New visualization/data capabilities | GEO map, relationship graph, global search, approved assistant | Each capability has a defined data contract, workspace/role scoping, failure/empty semantics, performance budget, accessibility alternative, and tests before it is shown as live | NOT_STARTED |
| 5. Legacy retirement | Remove old templates/assets only after replacement routes are verified | Route-by-route inventory has no required `NOT_STARTED`, `UNKNOWN`, or unexecuted verification entries; rollback path and release checks pass | NOT_STARTED |

### Phase 0 remaining work

- Capture representative current authenticated routes on the Ubuntu SaaS in a browser at matching desktop and narrow viewports. PARTIAL: the dashboard was inspected at 1229 × 892; its screenshot could not be retained, and other representative routes plus 1672 × 941 and 390px captures remain. The screenshot attachment is the design target, not a baseline capture of the app. Local source/tests remain the reviewed release source; browser evidence is collected from the requested Ubuntu runtime.
- Map each page family to its route/controller/view and current test/evidence source. Existing DMS-derived feature statuses are not frontend migration statuses.
- Record the exact React-to-Laravel data contract for dashboard metrics, including actual freshness and period semantics.
- Record the current Vite build and dependency changes in the Phase 1 implementation checkpoint above.

### Required per-slice record

Every subsequent route family update must add:

| Field | Required evidence |
|---|---|
| Route/workflow | Existing route names, aliases, URLs, methods, and page/action inventory |
| Source and contract | Blade/controller/service/API source, input/output, validation, persistence, and failure paths |
| Authorization | Middleware/policy/role matrix, workspace scope, and negative-access verification |
| React surface | Component/route owner and any adapter/API addition |
| Difference classification | `BUG`, `JUSTIFIED_MODERNIZATION`, `UNKNOWN`, or `APPROVED_DEVIATION`; a deviation needs explicit user approval |
| Verification | Focused PHP/JS tests, typecheck/build, browser route/state/theme/viewport checks, keyboard/accessibility, and screenshots compared at a matched viewport |
| Status | `NOT_DISCOVERED`, `INVESTIGATING`, `VERIFIED`, `IMPLEMENTING`, `TESTING`, `PARITY_CONFIRMED`, or `BLOCKED` |

Visual similarity by itself never closes a workflow or permission gate.

#### E-DASH-40 dashboard navigation visibility and collapsed desktop rail (2026-11-05)

Dashboard-only navigation now follows the verified workspace role matrix below. The admin Blade shell remains the sole route and label source; server-side authorization is unchanged. Non-dashboard pages keep the prior destinations and visibility.

| Destination | owner/admin/operator | privacy_officer | State |
|---|---|---|---|
| Campaigns SEND, NEGATIVES, MIXS | Visible | Hidden on Command OS dashboard | Existing routes still enforce their server authorization |
| Global Tests SEND, HISTORIES | Visible | Hidden on Command OS dashboard | Existing routes still enforce their server authorization |
| GSend PROJECTS, ACCOUNTS | Visible | Visible | Existing destinations retained |
| Campaign Statistics | Visible | Visible | Existing destination retained |
| GSend SEND, Campaign Logs | Existing no-href placeholders | Existing no-href placeholders | Retained as disabled placeholders; no route was invented |

The dashboard mini rail now preserves accessible link names while visually clipping labels in the collapsed state. Source inspection confirmed OneUI translates the sidebar content by 180px in mini mode and translates it back only on pointer hover, while the Command OS grid track remains 74px. Dashboard desktop-only hover and focus-within styles keep the sidebar sticky, expand it to 240px, reset header/content transforms, and show opened submenus; the expanded rail extends over the main track. At mobile widths, mini mode retains the fixed off-canvas drawer and its hidden/open transform, so resizing cannot leave a compact rail exposed over the page. Activating a submenu while collapsed removes the mini state before the click reaches OneUI's existing submenu handler, and the drawer controller synchronizes the width toggle's `aria-pressed` value both immediately and after OneUI changes the class. No routes, role rules, dependencies, or business workflows were added.

Changed files for this slice: `apps/api/resources/views/layouts/admin-shell.blade.php`, `apps/api/resources/css/command-os.css`, `apps/api/public/console-assets/command-os-drawer.js`, `apps/api/tests/Feature/WorkspaceAnalyticsDashboardTest.php`, `apps/api/tests/Feature/AlphaLinkCampaignSendNavigationTest.php`, `apps/api/tests/js/command-os-drawer.test.js`, and `apps/api/UX-CONTRACT.md`.

| Check | Result |
|---|---|
| Dashboard role-rendered link test | PASS — `WorkspaceAnalyticsDashboardTest.php::test_dashboard_nested_campaign_and_global_test_links_match_the_workspace_role`, 1 test / 26 assertions; privacy officers see no empty Global Tests parent on the dashboard, authorized roles retain the group, and privacy officers still see it on the accessible GSend Projects page |
| Direct privacy-officer access test | PASS — `AlphaLinkCampaignSendNavigationTest.php::test_privacy_officers_cannot_directly_open_hidden_dashboard_destinations`, 1 test / 5 assertions; all five forbidden GET routes return 403 |
| Full affected dashboard/navigation suites | PASS — `WorkspaceAnalyticsDashboardTest.php` 24 tests / 361 assertions; `AlphaLinkCampaignSendNavigationTest.php` 10 tests / 90 assertions; `GlobalTestsWorkflowTest.php` 8 tests / 45 assertions; existing server-navigation test 1 test / 78 assertions |
| Collapsed-rail / drawer Node suite | PASS — 5 tests; desktop sticky hover/focus sizing and content transforms, mobile mini drawer hiding, desktop→mobile→desktop state, submenu activation, accessible labels, and `aria-pressed` synchronization on expansion/collapse are asserted |
| Typecheck, build, changed-file Pint, and diff check | PASS — full-repository Pint also reports one existing style issue in unchanged `bootstrap/providers.php`; build retains the pre-existing 512.42 kB ECharts chunk advisory |
| Production browser review | NOT RUN — the saved authenticated app listener/database is unavailable; hover/focus visuals and keyboard activation still need deployed-browser confirmation |
| GitHub candidate | PASS — commit `9b5271f` pushed to `codex/alphalink-identity-reconstruction` after code audit and automated checks |
| Phase status | TESTING — Phase 1 remains open; no completion or production-release claim |
