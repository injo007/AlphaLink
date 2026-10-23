# Verification record — current through 2026-10-23

## DMS-INST-001 manual domain and runtime artifact checkpoint — 2026-10-23

| Check | Result | Limit |
|---|---|---|
| `DmsManualDomainImportTest` | **3 passed / 20 assertions** | Synthetic provider/domain records only; checks DMS trim/first-create/count, eligibility and workspace role boundaries. |
| Docker PHP 8.3 full suite | **97 passed / 651 assertions** | All current local tests. Pint passed on changed PHP paths and `git diff --check` passed. |
| Local app PMTA catalog | Exact reviewed private 4.0r8 bundle verified and shown as staged; `installable=false`. | Copy resides in the mounted local demo directory, which must be retained for container replacement. |
| Disposable AlmaLinux 9 RPM install | DNF install exited 0; `pmtad --version` exited 0. | No license validation, PMTA service/config test or real-host change. |

E-INST-19/20 record the source-backed domain behavior and limited package compatibility. No real domain/provider or target-host installation was created; INSTALL remains disabled.

## DMS-INST-001 PMTA artifact integrity checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 `PmtaBundleIntegrityTest` | **7 passed, 13 assertions.** | Synthetic bundles cover valid manifest/hash verification, tampering, private permissions, symlinks, unexpected files, and catalog installability. |
| Full Docker PHP 8.3 suite | **94 passed, 631 assertions.** | All local API feature/unit tests pass, including the PMTA artifact gate. |
| Docker PHP 8.3 Pint | Passed on all five changed PHP files. | Explicit paths were supplied because the app container has no Git metadata; the host PHP lacks XML, which Pint requires. |
| Read-only artifact check | Host-side private 4.0r8 bundle integrity verified. | Bundle bytes and license text were not displayed. The running app container has no private bundle mount and `PMTA_BUNDLE_DIR` is unset, so the API cannot consume the host-side files. |
| Disposable AlmaLinux 9 RPM check | Dependency resolution inspected; installation aborted. | The local RPM identifies PowerMTA 4.0r8/x86_64, the dependency solver resolves its requirements, signature metadata is absent, and `dnf --assumeno` stopped before installation. No package scriptlet ran. |
| DMS/target operations | None. | No DMS request, package execution, remote host change, provider write, or installation was run. |

E-INST-17 records the DMS RPM/patch guard and license upload behavior alongside AlphaLink's infrastructure-only integrity safeguard. E-INST-18 records RPM metadata and dependency resolution without installation. No trusted signer, license entitlement, or clean-host runtime compatibility is established. DMS-INST-001 remains `IMPLEMENTING`; INSTALL remains disabled.

## DMS-INST-001 row-preparation checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused PHP 8.3 preparation, eligibility, remote-IP and planner tests | **35 passed, 377 assertions** at the focused run. | Synthetic checks cover DMS server/domain filters, workspace-scoped credential retrieval, required preflight pin, and read-only IP ordering/error behavior. |
| Full Docker PHP 8.3 suite | **87 passed, 618 assertions.** | Includes the new row-preparation tests and existing feature/unit coverage. |
| Docker PHP 8.3 Pint | Passed on the three changed PHP files. | Eligibility, preparation service and feature tests match project formatting. |
| Real AlphaLink host operations | Read-only preflight and global-IP discovery only. | Verified AlmaLinux 9.8/x86_64/dnf; three addresses discovered. Addresses and stored fingerprint were not displayed or recorded. No package, service, firewall, DNS, PMTA or finalization change ran. |

E-INST-16 connects DMS's eligibility criteria and saved server credentials to the already-mapped read-only IP-discovery phase. The service is not yet connected to a production row runner. The local owner username is unset and there are no eligible domains, so the real host cannot enter the bulk selector. INSTALL remains disabled.


## DMS-INST-001 remote IP discovery checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 remote-IP, inventory and OS/PMTA tests | **11 passed, 78 assertions.** | Compares synthetic output sorting/deduplication, no-address and malformed-address guards, private asset presence and DMS installer branches. |
| Full Docker PHP 8.3 suite | **82 passed, 603 assertions.** | Includes the remote-IP adapter, asset inventory and installer matrix. |
| Docker PHP 8.3 Pint | Passed on the SSH probe, discovery adapter and unit test. | Changed PHP files match project formatting. |
| SSH/host/DMS operations | Not run. | Synthetic transport only; no host, SSH credential, DNS provider, firewall or PMTA operation was accessed. |

E-INST-15 maps the exact DMS read-only global-IP command and its IPv4-before-IPv6, packed-address ordering. AlphaLink reuses its verified-server host-key pin internally and adds an adapter that rejects empty/invalid output before later install phases. It remains disconnected from a production run-start/runner path; no fingerprint prompt was introduced. INSTALL stays disabled.

## DMS-INST-001 asset inventory checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 asset inventory and installer matrix | **7 passed, 71 assertions.** | Synthetic fixture checks source paths, DMS RPM/patch skip truth table, selected optional asset behavior, private root handling and symlink rejection. |
| Full Docker PHP 8.3 suite | **78 passed, 596 assertions.** | Includes the asset inventory, OS/PMTA matrix and existing API tests. |
| Docker PHP 8.3 Pint | Passed on the inventory, configuration and test files. | Changed PHP files match project formatting. |
| DMS/host/provider operations | Not run. | No real bundle, license, server, network, firewall, DNS provider or PMTA operation was accessed. |

E-INST-14 records an offline inventory for source-referenced DMS asset paths. It rejects a non-private root, symlink paths and invalid/empty files, while preserving DMS’s source skip behavior: PMTA setup skips only if both its RPM and patch are missing; selected proxy/IMAP assets may cause DMS to return early, and tracking may continue with Apache but omit the mini app. Inventory only proves non-empty regular files are present. It does not verify artifact identity, checksums, license entitlement, target compatibility or deployability, and it is not wired into a production run. INSTALL remains disabled.

## DMS-INST-001 OS/PMTA installer matrix checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 DmsBulkInstallInstallerMatrixTest | **3 passed, 49 assertions.** | Compares supported OS families and all PMTA 4.0/4.5/5.0 asset and branch selections, selected optional feature assets, and DMS’s RPM/patch early-return truth table. |
| Full Docker PHP 8.3 suite | **74 passed, 574 assertions.** | Includes the installer matrix and existing API tests. |
| Docker PHP 8.3 Pint | Passed on the matrix, OS selector and test. | All three changed PHP files match project formatting. |
| DMS/host operations | Not run. | No bundle, license, server, network, firewall, DNS provider or PMTA operation was accessed. |

E-INST-13 maps DMS’s CentOS 7 yum and CentOS 9 dnf paths, version-specific PowerMTA files/patch destinations, admin HTTP port rules, Nginx inputs and option-selected assets. The PMTA branch only returns early when both the RPM and patch are absent; DMS ignores later upload result booleans. AlphaLink’s matrix captures this source behavior but is not connected to artifact verification or a runner. A matching package/license set and real host execution remain outstanding; INSTALL remains disabled.

## DMS-INST-001 persisted transcript checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 transcript tests | **7 passed, 18 assertions.** | A fake coordinator run writes timestamped lines to the private store; the owner’s GET returns the exact JSON string. Additional checks cover coordinator output, cross-workspace isolation, nonmember/operator/unauthenticated denial and missing/malformed IDs. |
| Full Docker PHP 8.3 suite | **71 passed, 525 assertions.** | Includes transcript persistence/route tests and all local API feature/unit tests at that checkpoint. |
| Docker PHP 8.3 Pint | Passed on all six changed PHP files. | Controller, route, store, coordinator and tests match project formatting. |
| git diff --check | Passed after evidence updates. | No whitespace errors. |
| DMS log route, install, server, DNS or PMTA operations | Not invoked. | Only a synthetic fake runner and local private transcript files were used. |

E-INST-11 establishes DMS’s business behavior: it appends timestamped lines to a file, returns the complete transcript as a JSON string, and the browser polls while the synchronous install request runs and briefly after completion. AlphaLink now reproduces transcript formatting/storage/read output for a synthetic run and scopes reads to an authenticated workspace owner/admin. It does not yet create production runs, connect a real installer, poll from the browser or show logs. DMS-INST-001 remains IMPLEMENTING; INSTALL remains disabled.

## DMS-INST-001 progress transcript checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 `DmsBulkInstallRunCoordinatorTest` | **1 passed, 4 assertions.** | With a frozen clock, compares the exact DMS timestamp prefix, initial start message, per-server progress line and caught-error line. |
| Full Docker PHP 8.3 suite | **65 passed, 511 assertions.** | Includes the transcript-format update and all existing local API tests. |
| Docker PHP 8.3 Pint | Passed on the coordinator and test. | Both changed PHP files match the project formatter. |
| DMS log files / polling / installation | Not accessed or run. | The local coordinator keeps transcript entries in memory; no file append, log route, DMS request or server operation was invoked. |

At the earlier E-INST-11 checkpoint, AlphaLink formatted the initial and per-server transcript in memory only. E-INST-12 records the later file store and GET endpoint; browser polling and UI remain unimplemented. DMS-INST-001 remains `IMPLEMENTING` and INSTALL remains disabled.

## DMS-INST-001 install-sequence checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 `DmsBulkInstallSequencePlannerTest` | **4 passed, 14 assertions.** | Compares complete enabled/disabled phase order, the non-owned-domain DNS provider no-op, installed/used-domain guards, zero-IP abort, and malformed option rejection. |
| Full Docker PHP 8.3 suite | **65 passed, 512 assertions.** | Includes the source-mapped installation-sequence contract tests and all existing API tests. |
| Docker PHP 8.3 Pint | Passed on the sequence planner and test. | Both new PHP files match the project formatter. |
| DMS/provider/host operations | Not accessed or run. | The planner only returns synthetic phase descriptions. No DMS install, remote host, DNS provider, PMTA write or AlphaLink runner was invoked. |

DMS checks already-installed state before used-domain state, discovers/formats addresses, aborts on zero addresses before host configuration, and then runs host/services, optional tracking, DNS setup, PMTA/VMTAs, optional IMAP/proxy/HTTPS, firewall, finalization and terminal logs. This is an offline comparison of ordering and guards; it does not reproduce mid-phase host failure effects or execute the installer. DMS-INST-001 remains `IMPLEMENTING` and INSTALL remains disabled.

## DMS-INST-001 successful-install finalization checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 `DmsBulkInstallCompletionPlannerTest` | **3 passed, 10 assertions.** | Compares ordered synthetic completion operations for owned/non-owned domains, all seven stored switches, installed-last behavior, and rejection of malformed rows/options. |
| Full Docker PHP 8.3 suite | **61 passed, 498 assertions.** | Includes the new finalization contract tests with the full local API suite. |
| Docker PHP 8.3 Pint | Passed on the completion planner and test. | Both new PHP files match the project formatter. |
| DMS/database/host operations | Not accessed or run. | The planner has no persistence adapter; all records are synthetic. No database mutation, server, DNS provider, PMTA file or INSTALL action was invoked. |

Source shows `finish_install()` deletes the server's existing MTAs and IPs, inserts the discovered/formatted IP rows, marks an owned domain `used`, saves `data`, `pmta_version` and seven switches, and sets `is_installed=true` last. The inspected function is not wrapped in a transaction; actual database failure effects are unknown. AlphaLink only plans the order and data shape, so DMS-INST-001 remains `IMPLEMENTING` and INSTALL remains disabled.

## DMS-INST-001 ISP-to-VMTA mapping checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 `DmsBulkInstallIspPlannerTest` | **3 passed, 4 assertions.** | Verifies all-name lowercase and first-occurrence de-duplication, then compares exact per-ISP VMTA output for synthetic names. |
| Full Docker PHP 8.3 suite | **58 passed, 488 assertions.** | Includes current installation planners, all existing tests and the new ISP mapping tests. |
| Docker PHP 8.3 Pint | Passed on the ISP planner and test. | Both changed files match the project formatter. |
| `git diff --check` | Passed after the source and evidence updates. | No whitespace errors. |
| DMS ISP records / host operations | Not accessed or run. | ISP names are synthetic; no DMS row data, remote host, PMTA file or DNS provider was touched. INSTALL remains disabled. |

`IspAction::build_vmtas()` does not filter inactive ISPs. AlphaLink reproduces the query's name transformation but still has no ISP catalogue or production data source. DMS-INST-001 remains `IMPLEMENTING`.

## DMS-INST-001 discovered-IP and alias checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 IP/alias planner tests | **7 passed, 51 assertions.** | Verifies the exact DMS 1,022-word pool hash, random sample count/bounds, address deduplication and IPv4/IPv6 packed ordering, primary/first alias rules, malformed-input rejection, and use of formatted rows by the DNS and VMTA planners. IPs, domains and deterministic random seeds are synthetic. |
| Full Docker PHP 8.3 suite | **55 passed, 484 assertions.** | Existing suite plus the four IP and three alias-planner tests. |
| Docker PHP 8.3 Pint | Passed on the new planners and their tests. | All four files match the project formatter. |
| `git diff --check` | Passed after the source and evidence updates. | No whitespace errors. |
| DMS/provider/host operations | Not run. | No DMS form, DNS provider, server discovery command, SSH connection, PMTA write or installation was invoked. INSTALL remains disabled. |

The planners reproduce DMS's address sorting, exact alias pool and Laravel selection behavior, and `format_ips` mapping. They accept supplied synthetic discovered addresses; they do not connect to remote discovery. The checks demonstrate deterministic local output equivalence for the tested branches, not end-to-end installation parity. DMS-INST-001 remains `IMPLEMENTING`.

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
