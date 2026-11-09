# Warmup statistics route evidence

**Scope:** Read-only source map for AL-0410 and its clean alias AL-0778. The route inventory remains the source of truth; both entries are `NOT_STARTED` for React migration. This sheet excludes Warmup create, edit, send, stop, and other mutation workflows.

## Route and access

- [AL-0410](COMMAND_OS_ROUTE_INVENTORY.csv#L411): `GET|HEAD /app/workspaces/{workspace}/warmups/statistics`, `console.warmups.statistics`.
- [AL-0778](COMMAND_OS_ROUTE_INVENTORY.csv#L779): `GET|HEAD /warmups/statistics`, `clean.console.warmups.statistics`.
- Legacy URI is declared under web middleware `auth`, `active`, `auth.session`; the route-generation loop adds `workspace.legacy-redirect`. For ordinary HTML GET/HEAD it redirects to the ID-free clean URL and sets the active workspace. The generated clean alias uses `auth`, `active`, `workspace.current`, and `auth.session`. See [web group](../../apps/api/routes/web.php#L51), [legacy declaration](../../apps/api/routes/web.php#L166), [clean route generation](../../apps/api/routes/web.php#L487), [redirect logic](../../apps/api/app/Http/Middleware/RedirectLegacyWorkspaceUrl.php#L10), and [current workspace resolver](../../apps/api/app/Http/Middleware/ResolveCurrentWorkspace.php#L17).
- The clean alias reaches `WorkspaceMailerOperationsConsoleController::warmupStatistics`; an ordinary HTML request to the legacy URI redirects before the controller. An AJAX/JSON request bypasses that redirect but still receives the controller's Blade `View`, because the action has no JSON branch ([action](../../apps/api/app/Http/Controllers/Web/WorkspaceMailerOperationsConsoleController.php#L347)). Its workspace helper requires an active workspace membership and role `owner|admin|operator`; other roles receive 403 ([helper](../../apps/api/app/Http/Controllers/Web/WorkspaceMailerOperationsConsoleController.php#L935)).

## Query and rendered contract

The action accepts optional `from` and `to` date filters in `Y-m-d` format. Both cannot be future dates; `to` must be on or after `from`. Defaults cover today and the preceding 29 calendar days. It reads `campaign_engagement_unique_actions`, joins the campaign on campaign ID and workspace ID, scopes to the selected workspace, `kind=warmup`, and `campaigns.created_by` equal to the signed-in user, then restricts action timestamps to the selected range. Rows group by Warmup campaign and expose its ID/name plus counts of unique `open`, `click`, `lead`, `unsubscribe`, and `bot` actions, sorted by name; the summary totals those same five metrics. Source: [validation, query, and view data](../../apps/api/app/Http/Controllers/Web/WorkspaceMailerOperationsConsoleController.php#L347).

The creator filter applies to every allowed role, including owners and admins. Thus the report is not a workspace-wide aggregate: it displays only Warmups created by the current user. This is established source behavior; whether a redesign should preserve this exact scope is a parity decision, not inferred here.

[The Blade view](../../apps/api/resources/views/console/warmups/statistics.blade.php#L1) presents an explanatory unique-action boundary, date form, five summary cards, and a per-Warmup table or an empty state. It states that delivery and opt-out attribution are not inferred. The only visible navigation action in this view is a link to Warmup drafts. The form submits a GET to the same page. No inline script, polling, JSON response, or statistics-specific fetch asset was found. The separately linked draft/create/send/stop flows are outside this read-route scope.

## Direct tests and gaps

- [Warmup navigation, empty report](../../apps/api/tests/Feature/WorkspaceMailerOperationsTest.php#L73): the test asserts the clean statistics link and requests the statistics page, confirming its heading and empty state.
- [Unique action counts](../../apps/api/tests/Feature/WorkspaceMailerOperationsTest.php#L456): synthetic tracked events and unique-action rows exercise the rendered Warmup statistics counts.
- Both test requests call `get(route('console.warmups.statistics', workspaceId))`. `Tests\TestCase::get()` rewrites legacy workspace URLs to the clean destination for an authenticated member ([rewrite helper](../../apps/api/tests/TestCase.php#L16)); these tests cover the clean page behavior, not the legacy redirect. No direct legacy redirect test specific to Warmup statistics was found.
- No focused tests were found for from/to validation boundaries, owner/admin/operator role differences, a member who cannot access the workspace, another creator's Warmup being excluded, or a workspace with foreign unique-action rows. No dedicated JSON API route exists for this report.

## Unknowns

- CSV migration status is `NOT_STARTED`; no React page or API contract for this route is evidenced.
- Tests demonstrate owner-rendered behavior with synthetic data but do not establish every authorization and date-validation path.
- DMS-derived status and historical behavior are outside this source-only sheet.
