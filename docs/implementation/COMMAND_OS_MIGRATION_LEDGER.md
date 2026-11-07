# AlphaLink Command OS migration ledger

**Owner:** Luna implements the UI slices; Sol coordinates the roadmap and audits each gate.
**Objective:** Move AlphaLink to the user-supplied Command OS visual system without losing an existing route, page, action, permission boundary, workspace rule, backend behavior, or operational safety gate.
**Current phase:** Phase 0 — complete the route/action evidence baseline; Phase 1 dashboard remains in production testing.
**Status:** `TESTING` — the Command OS dashboard is deployed to Ubuntu. Release `4b83f92103e7ed9980de7f310d52f3da0315737b` adds always-visible Marketing Graph controls and role-allowed module navigation. The guarded deploy verified backup `/var/backups/alphalink/deploy-4b83f92103e7ed9980de7f310d52f3da0315737b-20261107T041310Z.dump`, no pending migrations, restored services, and HTTPS health. Production inspection confirms ten visible native links; a pointer click on Campaigns opened its route. At the sampled desktop panel width, Campaigns, Domains and Warmups are ellipsized and the node colors have low contrast. The earlier inconclusive pointer observation is superseded for Campaigns only; see E-DASH-46. Responsive, theme, session/refresh, route-family parity, and full screenshot-fidelity gates remain open.

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

**Current route-count recheck (2026-11-07):** A read-only `APP_ENV=testing php artisan route:list --json` from the active Git checkout returned 813 total declarations: 783 AlphaLink application routes (743 web, 40 API) and 30 non-application routes. All 783 application routes and their 1,162 method/URI pairs match the inventory; no missing or stale method/URI entries were found. The table above preserves the original 2026-11-05 snapshot, which counted 31 non-application routes. This validates route coverage, not route-by-route action parity. The active source checkout is `/media/akmed/4CF25768F25754F43/Alphalink` at `0e67e2f`; the environment-provided `...F41/Alphalink` path exists but has no Git metadata and was not used for source changes.

### Existing frontend and requested stack

The console began as Laravel Blade with vanilla page JavaScript, built by Vite. At the route-inventory baseline commit, React, TypeScript, and the visualization/UI libraries were not configured. The current checked `apps/api/package.json` and lockfile declare React/React DOM 19.3.1, TypeScript 7.0.2, Vite 8.3.5, Tailwind CSS 4.3.3, Lucide React 1.69.0, ECharts 6.1.0, MapLibre GL JS 6.16.0, `topojson-client` 3.1.0, `world-atlas` 2.0.2, and `i18n-iso-countries` 7.14.0; Tailwind v4 remains integrated through `@tailwindcss/vite`. The dashboard foundation uses this installed stack. Radix, TanStack Table/Query, React Router, XYFlow, Zustand, and Motion remain undeclared; add them only in the first verified slice that needs them. The current radial Marketing Graph does not require XYFlow. shadcn/ui components are project-owned source rather than a required runtime framework.

The API is not yet a complete presentation contract for the console. The JSON API provides public health/contact/tracking endpoints; Sanctum identity/workspace access; workspace-scoped audience, contact, consent/suppression, campaign draft/revision, and infrastructure slices. Most operational screens still submit to or render through Laravel web routes. Therefore the migration will retain Laravel as the authority and use route-backed Blade data initially. If a React workflow requires new JSON, add only the narrow, authorized presentation endpoint for that workflow; do not duplicate domain logic in TypeScript.

### Visual reference rule

Use the implemented Create Campaign screen at `/mailer/campaigns/create` and the user's supplied Create Campaign screenshot as the in-product visual anchor for remaining pages. Production review E-CAMP-163 covered desktop and phone layouts. The current Blade page has its own violet `--campaign-*` variables and inline styles, so it is a visual reference rather than a shared component or token system. Carry its palette, background treatment, spacing, icon language, section hierarchy, responsive behavior, and popover finish into each family through reviewed shared tokens; preserve each page's existing AlphaLink content and operations and compare at desktop and narrow widths. Keep the Create Campaign page open as the reference during visual work.

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

The user's latest reaffirmation (2026-11-05) explicitly authorizes the full redesign and upgrade journey, grants root/sudo authority for the work on the Ubuntu SaaS when available, and authorizes installation and updating of the approved frontend stack and required application/system packages, pushing every audited and verified phase to GitHub, and deploying each verified phase to the Ubuntu AlphaLink SaaS. Continue this release path throughout the journey without asking for the same authorization again. This supersedes the earlier planning-only clause in the active Codex Goal. This is scope authorization, not evidence that remote root credentials, an SSH identity, or any other operational prerequisite is currently available; verify those before use.

For every phase, finish its implementation, audit, automated checks, and release artifact before pushing the reviewed branch; then deploy that verified phase through the Ubuntu release path and verify it in the real SaaS before signing off. Preserve the release procedure's backup, health-check, and rollback gates, and never edit the active server checkout in place. Install the required versions and updates for the approved frontend stack and application compatibility, after confirming the existing PHP/Node/application versions and Ubuntu deployment method. Assess broader operating-system updates against production service dependencies and the release plan before applying them. The production SSH host identity must be verified against a trusted source before running privileged server commands. Do not access or modify the separate historical DMS service, and never include credential values from local or server environments in commits, logs, or reports.

The active system Goal cannot be edited through the available goal controls while it is unfinished. Do not mark it complete or replace it to work around that limitation. This migration ledger is the controlling project record for the user's updated authorization, active phase, and release gates; the Codex Goal remains active until the redesign and its completion criteria are actually finished.

**Goal-control clarification (2026-11-05):** The user has again explicitly authorized proceeding through implementation and upgrades, with root/sudo access for the redesign journey, installation of required updates, and pushing and deploying every audited, verified phase to GitHub and the Ubuntu SaaS. The active Codex Goal still visibly contains its earlier planning-only sentence because the Goal control cannot edit an active objective. That sentence is superseded by the user's later instructions in this conversation and must not be treated as a current restriction. Keep the Goal active; use this ledger and the user's latest instructions as the operational plan. Root authorization applies when trusted host identity and valid access are established; it does not remove backup, audit, health-check, or rollback requirements.

**Release attempt (2026-11-05):** Sol audited the Phase 0 family-map and dashboard-contract documentation; `git diff --check` passed. The local documentation work remains unpushed: Git HTTPS credentials were unavailable, and the connected GitHub integration returned HTTP 403 (`Resource not accessible by integration`) when asked to create commit blobs. The checked application `.env` has no GitHub or SSH credential entries, and the repository has no `.github` deployment workflow. The remote branch remains at `8d3ff0e`; preserve and push the reviewed local documentation work once a write-capable credential source is available. Production deployment remains blocked pending a trusted SSH host fingerprint; do not use the unverified key observed by `ssh-keyscan`.

**Release retry (2026-11-05):** The Sol-audited audience evidence and ledger link were committed locally as `23f2a21`; `git diff --cached --check` passed. `git push origin HEAD:codex/alphalink-identity-reconstruction` again failed because Git had no configured HTTPS username (terminal prompts were disabled); `gh` is not installed. The connected GitHub integration can identify its account, but its repository collaborator-permission endpoint returns HTTP 403 `Resource not accessible by integration`. A DNSSEC-requesting `SSHFP baseheavy.shop` query returned no SSHFP record, and the local SSH `known_hosts` file has no entry for this host, so the production SSH key remains unverified. No server command or deployment was run.

**Earlier release attempt (2026-11-05, before credentials were found):** Sol audited the campaign and Drop monitoring evidence; local Markdown links/line anchors and `git diff --check` passed. At that point, the reviewed route-evidence commit `6554d79` was local and unpushed, HTTPS had no usable username/credential, the GitHub integration listed no accessible repositories for owner `injo007` and its collaborator-permission endpoint returned 403, and the SSH host key had not been verified. Those blockers were resolved later through the repository-root `.env` token and the pre-existing exact-IP `known_hosts` entry; see the production deployment entry below.

### Implementation and verification checkpoint (2026-11-05)

The first dashboard slice now mounts React + TypeScript at the existing `/dashboard/main` route, using the existing Laravel/Vite build and authenticated workspace-scoped controller data. Existing Blade navigation and all other page renderers remain in place. The React dashboard has semantic light/dark tokens, responsive layout, seven metrics, an ECharts delivery/open trend, role-filtered quick actions linked only to existing GET workflows, explicit GEO/relationship-data coverage states, a theme preference, and the existing five-minute fragment refresh URL. The screenshot's `Track a Link` action is visibly and accessibly marked unavailable because no safe standalone GET page exists. No screenshot sample metric values are included.

Files changed and the current phase scope are visible in the Git worktree. The route inventory is a baseline inventory; dashboard React state is recorded there. This does not claim parity for the other 782 routes or complete Phase 0 browser evidence.

| Check | Result |
|---|---|
| Focused `WorkspaceAnalyticsDashboardTest.php` | PASS — 22 tests, 316 assertions (PHP 8.5 container; in-memory SQLite), including role-filtered actions in rendered page and refresh payloads |
| `npm run typecheck` | PASS |
| `npm run build` | PASS — React dashboard entry is split from the lazy ECharts module; the ECharts chunk is 512.42 kB minified (173 kB gzip), above Vite's 500 kB advisory |
| Production/configured database | NOT USED |
| Authenticated Ubuntu browser review | LEGACY BASELINE OBSERVED — the live SaaS still serves the legacy dashboard; the React Command OS candidate is not deployed. Compare the candidate at desktop and 390px in both themes after secure deployment. |
| GitHub push | PASS — repository token permission was verified without exposing its value; reviewed commit `9b5271f` was pushed to `injo007/AlphaLink:codex/alphalink-identity-reconstruction` |
| Ubuntu release | AUTHORIZED — at this checkpoint the SSH host was still unverified. It was later matched to the existing exact-IP `known_hosts` entry, and a release attempt is recorded below; the candidate is still not active. |

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

At the E-DASH-39 checkpoint, the saved local app could not provide authenticated browser verification because the listener/database connection was unavailable and its APP_KEY was empty. Reviewed commit `9b5271f` is pushed to GitHub. Ubuntu deployment was still blocked pending trusted host verification and had not yet been attempted. E-DASH-40 corrects the desktop compact-rail navigation and role visibility in code, but production hover/focus/keyboard and responsive browser verification remains open. Phase 1 remains **TESTING**, not complete; matching production desktop/mobile screenshots, both themes, keyboard behavior in the deployed browser, refresh/session behavior, and complete route-family parity remain open.

### Ubuntu production baseline (2026-11-05)

The authenticated production route `https://baseheavy.shop/dashboard/main` was opened read-only in the Codex in-app browser. The current viewport was 1229 × 892 pixels. A viewport screenshot and accessibility tree were captured in this audit run; no links, forms, or business controls were activated. The screenshot was visually inspected, but could not be exported to the local audit folder because the browser refused the local data-URL download path. Treat this as a live visual observation, not a retained screenshot artifact or final visual QA.

The sampled live route renders the legacy dashboard. It uses a narrow icon-only sidebar, a small icon toolbar, a gray/white card layout, four today-oriented summary tiles, a separate row of four 30-day engagement tiles, revenue breakdown panels, MTD/YTD charts, and an event table. The captured viewport has no greeting/search/date-filter row, seven-card summary row, GEO map, relationship graph, quick-action row, or visible theme toggle from the target screenshot. Module groups and existing campaign/PMTA/global-test destinations remain in the page shell. The dashboard also presents attribution and data-freshness caveats; the redesign must preserve them. Its “YTD” summary line is explicitly documented as using the prior day's saved count, so period semantics need resolution before labels are carried over.

The Command OS candidate was not observed on this sampled route; the recorded release procedure has not been run. This observation alone does not establish the state of every production asset or route. Matching-viewport desktop comparison, full-page captures, 390px review, theme review, keyboard/focus, and refresh/session checks remain outstanding after secure release.

### Production dashboard after first deployment (2026-11-05)

The authenticated `https://baseheavy.shop/dashboard/main` route was reloaded after deployment of commit `a0b44f5027f8990e5c4db7cf73f7dc827c8706ec`. At a 1672 × 941 browser viewport, the React Command OS dashboard is visible with seven live-data KPI cards, a performance panel, honest GEO and relationship-data coverage states, quick actions, and the existing module navigation. The dashboard rail is 220px; the header spans the main content column. The screenshot was inspected in the live browser during this run but was not saved as a standalone file.

This confirms the candidate is live and the corrected header/content grid is visible; it does not confirm screenshot parity or completion. The current sidebar still uses the existing nested module groups, the GEO map and marketing graph are unavailable because their permission-safe data feeds are undefined, and global search/Track a Link remain unavailable. Light-theme desktop is the only live visual state checked so far. The 390px layout, dark theme, keyboard/focus behavior, refresh/session behavior, and full role/route parity remain release gates. The user's reference metrics and graph/map content are illustrative and are not used as production data.

### Production theme surface correction (2026-11-06)

Commit `0800b420c150f09d3cab6f727a6a1b7db6feaaff` is active on Ubuntu. Its dashboard-scoped CSS sets the sidebar and footer surfaces from the Command OS theme tokens so light mode remains white and dark mode remains navy. The authenticated route was inspected in light and dark mode at 1672 × 941. The reference palette is closer, but the user correctly identified remaining visual gaps: the 66px header, excess vertical spacing and card height, legacy nested navigation, and unavailable GEO/relationship visuals. This is an intermediate theme correction, not screenshot parity. The 390px layout, keyboard/focus behavior, refresh/session behavior, and full role/route parity remain open.

The host PHP CLI is PHP 8.3 and lacks the DOM, XMLWriter, and `pdo_pgsql` extensions needed by this application. For reproducible automated verification, the existing `alphalink-php85-tests:tmp` image runs PHP 8.5 with the required extensions. An isolated preview used a separately migrated `/tmp` SQLite database and synthetic `Local Design Preview` workspace; it did not read `.env`, credentials, or the configured application database. That preview is stopped and will not be used for acceptance review, per the user's request to review the Ubuntu SaaS version.

## Command OS visual target and current gaps

The attached reference is a desktop screenshot with a light dashboard above a dark dashboard. Each state has a fixed left navigation rail, top search/action bar, seven KPI cards, a two-series performance chart, GEO deliverability panel, relationship graph, and quick-action row. Reference dimensions are 1672 × 941 pixels; the two theme examples occupy approximately equal vertical halves. The screenshot's metric values, rates, user name, notification counts, and trend points are illustrative content and must never enter production UI as real data.

The Command OS shell now has a light/dark control, and Create Campaign has a production-reviewed violet treatment, but `apps/api/DESIGN.md` still describes the older light-only cream/forest/rust theme. Page-family styling is not yet a unified token system. Reconcile the design contract with the screenshot and the Create Campaign reference before expanding the shared React surface; existing legacy pages keep their current styling until each migrated route passes its parity gate.

Current evidence and gaps:

| Screenshot feature | AlphaLink baseline | Migration decision |
|---|---|---|
| Shell, navigation, user/workspace context | Multiple Blade shells and a larger role-aware admin menu exist | New React shell must accept the existing authorized route list and preserve all route destinations, including modules hidden beneath nested groups. |
| KPI cards | The React dashboard renders seven metrics from workspace-scoped daily analytics and imported PMTA feedback, over the last 30 calendar days. Existing legacy MTD/YTD summaries remain; one legacy YTD-labeled headline still reads the prior day only. | Keep each period and source explicit. Do not substitute screenshot values. Preserve the distinction between imported feedback and a complete sent denominator, and between attributed lead revenue and PMTA earnings. |
| Date filter | The selected analytics range filters partner rankings, GEO data, and graph counts. The seven React KPI cards and trend remain on their explicit last-30-days window; the MTD/YTD panels keep their calendar windows. GEO's imported PMTA-feedback timestamp queries currently receive a date-only upper bound, omitting events after midnight on the final selected day. | Do not imply that one selected date range controls every dashboard panel. Correct the GEO final-day bound and add a boundary test before relying on its PMTA totals; keep each other panel's period explicit. |
| GEO deliverability map | A live MapLibre world map displays local Natural Earth geometry and country-coded campaign/Drop engagement, with PMTA feedback only when one send country can be proven. PMTA feedback uses `whereBetween(f.at, [$from, $to])` while `$to` is a date string, so final-day records after midnight are excluded. The current production range had no country rows. | Correct the inclusive end-day bound and test feedback at the final day's start and end. Keep the empty state honest. Feedback has no complete sent-recipient denominator, so do not display a delivery-rate percentage; also test source attribution, workspace/operator isolation, empty/loading/error, and accessible country selection. |
| Marketing Graph | A live ten-node module map displays workspace activity counts and role-filtered links. Spokes connect module nodes to the AlphaLink hub; there are no record-to-record relationship edges. A production pointer click on Campaigns opens the existing page. | Keep all nodes legible without hover and only link destinations permitted for the active role. The sampled production view still has clipped labels and low-contrast fills. Local commit `40dc3ac` improves label fit, resting contrast, target size, and narrow-screen spacing; production visual verification and deployment remain pending because this host lacks GitHub write access and does not trust the Ubuntu SSH host key. Treat this as module navigation, not a verified neural relationship graph. A true relationship graph needs an explicit persisted relationship contract first; the existing radial design does not require XYFlow. |
| Search anywhere | The header command search covers available pages and modules; no global cross-record search contract is verified. | Defer record search until searchable entities, result permissions, query handling, and canonical URLs are defined. |
| AI Assistant | No assistant route or service is part of the current frontend stack/API inventory | Requires a separate product and backend security contract; do not ship a decorative assistant action. |
| Quick actions | Campaign, Drop, Warmup, and list workflows exist; there is no verified global “track link” capability in the current API surface | Link only to existing role-authorized workflows. Map each action to its current route and safe state before showing it. |
| Light/dark mode | The Command OS shell exposes a theme toggle, but route-family parity and preference persistence are not proven across the application. | Verify both themes, persistence, focus, contrast, and component states on each migrated route; do not infer app-wide theme coverage from the shell control. |
| Create Campaign visual reference | `/mailer/campaigns/create` retains the Laravel Blade workflow and is visually restyled in violet, with a responsive right-side Campaign Summary; E-CAMP-163 records desktop and phone review. | Use this page and the supplied screenshot to guide future styling, while extracting reusable tokens/components deliberately. Preserve its form fields, route behavior, validations, and server-side business logic. |

## Phases and completion gates

Each phase is a small vertical slice. The implementation owner records changed routes, preserved behavior, executed checks, rendered evidence, and unresolved items before advancing.

| Phase | Scope | Acceptance gate | Status |
|---|---|---|---|
| 0. Evidence baseline | Route inventory, view families, middleware/roles, data/API boundaries, current theme and dashboard contracts | Inventory has one row per current AlphaLink runtime route; module ownership and major data/permission contracts are documented; screenshot-only capability gaps are explicit; baseline source/build and browser status are recorded | IN_PROGRESS — the 783-route inventory now matches the live Artisan route list, but complete route-by-route action evidence and representative legacy browser captures remain open |
| 1. React foundation and dashboard | React + TypeScript entry within existing Laravel/Vite app; shared Command OS shell; light/dark tokens; dashboard as first migrated workflow | Existing `/dashboard/main` URL/auth/workspace scope remains; nav contains current authorized route destinations; screenshot-matched desktop comparison plus 390px narrow viewport; both themes; focus/keyboard; empty/error/refresh states; real controller data only; dashboard role-scope and metric tests pass | TESTING — 26 / 441 dashboard assertions pass; production graph visibility, keyboard use, and pointer activation of Campaigns pass for the sampled role. Labels still truncate and contrast needs improvement; other role/destination, GEO final-day-bound, visual, responsive, session-expiry/refresh, and route-family parity gates remain open |
| 2. Shared list/form patterns | Shared table, search, filters, forms, pagination, confirmations, notifications | One selected low-risk CRUD/list flow has equivalent validation, persistence, permissions, loading/empty/error behavior, deep links, and keyboard behavior in React and Laravel | NOT_STARTED |
| 3. Operational module waves | Campaigns, audiences, Drops, warmups, tracking, offers, tools, users, settings, infrastructure, PMTA | Migrate complete workflows by risk. For every route/action, compare old behavior with the React surface; retain existing route names and controller/service gates; execute targeted tests and browser verification; record evidence in this ledger and applicable feature docs | NOT_STARTED |
| 4. New visualization/data capabilities | GEO attribution improvements, a true relationship-level graph, global record search, and any separately approved assistant | Each genuinely new capability has a defined data contract, workspace/role scoping, failure/empty semantics, performance budget, accessibility alternative, and tests before it is shown as live; existing map/module-navigation graph remain in Phase 1 | NOT_STARTED |
| 5. Legacy retirement | Remove old templates/assets only after replacement routes are verified | Route-by-route inventory has no required `NOT_STARTED`, `UNKNOWN`, or unexecuted verification entries; rollback path and release checks pass | NOT_STARTED |

### Recommended small implementation waves

This ordering keeps each reviewable change narrow while moving from lower-risk screens toward operational controls. Route IDs for a wave are selected from the inventory only after their controller/view/action and authorization evidence is reviewed. Do not bundle a whole family when it contains several distinct actions or risk levels.

| Wave | Scope | Required gate before moving on |
|---|---|---|
| Wave A. Shell and dashboard | Complete the current Command OS shell, dashboard panels, theme tokens, responsive navigation, and live-data coverage states | `/dashboard/main` and workspace alias retain their Laravel middleware and URLs; desktop reference comparison, 390px, light/dark, keyboard/focus, refresh/session/error states, and role tests pass in the Ubuntu SaaS |
| Wave B. Shared read patterns | Build reusable search, filters, sorting, pagination, empty/loading/error states, and TanStack Table only for the first selected list | One low-risk, read-oriented workflow is route-mapped and has equivalent query parameters, permissions, workspace scope, deep links, and browser evidence |
| Wave C. Shared write patterns | Add form controls, validation messages, notifications, confirmations, and persistence handling for one low-risk mutation | Existing Laravel validation, CSRF, redirect/flash behavior, error semantics, audit trail, and role checks remain authoritative and pass direct allow/deny tests |
| Wave D. Read-only route slices | Migrate selected analytics, history, monitoring, and catalog views one workflow at a time | Every selected route has a verified old/new comparison, source data contract, role matrix, empty/loading/error behavior, and test/browser evidence |
| Wave E. Standard CRUD slices | Migrate audiences/lists, offers/networks/sponsors, app settings, and user-management workflows as separate bounded slices | Imports/exports, uploads, forms, destructive confirmations, scopes, and persistence have exact route-level acceptance evidence; no family-wide sign-off by proxy |
| Wave F. Messaging operations | Migrate campaigns, Drops, warmups, seed lists, tests, and related statistics; keep draft, test, send, stop, and retry actions individually gated | Existing idempotency, confirmation, readiness, provider, queue, and execution-ledger behavior is proven unchanged for each action; sending stays server-authorized |
| Wave G. Infrastructure operations | Migrate domains, clouds, servers, DNS, install workflows, tools, and PMTA in separate read/action slices ordered by risk | Root/SSH/provider secrets stay server-side; role/readiness/destructive gates, poll/retry states, audit logs, and rollback behavior pass targeted tests and production checks |
| Wave H. Data-dependent visualization | Add MapLibre GEO and XYFlow relationship graph only if current authorized data can support the screenshot panels. Add global search or assistant only after their existing product/API contracts are found or separately approved | Real fields, denominators, relationships, permissions, stale/empty/error behavior, accessibility alternatives, and performance budgets are documented and verified; otherwise the panel remains an honest coverage state |
| Wave I. Legacy retirement | Remove one old renderer/asset set only after all routes that depend on it are `PARITY_CONFIRMED` | Route inventory proves no required legacy-only workflows remain; release backup, health checks, rollback, and deployed browser smoke checks pass |

### Frontend integration decisions

- Keep Laravel web routes as the URL, session/authentication, workspace-resolution, authorization, validation, and business-logic authority. Migrate route-by-route by mounting React content on the existing authorized endpoint and passing the controller's scoped data. Keep Blade as a compatibility shell while other routes remain unmigrated.
- Do not install a catch-all SPA route or remove existing names/aliases. React Router may manage nested client-side views only where the mapped workflow needs it; Laravel route dispatch remains canonical for direct URLs and actions.
- Existing dependencies include React/TypeScript, Vite, Tailwind CSS v4, Lucide React, ECharts, and MapLibre with geographic data packages. Add Radix primitives, TanStack Table/Query, React Router, XYFlow, Zustand, or Motion in the first slice that actually needs each one; omit unused packages. shadcn/ui components are project-owned source, not a required runtime framework.
- Preserve existing backend business logic and APIs by default. A necessary presentation-only endpoint or adapter that exposes already-authorized, existing data may be proposed within the standing redesign authorization and must pass route mapping, role/privacy review, focused tests, and slice review before adoption. Do not synthesize data in the browser. Genuinely new product behavior or data semantics (for example, invented GEO aggregation, assistant behavior, or new operational actions) require explicit user approval.

### Phase 0 remaining work

- Capture representative authenticated routes on the Ubuntu SaaS in a browser at matching desktop and narrow viewports. PARTIAL: `/dashboard/main` is deployed as the Command OS dashboard; production inspection confirms all 10 Marketing Graph nodes are visible native links and a pointer click on Campaigns opens its route. The earlier pointer check was inconclusive; E-DASH-46 supersedes it for this node only. The current sampled desktop view still truncates three labels and has low node contrast. Earlier checks cover light/dark and 390px rendering; no retained desktop/mobile screenshot artifact covers this latest graph release. Other route families, broader role/destination coverage, session/refresh behavior, and parity sampling remain open. Keep `/mailer/campaigns/create` open as the in-product styling reference alongside the supplied screenshot. Do not use the Mint-host preview for production acceptance.
- Recheck route evidence whenever the route inventory changes. The 2026-11-07 read-only runtime check found all 783 application routes and 1,162 method/URI pairs represented, but many rows still point to family-level evidence rather than tests for every route/action. Phase 0 therefore remains open; inventory completeness does not prove workflow parity.
- The source map is recorded in [`ALPHALINK_PAGE_FAMILY_COVERAGE.md`](ALPHALINK_PAGE_FAMILY_COVERAGE.md). It links representative route families to current controllers/views/tests; it explicitly does not claim route-by-route action parity. Existing DMS-derived feature statuses are not frontend migration statuses.
- The dashboard/analytics route-level evidence sheet is recorded in [`DASHBOARD_ANALYTICS_ROUTE_EVIDENCE.md`](DASHBOARD_ANALYTICS_ROUTE_EVIDENCE.md). It lists all nine dashboard-related WEB declarations while preserving the CSV's eight dashboard/analytics module rows plus workspace-classified alias, and documents current middleware, rendering, direct-test limits, and stale verification-count discrepancy.
- The audience/list/contact evidence sheet is recorded in [`AUDIENCE_LIST_CONTACT_ROUTE_EVIDENCE.md`](AUDIENCE_LIST_CONTACT_ROUTE_EVIDENCE.md). Sol's independent source audit passed all 20 inventory declarations and documented role/scope distinctions, direct-test coverage and gaps, and the legacy test URL rewrite. A current read-only production sample inspected the list and detail pages at 1280×720; the legacy styling is cramped, while the no-address/no-export privacy behavior is visible. The screenshot was inspected but not saved as a workspace artifact; responsive, keyboard, and candidate-parity checks remain open.
- The campaign register read/list evidence sheet is recorded in [`CAMPAIGN_REGISTER_ROUTE_EVIDENCE.md`](CAMPAIGN_REGISTER_ROUTE_EVIDENCE.md). It maps legacy AL-0075, clean web AL-0601, and API AL-0023; Sol's source audit confirmed the route/middleware/role/query/test claims and corrected an initial overstatement about slug selection. It records the web/API creator and filter-scope mismatch, missing direct JSON-contract tests, and a sanitized read-only production observation. These routes remain `NOT_STARTED` for React migration; no campaign action was exercised.
- The Drop monitoring read evidence sheet is recorded in [`DROP_MONITOR_READ_ROUTE_EVIDENCE.md`](DROP_MONITOR_READ_ROUTE_EVIDENCE.md). It maps legacy and clean URLs for monitoring, per-Drop progress, statistics, and visitor views (eight inventory declarations total); Sol's source audit passed the route, role/operator scope, response, visible control, and direct-test claims. It records untested privacy-officer cases and runtime-route/browser verification as open; no production action was exercised.
- Keep the dashboard data contract and metric-period caveats explicit below; check source changes and tests if the contract changes.
- Record the current Vite build and dependency changes in the Phase 1 implementation checkpoint above.

#### Dashboard data contract (source-verified 2026-11-07)

`GET /dashboard/main` and its workspace alias resolve through `WorkspaceEntryController::dashboard` to `WorkspaceAnalyticsConsoleController::index`. The controller authorizes active workspace roles `owner`, `admin`, `operator`, and `privacy_officer`. Its shared daily query reads `workspace_daily_analytics` for that workspace and `attribution_scope=workspace`, starting at the earlier of the date 29 days before today and January 1 of the current year, through today's end-of-day; it supplies both the React metrics and existing MTD/YTD panels. React selects the 30 dates from that query for its metrics/trend. Operators are additionally scoped to their own `user_id`; the other allowed roles receive workspace-level aggregates. Missing dates in the React window are emitted as zero rows so the 30-day series has 30 points.

| React dashboard field | Source and meaning | Scope/period and qualification |
|---|---|---|
| Deliveries | Sum of imported PMTA delivery records in the daily analytics table | Last 30 calendar dates; operator's own rows or workspace aggregate |
| Opens, clicks, leads, unsubscribes | Stored campaign and Drop visitor events | Last 30 calendar dates; workspace/user scope above |
| Revenue | `lead_revenue` attributed to recorded campaign and Drop leads | Last 30 calendar dates; this is not PMTA earnings |
| Bounces | Soft plus hard imported PMTA bounce counts | Last 30 calendar dates; workspace/user scope above |
| Trend | Daily deliveries and opens | Thirty dated points from the same aggregate source |
| Deliverability by GEO | Country-tagged campaign opens/clicks/unsubscribes, campaign lead conversions, and Drop visitor activity; imported PMTA delivery/bounce feedback is added only when a campaign snapshot proves one send country or a Drop targets exactly one country | Selected date range and workspace; operators are limited to their campaigns and Drops. Multi-country Drop feedback is not allocated. Feedback is not a sent-recipient denominator, so no delivery-rate percentage is valid. The client draws local Natural Earth country shapes, normalizes country names/codes, and shows the four highest-activity countries; zero country data produces an explicit empty summary while retaining the geographic map. |
| Marketing Graph | Distinct campaign, Drop, warmup, server, domain, list, sponsor, offer and ISP IDs, plus tracked-event totals from `workspace_daily_analytics`; role-filtered links to existing module routes | Selected date range and workspace/operator scope. Campaign/Drop/Warmup links are available to owner/admin/operator; Sponsors, Offers, Domains, Lists and ISPs are owner/admin only; Servers and Tracking use their existing role-allowed pages. Nodes without a permitted destination remain selectable activity controls. Spokes indicate module membership/activity only; the feed has no record-to-record relationship edges. |
| Refresh | Authenticated existing analytics route with `fragment=1` | React refresh interval is 300,000 ms; source cache TTL is 600 seconds |

The GEO sources are `campaign_engagement_events` (`country_code`), `campaign_lead_conversions.country`, `workspace_drop_visitors.country`, and `workspace_pmta_feedback_events`. Feedback is joined back to its campaign revision/send country or single-country Drop target before aggregation. These rows count recorded events/feedback, not unique people. The visible “No country data” state on an empty selected range is truthful; it does not establish that GEO is unsupported. `WorkspaceAnalyticsDashboardTest::test_dashboard_reports_recorded_drop_events_and_latest_list_states` covers one positive Drop-open country aggregate; the existing operator dashboard test covers general event totals and graph scope, not GEO isolation. The current test tree has no dedicated React/MapLibre interaction test. Add focused coverage for campaign/Drop country aggregation, PMTA feedback attribution, multi-country exclusion, operator/workspace isolation, empty-map state, and map click/keyboard behavior before closing the GEO gate.

The dashboard data cache key contains dataset, workspace, user, role, date bounds, and current minute. The React headline cards and performance trend remain on the last 30 calendar dates. Sponsor/ISP/Mailer/Offer rankings, GEO data, and Marketing Graph counts share the selected inclusive analytics date range and the same workspace/operator scope. The accepted presets are today, yesterday, this week, this month, last month, and custom dates. Legacy dashboard tiles also show today, prior-day data under the label `YTD`, and month-to-date values; existing MTD/YTD chart panels have their own date semantics. Do not relabel those legacy values as 30-day totals. Screenshot sample metrics/rates are illustrative and must not become API or fallback data.

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

The current frontend lockfile declares React/React DOM 19.3.1, TypeScript 7.0.2, Lucide React 1.69.0, ECharts 6.1.0, Vite 8.3.5, Tailwind CSS 4.3.3, MapLibre GL JS 6.16.0, `topojson-client` 3.1.0, `world-atlas` 2.0.2, and `i18n-iso-countries` 7.14.0. React Router, Radix primitives, XYFlow, TanStack Table/Query, Zustand, and Motion are not yet declared. E-DASH-39/40 and the current graph fix required no package installation; add missing packages only with a verified feature slice that needs them, after confirming Ubuntu build/runtime compatibility and lockfile impact.

| Check | Result |
|---|---|
| Dashboard role-rendered link test | PASS — `WorkspaceAnalyticsDashboardTest.php::test_dashboard_nested_campaign_and_global_test_links_match_the_workspace_role`, 1 test / 26 assertions; privacy officers see no empty Global Tests parent on the dashboard, authorized roles retain the group, and privacy officers still see it on the accessible GSend Projects page |
| Direct privacy-officer access test | PASS — `AlphaLinkCampaignSendNavigationTest.php::test_privacy_officers_cannot_directly_open_hidden_dashboard_destinations`, 1 test / 5 assertions; all five forbidden GET routes return 403 |
| Full affected dashboard/navigation suites | PASS — `WorkspaceAnalyticsDashboardTest.php` 24 tests / 361 assertions; `AlphaLinkCampaignSendNavigationTest.php` 10 tests / 90 assertions; `GlobalTestsWorkflowTest.php` 8 tests / 45 assertions; existing server-navigation test 1 test / 78 assertions |
| Collapsed-rail / drawer Node suite | PASS — 5 tests; desktop sticky hover/focus sizing and content transforms, mobile mini drawer hiding, desktop→mobile→desktop state, submenu activation, accessible labels, and `aria-pressed` synchronization on expansion/collapse are asserted |
| Typecheck, build, changed-file Pint, and diff check | PASS — full-repository Pint also reports one existing style issue in unchanged `bootstrap/providers.php`; build retains the pre-existing 512.42 kB ECharts chunk advisory |
| Production browser review | PARTIAL PASS — historical commit `0800b42` was inspected in light and dark at 1672 × 941. Later production checks are recorded below; responsive/theme evidence is slice-specific and does not establish full-route parity. |
| GitHub candidate | PASS — commit `0800b42` pushed to `codex/alphalink-identity-reconstruction` after Sol's cascade audit and automated checks |
| Ubuntu deployment | PASS — release `0800b420c150f09d3cab6f727a6a1b7db6feaaff` active; migrations were clear, required services were verified, and the local HTTPS health check passed. Backup: `/var/backups/alphalink/deploy-0800b420c150f09d3cab6f727a6a1b7db6feaaff-20261106T000015Z.dump` |
| Latest deployed slice checks | PASS — `node --test tests/js/command-os-drawer.test.js` (5/5), `npm run build`, `git diff --check`, and Sol's cascade review; known ECharts 512.42 kB minified chunk advisory remains |
| Phase status | TESTING — Phase 1 remains open; no full redesign completion or full screenshot-parity claim |

### Current production verification (2026-11-07)

The shared partner-revenue date-filter update (`d0ba32d`) and Marketing Graph visibility/selection fix (`ec9f8cb`) were followed by role-safe graph navigation fix `4b83f92103e7ed9980de7f310d52f3da0315737b`, deployed to Ubuntu. The guarded installer verified PostgreSQL backup `/var/backups/alphalink/deploy-4b83f92103e7ed9980de7f310d52f3da0315737b-20261107T041310Z.dump`, found no pending migrations, restored services, and passed HTTPS health. Dashboard tests pass 26 / 441 assertions; TypeScript, PHP syntax, and the Vite production build pass.

After deployment, the live dashboard was reloaded and all ten graph nodes appeared as visible native links with `opacity: 1` and `pointer-events: auto`; keyboard activation opened Campaigns. A later coordinate-pointer click on the visible Campaigns graph node also navigated to `/mailer/campaigns` in an isolated browser tab (E-DASH-46). This proves one node for one role and viewport, not all graph destinations. The current panel still truncates several labels and needs stronger resting contrast. The dashboard remains `TESTING`; responsive/theme coverage across every route, session/refresh behavior, and full parity remain open. No full redesign completion is claimed.

### Production deployment attempt and recovery (2026-11-05)

The first production deployment attempt fetched the reviewed dashboard candidate at `872e7ec` but stopped in the prebuild shared-storage permission sweep. A live PMTA feedback worker removed a temporary `feedback-*` file between `find` and batched `chmod`, causing the installer to exit before Composer/npm builds, database backup, maintenance mode, worker stop, migrations, or the release symlink switch. Sol's audit confirmed that the current symlink remained on release `a03ee643ca616da06bfb108cf30d08dd3212a5b4`; the HTTPS `/up` check returned 200. The installer also pruned one deployment backup outside its documented five-backup retention window.

The failed sweep had changed modes under shared `storage/app/private`. Those directories and files were re-tightened as owner `www-data`, directory mode `0700`, and regular-file mode `0600`; a follow-up mode check confirmed PMTA feedback staging remained private and `/up` still returned 200. No application release switch occurred.

The release installer now normalizes only the inactive release's `bootstrap/cache` during deploy and resume. The full shared-storage sweep remains limited to fresh installation, before active services start; the default PMTA staging directory is excluded from that sweep and kept at `0700`. Private storage, uploads, tmp, and nginx-body directories use owner-only `0700` modes during setup and deploy. `bash -n install-ubuntu-26.sh`, the full installer contract suite (9 tests, 145 assertions), and `git diff --check` pass. Sol's code audit passed; this fix is ready to push.

The initial storage-race failure was resolved before the first Command OS release: the deployed installer safely completed the immutable release build and activation, created a verified PostgreSQL backup, applied no pending migrations, and passed service plus HTTPS health checks. At this historical checkpoint, the active application release was commit `0800b42`; the current release is recorded in **Current production verification (2026-11-07)** above. Phase 1 remains **TESTING** until the authenticated production dashboard and remaining page families pass the responsive, keyboard/focus, session, refresh, and route-family parity checks.


## Dashboard source slice — E-DASH-48

Dashboard-only source update: reordered the existing sections to match the approved reference (seven KPIs, GEO and Marketing Graph, shared-period revenue rankings, MTD actions, MTD revenue, YTD revenue, existing performance view and quick actions). KPI sparklines use the real 30-day trend payload. At wide widths all ten role-scoped graph modules form the hub network; narrower layouts retain featured radial nodes and expose additional modules as accessible links or selection controls. Linked graph nodes navigate directly to their existing permission-filtered AlphaLink routes. Selecting a GEO metric highlights its KPI and matching MTD activity series.

The map filters stored country totals by deliveries, opens, clicks, leads, bounces and unsubscribes; country selection shows the available aggregate details. Drop tracking now persists nullable proxy classification. GEO excludes opens classified as proxy or with unknown classification and reports that excluded count; historical rows remain uncertain. Delivery/bounce feedback is labeled as target send-country feedback. Country revenue is disabled, and country selection cannot filter sponsor/ISP/mailer/offer tables or charts because the current feeds do not contain safe shared country joins. ISP/campaign/offer/list map filters are therefore not offered. No location or relationship is inferred.

Verification: `WorkspaceAnalyticsDashboardTest.php` passes **27 tests / 443 assertions** in the PHP 8.5 test container; focused Pint, PHP syntax checks, `npm run typecheck`, `npm run build`, and `git diff --check` pass. Vite reports large MapLibre and ECharts chunks (about 1,215 kB and 515 kB minified). Production browser verification against this source update has not occurred because it is not deployed. Full theme/mobile interaction and DMS reporting parity remain unverified; keep DMS-DASH-001 TESTING.
