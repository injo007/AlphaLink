## E-CAMP-34 — Live Symfony transport recheck — 2026-10-24 11:35 UTC

- Actual AlphaLink runtime: bootstrapped Laravel in the running PHP container, opened the configured Symfony ESMTP transport, and completed the SMTP connection handshake to `152.228.228.236:25`.
- Result: **connection succeeded; no email was sent**. A handshake does not establish relay acceptance or mailbox delivery.
- Focused regression: `CampaignTestEmailFlowTest` **15 passed / 146 assertions**; `IspInstallationCatalogTest` **8 / 91**; `WorkspaceSponsorManagementTest` **9 / 139**; ISP/Sponsor checkbox JavaScript **2 / 2**.
- The live draft remains blocked by reserved `hello@example.test`; the workspace has no verified sender domain. The first real test requires a domain the user controls with AlphaLink TXT verification and one explicitly authorized inbox. DMS's native test-send flow remains a separate, unimplemented parity target.

## E-CAMP-30 — Fresh first-test-email readiness check — 2026-10-24

- DMS source check: `CampaignController::test_manuel` requires explicit test addresses and server/IP/send-mode inputs, builds the DMS native test config, and invokes its native sender. AlphaLink's requested Symfony Mailer flow is separate and remains a documented difference.
- Live AlphaLink check: authenticated campaign detail and Domains pages on 8766 show reserved `hello@example.test`, two pending sender domains, and zero test attempts. A read-only public DNS check found neither exact AlphaLink proof TXT record. No DNS or campaign state was changed.
- Runtime configuration: default mailer and SMTP transport are `smtp`, with host/port configured; no SMTP username is configured. No secret values were printed.
- Command: `docker exec -w /app suspicious_solomon php artisan test tests/Feature/CampaignTestEmailFlowTest.php`
- Result: **14 passed / 138 assertions.** These synthetic/mocked tests verify application logic, not live email delivery.
- No real email was sent. Remaining inputs are a sender domain the user controls, its exact TXT proof published and verified, a saved message revision using that sender, and one authorized recipient inbox.

## E-CAMP-28 — Test-email sender readiness panel — 2026-10-24

- Change: test-draft entry now lists the workspace's verified and pending sender domains. Pending rows show the exact AlphaLink DNS TXT host/value; verified rows show the usable From-address pattern. The existing Domains page remains the place to publish/check ownership proof.
- DMS comparison: the read-only native `test_manuel` path still requires explicit test addresses and selected server/IPs, then invokes the native sender. This Symfony Mailer route is a separate AlphaLink-specific flow.
- Command: `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/CampaignTestEmailFlowTest.php`
- Result: **13 passed / 125 assertions**. Pint and `git diff --check` pass.
- Browser: authenticated test-draft page on port 8766 rendered correctly and showed two pending domains. No DNS update, draft save or email send was performed. One verified domain under user control and an authorized destination inbox are still required.

## E-CAMP-27 — Campaign-create test-email path — 2026-10-24

- Changed campaign creation to expose the standalone Symfony test-draft route before the marketing-targeting form. This is an AlphaLink-specific test path; DMS manual Email Test instead requires selected server/IPs and invokes the native sender.
- Added an endpoint feature test that renders the direct route link with no Sponsor, Offer, ISP or List selected and confirms that rendering creates neither a campaign nor a mail attempt.
- Command: docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/CampaignTestEmailFlowTest.php
- Result: 12 passed / 115 assertions. Pint and git diff --check pass.
- Live AlphaLink browser at 8766 shows the new link. The no-email SMTP handshake last succeeded at 10:16 UTC. No message was sent. A user-controlled verified sender domain and one authorized inbox remain required.

## E-CAMP-26 — Live Symfony test-email readiness — 2026-10-24

The effective 8766 runtime reports the Symfony SMTP mailer with host/port set and no authentication. The authenticated campaign page's **Check SMTP connection (no email)** action succeeded at 10:16 UTC and visibly recorded success. No message was sent. `CampaignTestEmailFlowTest` plus `DmsAudienceListHistoryTest`: **16 passed / 147 assertions**. The live workspace has 2 active sender domains, 0 verified; the saved demo revision uses reserved `hello@example.test`, and has zero test attempts. A real message remains blocked on a user-controlled TXT-verified From domain and one explicitly authorized inbox. This is an actual SMTP handshake, not a simulated delivery.

## E-CAMP-24 — Current first test-email readiness check — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| Live AlphaLink campaign | **Blocked safely.** | Authenticated 8766 page still shows reserved `hello@example.test`, no saved DMS test addresses and zero test attempts. The page shows the last no-email Symfony SMTP handshake succeeded at 08:51 UTC. No message was sent; handshake is not relay acceptance or delivery. |
| Sender domain | **Not verified.** | Read-only public DNS checks compared both saved AlphaLink TXT proofs; neither exact value is present. No DNS write and no AlphaLink database update occurred. |
| Regression tests | **36 passed / 304 assertions.** | ISP management, Audience List lifecycle/import, DMS-format upload and Symfony test-email flow ran with local synthetic data and mail transport. The Sponsor/provider refresh regression separately passes 43 tests / 436 assertions. |
| Next required input | **Controlled domain and authorized inbox.** | Publish the AlphaLink TXT proof for a sender domain the user controls, verify it in AlphaLink, save a new revision using that domain, and provide one recipient inbox explicitly authorized for this test. The Symfony one-recipient flow is an AlphaLink-specific deviation from DMS native sending. |

## E-CAMP-25 — Live first test-email gate recheck — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| Authenticated AlphaLink page | **Blocked safely at 10:00 UTC.** | Campaign detail still shows reserved `hello@example.test`, no saved DMS Email Test addresses, zero attempts, and no send form until the sender domain is verified in this workspace. The most recent no-email handshake shown is 09:14 UTC. No form was submitted and no message was sent. |
| Regression | **456 passed / 3,649 assertions.** | The full PHP backend suite passes. The focused ISP/List/import/Symfony test-email run is 37 / 316. Three stale assertions were aligned to DMS source behavior; all fixtures/transports are synthetic and do not verify PMTA acceptance or delivery. |
| Remaining input | **User-controlled sender and authorized recipient.** | Publish and verify the exact AlphaLink TXT proof for a sender domain you control, save a new revision using that domain, and provide one inbox authorized to receive the test. |
| Parity state | **Open.** | The requested one-recipient Symfony Mailer path differs from DMS's selected server/IP plus native sender test; no DMS parity or live email is claimed. |

## E-AUD-06 — DMS List History page — 2026-10-24

`DmsAudienceListHistoryTest`: **5 passed / 38 assertions** using synthetic workspaces, ISPs, lists and history snapshots. Covers route/navigation render, workspace-scoped dependent list lookup, date-filtered single-list history, ALL LISTS per-day aggregation, NA for five unsupported DMS counters, invalid ranges, cross-ISP rejection and role denial. The additive migration was applied to the live AlphaLink 8766 database; it adds nullable counters only and does not read or modify list rows. Pint passed for the changed PHP files; live route registration and the authenticated page render were verified. No data-list rows were loaded. DMS's daterangepicker/presets remain a visual and interaction difference.

## E-AUD-05 — DMS List history counts — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS source | **Verified read-only.** | `EmailAction::get_email_counts` counts 11 `Email.type` values; `ListsAction::process_list` queues an immediate refresh and `ListCalculateHistory` upserts by List/date on the hourly schedule. |
| AlphaLink implementation | **Source-mapped for supported data.** | AlphaLink stores a workspace/list/date snapshot for the six membership `email_type` values, refreshes it after both import entry points, and schedules a source-matched hourly update. The ISP→Lists grid reads the latest snapshot. The new empty snapshot table is deployed to the live 8766 database; no existing contact rows were queried. The five DMS-only types remain `NA`, since AlphaLink has no equivalent counters. |
| Regression | **12 passed / 90 assertions.** | `DmsAudienceListManagementTest` and `DmsListUploadTest` ran in the PHP 8.3 app container with synthetic contacts; they cover count updates, same-day idempotence, grid response, no address exposure and post-upload snapshotting. No production list rows were read. |
| Parity state | **Testing.** | DMS uses global address uniqueness and stores all 11 email-type counts. AlphaLink is workspace-scoped and currently persists six count types; migration-wide and five-type equivalence remain unverified. |

## E-AFF-09 — DMS status-triggered and scheduled offer info refresh — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS behavior | **Source traced.** | `OffersUpdateInfo` runs daily at 04:00 for active live offers from active sponsors. Setting a selected offer live invokes the same refresh for that offer without daily eligibility filters. Returned records update only name, payout, redirect, and nonempty unsubscribe; a name containing “pause” pauses the offer; missing records pause it; exceptions preserve previously saved fields; HTML creatives are deduplicated. |
| AlphaLink implementation | **Source-mapped.** | `DmsSponsorOfferRefresher` handles provider mapping and failures; the business-status route refreshes after saving `live`; `workspace:offers-refresh` supports scheduled and targeted execution. The scheduler registers the command daily at 04:00. |
| Regression | **6 passed / 36 assertions; combined 43 passed / 436 assertions.** | Fake provider responses cover metadata updates, blank unsubscribe preservation, pause/missing behavior, connection failure, no call on non-live status, daily eligibility, and targeted refresh of an inactive paused offer. No live affiliate API was called. |
| Parity state | **Partial / open.** | Provider rules are implemented and synthetic-tested; live provider behavior, browser-helper handoff, creative-share round trip, and conversion/revenue reconciliation remain unverified. DMS-SPO-001 and DMS-AFF-001 are not accepted. |

## E-AFF-08 — HasOffers selected-offer import — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS reference | **Source traced.** | The selected HasOffers offer path is traced to `HasOffers.php::get_offer`, its category/geo/tracking/CRM helpers, shared network mapping, and `OfferAction::add_auto`. |
| AlphaLink implementation | **Selected import implemented.** | AlphaLink maps DMS offer fields and performs the DNE unsubscribe or CRM opt-out fallback. The Add Offer page lists HasOffers with the other four import adapters. |
| Regression suite | **52 passed / 544 assertions.** | HasOffers, NewHitPath, Cake, HitPath, EverFlow, creative assets, Sponsor/Offer management and Symfony test-email flow pass with synthetic provider responses and DNS. |
| Provider access | **Not attempted.** | No affiliate credentials or provider APIs were accessed; the live workspace has no Sponsor to select. |
| Parity state | **Partial / open.** | Provider behavior needs a user-owned HasOffers account. Login/session, refresh, extension sharing and conversion reconciliation remain unimplemented; DMS-AFF-001 is not accepted. |

## E-AFF-07 — NewHitPath selected-offer import — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS reference | **Source traced.** | Selected `/api/campaigns/{id}` lookup, sender assets, HTML/images, rule mapping and inactive Offer creation are traced to `NewHitPath.php`, `Network.php` and `OfferAction::add_auto`. |
| AlphaLink implementation | **Selected import implemented.** | The Add Offer flow now enables NewHitPath; campaign/email APIs use the saved bearer token. AlphaLink imports the source fields and email creatives, with bounded public-HTTPS image fetch and DMS link placeholders. |
| Regression suite | **38 passed / 395 assertions.** | NewHitPath, Cake, HitPath, EverFlow, creative, Offer and Sponsor suites pass using synthetic DNS and provider responses. Tests cover exact duplicate/normalization order, authorization, link rewrites, image assets, rate limiting and missing redirects. |
| Transport difference | **Not accepted as equivalent.** | DMS disables TLS verification and forwards the bearer token to image URLs. AlphaLink verifies TLS, pins public DNS, disallows redirects, bounds image bytes/types, and forwards the token only to the configured provider host. Providers requiring the DMS insecure behavior need live validation; no affiliate credential was accessed. |
| Provider access | **Not attempted.** | No affiliate credentials, offer rows or provider API were accessed. The live AlphaLink Add Offer page has no saved Sponsor to select. |
| Parity state | **Partial / open.** | No live NewHitPath account was tested. HasOffers, provider login/session, refresh, extension sharing and conversion reconciliation remain gaps; DMS-AFF-001 is not accepted. |

## E-AFF-06 — Cake selected-offer import — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS reference | **Source traced.** | Read-only `Cake::get_offer`, shared `Network` mapping and `OfferAction::add_auto` define selected campaign lookup, offer fields, inactive creation and asset persistence. |
| AlphaLink implementation | **Selected import implemented.** | Cake is import-enabled in the DMS-style Add Offer form. The adapter passes the API key, affiliate ID and selected campaign ID to the source endpoint; maps first response entry, UK→GB, US fallback, price/type/days, normalized From/Subject, first redirect, unsubscribe and restrictions; and imports available bundles with the shared bounded safe archive processor. |
| Regression suite | **35 passed / 359 assertions.** | Cake, HitPath, EverFlow, creative import, Offer management and Sponsor management use synthetic responses/credentials. Cake tests include missing affiliate ID, invalid IDs, provider failure, sender mapping, geo fallback and creative bundle link/image transformation. |
| Provider access | **Not attempted.** | No real affiliate credentials or provider records were read and no provider API was called. Live provider behavior is unknown. |
| Parity state | **Partial / open.** | Cake's selected-offer import is source-mapped, but no live Cake account was used. NewHitPath, HasOffers, login/session, refresh, extension sharing and conversion reconciliation remain gaps; DMS-AFF-001 is not accepted. |

## E-CAMP-18 — Transactional test-draft entry point — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| Campaign UI route | **Passed.** | The register now exposes a distinct transactional “New test message” form without marketing Sponsor/Offer/ISP/list fields. It posts an immutable draft; the Symfony test send is a separate action on the saved draft. |
| DMS behavior boundary | **Read-only; deviation explicit.** | DMS manual Email Test selects recipient addresses and server/IPs, then invokes the native sender. AlphaLink's requested test uses one explicit recipient and Symfony Mailer. Do not call it DMS send parity. |
| Focused PHP suite | **11 passed / 109 assertions.** | `CampaignTestEmailFlowTest` ran in the active PHP 8.3 app container. Saving transactional test content creates no DMS marketing setup, recipient snapshot, or mail attempt. Existing synthetic transport tests exercise only isolated test cases. |
| Live email | **Not attempted.** | The current saved demo uses a reserved From identity. A user-controlled From on a verified workspace domain and one explicitly authorized inbox are still missing. No address was inferred and no email was sent. |

## E-CAMP-21 — Fresh SMTP readiness and ISP/Sponsor regression — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| Live AlphaLink SMTP handshake | **Passed at 09:14 UTC.** | Re-ran “Check SMTP connection (no email)” in the authenticated 8766 campaign page. The result persisted visibly as a successful handshake. No message was sent. |
| Live sender readiness | **Blocked.** | The saved demo revision still uses a reserved example/test sender; both existing AlphaLink sender-domain proofs remained Pending after checking them in the live Domains page. The test-attempt count is zero. A controlled verified From domain and one explicitly authorized inbox are required. |
| Combined feature regressions | **55 passed / 532 assertions.** | ISP, audience-list, Sponsor, Offer, Offer asset sharing, and Symfony Mailer test flow suites ran in the PHP 8.3 AlphaLink container with synthetic data/transports. |
| DMS comparison | **Difference remains explicit.** | DMS manual Email Test uses saved recipient addresses plus selected server/IP settings and invokes its native sender. AlphaLink uses the separately requested one-recipient Symfony Mailer path; parity is not claimed. |

## E-INST-52 DMS installer-setting normalization — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Reference source | **Verified.** | DMS `SettingsController::store` supplies defaults 1212/1213/1214 and false for missing firewall/monitor switches; CentOS 9 firewall false branch stops/disables firewalld and iptables. |
| AlphaLink runtime resolution | **Passed.** | With no workspace settings row, source defaults resolve to PMTA/admin/proxy ports 1212/1213/1214 and false firewall/monitor flags. Saved workspace values still take precedence; explicit invalid settings remain rejected. |
| Focused PHP tests | **Passed.** | Runtime input suite: 4 tests / 15 assertions. |
| Side effects | **None.** | This changes config resolution only; execution remains off and PMTA readiness remains blocked. No SSH, firewall, package or DMS mutation ran. |

## E-INST-53 authenticated DMS username mapping — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Reference identity route | **Verified, read-only.** | DMS `/profile` maps to `AuthController::profile`, which renders the authenticated user's profile. Relay output exposes only the username needed by DMS bulk eligibility; full name, account type, CSRF token, password form and other profile markup are removed. |
| Relay privacy tests | **Passed.** | Synthetic profile sanitizer tests verify only the username survives and fail closed on missing/ambiguous fields; complete relay suite: 18 tests. |
| AlphaLink mapping | **Saved.** | The current authenticated DMS username was saved to the local AlphaLink owner through its existing form. No username value is copied into evidence. |
| Eligibility recheck | **Blocked by source rule.** | Local read-only aggregate: one eligible provider-backed domain, zero eligible servers. The sole saved server name does not match the DMS username-in-name filter. |
| Host/reference operations | **None.** | No DMS mutation, server rename, SSH, install, package, DNS or firewall operation ran. |

## E-INST-51 bulk-install request preflight and DMS comparison — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Runtime settings/assets guard | **Passed.** | With PMTA readiness faked and required installer settings missing, the POST returns 503 before a transcript, child process, or row runner is created. |
| DMS empty-state control | **Passed.** | AlphaLink renders the INSTALL control enabled like the DMS source; the default-off server gate remains authoritative. |
| Failure feedback | **Passed.** | A server-side prerequisite response appears in the existing Installation Logs area and the button returns to INSTALL. |
| DMS visual comparison | **Partial.** | Sanitized, proxy-served DMS and AlphaLink empty-state pages were inspected at 1280×720. Content card, controls and button align; the AlphaLink workspace/user header remains different. No populated server row was captured. |
| Focused PHP / Node | **Passed.** | PHP: 35 tests / 404 assertions; Node bulk install: 5 tests. |
| Pint / host effects | **Passed / none.** | Changed PHP files pass Pint. No SSH, install, package, DNS, firewall, DMS mutation or transcript creation was run outside synthetic tests. |

## DMS-INST-001 production row-runner wiring — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Container binding | **Passed.** | The concrete AlmaLinux 9 runner resolves with the fail-closed DNS adapter while execution is configured off. No session or network operation is started by resolution. |
| Child execution gate | **Passed.** | A worker command with the default-off gate writes a failure transcript marker before workspace/server lookup; preparation and row-runner mocks are not called. |
| Owned-domain DNS boundary | **Passed.** | The production adapter throws before provider mutation. DMS bulk eligibility excludes owned domains; the selectable path skips this adapter. |
| Focused PHP tests | **14 passed / 66 assertions.** | Worker, runner-binding and domain-record tests use synthetic fixtures. |
| Full PHP suite | **218 passed / 1,334 assertions; 9 PHPUnit notices.** | All API tests pass. Notice diagnostics point to existing PHPUnit mock tests; no new binding or gate test emitted a notice. |
| Pint and diff check | **Passed.** | Changed PHP files formatted; `git diff --check` is clean. |
| Host/DMS operations | **None.** | The environment flag is false; no install, DNS mutation, DMS mutation or remote host command ran. |

E-INST-50 closes the dependency-injection gap while preserving the hard safety gate. This is runner wiring, not install acceptance: the PMTA gate, runtime settings, eligible server row, and controlled end-to-end installation proof remain outstanding.

# Verification record — current through 2026-10-23

## DMS-INST-001 installer settings and PMTA domains editor — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| DMS source mapping | `SettingsController`, `Settings::save`, `Ssh`, `Centos9`, routes and settings Blade | Mapped ports, boolean normalization, IPv4 filtering, per-version PMTA-domain load/save and installer consumption. DMS settings are global JSON. |
| AlphaLink storage and runtime | Feature and unit tests with synthetic data | Workspace-scoped encrypted settings and PMTA-domain text; runtime maps DMS names and passes the chosen version's saved config to the fakeable upload stage. Audit metadata excludes values. |
| UI comparison | Sanitized DMS GET and local AlphaLink page at 1280×720 | PMTA tab/editor geometry and components align. The general page omits non-install DMS controls; saved values are intentionally not shown and populated config comparison remains open. No full-page parity claim. |
| Verification | PHP 215 passed / 1,325 assertions; Node 4 passed; relay 15 passed; Pint and strict UI audit clean | No DMS mutation or host action. Container reports all 13 auxiliary DMS installer settings empty and the PMTA listener falls back to 1212; install remains blocked. |


## DMS-INST-001 finalization persistence checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| `DmsBulkInstallCompletionWriterTest` | **4 passed, 18 assertions.** | Synthetic DB fixtures verify MTA-before-IP deletion, IP insert mapping, non-owned/owned domain behavior, full install option persistence, installed-last ordering, workspace isolation, and source-compatible partial writes on a finalization failure. |
| Local SQLite migration | Applied. | Adds workspace-scoped managed-server IP/MTA rows and `install_parameters`; no production records or remote host are involved. |
| Full Docker PHP suite | **132 passed, 910 assertions.** | Includes lifecycle, preparation and completion-writer integration coverage. |
| PHP formatting / migration tests | Passed. | Pint passed on the new models, writer, migration, and feature test. |
| Host/DMS effects | None. | The completion writer is not wired to a production runner, and the hard-off execution gate remains unchanged. |

E-INST-27 maps `ServerInstallBulk::finish_install` and `IpAction::remove_old_server_ips`. AlphaLink mirrors the ordered DB writes, including DMS's no-transaction partial failure behavior, using workspace keys. This proves local persistence behavior only; no installation has invoked the writer.

## DMS-INST-001 run-start and log lifecycle checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| `InfrastructureBulkInstallLogsTest` | **11 passed, 40 assertions.** | Uses a fake runner to verify default-off 503/no transcript, run/log response and numeric-key scope, eligibility rejection, operator denial, and host-preflight failure before runner invocation without any fingerprint prompt. |
| Node bulk-install tests | **4 passed.** | Payload serialization plus the fake browser flow verify blank-row removal, selected-row submission, CSRF, two-second log polling, and the 20-second grace period. |
| Full Docker PHP suite | **132 passed, 910 assertions.** | The new lifecycle and preflight wrapper pass alongside the existing server, domain/provider, planning and installation contract tests. |
| Pint and `git diff --check` | Passed. | PHP files were formatted and checked explicitly because the app container has no Git metadata for Pint's `--dirty` mode. |
| Route and diff checks | Passed. | Authenticated POST run-start, numeric-or-UUID log GET, and `git diff --check` are clean. |
| DMS and target operations | None. | The POST was exercised only against a local fake runner. Production execution is hard-disabled; no DMS mutation, SSH, DNS, package, or installation ran. |

E-INST-26 maps DMS `mailer.php` route definitions, controller validation/process behavior, and Blade polling to the AlphaLink implementation. The same request contract is accepted, source eligibility is rechecked, and each row passes through the internal preflight/credential/read-only IP/ISP preparation service before runner delegation. The new local runner path uses the existing sequential coordinator, whereas DMS forks server installs; that remains a known parity difference. The frontend poll path is wired but INSTALL is still disabled because no production runner or complete installation safeguards are available. The feature remains `IMPLEMENTING`, not accepted.

## DMS-INST-001 Domains-to-install dependency checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| DMS vs AlphaLink Domains page and Add Domains manual modal | Compared through the existing `.env` read-only relay at 1280×720. | The empty DataTable, ONLY OUR switch, 50-row control, toolbar and iframe modal placement match. DMS's provider option was synthetic; AlphaLink correctly has no provider option because the workspace has no provider. No domain or provider form was submitted. |
| `DmsDomainManagementTest` | **9 passed, 70 assertions.** | Covers table shell/modal hosting, workspace filter, manual-domain DMS defaults, status/active/delete/audit behavior, history isolation and fake GoDaddy refresh. |
| Full Docker PHP suite | **123 passed, 865 assertions.** | Includes current installation, server, provider/domain and planning tests. |
| DMS relay tests | **12 passed.** | Confirms the domains page/modal is GET-only and domain table requests remain synthetic/blocked. |
| Docker PHP 8.3 Pint / `git diff --check` | Passed. | No formatting or whitespace errors in this slice. |
| Workspace readiness | Owner username unset; one active, uninstalled server; zero active domain providers and zero domains. | The DMS selector therefore has no eligible server/domain pair. No data was invented or copied from production rows. |
| Host/DMS/registrar operations | None. | The real AlmaLinux 9 server was not accessed or changed; no registrar credentials or live provider were used. INSTALL remains disabled. |

E-INST-25 identifies DMS's `DomainController::index` DataTable and Bootbox iframe behavior and records the same-viewport browser comparison. This closes the previous AlphaLink parent-modal gap and provides the DMS manual-domain path needed to produce selectable non-owned rows. The page is still not accepted as full domain parity: the DMS RECORDS row action and scheduled domain DNS/history collector are not implemented. The installation as a whole remains `IMPLEMENTING` because the username/provider/domain data, real run-start and per-server runner, artifacts/license validation, rollback and post-install health checks are unresolved.

## DMS-INST-001 registrar import checkpoint — 2026-10-23

| Check | Result | Limit |
|---|---|---|
| Focused ISP catalog and install row-preparation tests | **16 passed, 106 assertions.** | Covers DMS ISP name validation, source-country options, workspace access, active/inactive VMTA name input, lowercase/deduplication, and generated VMTA text. All rows and credentials are synthetic. |
| Focused `DmsDomainProviderImportTest` | **6 passed, 47 assertions.** | Tests GoDaddy active-domain mapping, Namecheap paging/locked-expired filtering, duplicate handling, credential precheck, generic provider errors and workspace/role limits. HTTP is faked. |
| Full Docker PHP 8.3 suite | **114 passed, 795 assertions.** | Includes ISP catalog and registrar import coverage. |
| Docker PHP 8.3 Pint | Passed on changed PHP files. | Formatting check for the current implementation slice. |
| Local ISP migration | `2026_10_23_020000_create_workspace_isps_table` applied. | Additive workspace-scoped table; no local ISP rows were imported or created. |
| DMS ISP source/form | `IspController`, `IspAction::build_vmtas()`, country helper and authenticated GET `/admin/isps/create` checked through the `.env` proxy. | Source says inactive ISP names are included and then lowercased/deduplicated. DMS form was not submitted. AlphaLink uses a local custom country picker and direct-page flow rather than DMS Select2 inside an iframe modal; UI is not accepted as equivalent. |
| DMS AUTO import form comparison | DMS `/admin/domains/create` through the sanitized `.env` relay and AlphaLink page at 1120×560. | The form matches DMS AUTO/manual structure; the extra provider link is removed, and success uses the source-mapped dialog/iframe close. Browser checks verified MANUEL mode and a missing-provider 422 alert at the form top. The parent Domains DataTable and iframe Add Domains modal are now implemented and were compared at 1280×720; table rows remain empty, and DMS shows a relay-synthetic provider while AlphaLink has no configured provider. |
| Relay and Node checks | Python DMS relay **11 passed**; Node bulk-install **3 passed**. | No DMS ISP rows, registrar API, real server, PMTA file, DNS update or installation was accessed. INSTALL remains disabled. |

E-INST-23 adds the ISP input needed by PMTA VMTA preparation while keeping the workspace-specific data boundary explicit. E-INST-24 adds the source-backed Namecheap/GoDaddy automatic registrar import. Registrar HTTP is faked, and the browser comparison used the read-only DMS relay. No provider, DMS record, or host was contacted.

## DMS-INST-001 manual domain and runtime artifact checkpoint — 2026-10-23

| Check | Result | Limit |
|---|---|---|
| `DmsManualDomainImportTest` | **3 passed / 20 assertions** | Synthetic provider/domain records only; checks DMS trim/first-create/count, eligibility and workspace role boundaries. |
| Docker PHP 8.3 full suite | **114 passed / 795 assertions** | Current local tests, including synthetic registrar imports. No real registrar or host was contacted. |
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


## DMS-INST-001 provider/manual-domain/username setup checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Laravel tests | **14 passed, 80 assertions.** | Provider validation/encryption/serialization, owner versus operator authorization, workspace isolation, active-provider selection, manual domain creation, DMS username setup validation, eligibility and row preparation use synthetic fixtures. |
| Full Docker PHP 8.3 suite | **103 passed, 696 assertions.** | No real DMS credentials, provider API, or server were used. |
| Pint and `git diff --check` | Passed. | Changed PHP files follow project formatting; the change set has no whitespace errors. |
| DMS relay tests | **11 passed.** | Production provider IDs/names on Add Domains are replaced with a synthetic option; protected table rows remain blocked. |
| DMS and AlphaLink provider form | Browser GET inspection through the `.env` proxy; same field order, labels, types, and DMS theme observed. | No DMS form was submitted. AlphaLink uses only synthetic feature-test credentials; model serialization and audit properties omit provider secrets. |
| Add Domains automatic mode | DMS IMPORT is active; AlphaLink now calls source-backed Namecheap/GoDaddy import with fake HTTP test coverage. | The form now uses DMS busy, validation, success-dialog and iframe-close behavior. E-INST-25 adds and visually checks the DMS-shaped Domains DataTable parent and modal. Imported domains retain DMS `is_our=true` semantics and are not by themselves eligible for the non-owned Cloud selector. The RECORDS row action and scheduled history producer remain absent. |
| Local database migration | `2026_10_23_015808_add_dms_credentials_and_workspace_scope_to_domain_providers_table` applied. | Adds nullable provider credential/type/workspace columns; no existing provider record was present in the app database. |

INSTALL remains disabled. The local workspace has no actual active provider/domain; the staged RPM is missing required versioned PMTA assets and license entitlement is unknown. No real server or DMS product state changed.

## DMS-INST-001 AlmaLinux host-preparation stage — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 host-preparation and service-setup tests | **6 passed, 12 assertions.** | Recording fake transports verify hostname/hosts-file commands, DMS's empty-domain and swap branches, setup command ordering, and continuation after a `false` result. |
| Full Docker PHP 8.3 suite | **138 passed, 922 assertions.** | Includes existing AlphaLink flows and both new isolated installer stages. |
| Node bulk-install browser-flow tests | **4 passed.** | Existing DMS request, APPLY, selection, and polling behavior remains green. |
| Docker PHP 8.3 Pint and `git diff --check` | Passed. | The new transport contract, host-preparation stage, tests, and evidence edits are formatted and whitespace-clean. |
| DMS/target host operations | Not invoked. | Source inspection only. No SSH transport was supplied; no hostname, package, filesystem, service, DNS, or PMTA operation ran. The stage is not production-wired. |

E-INST-28 maps DMS `ServerInstallBulk::install()` into CentOS 9 `config_host()`, `remove_old_packages()`, and `setup_services()`. These are source-backed command-stage implementations only, not completion of the setup or server installation workflow. `setup_records()`, versioned PMTA execution, VMTA configuration, optional services, firewall, finalization wiring, rollback, host-health checks, and production runner remain outstanding. INSTALL remains disabled.

## DMS-INST-001 DNS-record stage — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 `DmsBulkInstallDomainRecordStageTest` | **4 passed, 12 assertions.** | Fake resolver/provider tests cover the eligible non-owned no-op, owned-domain record generation, provider failure logging, empty record writes, and public-IP success/fallback. |
| Full Docker PHP 8.3 suite | **142 passed, 934 assertions.** | Includes the new isolated DMS record-stage and resolver tests. |
| Node bulk-install browser-flow tests | **4 passed.** | DMS request, APPLY, selection, and polling behavior remains green. |
| Docker PHP 8.3 Pint and `git diff --check` | Passed. | Code and evidence updates are formatted and whitespace-clean. |
| DMS/provider/host operations | Not invoked. | No external lookup, provider API call, SSH connection, DNS write, or host mutation occurred. |

E-INST-29 ties the existing record planner to DMS's setup-record control flow. The target's real bulk selector only admits non-owned domains, so its normal behavior is explicitly a no-op here. The owned-domain branch is modeled for source equivalence tests, but its public DNS adapter is not production-wired. PMTA installation is the next source phase; INSTALL remains disabled.

## DMS-INST-001 AlmaLinux 9 PMTA planning — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused Docker PHP 8.3 `DmsCentos9PmtaDeploymentPlannerTest` | **5 passed, 28 assertions.** | Synthetic plans compare DMS 4.0/4.5/5.0 assets, patch targets, ports, access rules, reverse-proxy variants and skip behavior. |
| Package/host operations | Not invoked. | The planner emits operation data only. It performs no asset read, upload, package install, port-25 kill, or service restart. |

E-INST-30 documents the source mapping. This does not establish PMTA asset completeness or license entitlement. Full suite and browser-flow checks are recorded in the latest checkpoint below.

| Full Docker PHP 8.3 suite | **147 passed, 962 assertions.** | Includes the new record-stage and PMTA-plan tests. |
| Node bulk-install browser-flow tests | **4 passed.** | Existing request, APPLY, selection and polling tests remain green. |
| Docker PHP 8.3 Pint and `git diff --check` | Passed. | Updated code and documentation pass formatting and whitespace checks. |

## DMS-INST-001 PMTA deployment gate — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused PMTA gate + run-start feature tests | **17 passed, 73 assertions.** | Five synthetic gate tests and the run-start route test cover unsupported host facts, missing/private asset roots, DMS's one-missing-file branch, all-present synthetic assets, verified-bundle limits, selected-option assets, and rejection before transcript/runner creation. Fake-runner success uses a test-local ready gate. |
| Full Docker PHP 8.3 suite | **153 passed, 995 assertions.** | Includes existing AlphaLink coverage and the new readiness gate. |
| Node bulk-install browser-flow tests | **4 passed.** | Request payload, APPLY, selection, and DMS log-polling behavior remain green. |
| Docker PHP 8.3 Pint and `git diff --check` | Passed. | Changed PHP files are formatted and the worktree diff is whitespace-clean. |
| DMS, assets, package, DNS and host operations | Not invoked. | Gate tests use synthetic files and host facts. No DMS action, asset upload, package command, SSH, DNS request, or real-host change occurred. |

E-INST-31 records the source behavior and the AlphaLink safeguard. In the current application, installation authorization remains false: the full DMS asset set lacks a reviewed digest manifest, and the reviewed bundle marks config deployability, license validity, and host compatibility unverified. The gate rejects before run-log creation or runner invocation.

## DMS-INST-001 VMTA and firewall phases — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused gate, VMTA, firewall and run-start tests | **22 passed, 82 assertions.** | Five readiness-gate tests, three VMTA stage tests, two firewall-planner tests and twelve run-start/log tests use synthetic assets, facts and recording fakes. |
| Full Docker PHP 8.3 suite | **158 passed, 1,004 assertions.** | All current AlphaLink API tests pass. |
| Node bulk-install browser-flow tests | **4 passed.** | DMS-shaped request, APPLY, selection and polling behavior remains green. |
| Docker PHP 8.3 Pint and `git diff --check` | Passed. | Current PHP changes are formatted and the worktree diff is whitespace-clean. |
| DMS, target host, package, DNS and firewall operations | Not invoked. | The VMTA stage uses recording fake transports and the firewall component only emits command data. No host upload, package command, SSH, DNS request, firewall change or DMS action occurred. |

E-INST-32 covers the next DMS phase after PMTA and its upload-result branches. E-INST-33 maps the source firewall disabled/enabled command sequences. Neither is connected to a production runner; INSTALL remains blocked by E-INST-31 and the hard-off execution setting.

## DMS-INST-001 optional IMAP and proxy phases — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused IMAP and proxy stage tests | **6 passed, 15 assertions.** | Three Dovecot and three Squid tests use synthetic templates/credentials and recording command/file transports; they verify source order, branches, generated file content and password exclusion from shell commands. |
| Full Docker PHP 8.3 suite | **164 passed, 1,019 assertions.** | All current AlphaLink API tests pass. |
| Node bulk-install browser-flow tests | **4 passed.** | DMS-shaped request, APPLY, selection and polling behavior remains green. |
| Pint and `git diff --check` | Passed. | Changed stage/test files are formatted and the worktree diff is whitespace-clean. |
| DMS, target host and proxy operations | Not invoked. | These stages are offline and not connected to SSH/SFTP or a production runner. No credentials, host commands, packages, services or files were touched. |

E-INST-34 and E-INST-35 implement the next selected DMS optional phases after VMTA: IMAP then proxy, before HTTPS/firewall. Their business logic is mapped to the CentOS 9 source and fake-tested. The password-to-file transport change avoids command/log exposure but requires a real transport check before asserting runtime equivalence. INSTALL remains disabled by E-INST-31.

## DMS-INST-001 HTTPS phase — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused CentOS 9 HTTPS stage tests | **3 passed, 3 assertions.** | Recording fake verifies no-domain no-op, Certbot/plugin/request/cron ordering, domain aliases and shell quoting. |
| Full Docker PHP 8.3 suite | **167 passed, 1,022 assertions.** | All current AlphaLink API tests pass. |
| Node bulk-install browser-flow tests | **4 passed.** | DMS-shaped request, APPLY, selection and polling behavior remains green. |
| Pint and `git diff --check` | Passed. | Changed stage/test files are formatted and the worktree diff is whitespace-clean. |
| Certbot, ACME and host actions | Not invoked. | The implementation emits commands to a fake transport only; no network or host operation occurred. |

E-INST-36 covers the DMS HTTPS step after optional IMAP and proxy. It is source-mapped and fake-tested but not production-wired; certificate issuance, Apache behavior, cron state and install-level equivalence remain unverified. INSTALL remains disabled by E-INST-31.

## DMS-INST-001 firewall dispatch stage — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused CentOS 9 firewall planner and dispatch tests | **4 passed, 5 assertions.** | The planner tests compare both source command paths; fake-transport tests verify enabled/disabled dispatch and that false command results do not stop the phase. |
| Full Docker PHP 8.3 suite | **169 passed, 1,025 assertions.** | All current AlphaLink API tests pass. |
| Node bulk-install browser-flow tests | **4 passed.** | DMS-shaped request, APPLY, selection and polling behavior remains green. |
| Pint and `git diff --check` | Passed. | Changed PHP files are formatted and the worktree diff is whitespace-clean. |
| Firewall and host actions | Not invoked. | Tests use a recording command transport; no SSH, firewall command or host change occurred. |

E-INST-37 dispatches the source-mapped DMS firewall operations through the command interface after optional services and before finalization. It remains offline because no production remote transport or runner is connected; the firewall feature is not accepted.

## DMS-INST-001 guarded PMTA deployment stage — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused PMTA stage, planner and gate tests | **13 passed, 68 assertions.** | Tests cover all planner versions and skip conditions, gate checks, zero operations after gate rejection or a missing asset, and ordered operation dispatch from complete synthetic private assets. Fake command/upload results are false to verify DMS continuation. |
| Full Docker PHP 8.3 suite | **172 passed, 1,037 assertions.** | All current AlphaLink API tests pass. |
| Node bulk-install browser-flow tests | **4 passed.** | DMS-shaped request, APPLY, selection and polling behavior remains green. |
| Pint and `git diff --check` | Passed. | Changed stage/test files are formatted and the worktree diff is whitespace-clean. |
| PMTA packages, real DMS assets/license and host operations | Not invoked. | No package, license, SSH/SFTP, DNS, PMTA or host action ran. The production runner remains unregistered. |

E-INST-38 executes the PMTA planner output only after the gate authorizes and all referenced uploads are readable under the private root. This stage is not connected to the bulk-install route. Current gate evidence still rejects deployment.

## DMS-INST-001 SSH/SFTP transport checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused fake SSH/SFTP session tests | **6 passed, 34 assertions.** | Missing saved fingerprint prevents connection; changed key prevents login; matching key authenticates; command output is trimmed; string upload uses the exact destination; auth errors are sanitized. |
| Full Docker PHP 8.3 suite | **178 passed, 1,071 assertions.** | All current AlphaLink API tests pass. |
| Node bulk-install browser-flow tests | **4 passed.** | DMS-shaped submit, APPLY, row selection and log polling remain green. |
| Pint on changed PHP files and `git diff --check` | Passed. | Touched PHP files are formatted and the worktree diff is whitespace-clean. A repository-wide Pint check still reports an unrelated pre-existing style issue in unmodified `bootstrap/providers.php`. |
| SSH/SFTP, DMS and target-host operations | Not invoked. | Tests use a fake connector/client. No `.env` value was displayed, no DMS request ran, and no server connection or command ran. |

E-INST-39 maps DMS `Ssh::exec()` and `Ssh::upload()` and adds the target transport adapter with internal saved-key verification. It is not a production row runner and does not establish install-level equivalence. INSTALL remains disabled by the existing configuration and PMTA readiness gate.

## DMS-INST-001 saved SSH credential resolution checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused row-preparation and SSH/SFTP transport tests | **13 passed, 57 assertions.** | Tests cover workspace-scoped encrypted password/key resolution, safe failures, no plaintext in prepared context, saved-host-key matching and DMS command/upload behavior. |
| Full Docker PHP 8.3 suite | **180 passed, 1,078 assertions.** | All current AlphaLink API tests pass. |
| Node bulk-install browser-flow tests | **4 passed.** | DMS-shaped submit and polling behavior remains green. |
| Pint on 13 changed PHP files and `git diff --check` | Passed. | Touched PHP files are formatted and the diff is whitespace-clean. |
| SSH/SFTP and server operations | Not invoked. | Tests use synthetic encrypted credentials and a fake SSH client; no host, DMS, registrar, DNS or `.env` value was accessed. |

E-INST-40 maps DMS's saved login-type credential selection and installer login path. AlphaLink now resolves the same credential types through its workspace-scoped encrypted store. This is a prerequisite for runner composition, not a completed server installation; INSTALL remains disabled and no production row runner is registered.

## DMS-INST-001 PHP stage and execution dependency checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused CentOS 9 PHP stage | **1 passed, 1 assertion.** | Fake transport matches DMS's four exact PHP commands in order. |
| Full Docker PHP 8.3 suite | **181 passed, 1,079 assertions.** | All current AlphaLink API tests pass. |
| Node bulk-install browser-flow tests | **4 passed.** | Existing request and polling flow remains green. |
| Repository-wide Pint and `git diff --check` | **Passed.** | The old `bootstrap/providers.php` import style issue is fixed; all 188 PHP files pass Pint. |
| Host, PMTA, DNS and DMS writes | Not invoked. | The new stage was exercised only with a recording fake; INSTALL remains disabled. |

E-INST-41 is an isolated source-mapped tracking prerequisite. `setup_tracking()`, guarded row composition, verified PMTA inputs and controlled environment install proof remain open.

## DMS-INST-001 tracking stage checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused tracking/SSH and host-preparation tests | **13 passed.** | Synthetic transports cover mini app present/absent/malformed, safe helper replacement, authenticated file download and separate host phases. |
| Full Docker PHP 8.3 suite | **186 passed, 1,105 assertions.** | All current API tests pass, including a UUID domain-ID regression. |
| Node bulk-install flow | **4 passed.** | Existing request and polling flow remains green. |
| Pint | Passed. | PHP formatting is clean. |
| Real host/reference actions | None. | No SSH connection, package installation, Apache/cron change, DNS or PMTA operation occurred. |

E-INST-42 closes the isolated AlmaLinux 9 tracking-stage code gap. Production composition and evidence-backed PMTA authorization remain open; INSTALL remains disabled.

## DMS-INST-001 source-ordered AlmaLinux 9 workflow checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused workflow tests | **4 passed, 30 assertions.** | Synthetic session proves source phase order, selected tracking, no host commands after settings/gate rejection, and completion dispatch. |
| Full Docker PHP 8.3 suite | **190 passed, 1,135 assertions.** | All current API tests pass. |
| Node bulk-install flow | **4 passed.** | Existing request and polling flow remains green. |
| Pint and `git diff --check` | Passed. | Repository PHP style and diff whitespace are clean. |
| Real host/reference actions | None. | No SSH connection, package install, DNS, PMTA, firewall, cron or final database write ran outside synthetic tests. |

E-INST-43 is an unregistered stage composition, not an executable install route. The local PMTA gate remains closed and the workspace has no eligible domain or username mapping.

## DMS-INST-001 guarded row runner checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused runner, runtime input and row-preparation tests | **12 passed, 52 assertions.** | Synthetic tests verify private selected asset loading, settings rejection, gate-before-secret/SSH order and session handoff. |
| Full Docker PHP 8.3 suite | **196 passed, 1,170 assertions.** | All current API tests pass. |
| Node bulk-install flow | **4 passed.** | Existing request and polling flow remains green. |
| Pint and `git diff --check` | Passed. | PHP formatting and diff whitespace are clean. |
| Real host/reference actions | None. | The row runner is not registered and INSTALL remains disabled. |

E-INST-44 provides an implementation path but not authorization to install. Local readiness lacks the required DMS settings, complete private assets, PMTA evidence and eligible domain/username inputs.

## DMS-INST-001 process isolation and saved-host preflight checkpoint — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused process-pool, worker-command and run/log lifecycle tests | **17 passed, 75 assertions.** | Fake-process coverage verifies one child per row, payload mapping and isolated row failures; worker-command tests cover transcript logging and preflight rejection without a fingerprint prompt. |
| Full Docker PHP 8.3 suite | **207 passed, 1,248 assertions.** | All current API tests pass. |
| Node bulk-install flow | **4 passed.** | Request filtering and two-second polling/20-second grace behavior remain green. |
| DMS relay tests | **13 passed.** | Read-only relay safety tests remain green. |
| Pint and `git diff --check` | Passed. | Touched PHP files are formatted and the worktree diff has no whitespace errors. |
| Actual AlphaLink process-pool smoke test | **Parent succeeded; row failed closed before preparation.** | An Artisan child process ran against the local saved workspace and stopped because the production runner is unbound; no saved credential was decrypted and no SSH connection was attempted by that child. The temporary transcript was removed. |
| Authorized saved-server preflight | **Passed; read-only.** | Existing encrypted credential and internal saved host-key pin verified the AlphaLink server as AlmaLinux 9.8/x86_64 with `dnf`; PMTA binary was absent. Only AlphaLink preflight facts/audit fields changed. No installation or host mutation occurred. |
| Current installation inputs | **No eligible server row; PMTA gate closed.** | The current workspace has one active provider-backed eligible domain, but the owner DMS username is unset. The default-version PMTA gate reports ten blockers; execution remains disabled and no production runner is bound. |

E-INST-48 closes the DMS-shaped child-process orchestration gap in code and demonstrates that the real child command fails closed while the parent preserves per-row status. This does not accept installation parity: the PMTA gate remains closed, the production runner is not registered, and no host install has run.


## AlphaLink asset-catalog separation and live readiness retest — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Focused PHP tests | **61 passed, 568 assertions; no PHPUnit notices** | Covers the inventory, path translation, PMTA gate, runtime inputs, PMTA stage, settings editor, install response and rendered script path. |
| Node bulk-install tests | **7 passed** | The relocated AlphaLink script still filters rows, builds requests, polls logs and renders server-side errors. |
| Real AlphaLink browser INSTALL | **Blocked before host work, as required.** | With the saved eligible server/domain, PMTA 4.0 and optional switches off, the running app listed six missing AlphaLink asset paths. The read-only database check found the server still uninstalled. |
| Private root / app environment | **Available and mode 0700.** | The configured root lives in AlphaLink's persistent private app volume. The dev-server worker now receives `ALPHALINK_ENV_DIR`, so it loads the same protected environment as Artisan. |
| DMS and host actions | **None.** | No DMS asset was copied, no installation child/SSH session started, and no server or DMS state changed. |

`E-INST-58` records the source-to-AlphaLink mapping and runtime boundary. The exact DMS payload files are not present in the source snapshot; request read-only access to the original installer asset directory before continuing artifact validation.

## PMTA blocker wording and real-page retest — E-INST-59 — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| PMTA gate regression tests | **7 passed, 40 assertions.** | Missing inputs are no longer misreported as digest mismatches; present altered files still fail the pinned comparison. |
| Pint and `git diff --check` | Passed. | The gate/test edits are formatted and whitespace-clean. |
| Real AlphaLink INSTALL preflight | **Blocked before host work, as required.** | The app now displays the six missing AlphaLink asset paths and substantive evidence blockers without false package/license mismatch claims. The saved server remains uninstalled. |
| Staged bundle and install-asset roots | **Bundle verifies; install root empty.** | Bundle contains the pinned RPM/license plus reference config only. No daemon patch or deployable config is staged; license entitlement remains unverified. |

## Domains visibility filter retest — E-INST-60 — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| DMS business logic | **Source verified.** | DMS defaults ONLY OUR checked and filters to `is_our=true`; manual Add Domains sets `is_our=false`, so added rows are hidden until the filter is turned off. The source view reloads the DataTable on checkbox `click`. |
| AlphaLink live browser | **Passed.** | The former `change` listener left stale rows until Refresh. AlphaLink now uses the source `click` interaction. In the running app, checked returned 0 rows and unchecked immediately displayed both saved workspace domains. The view was left with ONLY OUR off so the domains are visible. |
| Regression contract / syntax | **Updated / passed.** | `DmsDomainManagementTest` now asserts the source-matched click handler; PHP syntax check passes. PHPUnit could not start because this host lacks PHP `dom` and `xmlwriter` extensions. |
| Scope | **Filter fix only.** | DMS theme, table and default checked state remain intact. No domain or server data was changed; this does not accept the remaining Domains feature gaps. |

## DMS PMTA asset validation — E-INST-61 — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| DMS source mapping | **Matches.** | `Centos9.php` reads the supplied versioned RPM/patch/license/domains files plus shared `pmta_source` and `pmta_config`. DMS expands `__IP__` and substitutes listener/admin ports. |
| AlphaLink private staging | **Passed.** | Catalogued 4.0/4.5/5.0 PMTA files and mapped optional install assets were copied into the actual AlphaLink private volume with 0700 directories and 0600 files. The DMS cloud SSH key and unrelated binaries were excluded. |
| Current page selection | **Presence/digests/config passed.** | The live page is set to PMTA 4.5 with Tracking on. The actual AlphaLink gate reports all selected assets present, hash-matched and config-template substitutions available. It remains closed. |
| Real target RPM validation | **Target validation failed for all three versions.** | The supplied files are recognized locally as RPM v3.0 binaries with RPM lead magic (`ed ab ee db`); the 4.5 lead contains the PowerMTA 4.5 build string. Using AlphaLink's saved fingerprint-verified SSH credential, the AlmaLinux 9.8 host's RPM parser rejected each signature-region trailer; `rpm -qp`, `rpm -K` and `rpm --test` failed, and `dnf --assumeno` could not load 4.5. This does not mean the files are not RPMs; target parsing, signature acceptance and compatibility remain unverified. Remote SHA-256 matched the private staged bytes; temporary RPM files were removed. No package was installed. |
| Regression checks | **Passed.** | DmsPmtaDeploymentGateTest: 8 passed / 44 assertions. PHP syntax checks, Pint and `git diff --check` passed. A real AlphaLink service evaluation passes 9/12 checks and reports RPM validation, license entitlement and target compatibility as blockers. |
| Sensitive DMS file | **Excluded from staging.** | `pmta/private_key` is a DMS cloud SSH private key, not a PMTA installer asset. The source directory is on a `fuseblk` mount that ignores chmod; `.gitignore` now excludes `/pmta/`, but the source copy remains broadly readable. |

## DMS installation transcript review — E-INST-62 — 2026-10-23

Using the authenticated read-only DMS session, I fetched the three log tokens through `GET /mailer/clouds/bulk-install/{token}/logs` and confirmed each response matches its local `log_DMS/` copy byte-for-byte. DMS resolves these logs from `storage/app/tmp/server:install_bulk:<token>`. The transcripts contain PMTA 4.5 stage markers for 1, 17 and 18 server rows. All show `Setup PMTA 45`, `End Setting PMTA`, a PMTA management link and disconnect marker; none contains an explicit error. This proves the DMS workflow reached and returned from its PMTA stage, not that the package installed or the daemon/license worked. DMS `Ssh::exec` returns command output without checking remote exit status, and the transcripts do not contain command output. The DMS installer also does not verify PMTA license entitlement. A read-only inspection of a corresponding installed DMS host is still needed. The raw `log_DMS/` folder is Git-ignored because it contains server identifiers and management URLs.


## DMS-INST-001 streamed PMTA upload and target evidence correction — 2026-10-23

The PMTA stage snapshots and hash-validates all planned uploads before the first remote command, then uses SFTP local-file mode for large RPM/patch files. Its production adapter was exercised against the saved AlmaLinux 9.8 host by uploading the DMS PMTA 4.5 RPM to a unique temporary path; metadata parsing, RPM digest check, and DNF `--assumeno` dependency resolution succeeded. The temporary RPM was removed. A later download-only probe exceeded AlphaLink's SSH channel timeout; after reconnect, the exact RPM/cache were cleaned. No package/service installation occurred.

Updated fake transports cover the streamed upload interface. Full Docker PHP suite: 231 passed / 1,417 assertions. DMS install assets copied into the private volume match 21/21 reviewed pins. The runtime PMTA gate now passes 11/12 checks, with license entitlement alone unresolved; execution remains off.
## E-INST-64 — Real PMTA 4.5 install verification — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| Local OS | **Confirmed.** | Linux Mint 22.3 x86_64; `rpm` and `dnf` are not installed. The DMS bundle contains an RPM, while the actual target is AlmaLinux 9.8. |
| DMS package validation on target | **Passed for metadata, digests and transaction planning.** | PMTA 4.5r11 x86_64 metadata parsed; `rpm -K` reported `digests OK`; DNF resolved 59 packages. `--assumeno` intentionally aborted with exit 1. No vendor signature was verified. |
| AlphaLink SSH fix | **Passed.** | Installer session timeout changed from 8 to 300 seconds; a false/timeout exec disconnects and throws so it cannot be treated as empty success. |
| Live AlphaLink installation | **Passed.** | Real controller, process-pool worker, saved SSH credential and host-key pin installed PMTA 4.5. The transcript reached all base stages and returned `succeeded`. |
| Post-install runtime | **Passed.** | Installed RPM identity, DMS patch hash, DMS license hash, active `pmta`/`pmtahttp`, CLI status and ports 25/1212/1213 confirmed over SSH. AlphaLink DB and browser show the server installed. |
| Automated tests | **Passed.** | PHP 233 tests / 1,425 assertions; Node 7/7; Pint and `git diff --check` passed. |
| Remaining limits | **Open.** | 4.0/5.0 target validations, contractual license scope, vendor-signature trust, optional features, full browser log polling and DMS parity remain unverified. DMS host setup changed hostname/resolver and SELinux state and removed old HTTP/mail packages; DMS itself was not modified. |

## E-INST-65 — PMTA monitor access — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| DMS source behavior | **Verified.** | The 4.5 PMTA backend ACL is `127.0.0.1 monitor`; `allow_all_monitor` opens the Nginx proxy by removing its IP allow/deny list. |
| User-requested direct-port behavior | **Applied.** | Added `http-access 0/0 monitor` to the included PMTA access file and restarted only `pmtahttp`. Port 2426 stayed configured; no `admin` permission was added. This is a direct-backend development override, not the DMS 4.5 default. |
| Live check | **Passed.** | Remote PMTA HTTP service is active; an actual GET to port 2426 returned HTTP 200, the PowerMTA monitoring title, and no access-denial message. |
| Persistent installer behavior | **Passed.** | AlphaLink's existing SHOW PMTA MONITOR TO ALL setting now generates the global monitor ACL for PMTA 4.5/5.0 direct listeners when YES, while NO retains loopback access. A focused planner test covers monitor-only access and continued dev-IP-only admin access. |
| Saved workspace setting | **Passed.** | The current workspace now stores the switch as YES; `DmsBulkInstallRuntimeInputs` resolves it as `true`. The PMTA listener/admin/proxy port defaults remain 1212/1213/1214, and the installed PMTA HTTP port remains 2426. |
| Regression checks | **Passed.** | Focused planner tests: 6/32 assertions; full PHP suite: 234/1,429; Pint passes. |

## DMS-SRV-008 — Servers Providers pages — 2026-10-23

| Check | Result | Meaning |
|---|---|---|
| DMS reference | **Source and sanitized UI inspected.** | Read-only source establishes provider CRUD routes, field rules, server counts, iframe forms and soft-delete behavior. The GET-only DMS relay served the list shell and Add form with provider-row JSON replaced by synthetic empty data. |
| AlphaLink browser | **Passed at 127.0.0.1:8766.** | The actual list loaded; synthetic create and edit succeeded; blank edit password preserved the saved value; soft-delete removed the QA row and the list returned to nine shared providers. No DMS data or credentials were copied. |
| PHP regression | **33 passed / 449 assertions.** | `DmsServerProviderManagementTest` and `InfrastructurePlanningTest` cover CRUD, DMS validation limits, workspace scoping, shared defaults, password encryption/masking, audit secrecy, provider-filtered server rows and linked-server retention. |
| DMS relay tests | **19 passed.** | Confirms provider page allowlisting and blocks provider-row JSON from the upstream DMS. |
| Pint / diff check | **Passed.** | Targeted PHP formatting check and `git diff --check` passed. |
| Acceptance state | **TESTING.** | Workspace scoping/shared defaults, encrypted/masked secrets, blank-password preservation and corrected delete text are documented adaptations; no parity claim is made. The synthetic QA record remains soft-deleted. |


## E-ISP-01 / E-SPO-01 — ISP list linkage and Sponsor source review — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS source review | **Passed, read-only.** | ISP access propagation and List inheritance were traced to `IspController`, `ListsAction`, and the models. Sponsor CRUD, offer propagation, active My Sponsors filtering, five adapter types and login-session behavior were traced from source. No DMS list rows, sponsor/offer rows or affiliate credentials were read. |
| AlphaLink migration | **Passed.** | The new composite workspace/ISP relation migration ran successfully in the existing app container. It adds optional ISP and tracked-country links plus per-list user/sponsor access fields. |
| Focused suites | **Passed.** | ISP catalog, audience list and Sponsor suites: **20 tests / 200 assertions**. Tests cover workspace-safe linkage, allowlist inheritance, tracked-country validation, list counts, transactional ISP-to-list cascade, Sponsor CRUD, encrypted credentials and network access filtering. |
| Pint / real 8766 page | **Passed.** | Pint passed on changed PHP files. The live ISP table now shows `TOTAL LISTS 0` for its current unlinked ISP rather than a fabricated `N/A`; the live Sponsor page renders its table shell and clearly labels offers/login as unavailable. No AlphaLink Sponsor or list records were changed. |
| Acceptance | **Open.** | ISP/List administration screens are missing. Sponsor Offer entity/import, five adapters, login session and extension are missing. No DMS mutation or external affiliate API request occurred. Neither feature is parity-confirmed and Send is not resumed. |


## E-CAMP-08 — Campaign register, PMTA consumer and ISP/Sponsor prerequisites — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| Focused PHP feature suites | **44 passed / 452 assertions.** | `DmsCampaignSendNavigationTest`, `DmsCampaignSendCatalogTest`, `CampaignTestEmailFlowTest`, `IspInstallationCatalogTest`, `DmsAudienceListManagementTest`, `WorkspaceSponsorManagementTest`, and `DmsOfferManagementTest`; synthetic DB/SMTP fixtures only. |
| PHP Pint | **Passed.** | Three changed PHP files formatted. |
| Live browser | **8766 verified.** | Campaign register renders the DMS-shaped 14 columns, real page-size selector, row action states and no-send explanation. Settings displays saved PMTA HTTP port 2426. |
| DMS/source boundary | **Read-only.** | DMS CampaignController and view source checked. The relay's campaign table has no production rows. No DMS List/Sponsor/Offer rows or affiliate credentials read. |
| External effects | **None.** | No campaign test email/send/schedule, provider call, DMS mutation or host change. |

The PMTA port consumer is now explicit: campaign PMTA Web Monitor links use the workspace setting immediately; install/reinstall inputs use the same value for the next run. Saving the port does not rewrite PMTA configuration on a server already installed. Full send parity is still blocked by suppression snapshot generation, native sender/worker, history, scheduling and delivery/recovery feedback.

## E-SPO-03 — Offer asset bulk status — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS behavior | **Source verified.** | `OfferController::assets_status_bulk` and `AssetAction::disable` enable/disable selected From, Subjects and Creatives while excluding Unsubscribe. |
| AlphaLink implementation | **Passed.** | DMS-like toolbar controls, selected-row operations and a route-scoped update are present. The server validates all selected IDs belong to the requested Offer in the current workspace; Unsubscribe is not mutated. |
| Synthetic feature suite | **46 passed / 390 assertions.** | ISP/List/Sponsor/Offer tests include bulk status, unsubscribe exclusion, cross-offer rejection and the rendered toolbar contract. |
| PHP style / Blade JavaScript / static UI audit | **Passed.** | Pint, extracted inline JavaScript syntax check, route registration, `git diff --check`, and strict `audit_project.py` passed with zero findings (`premium-audit.json`). |
| Live visual comparison | **Unverified.** | The 8766 workspace has zero Sponsors and Offers, so no populated Offer Assets page exists for browser comparison. Do not mark Sponsor parity accepted. |

## E-AFF-03 / E-CAMP-13 — EverFlow import and first-email readiness — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS source review | **Read-only.** | Traced selected-ID import, EverFlow endpoint and field mapping, duplicate checks, per-offer transaction and native manual test sender. No affiliate rows, credentials or DMS send endpoints were accessed. |
| EverFlow synthetic feature tests | **Passed.** | Import mapping, UK→GB, From/Subject assets, paused/inactive status, duplicate/deleted duplicate, invalid ID, provider failure, unsupported provider and workspace/sponsor validation are covered. |
| Focused combined PHP tests | **22 passed / 194 assertions.** | `DmsEverFlowOfferImportTest`, `DmsOfferManagementTest`, and `CampaignTestEmailFlowTest` ran in the PHP 8.3 app container. The host PHP lacked DOM/XMLWriter, so tests used the running AlphaLink container. |
| Relay regression | **26 passed.** | GET-only DMS reference relay allowlist and row-redaction unit tests pass. |
| AlphaLink live SMTP check | **Passed, no email sent.** | On the actual 8766 campaign page, “Check SMTP connection” completed a Symfony SMTP handshake. The UI displayed “SMTP CONNECTION VERIFIED · NO EMAIL SENT.” |
| First real test email | **Blocked by missing user-controlled addresses.** | The only saved campaign is demo content from `hello@example.test`; the sender domain guard blocks it and no Email Test recipient exists. Need a From address on a domain the user controls and one explicitly authorized inbox. PMTA sender/recipient acceptance and mailbox delivery remain unverified. |
| Acceptance | **Open.** | DMS test-manual requires explicit test addresses plus server/IP selection and launches its native sender. The requested Symfony one-recipient path is a scoped deviation, not DMS test-send parity. EverFlow creative bundles and other providers remain open. |


## E-CAMP-13 — First test email readiness — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS source comparison | **Read-only.** | DMS manual Email Test uses explicit recipients plus selected servers/IPs and invokes its native sender; AlphaLink's user-requested path remains a separate one-recipient Symfony Mailer action. No DMS send endpoint was called. |
| SMTP readiness | **Handshake verified; no message sent.** | The active AlphaLink runtime has SMTP selected and the live 8766 no-email handshake succeeded. SMTP acceptance, mailbox delivery, and relay permission are not proven. |
| Sender/domain check | **Blocked with evidence.** | The configured global From and saved demo From are reserved. The two workspace domains remain `new`, and prior DNS lookup found no matching AlphaLink ownership TXT token. AlphaLink now also blocks every sender whose exact domain lacks same-workspace verified TXT state; the live campaign UI links directly to domain verification. |
| Test-email PHP suite | **8 passed / 76 assertions.** | `CampaignTestEmailFlowTest` covers one explicit recipient, immutable latest revision, idempotency, reserved, pending/unverified, and cross-workspace sender-domain rejection, SMTP failure handling, workspace/creator scope, and the actionable Domains link. Synthetic SMTP transport only; no live email sent. |
| PHP Pint / diff check | **Passed.** | Targeted Pint and `git diff --check` pass. |
| Real email | **Not sent.** | No verified From identity or explicitly authorized test inbox is available in this task state. No recipient was inferred from account or audience data. |


## E-AFF-04 — EverFlow creative bundle import — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS source comparison | **Read-only.** | Traced the DMS EverFlow ZIP parser, HTML/image rewrite, Offer asset persistence and public media behavior. No affiliate credentials or provider records were read. |
| Synthetic EverFlow import and bundle tests | **Passed.** | Selected-ID offer metadata, HTML deduplication, embedded PNG, redirect/unsubscribe placeholders, public asset route, external image retrieval, private URL rejection, and traversal archive rejection are covered. |
| Focused import/composer suites | **23 passed / 314 assertions.** | `DmsEverFlowOfferImportTest`, `DmsEverFlowCreativeImportTest`, `DmsCampaignSendCatalogTest`, and `DmsCampaignSendNavigationTest`; no real EverFlow request. |
| Expanded prerequisite regression | **80 passed / 830 assertions.** | ISP/List, Sponsor/Offer, EverFlow import/creatives, campaign catalog/navigation, sender-domain, and Symfony test-email suites all pass. |
| First test email | **Still blocked.** | The sender domain and authorized inbox prerequisites in E-CAMP-13 are unchanged; no email was sent. |
| Acceptance | **Open.** | EverFlow creative behavior is synthetic-tested only. Four other adapters, refresh, login/session, extension, asset sharing, conversion reconciliation and actual public image reachability remain unverified. |

## E-CAMP-14 — Current Symfony test-email preflight — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| Live AlphaLink SMTP transport | **Handshake succeeded.** | In the running 8766 app container, Symfony's configured SMTP transport completed `start()` and `stop()`. This proves only the SMTP greeting/handshake. No message was submitted. |
| Focused feature regression | **9 passed / 82 assertions.** | `CampaignTestEmailFlowTest` uses synthetic SMTP for delivery-path checks and verifies persisted SMTP status display; no real email is sent by the suite. |
| Live campaign page after reload | **Latest handshake remains visible.** | The 8766 page displays the persisted no-email SMTP handshake result and timestamp; the saved message remains demo content. |
| Campaign content | **Not sendable.** | `Welcome series` still uses `hello@example.test`, demo body content, and has no Email Test recipient. No test attempt was created. |
| Workspace sender domains | **None verified.** | `jjstlinddefend.com` and `DFSGFGQZSDF.COM` are both pending; DMS marks both not ours. The configured global From domain is `example.com` and has no verified workspace ownership. No domain/DNS state was changed. |
| Next requirement | **User input required.** | A From address on a domain the user controls and has verified in this workspace, and one explicitly authorized recipient inbox. No recipient was inferred. |


## E-CAMP-16 — Correct SMTP runtime configuration guidance — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS source comparison | **Read-only.** | DMS `test-manuel` uses its native sender and has no AlphaLink environment-file instruction. |
| AlphaLink configuration evidence | **Confirmed.** | `bootstrap/app.php` can load the environment through `ALPHALINK_ENV_DIR`; the previous error message incorrectly named `apps/api/.env`. |
| Focused regression | **9 passed / 84 assertions.** | `CampaignTestEmailFlowTest` verifies the campaign page points to the active runtime environment, the stale path is absent, and SMTP check/send remain blocked when SMTP is not configured. |
| Live email | **Not sent.** | The real app's SMTP handshake was already verified separately. The campaign still has a reserved demo From address; a user-controlled verified sender and one explicitly authorized inbox are still missing. |
| Acceptance | **Open.** | This fixes operator guidance only. It does not establish relay permission, recipient acceptance, mailbox delivery, or DMS native send parity. |


## E-AFF-05 — HitPath selected-offer import — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS source mapping | **Read-only.** | `HitPath::get_offers` loads the campaign catalog, filters selected IDs, reads email rows for From/Subject assets and takes the first/last anchor links for redirect/unsubscribe. `OfferAction::add_auto` skips existing and soft-deleted offer IDs and creates offer/assets transactionally with sponsor access. |
| AlphaLink implementation | **Synthetic-tested.** | Selected-ID XML parsing and field mapping, workspace scope, duplicate soft-delete handling, provider failure handling and private/reserved DNS rejection are covered; live provider requests were not made. |
| Focused regression | **33 passed / 328 assertions.** | `DmsAffiliateNetworkOfferImportTest`, EverFlow offer/creative suites, `DmsOfferManagementTest`, `WorkspaceSponsorManagementTest`, and `DmsOfferScopedSuppressionTest`. |
| AlphaLink browser | **Verified at 8766.** | Add Offer screen renders the live dynamic status: “Choose a HitPath or EverFlow sponsor.” No Sponsor records were present, so no import was submitted. |
| Parity status | **Open.** | DMS caches HitPath responses and live provider behavior is unknown. NewHitPath, Cake and HasOffers remain unavailable. No affiliate credentials or DMS offer rows were read. |


## E-CAMP-17 — Case-insensitive verified sender matching — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| Focused test-email suite | **10 passed / 91 assertions.** | `CampaignTestEmailFlowTest` includes an uppercase DMS-imported sender domain and verifies the one-recipient Symfony test reaches the transport when that same-workspace domain is verified. |
| PHP formatting / diff | **Passed.** | Pint and `git diff --check` pass for the controller and regression test. |
| Live delivery | **Not attempted.** | The live campaign remains blocked by reserved demo sender content, no verified sender identity, and no explicitly authorized inbox. The recorded SMTP handshake proves greeting only. |
| DMS parity | **Open.** | DMS manual test uses explicit addresses plus selected servers/IPs and its native sender. AlphaLink's Symfony test is a requested separate path; no equivalence or delivery claim is made. |

## E-ISP-04 — DMS country picker parity — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| Focused ISP suite | **6 passed / 65 assertions.** | The form renders the DMS multiple-select markup, locally hosted pinned Select2 4.0.13 and preselected edit values. |
| Live UI | **Compared against read-only DMS at the same viewport.** | Selecting Burkina Faso then Canada kept each dropdown open and rendered the same chip order; neither form was saved. |
| Asset | **Served locally with HTTP 200.** | SHA-256 `00501810e93307a8882a74d864e7547fd1458deea539361dc1124ac133799a4b`; license included. |
| Formatting | **Passed.** | Pint and `git diff --check` pass. |

## E-SPO-06 — Share Creatives Hashids path alignment — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS source | **Read-only.** | `OffersShare` and `OfferAction` both use `Hashids(settings.hash_token)->encode(offer.id)` for `/var/mini/public/{hashid}`. |
| AlphaLink path | **Corrected.** | The share service now reads the encrypted workspace FOLDERS HASH TOKEN and uses Hashids over the local offer ID. Missing salt blocks in the page and fails before SSH on the endpoint. |
| Focused suite | **6 passed / 56 assertions.** | Synthetic SSH coverage verifies the DMS default Hashids vector, directory construction, existing-file skip, permissions, changed host key, and no SSH attempt when the token is absent. |
| Combined Sponsor/Offer/Email regression | **70 passed / 681 assertions.** | Provider/import, assets, catalog, and Symfony test-email paths passed after the dependency and share-path change. Provider and mail test transports remain synthetic. |
| Live AlphaLink settings | **Token absent.** | A sanitized 8766 check returned only the configured/missing boolean; the secret was not output. |
| Live DMS setting | **Unavailable through the read-only relay.** | The relay intentionally strips all settings input values; no secret value was read. |
| Live share | **Not exercised.** | The workspace has no live Offer rows. No remote server, DMS record, or AlphaLink asset was changed. |
| Acceptance | **Open.** | Copy the matching DMS FOLDERS HASH TOKEN into AlphaLink, then verify a populated offer's remote asset path against the campaign image URL before treating Share Creatives as equivalent. |

## E-AUD-04 — DMS List upload parsing edge cases — 2026-10-24

| Check | Result | Meaning |
|---|---|---|
| DMS reference | **Read-only source inspection.** | `ListsAction::process_list` trims each whole line before splitting fields, then validates the first field as received. Its `if ($filter && ...)` guard treats string `"0"` as no filter. |
| AlphaLink alignment | **Implemented.** | The DMS-format import no longer separately trims the first field and now applies the same PHP truthiness guard, retaining DMS's `Str::contains($filter, $email)` argument order. |
| Focused suite | **6 passed / 41 assertions.** | `DmsListUploadTest` covers the `"0"` filter and whitespace-before-comma cases alongside profile import, duplicate behavior, age validation atomicity and workspace scope. |
| Formatting | **Passed.** | Pint passes for the import service and test. |
| Parity | **Open.** | This fixes two parser edge cases only. AlphaLink's per-workspace uniqueness is an intentional tenant boundary and differs from DMS global uniqueness; broader ISP/List parity remains open. No live recipient data was accessed. |

## E-CAMP-23 — Symfony test-email regression — 2026-10-24

- Command: `docker exec -w /app suspicious_solomon php artisan test tests/Feature/CampaignTestEmailFlowTest.php`
- Result: **11 passed / 109 assertions**.
- Scope: the Symfony test-email route and transactional test-draft flow, using synthetic mail transport for successful/blocked cases.
- Live check: authenticated 8766 campaign page still blocks the reserved `hello@example.test` sender; prior no-email handshake is visible; zero attempts. No live email was sent.
- Limit: requires a user-controlled From domain with AlphaLink TXT verification and one explicitly authorized inbox before the real SMTP send can be exercised.

## E-CAMP-29 — SMTP attempt reservation ordering — 2026-10-24

- Command: `docker exec suspicious_solomon sh -lc 'cd /app && php artisan test --compact tests/Feature/CampaignTestEmailFlowTest.php'`
- Result: **14 passed / 138 assertions.**
- Regression: the test inspects the database from the SMTP transport's `start()` boundary and confirms the unique attempt plus `preparing` event already exist before connecting; the flow finishes at `accepted_by_transport` without exposing the recipient address. An accepted idempotency replay remains a no-op after SMTP is misconfigured.
- Formatting: container Pint on the two changed PHP files; `git diff --check` passes. `--dirty` is unsupported because the bind-mounted `/app` has no `.git` directory.
- Live AlphaLink: authenticated 8766 campaign page still has reserved demo sender `hello@example.test`, prior handshake timestamp 10:16 UTC, and zero test attempts. No email was sent.
- Limit: the test confirms ordering/idempotency with a mocked transport; it is not evidence of PMTA relay acceptance or inbox delivery. A user-controlled verified From domain and explicitly authorized recipient are still required.

## E-CAMP-31 — Effective Symfony SMTP destination display — 2026-10-24

- Focused: `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/CampaignTestEmailFlowTest.php` — **15 passed / 146 assertions**.
- Combined campaign regression: `CampaignTestEmailFlowTest`, `DmsCampaignSendCatalogTest`, and `DmsCampaignSendNavigationTest` — **29 passed / 374 assertions**.
- New coverage sets a synthetic SMTP target and verifies the campaign page displays the host, port, and STARTTLS mode from Symfony's resolved transport, with neither username nor password rendered.
- Formatting: container Pint passed on the two changed PHP files. `git diff --check` passed. Premium strict UI audit: zero findings.
- Live AlphaLink 8766: campaign detail now shows `152.228.228.236:25` and “STARTTLS when offered by the server.” This was a page read only; it made no SMTP connection and sent no email. The latest saved no-email handshake remains at 10:16 UTC.
- DMS comparison: DMS manual Email Test requires selected server/IP and native sender mode; there is no matching SMTP target panel. This is a transparent AlphaLink diagnostic, not DMS parity.
- Blocker remains the reserved demo sender, no verified controlled From domain, and no explicitly authorized recipient. No real email was sent.


## E-SPO-08 — Source-verified Sponsor access propagation — 2026-10-24

DMS `SponsorController::update_users` saves the single Sponsor and updates every related Offer. Its separate bulk action updates only selected Sponsor rows; related Offers are deliberately not cascaded. AlphaLink already implements that distinction within a workspace-scoped transaction. Expanded `WorkspaceSponsorManagementTest` to prove the single path updates active and inactive Offers, and the bulk path updates selected Sponsors while leaving selected/unselected Offers and an unselected Sponsor untouched. Focused Sponsor suite: **7 passed / 113 assertions**. Combined ISP/List/import/Sponsor/Offer regression: **40 passed / 390 assertions**. Container Pint and `git diff --check` pass. Tests use synthetic records only; no DMS mutations, live Sponsor/Offer rows, affiliate credentials or provider calls. Sponsor and affiliate parity remain open.


## E-CAMP-67 — Campaign Test feedback, saved snapshot and PMTA monitor cadence — 2026-10-25

| Check | Result | Meaning |
|---|---|---|
| DMS source | **Read-only.** | DMS Edit uses AJAX for Test and a five-second `bottom-end` toast. Its test log retains submitted campaign-test configuration after sender output. The PMTA parent renders selected-server monitor iframes with manual reload controls. |
| AlphaLink regression | **17 passed / 514 assertions.** | `DmsCampaignSendCatalogTest` verifies JSON/no-redirect Test responses, fresh-key retry and same-key replay protection, encrypted configuration snapshots, campaign log display, monitor refresh cadence and schedule status/count behavior. SMTP and PMTA calls are faked; no email or queue command ran. Pint passed for the changed PHP files. |
| Browser | **8766 rendered.** | The selected AlphaLink monitor displays with “auto refresh 30s”; a timed browser check showed its Updated time advance from 00:07:53 to 00:08:24 across a 32-second wait; the campaign Edit form was inspected with a read-only GET. No Test action was clicked. |
| Difference | **Documented.** | AlphaLink’s parent auto-refreshes its read-only monitor frame every 30 seconds because its proxy strips remote refresh scripts/meta tags; this follows the user's explicit request and is an APPROVED_DEVIATION from the observed DMS parent control. The native DMS sender and mailbox delivery remain unknown. |



## E-PMTA-08 — PMTA monitor empty-selection behavior — 2026-10-25

| Check | Result | Meaning |
|---|---|---|
| DMS source | **Read-only.** | Server change regenerates the monitor holder from selected options; no selection renders no cards. |
| AlphaLink regression | **17 passed / 521 assertions.** | The focused suite verifies empty initial state, selection URL synchronization, and visibility toggling in the monitor render contract. Pint passes for the Blade and feature-test files. |
| Browser | **Verified on 8766.** | Selecting the saved server displayed its read-only monitor; Deselect All removed the monitor card and refresh status without a page reload. The query selection was cleared. |
| Side effects | **None.** | Only the local monitor's read-only GET ran; no PMTA queue/restart command or email was sent. |


## E-CAMP-68 / E-PMTA-09 — Campaign tags, schedule results and monitor refresh — 2026-10-25

| Check | Result | Meaning |
|---|---|---|
| DMS source and browser | **Read-only.** | Source confirms the tag pipeline and the immediate-plus-every-N-seconds PMTA schedule loop. The live Campaigns list showed no rows through the GET-only relay; the direct campaign detail request for the item in the supplied screenshot returned 403. The latest campaign’s runtime state could not be inspected. |
| AlphaLink regression | **18 passed / 581 assertions.** | `DmsCampaignSendCatalogTest` covers campaign tag resolution with three synthetic recipients and fake SMTP, resolved sender/subject, server/IP/domain/PTR values, random/boundary/placeholder values, DMS-shaped Test token fields, saved test-log snapshot, schedule command mapping and the five-second monitor setting. No network email or PMTA command ran. |
| PMTA Admin browser | **Verified on 8766.** | With the one saved server selected, the page showed “Selected PMTA monitors refresh automatically every 5 seconds.” The Updated stamp advanced from 00:33:05 to 00:33:10 after a six-second wait; the PowerMTA monitor rendered through the local read-only proxy. The result table now includes run number, server, command and result; failed HTTP requests no longer increment the response count. |
| Formatting and diff | **Passed.** | Targeted Pint passed for the changed PHP files; `git diff --check` passed. `pint --dirty` cannot run inside the app container because its mounted `/app` path has no Git metadata. |
| Side effects and remaining unknowns | **No live command.** | No campaign Test, email, PMTA schedule, queue, or restart action was issued. The user-requested five-second parent refresh is recorded as a deviation from the inspected DMS parent view. Native sender token consumption, mailbox delivery, and live PMTA command acceptance remain unknown. Keep both feature rows in TESTING/IMPLEMENTING. |


## E-PMTA-10 — DMS queue schedule cadence and five-second refresh — 2026-10-25

| Check | Result | Meaning |
|---|---|---|
| DMS source | **Read-only.** | The PMTA queue SCHEDULE input means seconds between runs. A positive N runs immediately, then once every N seconds until STOP; blank/zero runs once. This is separate from DMS campaign `ScheduleJob`, which launches at the campaign's saved scheduled time. The GET-only relay hides campaign rows and the latest campaign detail GET remains 403. |
| AlphaLink source and focused regression | **18 passed / 581 assertions.** | Source and focused tests confirm the same immediate-plus-interval loop, `*/*` command mapping, STOP control, and 5,000 ms monitor timer with fake transports. |
| Browser | **Verified after reload.** | The open tab initially still rendered the former 30-second page. After reloading, it showed “Selected PMTA monitors refresh automatically every 5 seconds.” A 6.5-second wait showed the selected monitor's Updated stamp advance; only read-only proxy GETs ran. |
| Side effects and remaining blocker | **No live queue action.** | No schedule command, queue command, campaign send, or email was run. The saved server's PMTA action endpoint has returned access denied. A read-only console check found one active `http-access ... admin` rule matching the latest SMTP relay-denial source, but did not identify the source address of AlphaLink's HTTP `/command` request. PMTA command authorization remains UNKNOWN; the timer itself is working and no reinstall is indicated. Keep DMS-PMTA-001 TESTING. |

## E-PMTA-11 — Schedule interval result and five-second PMTA refresh — 2026-10-25

| Check | Result | Meaning |
|---|---|---|
| DMS reference | **Read-only source verified.** | PMTA Admin SCHEDULE runs once immediately then repeats every entered N seconds until STOP; blank/zero runs once. DMS campaign Schedule At is a separate delayed launch workflow. The GET-only relay shows no campaign rows, so the latest campaign and its saved schedule could not be inspected. |
| AlphaLink focused regression | **19 passed / 594 assertions.** | `DmsCampaignSendCatalogTest` verifies the PMTA Admin view, `*/*` schedule mapping, immediate-plus-interval timer, STOP control, single-run behavior, five-second monitor refresh configuration, and the API/UI success contract. A PMTA `0 of 0 matching queues scheduled` result is accepted; explicit server errors stop the repeating loop. Targeted Pint passed. |
| AlphaLink browser | **Verified on 8766.** | The existing results view showed one prior `schedule */*` response: `0 of 0 matching queues scheduled`. This agent did not submit that command; its provenance is unknown. The selected PowerMTA card rendered, and its monitor URL refresh token changed during a six-second wait. The page states cards refresh every five seconds. The new error handling was verified through focused API/UI regressions only; no live command was sent. |
| Side effects and parity limits | **No live action.** | No schedule, queue, restart, campaign send, or email command was issued. A zero-match response indicates no queued job matched at that run; it does not verify recurring execution. The latest DMS campaign’s state remains UNKNOWN, and PMTA command authorization remains unresolved. Keep DMS-PMTA-001 TESTING. |


## E-CAMP-73 — Global Tests — 2026-10-25

- `GlobalTestsWorkflowTest.php`: 8 passed / 45 assertions.
- Scoped Laravel Pint on the 7 Global Tests PHP files: pass.
- PHP syntax checks on controller, runner, resolver, model, factory, migration and feature test: pass.
- `node --check apps/api/public/dms-reference/global-tests.js`: pass.
- Local `mode-html.js` asset request: HTTP 200; browser token inspection confirms HTML mode.
- Running-browser checks: hamburger menu open/close, right-click menu, tag-help dialog open/close, placeholder collapse/add/remove. No form submission.
- Unconfigured-sender regression asserts HTTP 503, no history row and no PTR resolver call.
- No message, PMTA command, DMS mutation, DMS sender, or external PTR lookup ran.
- Repository `git diff --check`: pass.

This slice is TESTING only; the AlphaLink-native sender is not configured.
## E-PMTA-92 — PMTA Admin Restart client parity — 2026-10-25

- DMS source: each selected server receives its own concurrent restart POST; intervals of at least five seconds dispatch immediately and then repeat until STOP.
- `node --test tests/js/pmta-admin.test.js tests/js/pmta-vmta-reset.test.js tests/js/pmta-vmta-response.test.js`: **20 passed / 0 failed**.
- `php -l app/Http/Controllers/Web/WorkspaceConsoleController.php`: pass. `git diff --check`: pass.
- Running AlphaLink 8766 PMTA Admin GET serves the updated client script and selected-server monitor; browser console showed no errors. No Restart POST was submitted.
- Restart parity remains TESTING pending a connected action outcome and multi-server browser verification.

## E-PMTA-93 — VMTA Reset selection and confirmation — 2026-10-25

- DMS source: legacy Reset requires selected IPs; VMTA 2 Reset requires a separate selected server; both ask for confirmation before POST.
- Running AlphaLink 8766 page: empty legacy selection showed the Oops alert. Selecting an IP opened confirmation. VMTA 2 server selection independently opened its confirmation. Both were canceled; no reset request was sent.
- `node --test tests/js/pmta-vmta-reset.test.js tests/js/pmta-vmta-response.test.js`: **14 passed / 0 failed**.
- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/PmtaPagesTest.php --filter=reset`: **5 passed / 44 assertions**, fake SSH.
- Host PHPUnit lacks DOM/XMLWriter; the focused suite passed in the app container. No connected PMTA configuration was removed or reloaded.
- Reset confirmation UI matches the source. Connected cleanup outcome remains UNKNOWN; DMS-PMTA-001 stays TESTING.

## E-PMTA-96 — PMTA Admin Schedule fan-out — 2026-10-25

- DMS source: `pmta_index.blade.php:358-417` dispatches one Schedule POST per selected server, immediately and once per positive seconds interval; STOP clears the interval.
- AlphaLink now dispatches singleton server requests concurrently. Like DMS, every run clears the rows and shows loading states; each response updates its row in completion order, including when an older run completes last. It counts individual responses and stops the interval on the first transport failure.
- `node --test apps/api/tests/js/pmta-admin.test.js`: **9 passed / 0 failed**. Deferred responses cover two servers, repeat cadence, out-of-order completion, STOP, one-shot mode, and transport failure.
- Focused `DmsCampaignSendCatalogTest::test_pmta_admin_page_renders_selected_server_and_runs_source_mapped_queue_command`: **1 passed / 115 assertions** with fake PMTA HTTP. Targeted Pint passed.
- Running AlphaLink 8766 browser: updated PMTA Admin page rendered and the unselected-server Schedule guard displayed. No Schedule action or PMTA command ran.
- The real workspace has one selected PMTA server, so connected multi-server parity remains UNKNOWN. DMS-PMTA-004 stays TESTING.


## E-PMTA-97 — PMTA monitor fresh-tab load and Status navigation — 2026-10-25

- Read-only DMS Blade evidence keeps one monitor iframe per selected server and reloads its current page on a five-second cycle.
- Fresh AlphaLink 8766 selected-server tab transitioned from Loading to populated Home content; its timestamp advanced on refresh. Status navigation rendered the selected server status page inside the same card.
- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/DmsCampaignSendCatalogTest.php --filter=pmta_monitor_proxy`: **1 passed / 10 assertions**, fake HTTP; verifies the proxy response and disabled command paths.
- No queue command, config/license action, DMS mutation, email send, or host change ran. Populated DMS monitor comparison remains unavailable through the read-only relay; keep DMS-PMTA-002 TESTING.
## Suppression, user management and Tools focused validation — 2026-10-25

- Focused PHPUnit suite: **26 passed / 221 assertions** across `DmsAffiliateProviderHttpTest`, `DmsAffiliateSuppressionRefreshTest`, `WorkspaceDropSuppressionTest`, `WorkspaceUserManagementTest`, `DmsEncodingToolTest`, `DmsSpfScannerTest`, and `DmsWorkspaceToolTest`.
- Node regression: `node --test apps/api/tests/js/dms-placeholder-editor.test.js tests/js/text-mechanic.test.js` — **5 passed**.
- PHP syntax checks pass for the changed suppression, SPF, Workspace and user/encoding controller files. Targeted Pint completed on changed PHP files. The mandatory `pint --dirty --format agent` form cannot run inside the PHP container because `/app` has no Git metadata; the equivalent targeted path formatting passed.
- Local database migration status: all three migrations for DMS user fields, SPF scanner tables and Workspace Google accounts are `Ran`. Laravel tool route listing exposes 27 tool-related routes. Browser route probes redirect to Sign in, so authenticated browser parity remains unverified.
- Full PHPUnit regression initially reported **623 passed / 8 failed / 5,729 assertions**. The stale expectations and fixture were corrected: campaign/misc navigation IDs are stable, the list-history test checks the `to` validation field, workspace members retain server controls with settings scope enforced, and the Global Test fixture now selects a saved VMTA for its server.
- Final full PHPUnit regression: **631 passed / 5,780 assertions**. The settings regression confirms workspace members can view and update server/PMTA settings while the general app-settings POST remains manager-only and all settings stay workspace-scoped.
- The PHP app container has no `npm`; host `/usr/bin/npm run build` in `apps/api` succeeds with Vite 8.3.5. Targeted Pint, five Node regressions, and `git diff --check` pass.
- Authenticated AlphaLink 8766 page checks cover Suppression, Users/Add User, Encoding, SPF Scanner and Workspace. The Encoding POST and seven IPv4 output forms were verified with synthetic input; the other pages were inspected without triggering state-changing workflows. The missing Workspace relay bundle blocks push before SSH, and no provider or DMS mutation was run.
- No DMS mutation, live email, provider request, PMTA action, server installation, remote reset, or Workspace push was executed.

## E-AFF-10 / E-DASH-03 — campaign and Drop engagement inputs — 2026-10-26

- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Unit/DmsAffiliateProviderSalesReaderTest.php tests/Feature/CampaignLeadReconciliationTest.php tests/Feature/DropTrackingTest.php tests/Feature/WorkspaceAnalyticsDashboardTest.php tests/Feature/CampaignTrackingTest.php tests/Feature/DmsAudienceListHistoryTest.php`: **26 passed / 218 assertions**.
- `php artisan route:list --path=stats` in the running AlphaLink container exposes `POST api/v1/stats` and `POST api/v1/stats-drop`; `schedule:list` exposes five-minute and daily ten-day sponsor conversion imports. Both Drop-visitor/conversion migrations are applied to the local AlphaLink DB.
- Authenticated AlphaLink Main Dashboard browser check shows zero visitor events, one fresh membership, and zero delivery/open/click/lead/unsubscribe membership totals. No test event or email was submitted.
- At the time of this evidence run, the runtime tracking key was unset. E-TRACK-06 later adds and synthetically verifies install-key resolution. No real affiliate provider report or DMS operation was invoked. Keep Dashboard, Tracking and affiliate reconciliation TESTING until the sender callbacks and provider inputs populate local data and are checked in the app.

## 2026-10-26 — Campaign Statistics page

- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/WorkspaceCampaignStatisticsTest.php`: **3 passed / 14 assertions**.
- Targeted Pint passes for `WorkspaceCampaignStatisticsConsoleController.php` and `WorkspaceCampaignStatisticsTest.php`; `git diff --check` passes.
- Local route listing exposes `GET app/workspaces/{workspace}/campaign-statistics`. Authenticated AlphaLink 8766 browser shows the page and DMS Campaigns → Statistics location, with available campaign, ISP, and list filters and a correct empty state.
- No DMS request/action, local tracking event, provider report, or email send ran.

## E-CAMP-95 — Campaign visitor logs

- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/WorkspaceCampaignVisitorsTest.php`: **3 passed / 24 assertions**. Coverage includes campaign event/lead totals, server-side DataTables filtering, exclusion of contact email addresses, and workspace/creator authorization.
- Combined visitor/statistics/dashboard/tracking/lead/list-history suite: **25 passed / 202 assertions**.
- Targeted Pint passes for `WorkspaceCampaignVisitorsConsoleController.php` and `WorkspaceCampaignVisitorsTest.php`; `node --check public/dms-reference/campaign-visitors.js` passes.
- `git diff --check` passes; strict Premium UI audit reports **0 findings**.
- Authenticated AlphaLink 8766 browser confirms the DataTables assets initialize, the empty state renders for the current no-event campaign, search returns no matches, and Clear restores the empty state. No local test event or email was submitted.
- DMS remained read-only. Country/device enrichment and native-send configuration rows are still absent; this does not establish parity.

## E-DASH-04 — Dashboard date-range controls

- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/WorkspaceAnalyticsDashboardTest.php`: **6 passed / 63 assertions**.
- `node --check apps/api/public/dms-reference/analytics-date-range.js` and `git diff --check` pass. The strict Premium UI audit reports **0 findings**.
- Authenticated 8766 browser: All Stats Last 7 Days applies the expected dates, the range restores on return, and Reset clears the stored range. ISP Stats and Mailer Revenue show the six presets and per-page storage keys. All report results correctly remain empty for the current workspace.
- Pint could not run: the app container does not expose the Pint command and host Pint requires missing `ext-xml`.
- No DMS request/action, local tracking event, provider report, or email send ran. Dashboard workflows remain TESTING.

## E-CAMP-96 — Campaign Statistics date presets

- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/WorkspaceCampaignStatisticsTest.php tests/Feature/WorkspaceAnalyticsDashboardTest.php`: **9 passed / 85 assertions**.
- Authenticated AlphaLink 8766 browser applies Last 7 Days to Campaign Statistics (`from=2026-10-20`, `to=2026-10-26`) and Reset returns to Today. The current workspace's event/lead table remains empty.
- `node --check apps/api/public/dms-reference/analytics-date-range.js`, PHP syntax and `git diff --check` pass; strict Premium UI audit reports **0 findings**.
- DMS uses a daterangepicker and refreshes the pivot in place; AlphaLink uses six visible buttons and GET page rendering. No DMS request/action, synthetic saved-workspace event, provider report, or email send ran. DMS-CAMP-007 stays TESTING.

## E-DASH-05 — Main Dashboard today and MTD summaries

- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/WorkspaceAnalyticsDashboardTest.php tests/Feature/WorkspaceCampaignStatisticsTest.php`: **9 passed / 87 assertions**. Coverage includes workspace isolation, same-day and prior-day open/click counts, month-to-date totals, attributed-lead revenue breakdowns by sponsor/ISP/mailer/offer, campaign statistics presets, and list membership snapshots.
- Targeted Pint passes for `WorkspaceAnalyticsConsoleController.php` and `WorkspaceAnalyticsDashboardTest.php`. `git diff --check` passes; strict Premium UI audit reports **0 findings**.
- Fresh authenticated AlphaLink 8766 browser load shows the Main Dashboard summaries, four MTD breakdown panels, and unavailable PMTA delivery marker. The current workspace has zero visitor events, so event values and breakdown tables show empty states.
- DMS `DashboardAction` labels a previous-day query as YTD; AlphaLink mirrors that source calculation and documents it. AlphaLink earnings include attributed campaign/Drop lead revenue only; MTD/YTD charts, ten-hour cache, PMTA delivery/bounce data, seed inputs, role mapping, and sender callback inputs remain open. No DMS request/action, local event, provider report, or email send occurred. DMS-DASH-001 remains TESTING.


## E-DASH-06 — Main Dashboard chart coverage

- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/WorkspaceAnalyticsDashboardTest.php`: **7 passed / 72 assertions**. Tests verify populated event and lead-revenue series plus zero-filled current-month and year periods.
- The combined Dashboard and Campaign Statistics regression run passes **10 tests / 94 assertions**. Targeted Pint passed for `WorkspaceAnalyticsConsoleController.php` and `WorkspaceAnalyticsDashboardTest.php`; `npm run build`, PHP syntax, strict Premium UI audit, and `git diff --check` passed.
- Authenticated AlphaLink 8766 reload shows the three MTD/YTD charts and zero-value state for the current saved workspace.
- DMS's source chart uses PMTA deliveries/bounces and earnings. AlphaLink currently plots available engagement events and attributed lead revenue only; ten-hour caching and PMTA/seed inputs remain open. No DMS action, local event, provider report or email send occurred. DMS-DASH-001 remains TESTING.


## E-TRACK-06 — Tracking callback install-key resolution — 2026-10-26

- Targeted Pint passes for `TrackingIngestAuthenticator`, `CampaignTrackingController`, `DropTrackingController`, `CampaignTrackingTest`, and `DropTrackingTest`.
- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/CampaignTrackingTest.php tests/Feature/DropTrackingTest.php tests/Feature/WorkspaceAnalyticsDashboardTest.php tests/Feature/DmsAudienceListHistoryTest.php`: **23 passed / 196 assertions**. Coverage verifies the saved workspace key on both DMS-shaped routes, the configured installer fallback when workspace settings are absent, and fail-closed behavior when a saved key is empty.
- The actual-app no-op probe was attempted through the AlphaLink application kernel using saved workspace resources, with `email_id=0` so it could not persist an event. It was skipped because no workspace with both a saved install key and campaign was available. No saved event or email was created.
- DMS `RedirectHandlerController::stats/stats_drop` remains read-only source evidence. Campaign sender tokens, Drop callback wiring, queued processing, DMS geo/device and email-log side effects, and live data population remain open. Dashboard/Tracking stay TESTING; return to Suppression after the requested event/list foundation is populated and verified.

## E-AUD-07 — Bot recipient history count

- `docker exec -w /app suspicious_solomon vendor/bin/pint --format agent app/Models/ContactListMembership.php app/Services/Audiences/AudienceListHistoryCalculator.php app/Http/Controllers/Web/AudienceListManagementController.php app/Http/Controllers/Web/AudienceListHistoryController.php tests/Feature/DmsAudienceListManagementTest.php tests/Feature/DmsAudienceListHistoryTest.php tests/Feature/CampaignTrackingTest.php`: passes; Pint sorted one import.
- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/DmsAudienceListManagementTest.php tests/Feature/DmsAudienceListHistoryTest.php tests/Feature/CampaignTrackingTest.php`: **20 passed / 148 assertions**. Includes bot callback state change, daily snapshot count, zero count in the Lists grid, List History bot value, and the four unsupported counters remaining `NA`.
- Authenticated AlphaLink 8766 browser check loaded the Lists empty state for the selected existing ISP, with DMS's bot count column, then rendered List History selectors. There were no list rows in the browser state, so populated results are verified by synthetic feature tests only.
- The strict Premium UI audit output is saved at `/tmp/alphalink-premium-audit-20261026.json`. It reports nine existing findings in copied DMS assets and bundled PHPUnit JavaScript; none point to the changed feature files. `testing-best-practices` is not installed in the available skill paths, so the tests follow the repository's existing PHPUnit/RefreshDatabase feature-test conventions.
- No DMS mutation, local contact/list/event creation, message send, or remote action occurred. Historical null bot snapshots remain `NA` until their normal recalculation; no history backfill was run.


## E-DASH-07 — All Stats dimensions for available AlphaLink events — 2026-10-26

- `docker exec -w /app suspicious_solomon php artisan test --compact tests/Feature/WorkspaceCampaignStatisticsTest.php tests/Feature/WorkspaceAnalyticsDashboardTest.php`: **11 passed / 110 assertions**. Coverage includes a populated synthetic campaign open and attributed lead with resolved DMS dimensions and revenue, plus existing dashboard/report scope and empty-state behavior.
- Scoped Pint passes for the analytics controller and dashboard/statistics tests; PHP syntax checks pass for all three changed PHP files; strict Premium UI audit reports **0 findings**; `git diff --check` passes.
- Authenticated AlphaLink 8766 loaded `/app/workspaces/01a164e6-14b5-7275-8604-d9494d2fa4ed/dashboard/all-stats`; the live workspace has no matching campaign/Drop event or attributed lead rows, so the actual page rendered its empty state. No event was added to the saved workspace.
- DMS `DashboardAction::all_stats` and its Blade pivot are read-only source evidence. `CampaignAction::generate_body` only generates a preview body using zero send/recipient IDs; live sender token wiring remains unknown. DMS stayed read-only, and no provider report or email ran. The report remains partial and DMS-DASH-002 stays TESTING.

## Dashboard engagement queue regression — 2026-10-26

- Disposable PHP 8.3 / SQLite in-memory run with synthetic `APP_KEY`: `php artisan test --compact tests/Feature/WorkspaceAnalyticsDashboardTest.php tests/Feature/WorkspaceCampaignStatisticsTest.php tests/Feature/WorkspaceCampaignVisitorsTest.php tests/Feature/CampaignTrackingTest.php tests/Feature/DropTrackingTest.php tests/Feature/DmsAudienceListManagementTest.php tests/Feature/DmsAudienceListHistoryTest.php tests/Feature/CampaignLeadReconciliationTest.php`: **51 passed / 404 assertions**.
- Queue-fake coverage verifies campaign and Drop tracking callbacks return HTTP 204 after dispatching retryable five-attempt jobs and before event persistence. Existing service tests cover event processing, membership transitions, dashboard/report aggregation, visitor search, and list-history calculations.
- The current app listener on port 8766 is not running, so no current browser verification was possible. Previously open tabs do not establish current runtime or route behavior. No DMS mutation, real tracking callback, provider report, contact/list mutation, or email send ran.

## Dashboard and audience-list focused recheck — 2026-10-26

`docker exec amazing_burnell php artisan test --compact tests/Feature/WorkspaceAnalyticsDashboardTest.php tests/Feature/CampaignTrackingTest.php tests/Feature/CampaignLeadReconciliationTest.php tests/Feature/DropTrackingTest.php tests/Feature/DmsAudienceListHistoryTest.php tests/Feature/DmsAudienceListManagementTest.php tests/Feature/AudienceListImportTest.php` passed **63 tests / 493 assertions** in the PHP 8.3 container. The browser review used the same disposable SQLite fixture with synthetic history values. It is not a real saved-workspace event or sender test. No email, provider API or DMS action ran.


## Dashboard and audience-list opt-out count — 2026-10-26

- The focused PHP 8.3 command covered Main Dashboard, Campaign Tracking, campaign lead reconciliation, Drop Tracking, Audience List History, Audience List Management and List Import: **64 passed / 496 assertions**. Tests used SQLite memory, synthetic contacts/memberships and a temporary test key.
- The new regression verifies a saved opt_out membership is counted by daily recalculation, returned in List History, and shown in the Lists grid. Older nullable snapshots still return NA until recalculated.
- Targeted vendor/bin/pint --dirty --format agent passes for the five changed PHP files. git diff --check is run after the documentation update.
- No browser check was performed in this turn because browser policy rejected opening the local AlphaLink route. Prior isolated browser page checks remain evidence only for their captured data. No saved AlphaLink records, DMS action/data, email, provider call or live event was used.


## All Stats CTOR/EPC summaries — 2026-10-26

- `node --test tests/js/analytics-pivot.test.js`: **3 passed**; validates DMS CTOR and partial attributed-revenue EPC formulas, including zero denominators.
- `npm run build`: passed. `git diff --check`: passed.
- Strict Premium audit over a focused temporary project containing the changed JS: 0 findings. A scan of the full `public/dms-reference` tree returns 8 existing findings in unrelated `assets/js/script.js`, `vendor/jstree/jstree.min.js`, and `assets/css/theme.css`; none is in the changed file. The default project manifest excludes this reference tree.
- Browser verification could not run: authenticated CUA request to local All Stats returned `net::ERR_CONNECTION_REFUSED`. No DMS action, local saved-data mutation, PMTA operation or email send ran.


## AlphaLink browser runtime blocker — 2026-10-26

- Host PHP 8.3 lacks `DOMDocument`; the app's saved `.env` has an empty APP_KEY.
- A temporary loopback-only PHP 8.3.35 container with DOM and PDO PostgreSQL reached the All Stats route, which returned `MissingAppKeyException` before any database query. CUA showed a Laravel 500 page. The temporary container was stopped; no worker or scheduler ran.
- The inspected AlphaLink models use encrypted casts for server/provider credentials and workspace data. No replacement key was written because a new key could make saved encrypted values unreadable. No DMS action, PMTA operation, database mutation or email send ran.


## Dashboard daily analytics rollup — E-DASH-20 — 2026-10-26

- Isolated PHP 8.3 container, synthetic APP_KEY, SQLite memory: `php artisan test --compact tests/Feature/WorkspaceAnalyticsDashboardTest.php tests/Feature/CampaignTrackingTest.php tests/Feature/DropTrackingTest.php tests/Feature/CampaignLeadReconciliationTest.php tests/Feature/WorkspaceCampaignStatisticsTest.php tests/Feature/DmsAudienceListManagementTest.php tests/Feature/DmsAudienceListHistoryTest.php tests/Feature/AudienceListImportTest.php` — **71 passed / 593 assertions**.
- Coverage includes daily rollup counts/revenue, repeat-run idempotency, negative-lookback rejection without writes, workspace and operator scoping, scheduled prewarming, Main rendering from persisted rows, campaign and Drop event ingestion, attributed lead reconciliation and list-history behavior.
- Targeted `vendor/bin/pint --format agent` passed for the changed PHP files; `git diff --check` passed.
- No browser check was run because the saved APP_KEY is empty and the 8766 listener is unavailable. No DMS, saved local workspace event, PMTA host, provider or email operation ran.


## Dashboard report rollups — E-DASH-21 — 2026-10-26

- Isolated PHP 8.3 container, synthetic APP_KEY, SQLite memory: `php artisan test --compact tests/Feature/WorkspaceAnalyticsDashboardTest.php tests/Feature/CampaignTrackingTest.php tests/Feature/DropTrackingTest.php tests/Feature/CampaignLeadReconciliationTest.php tests/Feature/WorkspaceCampaignStatisticsTest.php tests/Feature/DmsAudienceListManagementTest.php tests/Feature/DmsAudienceListHistoryTest.php tests/Feature/AudienceListImportTest.php` — **74 passed / 607 assertions**.
- Coverage includes persisted All Stats JSON and dimensions, ISP/day-week-month grouping, mailer totals, Campaign Statistics filters, full-history backfill, invalid range rejection, idempotence, operator/workspace isolation, and campaign/Drop/list regressions.
- Targeted Pint passed for the changed PHP files. The local browser remains unavailable: saved APP_KEY is blank and port 8766 has no listener. No saved workspace data or DMS state was used.


## Dashboard and audience-data regression recheck — 2026-10-26

- Existing focused suites in an ephemeral PHP 8.3 container with a synthetic APP_KEY and PHPUnit's SQLite `:memory:` database: **66 passed / 555 assertions** across `WorkspaceAnalyticsDashboardTest`, `CampaignTrackingTest`, `DropTrackingTest`, `CampaignLeadReconciliationTest`, `DmsAudienceListHistoryTest`, `DmsAudienceListManagementTest`, and `WorkspaceCampaignStatisticsTest`.
- This rechecks existing local event processing, dashboard/report queries, and audience-list counters. It adds no proof of sender token generation, live callbacks, or queue-worker execution.
- The saved-workspace browser app remains unavailable at port 8766. No saved database, DMS state, email, provider, or PMTA host was used.

## Dashboard and tracker evidence recheck — E-DASH-23 / E-TRACK-16 — 2026-10-26

- Current checkout, ephemeral PHP 8.3 container, process-only synthetic `APP_KEY`, PHPUnit SQLite `:memory:`: **74 passed / 607 assertions** across dashboard, campaign/Drop tracking, lead reconciliation, campaign statistics, audience-list management/history, and import suites.
- Read-only DMS session used the configured proxy; four candidate tracker archive GET paths returned HTTP 404. REA static call-edge pages still do not identify a sender token/callback contract. No target was executed and no DMS product action/data row was accessed.
- Tests do not establish live event callbacks, queue-worker execution, saved-workspace state, or browser parity. No saved database, PMTA host, provider, or email was used.


## E-DASH-26 — Mailer Revenue pivot verification — 2026-10-26

- `WorkspaceAnalyticsDashboardTest` + `WorkspaceCampaignStatisticsTest`: **21 passed / 243 assertions** in ephemeral PHP 8.3 with synthetic APP_KEY and SQLite memory.
- `node --test apps/api/tests/js/analytics-pivot.test.js`: **5 passed**; `node --check` passed for `analytics-pivot.js` and `analytics-report-refresh.js`.
- Targeted Pint passed for the Mailer Revenue controller and dashboard test. The strict frontend audit produced 0 errors, warnings, violations or unresolved findings.
- No fresh browser check was possible: port 8766 has no listener and the saved APP_KEY is empty. This is local test evidence, not parity acceptance. See E-DASH-26 in the evidence register.


## E-DASH-27 — Campaign Statistics pivot verification — 2026-10-26

- `WorkspaceCampaignStatisticsTest`: **5 passed / 106 assertions**; combined dashboard and campaign statistics suites: **21 passed / 267 assertions** in ephemeral PHP 8.3 with synthetic APP_KEY and SQLite memory.
- `node --test apps/api/tests/js/analytics-pivot.test.js`: **7 passed**; `node --check` passed for `analytics-pivot.js` and `analytics-report-refresh.js`.
- Targeted Pint passed. Vite production build passed using workspace-local npm cache and temporary paths.
- Port 8766 has no listener and the saved APP_KEY is empty; no fresh browser parity check was made. See E-DASH-27 in the evidence register.


## E-DASH-28 — ISP Stats chart panels — 2026-10-26

- `WorkspaceAnalyticsDashboardTest` + `WorkspaceCampaignStatisticsTest`: **21 passed / 289 assertions** in an ephemeral PHP 8.3 container with a synthetic APP_KEY and SQLite `:memory:` database.
- `node --test apps/api/tests/js/analytics-pivot.test.js apps/api/tests/js/isp-stats-charts.test.js`: **11 passed**; `node --check` passed for both chart and pivot modules. Targeted Pint and `git diff --check` passed.
- The strict frontend audit returned 9 existing findings outside the files changed here: empty-hash links in copied/vendor JavaScript, a vendor jQuery textarea false positive, and the existing DMS theme's WebKit-only scrollbar styling. The changed dashboard files produced no matches in the focused false-affordance scan.
- The open browser reports 127.0.0.1:8766 refused the analytics route, so no fresh browser or saved-workspace check was possible. Tests use synthetic fixtures only and do not establish live sender callbacks or event population. No DMS mutation, saved AlphaLink database, PMTA host, provider, or email operation ran.


## E-DASH-30 verification replay — 2026-10-26

- Re-ran `WorkspaceAnalyticsDashboardTest`, `CampaignTrackingTest`, `DropTrackingTest`, `CampaignLeadReconciliationTest`, `WorkspaceCampaignStatisticsTest`, `DmsAudienceListManagementTest`, `DmsAudienceListHistoryTest`, and `AudienceListImportTest` in an isolated PHP 8.3 container with a test-only APP_KEY and SQLite `:memory:` database: **76 passed / 756 assertions**.
- The cross-surface scenario that sends synthetic open/click/unsubscribe callbacks, imports an attributed lead, rebuilds daily analytics, and checks dashboard and list pages also passes in the current checkout.
- This is synthetic local test evidence only. The browser still reports port 8766 unavailable and the saved APP_KEY is empty; sender token/callback behavior and event population from the saved AlphaLink workspace remain unverified. No DMS state, saved database, provider, PMTA host, or email was touched.


## Frontend regression sweep — 2026-10-26

- `node --test tests/js/*.test.js`: **60 passed / 0 failed** across the current browser-side regression suite, including analytics pivots, dashboard refresh, campaign placeholders/domain synchronization, audience assignment, visitor charts, PMTA controls, installation logs, and text tools.
- `npm run build`: passed with Vite 8.3.5.
- These checks do not start AlphaLink or verify browser parity. Port 8766 remains unavailable and the configured APP_KEY is empty; the original private key must be restored before starting the saved-workspace app. No saved database, DMS state, remote server, provider, or email was touched.

## E-CONN-01 — provider account connection checks — 2026-10-26

Focused provider regression: 44 tests / 454 assertions across Domain Provider registration/import, Cloud Provider console, and Sponsor management. Fake HTTP verifies success, denial, malformed success payloads, request methods/paths/auth/query parameters, same-type secret fallback, no persistence, no secret/body disclosure, and owner/admin permissions. Authenticated AlphaLink browser checks at 8766 verified the clean Domain Providers, Add Sponsor, and Add Cloud Provider forms; empty credentials produced inline feedback and no provider request. Real Namecheap, GoDaddy, Cloudflare, sponsor, Linode, and DigitalOcean accounts have not been tested.

## E-CAMP-100 offer suppression-file management — 2026-10-26

- `WorkspaceDropSuppressionTest` and `DmsOfferManagementTest` in the PHP 8.3 app container: **23 passed / 203 assertions**.
- Targeted Pint passed on the touched PHP files; `node --check public/dms-reference/drops-suppression.js` and `git diff --check` passed.
- Route-backed tests cover catalog visibility of inactive/file-missing offers, upload persistence and private path behavior, sponsor ownership, and role authorization. Suppression-run eligibility continues to use the existing strict rules.
- Authenticated 8766 browser check confirms sponsor selection loads its inactive/file-missing offer, shows its status and exposes file selection; no upload was submitted because that would change the existing offer. Post-upload browser persistence remains unverified. This is local test evidence, not accepted parity.

## E-CAMP-102 — campaign picker and PMTA relay diagnosis — 2026-10-27

- `DmsCampaignSendCatalogTest` selected regression: **3 passed / 322 assertions**.
- `node --test apps/api/tests/js/dms-placeholder-editor.test.js`: **1 passed / 0 failed**.
- Targeted Pint and `git diff --check`: passed.
- Authenticated local browser shows the selected OVH server's two IP options; the initial placeholder table is empty. The listener fallback is port 1212 and the admin panel port remains 1213.
- After user authorization, installed the reviewed single-source PMTA stanza, reloaded PMTA, and verified the service stayed active. A no-DATA SMTP envelope probe reached RCPT acceptance and issued RSET before DATA; no email was sent.
