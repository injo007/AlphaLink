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

The user's latest reaffirmation (2026-11-05) explicitly authorizes the full redesign and upgrade journey, grants root/sudo authority for the work on the Ubuntu SaaS when available, and authorizes installation and updating of the approved frontend stack and required application/system packages, pushing every audited and verified phase to GitHub, and deploying each verified phase to the Ubuntu AlphaLink SaaS. Continue this release path throughout the journey without asking for the same authorization again. This supersedes the earlier planning-only clause in the active Codex Goal. This is scope authorization, not evidence that remote root credentials, an SSH identity, or any other operational prerequisite is currently available; verify those before use.

For every phase, finish its implementation, audit, automated checks, and release artifact before pushing the reviewed branch; then deploy that verified phase through the Ubuntu release path and verify it in the real SaaS before signing off. Preserve the release procedure's backup, health-check, and rollback gates, and never edit the active server checkout in place. Install the required versions and updates for the approved frontend stack and application compatibility, after confirming the existing PHP/Node/application versions and Ubuntu deployment method. Assess broader operating-system updates against production service dependencies and the release plan before applying them. The production SSH host identity must be verified against a trusted source before running privileged server commands. Do not access or modify the separate historical DMS service, and never include credential values from local or server environments in commits, logs, or reports.

The active system Goal cannot be edited through the available goal controls while it is unfinished. Do not mark it complete or replace it to work around that limitation. This migration ledger is the controlling project record for the user's updated authorization, active phase, and release gates; the Codex Goal remains active until the redesign and its completion criteria are actually finished.

**Goal-control clarification (2026-11-05):** The user has again explicitly authorized proceeding through implementation and upgrades, with root/sudo access for the redesign journey, installation of required updates, and pushing and deploying every audited, verified phase to GitHub and the Ubuntu SaaS. The active Codex Goal still visibly contains its earlier planning-only sentence because the Goal control cannot edit an active objective. That sentence is superseded by the user's later instructions in this conversation and must not be treated as a current restriction. Keep the Goal active; use this ledger and the user's latest instructions as the operational plan. Root authorization applies when trusted host identity and valid access are established; it does not remove backup, audit, health-check, or rollback requirements.

**Release attempt (2026-11-05):** Sol audited the Phase 0 family-map and dashboard-contract documentation; `git diff --check` passed. The local documentation work remains unpushed: Git HTTPS credentials were unavailable, and the connected GitHub integration returned HTTP 403 (`Resource not accessible by integration`) when asked to create commit blobs. The checked application `.env` has no GitHub or SSH credential entries, and the repository has no `.github` deployment workflow. The remote branch remains at `8d3ff0e`; preserve and push the reviewed local documentation work once a write-capable credential source is available. Production deployment remains blocked pending a trusted SSH host fingerprint; do not use the unverified key observed by `ssh-keyscan`.

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

The sampled live route renders the legacy dashboard. It uses a narrow icon-only sidebar, a small icon toolbar, a gray/white card layout, four today-oriented summary tiles, a separate row of four 30-day engagement tiles, revenue breakdown panels, MTD/YTD charts, and an event table. The captured viewport has no greeting/search/date-filter row, seven-card summary row, GEO map, relationship graph, quick-action row, or visible theme toggle from the target screenshot. Module groups and existing campaign/PMTA/global-test destinations remain in the page shell. The dashboard also presents attribution and data-freshness caveats; the redesign must preserve them. Its “YTD” summary line is explicitly documented as using the prior day's saved count, so period semantics need resolution before labels are carried over.

The Command OS candidate was not observed on this sampled route; the recorded release procedure has not been run. This observation alone does not establish the state of every production asset or route. Matching-viewport desktop comparison, full-page captures, 390px review, theme review, keyboard/focus, and refresh/session checks remain outstanding after secure release.

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
- Existing dependencies include React/TypeScript, Vite, Tailwind CSS v4, Lucide React, and ECharts. Add Radix primitives, TanStack Table/Query, React Router, XYFlow, MapLibre, Zustand, or Motion in the first slice that actually needs each one; omit unused packages. shadcn/ui components are project-owned source, not a required runtime framework.
- Preserve existing backend business logic and APIs by default. A necessary presentation-only endpoint or adapter that exposes already-authorized, existing data may be proposed within the standing redesign authorization and must pass route mapping, role/privacy review, focused tests, and slice review before adoption. Do not synthesize data in the browser. Genuinely new product behavior or data semantics (for example, invented GEO aggregation, assistant behavior, or new operational actions) require explicit user approval.

### Phase 0 remaining work

- Capture representative current authenticated routes on the Ubuntu SaaS in a browser at matching desktop and narrow viewports. PARTIAL: `/dashboard/main` was observed read-only at 1229 × 892 and visually inspected; the screenshot exists only in the current audit session, not as a saved evidence artifact. The live route still uses the legacy layout, so candidate comparison has not happened. Other routes plus 1672 × 941 and 390px captures remain. The user screenshot is the design target, not a baseline capture. Do not use the Mint-host preview for acceptance.
- The source map is recorded in [`ALPHALINK_PAGE_FAMILY_COVERAGE.md`](ALPHALINK_PAGE_FAMILY_COVERAGE.md). It links representative route families to current controllers/views/tests; it explicitly does not claim route-by-route action parity. Existing DMS-derived feature statuses are not frontend migration statuses.
- The dashboard/analytics route-level evidence sheet is recorded in [`DASHBOARD_ANALYTICS_ROUTE_EVIDENCE.md`](DASHBOARD_ANALYTICS_ROUTE_EVIDENCE.md). It lists all nine dashboard-related WEB declarations while preserving the CSV's eight dashboard/analytics module rows plus workspace-classified alias, and documents current middleware, rendering, direct-test limits, and stale verification-count discrepancy.
- The audience/list/contact evidence sheet is recorded in [`AUDIENCE_LIST_CONTACT_ROUTE_EVIDENCE.md`](AUDIENCE_LIST_CONTACT_ROUTE_EVIDENCE.md). Sol's independent source audit passed all 20 inventory declarations and documented role/scope distinctions, direct-test coverage and gaps, and the legacy test URL rewrite. A current read-only production sample inspected the list and detail pages at 1280×720; the legacy styling is cramped, while the no-address/no-export privacy behavior is visible. The screenshot was inspected but not saved as a workspace artifact; responsive, keyboard, and candidate-parity checks remain open.
- Keep the dashboard data contract and metric-period caveats explicit below; check source changes and tests if the contract changes.
- Record the current Vite build and dependency changes in the Phase 1 implementation checkpoint above.

#### Dashboard data contract (source-verified 2026-11-05)

`GET /dashboard/main` and its workspace alias resolve through `WorkspaceEntryController::dashboard` to `WorkspaceAnalyticsConsoleController::index`. The controller authorizes active workspace roles `owner`, `admin`, `operator`, and `privacy_officer`. Its shared daily query reads `workspace_daily_analytics` for that workspace and `attribution_scope=workspace`, starting at the earlier of the date 29 days before today and January 1 of the current year, through today's end-of-day; it supplies both the React metrics and existing MTD/YTD panels. React selects the 30 dates from that query for its metrics/trend. Operators are additionally scoped to their own `user_id`; the other allowed roles receive workspace-level aggregates. Missing dates in the React window are emitted as zero rows so the 30-day series has 30 points.

| React dashboard field | Source and meaning | Scope/period and qualification |
|---|---|---|
| Deliveries | Sum of imported PMTA delivery records in the daily analytics table | Last 30 calendar dates; operator's own rows or workspace aggregate |
| Opens, clicks, leads, unsubscribes | Stored campaign and Drop visitor events | Last 30 calendar dates; workspace/user scope above |
| Revenue | `lead_revenue` attributed to recorded campaign and Drop leads | Last 30 calendar dates; this is not PMTA earnings |
| Bounces | Soft plus hard imported PMTA bounce counts | Last 30 calendar dates; workspace/user scope above |
| Trend | Daily deliveries and opens | Thirty dated points from the same aggregate source |
| Geography and relationship graph | No country delivery totals/denominators and no permission-safe cross-module relationship feed in this controller contract | Must remain an honest unavailable/coverage state until a separately scoped data contract exists |
| Refresh | Authenticated existing analytics route with `fragment=1` | React refresh interval is 300,000 ms; source cache TTL is 600 seconds |

The dashboard data cache key contains dataset, workspace, user, role, date bounds, and current minute. Legacy dashboard tiles also show today, prior-day data under the label `YTD`, and month-to-date values; existing MTD/YTD chart panels have their own date semantics. Do not relabel those legacy values as 30-day totals. Screenshot sample metrics/rates are illustrative and must not become API or fallback data.

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

The current frontend lockfile declares React/React DOM 19.3.1, TypeScript 7.0.2, Lucide React 1.69.0, ECharts 6.1.0, Vite 8.3.5, and Tailwind CSS 4.3.3. React Router, Radix primitives, XYFlow, MapLibre, TanStack Table/Query, Zustand, and Motion are not yet declared. E-DASH-39/40 required no package installation; add missing packages with the feature phase that uses them, after confirming the Ubuntu build/runtime versions and lockfile impact.

| Check | Result |
|---|---|
| Dashboard role-rendered link test | PASS — `WorkspaceAnalyticsDashboardTest.php::test_dashboard_nested_campaign_and_global_test_links_match_the_workspace_role`, 1 test / 26 assertions; privacy officers see no empty Global Tests parent on the dashboard, authorized roles retain the group, and privacy officers still see it on the accessible GSend Projects page |
| Direct privacy-officer access test | PASS — `AlphaLinkCampaignSendNavigationTest.php::test_privacy_officers_cannot_directly_open_hidden_dashboard_destinations`, 1 test / 5 assertions; all five forbidden GET routes return 403 |
| Full affected dashboard/navigation suites | PASS — `WorkspaceAnalyticsDashboardTest.php` 24 tests / 361 assertions; `AlphaLinkCampaignSendNavigationTest.php` 10 tests / 90 assertions; `GlobalTestsWorkflowTest.php` 8 tests / 45 assertions; existing server-navigation test 1 test / 78 assertions |
| Collapsed-rail / drawer Node suite | PASS — 5 tests; desktop sticky hover/focus sizing and content transforms, mobile mini drawer hiding, desktop→mobile→desktop state, submenu activation, accessible labels, and `aria-pressed` synchronization on expansion/collapse are asserted |
| Typecheck, build, changed-file Pint, and diff check | PASS — full-repository Pint also reports one existing style issue in unchanged `bootstrap/providers.php`; build retains the pre-existing 512.42 kB ECharts chunk advisory |
| Production browser review | LEGACY BASELINE ONLY — authenticated SaaS dashboard was observed read-only at 1229 × 892 and still serves the legacy shell; the Command OS candidate has not been deployed. Deployed hover/focus, keyboard, responsive, theme, session and refresh verification remains open. |
| GitHub candidate | PASS — commit `9b5271f` pushed to `codex/alphalink-identity-reconstruction` after code audit and automated checks |
| Phase status | TESTING — Phase 1 remains open; no completion or production-release claim |
