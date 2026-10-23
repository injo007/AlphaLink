# AlphaLink progress

## DMS-INST-001 runtime bundle and domain-input checkpoint — 2026-10-23

The running local app now sees a private verified copy of the reviewed 4.0r8 bundle and reports it as staged while keeping `installable=false`. A disposable AlmaLinux 9 container installed that RPM and ran `pmtad --version` successfully; the real server and DMS were untouched. The private copy is under the app’s mounted local `/demo/private/pmta` directory; it survives container replacement only while that demo directory is retained. E-INST-20 records this limited compatibility result.

DMS manual Add Domains creates provider-backed, active, non-owned `new` rows and counts only new names. AlphaLink now has a workspace-scoped manual-import POST with that response and selector behavior; three feature tests/20 assertions and the full suite (97/651) pass. E-INST-19 records the source and target. The actual workspace still has no configured provider/eligible domain and the owner has no DMS username mapping; this route was tested only with synthetic data and has no DMS-matched visual form yet. INSTALL remains disabled pending the production runner, complete assets/licensing, provider and host phases, and end-to-end verification.

## DMS-INST-001 PMTA artifact integrity checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 `PmtaBundleIntegrityTest` | **7 passed, 13 assertions.** | Synthetic bundles verify the exact manifest/file allowlist, hashes, modes, symlink rejection, tamper rejection, and catalog behavior. A verified artifact still does not make PMTA installable. |
| Full Docker PHP 8.3 suite | **94 passed, 631 assertions.** | Includes the new artifact-integrity tests and all current AlphaLink tests. |
| Docker PHP 8.3 Pint | Passed on all five changed PHP files. | Explicit paths were supplied because the app container has no Git metadata; the host PHP lacks the XML extension Pint requires. |
| Host-side artifact check | Integrity verified without displaying bundle contents. | The private 4.0r8 package/license/reference-config files match the reviewed manifest and pinned hashes. At the E-INST-17 checkpoint, the app container had no private bundle path, so it reported the bundle unavailable; E-INST-20 records the later local runtime copy. |
| Disposable AlmaLinux 9 RPM check | Metadata and dependency resolution inspected; installation aborted. | RPM metadata identifies PowerMTA 4.0r8/x86_64; dependency resolution found requirements, the RPM reports no PGP/RSA signature metadata, and `dnf --assumeno` aborted before package installation. No scriptlet or target host ran. |
| DMS and target host operations | None. | Source inspection only; no license text was printed, and no PMTA package, remote service, DNS, firewall, or DMS operation ran. |

E-INST-17 maps DMS's actual AlmaLinux 9 PMTA behavior: DMS skips PMTA only when both the versioned RPM and patch are absent; otherwise it installs the RPM and uploads the versioned license. E-INST-18 records RPM metadata and dependency resolution in a disposable AlmaLinux 9 container. AlphaLink's manifest/hash verifier is an infrastructure safeguard; it does not establish DMS parity, vendor provenance, target compatibility, or license entitlement. INSTALL remains disabled.

## DMS-INST-001 row-preparation checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Actual AlphaLink target preflight | Verified read-only. | The saved host reports AlmaLinux 9.8, x86_64, and dnf; no PMTA binary is installed. The host key is pinned internally and never shown or requested. |
| Actual global-IP discovery | Succeeded with a read-only DMS command. | Three global addresses were returned (two IPv4, one IPv6); values were not displayed or stored in project evidence. |
| Local install eligibility | Not ready. | The owner username mapping is blank and there are zero eligible domains, so the DMS bulk selector has no server/domain pair. |
| Full PHP suite and Pint | **87 tests / 618 assertions pass; Pint passes.** | DMS-mapped selected-row preparation loads the workspace/server credential and calls read-only IP discovery only after host preflight. |

E-INST-16 adds a DMS-eligible row preparation service and tests. It does not run host changes and is not connected to a production runner. The remaining installation dependencies are the DMS-compatible provider/domain inputs, PMTA package and matching license validation, real host/DNS/persistence adapters, per-server process/run/log lifecycle, rollback and health checks, then visual comparison of the complete DMS log flow. INSTALL remains disabled until the end-to-end flow is demonstrated equivalent.


## DMS-INST-001 read-only IP discovery checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 IP discovery + asset/matrix tests | **11 passed, 78 assertions.** | Synthetic output verifies DMS ordering/deduplication, invalid/empty guards, private-root assets and version branches. |
| Full Docker PHP 8.3 suite | **82 passed, 603 assertions.** | All current PHP tests pass. |
| SSH/DMS operations | Not invoked. | The command adapter is isolated; no server or reference-system state was touched. |

E-INST-15 implements DMS’s read-only global-IP discovery path behind a previously verified host key and reuses the planner’s exact address ordering. It is not wired to a production run. INSTALL remains disabled. Next dependencies: connect this adapter and verified file inventory to a real per-server runner, then implement the remaining service, DNS/ISP, PMTA and database phases plus the DMS polling/log UI.

## DMS-INST-001 source-mapped artifact inventory checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 inventory + OS/PMTA matrix | **7 passed, 71 assertions.** | Compares synthetic local asset trees to DMS source paths and verifies private-root, no-symlink and DMS skip behavior. |
| Full Docker PHP 8.3 suite | **78 passed, 596 assertions.** | Existing coverage plus the new inventory tests. |
| DMS/host/provider operations | Not invoked. | This is a local file-presence diagnostic. It does not verify package, license, compatibility or execute any installation. |

E-INST-14 traces the CentOS 7/9 asset checks in DMS. AlphaLink `DmsBulkInstallAssetInventory` reports present, missing or unsafe files under a private asset root, maps missing optional files to the actual DMS skip/partial behavior, and reproduces the PMTA RPM/patch guard. It is not connected to a production route or runner, does not prove artifact validity, and leaves INSTALL disabled. The next install dependency remains a real DMS-compatible runner with validated PMTA assets, remote IP discovery, host/database adapters, and DMS-equivalent progress display.

## DMS-INST-001 OS/PMTA installer matrix checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 DmsBulkInstallInstallerMatrixTest | **3 passed, 49 assertions.** | Covers the six OS/version combinations, package managers, versioned assets and patch targets, PMTA admin-port selection, reverse-proxy asset differences, PMTA 5.0 directives, selected optional assets and the source RPM/patch guard truth table. |
| Full Docker PHP 8.3 suite | **74 passed, 574 assertions.** | Includes the new source matrix and existing platform, planner, feature and unit tests. |
| Docker PHP 8.3 Pint | Passed on three changed PHP files. | The installer matrix, platform selector and unit test match project formatting. |
| DMS/host/provider operations | Not invoked. | Source inspection only; tests use local synthetic release/options values. No package, license, host, DNS provider, firewall or PMTA operation was accessed. |

DMS source maps CentOS Linux 7 to yum and CentOS Stream 9/Rocky Linux 9/AlmaLinux 9 to dnf. PMTA versions use different RPM, patch, license and domain files; 4.5/5.0 also use Nginx with OS-specific local config inputs, and 5.0 adds two HTTP directives. The source’s early-return gate only skips when both the RPM and patch are absent. AlphaLink now records that matrix as pure data; it does not validate, stage or execute the artifacts. The actual package/license bundle and production host runner remain blockers, so INSTALL stays disabled.

**Checkpoint:** 2026-10-23. The root Git repository starts at `5797a6f` (`Start evidence-backed AlphaLink reconstruction`). This is a fresh local checkpoint, not recovered upstream history. Current validated work includes the server management/history slices and the in-progress DMS bulk-install reconstruction.

## DMS access and safety

Read-only DMS browser access is restored through the existing project `.env` proxy and the local relay that allows browser GETs only. The relay made one normal login/session POST upstream. The sanitized redirect trace and inspected pages are recorded as E-SRV-LIVE-01. For the latest list inspection, the relay returned synthetic empty DataTables JSON without making the production row-data request; it also hid the production account label. No DMS product form was submitted, no SSH session to a DMS-managed host was opened, and no product or operational state was changed. The relay is stopped and does not expose `.env` values.

## Current implementation slice

DMS-SRV-001 (server registration) and DMS-SRV-002 (server list/returned rows) remain `TESTING`, not accepted. AlphaLink has a provider-backed DMS-shaped registration form, multi-IP API flow, encrypted credential storage, expiry and separate Test Connection UI, plus a workspace-scoped list, returned-row filter and DMS-style Add modal. The local IP guard blocks private, reserved and documentation ranges before socket creation. A synthetic local registration was displayed and removed after verification.

DMS-SRV-003 (Allowed Users, lock/status, return and restore) is `IMPLEMENTING`. Source evidence E-SRV-02 maps DMS mailers to workspace operators and DMS managers to workspace owners/admins. The local backend applies same-workspace operator assignments, filters operators to assigned servers (including returned rows), permits assigned operators to change status or return unlocked servers, and limits assignment/lock/restore to owners/admins. Returning soft-deletes the row; locked servers cannot be returned. Individual and bulk permission forms match the DMS interaction, and transitions emit audit events. Tests verify cross-workspace rejection and state changes. Populated visual comparison of the controls remains open; production DMS rows stay hidden by the relay.

DMS-SRV-004 (server health history) is `IMPLEMENTING`. E-SRV-03 traces DMS's every-ten-minute job: active installed servers receive concurrent SSH, Apache/port 80 and PMTA/configured-port checks; the job stores down flags with timestamps and fills missing country by Pro IP-API with an end-of-day cache and MaxMind city fallback. DMS history shows ID, three service states and time; records are pruned after three months. AlphaLink now schedules concurrent raw-TCP port checks, persists workspace-scoped history, shows latest SSH status/history, and prunes after three months. These checks use no SSH login or fingerprint prompt. PMTA defaults to 1212 and is configurable. The same API country contract is implemented; MaxMind fallback needs GeoIP2 and a database, and the target does not currently include GeoIP2. Focused tests pass (6/27), and the latest full suite passes (41/422). Tests fake HTTP and use loopback; no external server or geo API was contacted.

The owner confirmed that DMS does not request an SSH fingerprint during Test Connection or installation. AlphaLink no longer displays or accepts fingerprint input: Test Connection logs in directly over SSH/SFTP, and preflight automatically pins the first host key internally so later key changes are rejected without an operator prompt. DMS live SSH/install actions remain untested and prohibited. The installation execution route remains disabled.

DMS-INST-001 bulk installation remains `IMPLEMENTING`: the DMS-shaped page now matches source-backed eligibility, options, APPLY-to-all (including applying the blank selection), owned-domain guard and row-removal confirmation. The submission builder drops rows with no domain and sends the DMS-shaped fields; a backend contract validator checks the nonempty row list, numeric key, PMTA 40/45/50 and seven booleans, reducing rows to server/domain IDs. An injected fake-runner coordinator verifies DMS's row-level exception logging and parent-success semantics. `DmsBulkInstallSubdomainGenerator` now carries the exact 1,022-entry DMS word pool and Laravel random selection; a SHA-256 test checks its ordered contents. `DmsBulkInstallIpPlanner` reproduces IPv4-before-IPv6 ordering, packed binary sorting, duplicate removal, primary-IP flag and first/primary alias rules. Four synthetic IP tests compare formatted rows, exercise generated aliases and feed output through DNS/VMTA planners. `DmsBulkInstallDnsRecordPlanner` reproduces guarded DNS record shapes/order, SPF/DKIM/DMARC options and private-IP fallback; four synthetic tests cover those results. `DmsBulkInstallVmtaPlanner` renders DMS's per-IP/per-ISP VMTAs, IPv6 naming, optional domain-key lines, owned-domain inbound blocks and CentOS 9 feedback loop; three synthetic tests compare exact config strings and branch differences. All planners are offline and have no provider/PMTA adapter. Read-only remote address discovery is implemented (E-INST-15) but not wired to the runner; an AlphaLink ISP data source remains unavailable; the DMS name normalization currently accepts only supplied names. The usual Cloud page selects non-owned domains, so its DNS write branch is a no-op. The coordinator remains sequential; no production submission/run-start route or remote runner is registered. E-INST-12 now persists synthetic-run transcripts and exposes the workspace-scoped GET read endpoint, but no production run feeds it and no browser polling/log UI exists. E-INST-13 maps the CentOS 7/9 PMTA asset/option branches offline; artifact validation and all remote execution remain absent. INSTALL remains disabled. DMS source shows SSH/SFTP login, x86_64 and supported OS checks, IP discovery, host/DNS/service changes, PMTA/VMTA/firewall setup and installation-state writes. No fingerprint prompt appears in that source flow. Username/provider mappings, populated-page comparison, package/license validation, rollback, remote progress and full topology remain open. No DMS or AlphaLink server was contacted.

The next completed offline slice maps DMS `finish_install()` finalization: delete old server MTAs/IPs, insert the formatted IP rows with owned-domain DNS rules, conditionally mark an owned domain `used`, save install parameters, and mark the server installed last. `DmsBulkInstallCompletionPlanner` plus three synthetic tests model this operation order and reject malformed row/options input. The planner does not write a database; the DMS code has no transaction in the inspected function, so database failure effects remain unverified. E-INST-09 and the parity/blocker records capture these limits. The feature remains `IMPLEMENTING`; INSTALL remains disabled.

The following offline slice models DMS `ServerInstallBulk::install()` order and early guards in `DmsBulkInstallSequencePlanner`: installed servers and used domains stop before setup; zero discovered IPs aborts before host changes; option flags gate tracking/PHP, IMAP, proxy and HTTPS; DNS provider work is a no-op for non-owned domains. Four synthetic tests compare full phase ordering and failure messages. It is not connected to a runner, and host-phase failures remain unverified.

The fake-runner coordinator now writes DMS-formatted timestamped start/progress/error lines through an optional callback into a private, workspace-keyed append-only transcript file. The authenticated workspace log endpoint returns the exact transcript as a JSON string. A frozen-clock feature test runs the fake coordinator through persistence and reads the result; other tests cover cross-workspace isolation, operator/nonmember/unauthenticated denial and missing IDs. E-INST-11 records the DMS file append/JSON response and two-second polling plus twenty-second post-request window; E-INST-12 records the local store and endpoint. No production run-start path, real runner, polling client or log UI is connected, so INSTALL remains disabled.

The live DMS and AlphaLink form/modal and filtered list empty state were compared in-browser at an equal 863×892 CSS viewport. The Add fields/order/defaults and modal structure are closely matched, and the no-match table state uses the same label. Parity remains open: DMS credential-storage and partial-failure rules, exact Test Connection outcomes, country and IP relations, automatic health collection, console/edit actions, and populated row-state comparison remain incomplete. The local history page was validated through synthetic tests; it has not yet had a populated browser comparison against a synthetic DMS capture.

DMS-AUD-002 is now `IMPLEMENTING`. The local import service applies lowercase normalization, email validation, conflict-do-nothing behavior and one-list assignment within a workspace. It writes encrypted contacts, append-only membership events, audit records and outbox events. The source Lists page was inspected through the existing `.env` proxy with synthetic ISP options and an empty table; its `/admin/lists/:id/load` GET remains uncalled because safety is unknown. The AlphaLink ISP entity and matching Lists UI are still missing, so no audience feature is accepted.

## Verification

Latest verification: Docker PHP 8.3 full suite **82 tests / 603 assertions**; focused remote-IP + asset inventory + OS/PMTA matrix **11 / 78**, transcript endpoint/coordinator **7 / 18**; install-sequence planner **4 / 14**, finalization planner **3 / 10**, ISP mapping **3 / 4**, IP/alias **7 / 51**, DNS/VMTA **7 / 11**; Node bulk-install tests **3 passed**. Pint passed on the current changed PHP files; git diff --check passed after evidence updates. The local INSTALL control remains disabled: no production run route/runner or browser log polling/UI exists. No fingerprint prompt was added; no connection test, installation, DNS provider write, PMTA config write or server probe was submitted. See docs/implementation/TEST_REPORT.md and docs/implementation/PARITY_REPORT.md.

## 80% target

The requested 80% means implemented, evidence-backed frontend and backend workflows that work locally and meet the acceptance gates. The feature inventory is incomplete, complex workflow weights are not yet defined, and no feature is `PARITY_CONFIRMED`; therefore no honest 80% percentage is available. Do not count screens, routes, documents, or code files as completed coverage. Continue with reference evidence and one dependency-ready vertical slice at a time.
