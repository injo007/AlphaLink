# Verification record — current through 2026-10-23

## DMS-INST-001 DNS and VMTA planner checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Full Docker PHP 8.3 suite | **48 passed, 433 assertions.** | Includes four synthetic DNS-planner tests and three VMTA-planner tests for DMS record/configuration strings and branch differences. |
| Focused DNS/VMTA planner tests | **7 passed, 11 assertions.** | Compares DNS record order/content, injected private-IP mapping/fallback, owned-domain no-op, exact CentOS 9 VMTA/inbound/FBL output and CentOS 7/external-domain behavior. |
| Focused installation contract tests | **10 passed, 19 assertions.** | Covers DMS request normalization, per-row exception isolation, and the DMS OS/architecture gate. |
| Node bulk-install tests | **3 passed.** | Confirms the existing DMS-shaped row filtering, option serialization, blank APPLY, and owned-domain guard. |
| Docker PHP 8.3 Pint | Passed on the four changed PHP files. | Formatter check for both planners and their unit tests. |
| `git diff --check` | Passed. | No whitespace errors after the evidence and progress updates. |
| DMS/provider/host operations | Not run. | Planner consumes synthetic inputs only; no DMS mutation, DNS write, server probe or installation occurred. INSTALL remains disabled. |

The DNS planner reconstructs the source-owned-domain branch, although the standard DMS Cloud Bulk Install page filters candidates to non-owned domains and therefore normally reaches the provider no-op branch. The VMTA planner reproduces source output strings but is not wired to a PMTA adapter. Neither planner is connected to an execution route, and DMS's final IP/random-subdomain data and ISP mapping are not yet available in AlphaLink. DMS-INST-001 remains `IMPLEMENTING`.

## Current DMS-SRV-004 health collector checkpoint

| Check | Result | Meaning |
|---|---|---|
| Focused `ManagedServerHealthTest` | **6 passed, 27 assertions.** | Covers active/installed filtering, down-flag persistence and country in the server-list response, fake IP-API success/cache/error/no-key behavior, loopback TCP reachability, and the three-month cutoff. No external address is contacted. |
| Docker PHP 8.3 full suite | **41 passed, 422 assertions.** | All current AlphaLink feature tests pass with SQLite `:memory:` as configured in `phpunit.xml`. |
| Docker PHP 8.3 Pint | Passed on 23 changed PHP files. | Explicit file paths were used because the app container has no Git metadata. |
| `schedule:list` | Shows `servers:collect-health` every ten minutes without overlap and `model:prune` daily for `ManagedServerHistory`. | Confirms schedule registration; it does not execute either job. |
| AlphaLink country migration | `2026_10_22_235100_add_country_to_managed_servers` applied to the local AlphaLink database. | Adds a nullable country code; no existing server rows were rewritten. |
| `git diff --check` | Passed. | No whitespace errors in the current source and documentation changes. |
| External network calls | Not run. | Country requests were faked; the TCP fixture used loopback only. The scheduled job was not invoked. |

DMS-SRV-004 remains `IMPLEMENTING`: the MaxMind fallback dependency/database and safe populated-history visual comparison are open. No DMS mutation, connection, install, server probe, or live geo lookup was performed.

## Previous DMS-SRV-003 management and DMS-SRV-004 history viewer checkpoint

| Check | Result | Meaning |
|---|---|---|
| Focused `InfrastructurePlanningTest.php` | **21 passed, 336 assertions.** | Includes allowed-user replacement/clear, lock-protected soft return, restore, operator assignment scope, returned-row filtering, and scoped server-history UI/data tests. All server and history fixtures are synthetic. |
| Docker PHP 8.3 full test suite | **28 passed, 370 assertions.** | The full local suite passes after the management and history changes. No DMS request or server network probe is part of the tests. |
| Docker PHP 8.3 Pint | Passed on 7 changed PHP files. | Explicit file paths were used because the app container has no Git metadata. |
| JavaScript syntax | Passed for `server-index.js`, `server-users.js`, and `server-history.js`. | Static parsing only. |
| DMS relay privacy tests | **8 passed.** | Relay sanitization and fail-closed behavior pass. The relay is GET-only for browser use and is stopped after the reference capture. |
| `git diff --check` | Passed. | No whitespace errors in the current source and documentation changes. |
| AlphaLink database migration | `2026_10_22_235000_create_managed_server_histories` applied in the local app container. | Adds an empty, workspace-scoped history table. No existing server rows or state were edited. |
| AlphaLink history page browser smoke check | The server row's **SERVER HISTORY** action opens the DMS-themed history page; its empty DataTable loads and reports no available history rows. | This used a read-only local page load. No history row or server state was inserted or changed in the local browser. The new populated history layout was tested with synthetic fixtures in PHP, but not visually compared to DMS because production history is blocked by the relay. |
| DMS browser comparison | DMS `/admin/servers` was inspected read-only with an empty/sanitized table. New management and populated history states were not browser-compared against DMS rows. | The relay blocks production row values. Do not claim full visual parity or feature acceptance. No DMS mutation, test connection, installation, or server probe was performed. |
| Fingerprint requirement | No fingerprint UI or input was added to server add/test/install paths. | This implementation only adds local history display; no connection or installation action was triggered. |

This earlier checkpoint recorded DMS-SRV-004 before the collector and pruning job were added; see the current checkpoint above. DMS-SRV-003 and DMS-SRV-004 remain `IMPLEMENTING`, and no feature is `PARITY_CONFIRMED`.

## DMS-INST-001 bulk-install planning checkpoint

### Request contract continuation — 2026-10-22

| Check | Result | Meaning |
|---|---|---|
| Full Docker PHP 8.3 suite | **41 passed, 422 assertions.** | Includes request normalization, row exception isolation, platform blockers and existing server health/management tests. SQLite/synthetic fixtures only. |
| Focused Docker PHP 8.3 bulk-install tests | **6 passed, 56 assertions.** | Validates DMS request normalization and uses an injected fake runner to show one row exception is logged while the next row executes and the parent result remains successful. No remote adapter is present. |
| Node built-in tests | **3 passed.** | Verifies selected-domain row filtering, PMTA/options serialization, UUID domain IDs, blank APPLY and owned-domain guard behavior. |
| Docker PHP 8.3 Pint | Passed on 23 changed PHP files. | Uses explicit paths because the app container has no Git metadata. |
| DMS INSTALL, SSH, DNS or process | Not run. | UI remains disabled; the coordinator is sequential and test-only through fake runner injection. No DMS or AlphaLink host was contacted. |
| `git diff --check` | Passed. | Confirmed after the source and evidence updates. |

This advances the local request contract, not installation parity. DMS uses a synchronous child process with forked per-server installers and a polled log token; AlphaLink has no submit route, production runner, persisted run/log lifecycle, or remote installer. The UI remains disabled.

### Eligibility/page checkpoint

| Check | Result | Meaning |
|---|---|---|
| Docker PHP 8.3 `php artisan test --compact` | **23 passed, 278 assertions.** | Includes DMS bulk-install page controls/empty state, exact server/domain eligibility filters, and rendered eligible candidate labels. The suite uses synthetic local fixtures; no DMS install or server connection is part of it. |
| Docker PHP 8.3 Pint | Passed on 9 changed PHP files. | New model, eligibility query, migration, route/controller and tests match project formatting. |
| DMS relay privacy tests | **8 passed.** | Synthetic row/domain substitution, empty DMS grid substitution, fail-closed behavior and row privacy are covered. |
| DMS and AlphaLink browser pages | Empty grid and visible layout compared at the same viewport. Both show the PMTA/switch bar, matching column labels and “This Grid Is Empty.” A separate sanitized DMS row capture uses fake server/domain values. | AlphaLink INSTALL is disabled; DMS INSTALL is enabled. No DMS INSTALL, process log, SSH action, or mutation was invoked. A populated side-by-side AlphaLink comparison is still open. |
| `git diff --check` | Passed. | No whitespace errors in the tracked change set. |

This is a partial planner implementation. AlphaLink does not submit the option/row payload, start the worker, or show logs. Existing accounts and domains are not automatically mapped to DMS usernames and provider/status fields, so unmapped records remain ineligible. DMS-INST-001 stays `IMPLEMENTING`.

## SSH fingerprint prompt removal — current checkpoint

| Check | Result | Meaning |
|---|---|---|
| Docker PHP 8.3 `php artisan test --compact` | **21 passed, 232 assertions.** | Full target suite passes with direct SSH/SFTP Test Connection and no-body preflight; tests verify no fingerprint input or fingerprint output in these flows, and preflight automatically stores the initial host-key pin. |
| Focused `InfrastructurePlanningTest.php` | **14 passed, 198 assertions.** | Covers registration, Test Connection, automatic first-use pinning, preflight, installation-plan blocking, role checks, and special-use IP rejection. |
| Docker PHP 8.3 Pint | Passed on changed PHP files. | Explicit file paths were used because the container has no Git metadata. |
| JavaScript syntax and `git diff --check` | Passed. | Registration UI script parses, and changed files have no whitespace errors. |
| Local Add Servers browser page | Reopened through the current local AlphaLink app; the rendered form contains provider, name, IPs, port, username, password type, credential, expiry, Test Connection and Save only. | No fingerprint field/dialog is present. No connection test was submitted and no credential was sent. |
| DMS reference behavior | Read-only Lists GET through the configured `.env` proxy returned `Lists - DMS` with the ISP selector and `LOAD LISTS`; existing server-form evidence also shows no fingerprint input. | No DMS Test Connection, SSH connection, install, or state-changing form was invoked. |

The AlphaLink Add/Test Connection and preflight paths do not ask the operator for a fingerprint. Preflight retains automatic first-use host-key pinning internally and blocks a later key change without asking for fingerprint text. Exact DMS connection success/failure strings remain unverified; the server feature remains `TESTING`.

| Check | Result | Meaning |
|---|---|---|
| Docker PHP 8.3 `php artisan test --compact` | **16 passed, 205 assertions**. Tests use SQLite/synthetic fixtures; no DMS request is part of the suite. | Covers existing application tests plus server registration, multi-IP cardinality, duplicate/restore paths, encrypted password/key handling, preflight pin ordering, IANA special-address blocking, workspace scope, active/returned list JSON, and unknown list-relation fields. This is local behavior evidence, not DMS parity by itself. |
| Docker PHP 8.3 Pint on changed PHP files | Passed; 10 files checked. | Changed PHP matches project formatting. `--dirty` is unavailable because the app container does not mount Git metadata; explicit file paths were used. |
| Node syntax checks | Passed for `server-index.js`, `server-registration.js`, and `tables_datatables.js`. | Static syntax only. |
| `git diff --check` | Passed for source, tests, and documentation. | Mirrored DMS vendor assets were preserved byte-for-byte; the upstream asset bundle contains trailing whitespace, so it is excluded from the source/documentation whitespace check. |
| DMS authenticated browser trace | Passed through the existing `.env` proxy using a local GET-only relay. Login/session redirects and the two target GET pages returned expected status/title. | No secret values or production row content were recorded. The relay rejected browser state-changing methods. No DMS registration, install, SSH test, or other mutation was issued. |
| DMS relay row-data privacy tests | Passed; 4 Python unit tests. | The server-list page stays available for layout inspection, query-bearing table requests receive synthetic empty data without an upstream fetch, and the production account label is hidden. |
| DMS ↔ AlphaLink server UI comparison | Performed at the same 863×892 CSS viewport. Add form and Add modal were inspected; list toolbar/header/returned toggle/search and empty state were compared. | The current relay blocks DMS table-data queries and returns a synthetic empty result. A prior comparison used a synthetic no-match filter and one synthetic local fixture, which was removed. This is partial visual evidence; row data and action states remain unverified. |
| Local browser registration | Saved one reserved documentation-range IP into AlphaLink using synthetic data; list displayed that local row. The record, credential and related audit event were removed after verification. | No remote socket was opened by this save path. A separate local Test Connection attempt for the reserved address returned `blocked` before socket creation. No credentials were sent to a host. |
| IANA address-range regression | Passed against synthetic IPv4/IPv6 private, reserved, documentation, NAT64, benchmarking, dummy and SRv6 addresses. | These are AlphaLink safety checks sourced from E-ADDR-01; they do not establish DMS product behavior. |
| Reference mutation-based comparison | Not run. | Required by the absolute read-only DMS boundary. |
| REA artifact/runtime checks | Not run in this slice. | No shipped artifact was needed to validate the server form/list contract. |

The server form/list remain `TESTING`, not `PARITY_CONFIRMED`. Browser comparison and local tests do not resolve DMS credential storage, exact list-row relations/actions, or live Test Connection outcomes. The 80% coverage milestone is not calculated because the complete workflow denominator and weights are not established.

## DMS-AUD-002 import checkpoint — 2026-10-22

| Check | Result | Meaning |
|---|---|---|
| Docker PHP 8.3 `php artisan test --compact` | **21 passed, 236 assertions**. | Covers the existing application suite and new import tests for lowercase normalization, comma/line splitting, invalid-token skipping, duplicate no-move behavior, workspace role control, missing lookup-key failure, and the 5,000-candidate cap. All fixtures are synthetic. |
| Docker PHP 8.3 Pint | Passed on the five changed PHP files. `--dirty` is unavailable because the app container has no Git metadata; explicit file paths were formatted. | Changed Laravel code matches the project formatter. |
| DMS reference relay privacy tests | **5 passed.** | The `/admin/lists` HTML replaces live ISP options with `Example ISP` and clears table bodies; query-bearing list/server table requests return synthetic empty data without an upstream fetch. |
| DMS Lists screen | Authenticated GET returned title `Lists - DMS`; the visible page contains an ISP selector and `LOAD LISTS`. Static JavaScript points the table at `/admin/lists/:id/load` and disables pagination. | No ISP records or recipient rows are retained. The GET route was not called because its safety is unknown. |
| DMS import comparison | Not complete. | Source establishes lowercase/validation and conflict-do-nothing, but exact import columns, invalid-row feedback, failure responses, and the loaded table remain unverified. No parity acceptance is claimed. |
