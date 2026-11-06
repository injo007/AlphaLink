# AlphaLink UI behavior contract

This document defines current AlphaLink interface behavior. It is scoped to AlphaLink's own workspace, application data, and permissions.

## Product context

- **Users:** Workspace owners, administrators, campaign operators, and privacy officers.
- **Primary tasks:** Manage audiences and campaigns, review engagement data, configure providers, and operate authorized server controls.
- **Design system:** `resources/css/app.css` owns runtime tokens; `DESIGN.md` documents their use.
- **Language and dates:** English interface; dates use the configured application timezone.
- **Accessibility:** Keep semantic headings and controls, keyboard operation, visible focus, accessible status names, and readable contrast.

## Core workflows

| Area | Current behavior | Verification source |
|---|---|---|
| Sign-in and workspace | Laravel session authentication; users can enter only workspaces where they have active membership. Controllers scope data and actions to that workspace. | Auth and workspace authorization feature tests. |
| Audience lists | Lists are associated with an ISP and country and can hold multiple membership states. Operators can search, import, segment, and review action totals where event data exists. | Audience-list management, import, history, and tracking tests. |
| Campaigns | Campaign content is revisioned. A campaign send selects one ISP and country, with multiple lists or audience segments within that selection. Sending to another ISP or country requires another campaign. | Campaign composer, audience snapshot, and send execution tests. |
| Tracking and analytics | Open, click, lead, unsubscribe, and bot events are recorded through AlphaLink endpoints and shown only where source data exists. Tracking signals can be affected by scanners and privacy proxies; reports should distinguish raw requests from unique recipient actions. | Campaign and drop tracking tests; analytics and visitor tests. |
| Infrastructure | Server registration, provider settings, installation, domain changes, PMTA controls, and logs are workspace-scoped and retain their separate operational gates. | Infrastructure, provider, and installation tests. |
| Settings and operations | General settings, health, application errors, and queue status are shown through workspace-authorized pages. Sensitive configuration values are not prefilled into forms or returned to clients. | Settings, health, and application error tests. |

## Permissions and state

- Navigation visibility is a convenience; direct requests are authorized in controllers and policies.
- Cross-workspace records are not exposed through direct identifiers.
- Destructive or connected operations require their explicit confirmation or readiness gate.
- Empty states must say when no records are available instead of inventing sample data.
- Forms preserve user-authored campaign HTML during editing. Send-time substitutions apply only to declared AlphaLink tracking tokens.

## Verification

Use focused PHPUnit suites for the affected controller, service, persistence, and UI behavior. Run PHP syntax checks and `vendor/bin/pint --dirty --format agent` after PHP changes. For browser work, verify the actual AlphaLink page and state when the local environment is available; synthetic fixtures do not establish production data or delivery behavior.

## Canonical UI Map

| Capability | Canonical owner | Source of truth | Allowed variants | Verification |
|---|---|---|---|---|
| CRUD | Workspace named routes and their Laravel controllers; the owning Blade page renders forms and state | Route definitions, controller validation/authorization, and feature tests | Create/edit pages may use native HTML forms; stay/return behavior is page-specific | Focused PHPUnit feature tests cover route access, validation, persistence, and redirects |
| Form | Native HTML controls and the owning Laravel Blade form | Form request/controller validation and the page's field names | Native inputs/selects/textareas with existing page-specific enhancements | Focused feature tests cover valid and invalid submissions; browser checks cover keyboard/focus behavior when runtime is available |
| Date | Native browser date inputs in the owning filter form; inline React validation owns range errors and Laravel validates requests server-side | `premium-ui.json` ownership declaration, `WorkspaceAnalyticsConsoleController::dashboardAnalyticsPeriod`, and the filter markup | Native date inputs for dashboard reporting windows; custom ranges are inclusive and bounded to today; invalid custom ranges show inline guidance and focus the first invalid input | Dashboard date-filter feature tests cover each preset, custom bounds, invalid ordering, and refresh preservation; browser checks cover native keyboard/calendar controls and inline error recovery |
| Scrollbar | Global app stylesheet; Command OS shell may set responsive sidebar scrolling | `resources/css/app.css`, `resources/css/command-os.css`, and `DESIGN.md` | Sidebar scrolling may be independently contained on narrow screens | Browser computed-style and scroll checks; note saved-app browser availability in evidence |
| Select/Listbox | Native HTML select/listbox controls | `premium-ui.json` ownership declaration and the owning form markup | Existing Bootstrap Select enhancement may wrap native selects without changing their data contract | Feature tests cover submitted values; keyboard and popup behavior are checked in the browser when runtime is available |
| Table Selection | The owning page's table and selection controls | Page-specific route/payload and selection tests | Selection scope is explicitly page-only or all-results where the page supports it | Focused tests cover selected rows, bulk action payloads, permission denials, and empty selection |
| Navigation | `layouts/admin-shell.blade.php` is the single route-label/navigation source; `command-os-drawer.js` owns dashboard mobile drawer interaction and desktop mini-rail activation | Laravel named routes, workspace membership role, Blade navigation payload, and OneUI submenu handler | Dashboard Campaigns SEND/NEGATIVES/MIXS and Global Tests SEND/HISTORIES links are role-filtered; GSend PROJECTS/ACCOUNTS and Campaign Statistics remain visible; GSend SEND and Campaign Logs remain placeholders. Desktop-only mini-rail hover/focus preserve sticky positioning while expanding the rail to 240px and resetting OneUI's translated content, even though the grid track stays 74px. Mobile mini mode keeps its fixed hidden drawer and responsive transform. Submenu activation expands the rail before OneUI handles the click and synchronizes the width toggle's `aria-pressed` state. Non-dashboard navigation is unchanged | `WorkspaceAnalyticsDashboardTest`, `AlphaLinkCampaignSendNavigationTest`, server-navigation feature tests, and `command-os-drawer.test.js` |
| Permission Visibility | Server-side workspace authorization; Blade navigation visibility is a convenience only | Route middleware, policies/controller authorization, and workspace membership role | On dashboard pages, owner/admin/operator see all mapped Campaigns and Global Tests destinations. Privacy officers see only GSend PROJECTS/ACCOUNTS and Campaign Statistics; forbidden items are hidden while placeholders remain. Direct requests remain protected server-side. Other page families retain the existing navigation | Role-rendered-link assertions plus direct GET authorization tests |
| Mobile Drawer | `public/console-assets/command-os-drawer.js` owns open/close, focus, modal and OneUI state; `resources/css/command-os.css` owns presentation | Dashboard shell markup and OneUI `App.layout` action contract | Mobile drawer only; Escape, close control and backdrop dismiss it; desktop navigation remains the Blade shell sidebar | Node interaction tests cover focus entry/trap/restoration, hidden submenu exclusion, inert background, Escape/backdrop dismissal and OneUI state cleanup |

An unavailable dashboard action is rendered as a focusable `aria-disabled` button with a visible reason connected by `aria-describedby`. It intentionally has no destination and cannot navigate or mutate state; static detectors that flag it as an actionless button are reporting an unavailable-state control, not a missing action handler.
