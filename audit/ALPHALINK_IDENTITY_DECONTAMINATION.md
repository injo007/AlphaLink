# AlphaLink identity and dependency audit

**Status:** Baseline recorded 2026-11-04; implementation audit in progress.

## Scope and boundaries

This audit distinguishes AlphaLink’s visible product identity, historical research material, database compatibility, and code or assets that affect runtime behavior. AlphaLink is the only live application in scope. Do not access or operate the former service, its servers, credentials, databases, mail, APIs, or assets. The audit does not read local credential files or the contents of untracked server logs.

## Baseline and rollback

| Item | Baseline |
|---|---|
| Repository | `/media/akmed/4CF25768F25754F43/Alphalink` |
| Branch | `codex/alphalink-identity-reconstruction` |
| Baseline commit | `690bcf92ada8ba3eafe450e6a08c1c25beff3306` |
| Tracked paths | 988 |
| Tracked text files matching the bounded DMS-name scan | 57 |
| Matching lines | 3,411 |
| Source changes at baseline | None |
| Production changes | None |

All implementation changes are to be made on this branch. Reverting this branch to the baseline commit restores the tracked code and documentation state. Production deploys are outside this audit until source changes, deterministic tests, fresh-install checks, and review are complete.

The working tree also contains untracked `.env` linkage, `Alphalink_crendentials.txt`, and `log_DMS/`. They are excluded from this baseline’s content scan and must remain unstaged. Only the directory/file names were observed; credential and log contents were not read. The environment key-name-only check did not identify DMS-named or legacy-named keys; values were not read.

## Identity finding

The live AlphaLink UI page checked on 2026-11-04 displays **AlphaLink** in its navigation and footer and contains no visible DMS brand label. The current workspace’s Lists selector exposes one active ISP, Gmail, and one list, `Gm_Repo` (US). This is a snapshot of current account configuration, not a limit in the data model.

The app data model supports multiple ISP records per workspace, tracked countries per ISP, and multiple audience lists associated with each ISP and country. Campaign send setup currently selects one ISP and one country per campaign; it supports multiple list/type segments within that selection. Sending to several ISP/country combinations therefore requires separate campaigns today.

## Reference scan classification

The 57 tracked text files and 3,411 matches were classified as follows:

| Class | Finding | Treatment |
|---|---|---|
| Historical audit and evidence | `audit/` reports, route maps, tracking studies, and related files use former-service names to identify the evidence being described. | Retain as historical evidence; mark the boundary clearly. Do not treat as runtime dependencies or feature authorization. |
| Product and implementation reference docs | `docs/reference/`, `docs/implementation/`, `design/`, and `apps/api/UX-CONTRACT.md` contain comparisons, feature identifiers, and test history. | Keep verifiable AlphaLink decisions; clearly label former-service material as historical context. Remove stale claims from current contracts. |
| Migration compatibility | Existing migrations map historical migration-ledger names and the old `dms_csv` format marker to AlphaLink identifiers/formats. | Retain the reversible mapping so existing databases remain upgradeable. Do not rewrite stored migration history. |
| Tests | Tests cover reversible migration behavior and AlphaLink upload compatibility. | Keep synthetic tests; ensure naming tests distinguish compatibility strings from runtime UI. |
| Active tracking deployment asset | `install-assets/optional/tracking/mini-app.zip` was a tracked ~9.9 MB package, pinned by `AlphaLinkReviewedAssetDigestVerifier` and deployed by `AlphaLinkCentos9TrackingDeploymentStage`. Existing evidence-register entries explicitly identify it as a former-service mini-app archive. The deployment stage unpacked it and edited former app files by matching source strings. | Replaced in source with AlphaLink-owned, source-controlled PHP tracking files. The public root remains `/var/mini/public` to preserve externally shared offer media; only the root `index.php` is replaced, and the separate tracker handler/config are installed at `/var/alphalink-tracker` and `/etc/alphalink-tracker.php`. The legacy archive is not inspected or executed. |
| Runtime text and dependencies | No DMS-named package dependency, environment key, visible UI label, or DMS-named route was found in the reviewed live application source/configuration. The historical tracking archive has been replaced by a first-party tracker. Separately, repository history identifies the active workspace relay binary as a “DMS release asset”; its source/build provenance remains unresolved. | Keep the first-party tracker. Do not claim all active code has independent provenance until the relay has a first-party reproducible source/build or is independently verified. Keep historical evidence separated from current contracts. |

## Implementation snapshot — 2026-11-04

- Removed the tracked opaque tracker bundle without inspecting or extracting it. Its baseline SHA-256 is `be4c6fc20e109f57f532715727c9e305521fb6c8c9cad9438da9cd51412f921a` (9,855,996 bytes), retained here only to identify the removed artifact.
- Replaced it in the installer catalog with `install-assets/optional/tracking/index.php` (3,250 bytes, SHA-256 `8fcc32fca7d97d3144715ddd9e676bcfc0cbfb7107d61300669d002039029974`) and `TrackerRequestHandler.php` (11,669 bytes, SHA-256 `c4a586dcea351051775e733bc092de5a715c48f4cd0d12db93119bbc696c65bb`). Both source pins verify from a private staged asset root.
- The tracking deployer keeps `/var/mini/public` as Apache's public root for existing offer media, moves handler code to `/var/alphalink-tracker`, and writes protected configuration to `/etc/alphalink-tracker.php`. It rewrites only tracking-shaped paths and `/healthz`, leaves media paths untouched, and blocks direct execution of other PHP files in the public root.
- Renamed the encrypted workspace field to `tracking_ingest_key`. A reversible migration renames the column without decrypting or changing stored ciphertext. `ALPHALINK_INSTALL_MINI_APP_KEY` remains an environment fallback until installed environments can migrate to `ALPHALINK_TRACKING_INGEST_KEY`.
- The API now requires PHP `^8.5` in `composer.json` and `composer.lock`, matching the fresh installer’s PHP 8.5 target. The workstation default remains PHP 8.3.6; focused application validation uses PHP 8.5.12 explicitly.
- The standalone handler suite passes 6 synthetic checks. The PHP 8.5.12 focused suite passes 154 tests / 1,376 assertions, changed PHP syntax checks pass, and Pint passes. The new reversible key-column migration also passes against a disposable PostgreSQL 18 database (1 test / 7 assertions), preserving encrypted bytes across down/up. The full PHP suite has one reproducible failure in the unrelated `AlphaLinkPmtaFeedbackImportTest::test_delivery_and_hard_bounce_follow_reference_recipient_type_precedence` (expected 2, got 3); it ran 1,048 tests / 9,736 assertions, with 15 notices and one skip. The PHP 8.5 Vite build and `git diff --check` pass. No production change has occurred.
- Additional current-worktree PHP 8.5.12 verification passes 91 focused tests / 826 assertions across workspace tools, audience upload, campaign tracking, and Drop tracking. Pint passes for all 100 changed PHP files. A repository-wide Pint scan still reports a formatting issue in unchanged `apps/api/bootstrap/providers.php`.

### Relay artifact provenance gate — 2026-11-04

- `pmta/workspace_relay/workspace_relay` is a tracked, stripped, statically linked Linux x86-64 executable (SHA-256 `8cd307e33cfba2411f8fed6e5781ff48180589666e5e0321f23a907c6ad878c4`). The AlphaLink workspace SMTP deployment service copies it to managed mail hosts, so it is an active runtime asset.
- Repository history identifies commit `0b90f2afc8988bc6c7919c18ceee7df13dce94b3` as “Replace crashing Workspace relay with DMS release asset.” Go build metadata (REA evidence `ev_e35a422fb4caf1f7bcda9d9d25618fb6396926d33b509bfc9a53c3e7e9848ea2`) identifies the module as `workspace_listener`, built with Go 1.25.0 from source revision `d0a041ced117dd919437fadcb31e0e46e53b9d99` and marks the source tree `modified=true`. That revision is absent from the AlphaLink Git object database, and no corresponding Go source, build manifest, or reproducible build recipe exists in this repository.
- We did not execute the binary or contact any external service. The embedded module name is not DMS-branded, but its recorded release-asset provenance and dirty, unavailable source mean code independence is unverified. Build metadata does not prove a live connection—or the absence of one—to the former SaaS. Keep this as an open provenance gate. Do not remove or replace the binary until a first-party reproducible implementation can preserve the SMTP relay contract and pass isolated functional tests.

### Final scoped identity check — 2026-11-04

- A bounded case-insensitive scan found no DMS-name strings, former implementation labels, or former class identifiers in active PHP source, configuration, routes, views, public build assets, or text installer assets. The current README, master specification, progress summary, and UX contract contain no DMS-name references. The active relay binary remains a separate provenance gate because its source is unavailable and repository history identifies it as a DMS release asset.
- Four application paths retain old names only for data compatibility: two migrations that normalize historical migration-ledger names and the `dms_csv` import-format marker, plus their two feature tests. These are not runtime product labels or connections to another service.
- Archived engineering records are explicitly classified in `audit/README.md`; use the root progress file and master specification as the current product status and requirements. The live page checked during this audit shows AlphaLink in the shell. The account snapshot is one Gmail ISP and one US list (`Gm_Repo`); it is not a data-model limit.
- The first-party tracker files remain mapped in the installer catalog and match the reviewed SHA-256 pins above. Focused installer/settings/tracker tests and the fresh-install contract test ran in the PHP 8.5.12 suite. The full PHP suite has the unrelated PMTA feedback failure recorded above; relay provenance and a clean-host installation remain open. No live install or production mutation occurred.

### Baseline tracked-file inventory

Counts below are matching lines per path from the baseline commit's bounded, case-insensitive scan. They are historical baseline counts, not current-branch counts. `DMS` references are retained as evidence unless their row is separately identified as a runtime dependency.

**Root:** `ALPHALINK_MASTER_SPEC.md` (2), `ALPHALINK_PROGRESS.md` (769), `README.md` (1).

**Application:** `apps/api/UX-CONTRACT.md` (32); `apps/api/database/migrations/2026_10_22_225000_normalize_historical_migration_ledger_names.php` (10); `apps/api/database/migrations/2026_11_03_055018_normalize_audience_list_import_format.php` (2); `apps/api/tests/Feature/AlphaLinkListUploadTest.php` (2); `apps/api/tests/Feature/AlphaLinkMigrationLedgerNormalizationTest.php` (10).

**Audit:** `audit/ALPHALINK_ADVANCED_REFERENCE_SYSTEM_2026-10-22.md` (12), `audit/ALPHALINK_BUILD_HANDOFF_2026-10-22.md` (8), `audit/ALPHALINK_DATABASE_BLUEPRINT_LEGACY_TRACE.md` (41), `audit/ALPHALINK_IMPROVEMENT_OPPORTUNITIES.md` (2), `audit/BACKEND_ARCHITECTURE.md` (3), `audit/BACKEND_READ_ONLY_PLAN.md` (2), `audit/CAMPAIGN_ORCHESTRATION.md` (1), `audit/DATABASE_ARCHITECTURE.md` (1), `audit/EXTERNAL_INTEGRATIONS.md` (3), `audit/LEGACY_ARCHITECTURAL_WEAKNESSES.md` (1), `audit/LEGACY_CAMPAIGN_STATE_MACHINE.md` (1), `audit/LEGACY_COMPLETE_COMPONENT_MAP.md` (3), `audit/LEGACY_COMPLETE_CONCLUSION_2026-10-22.md` (10), `audit/LEGACY_FAILURE_RECOVERY_ANALYSIS.md` (1), `audit/LEGACY_MESSAGE_COMPOSITION.md` (3), `audit/LEGACY_NATIVE_SENDER_ANALYSIS.md` (6), `audit/LEGACY_PHASE_4B_REASSESSMENT.md` (16), `audit/LEGACY_REA_REFRESH_EVIDENCE_2026-10-22.json` (1), `audit/LEGACY_REA_SENDER_RESOLUTION_2026-10-22.md` (3), `audit/LEGACY_RECONSTRUCTION_EVIDENCE.md` (4), `audit/LEGACY_REFERENCE_AUDIT_PHASE_1.md` (14), `audit/LEGACY_REFERENCE_ROUTE_EVIDENCE.md` (1), `audit/LEGACY_REVERSE_ENGINEERING_FINAL_REPORT.md` (5), `audit/LEGACY_ROUTE_WORKFLOW_ASSIGNMENTS_2026-10-25.csv` (514), `audit/LEGACY_SECURITY_BOUNDARIES.md` (1), `audit/LEGACY_SENDING_DATAFLOW.md` (6), `audit/LEGACY_TO_ALPHALINK_PARITY_2026-10-22.md` (19), `audit/LEGACY_TRACKING_ATTRIBUTION_FLOW.md` (2), `audit/LUNA_IMPLEMENTATION_AND_PMTA_AUDIT_2026-10-22.md` (1), `audit/MAIL_INFRASTRUCTURE.md` (4), `audit/PHPMAILER_SENDING_ENGINE.md` (3), `audit/PROGRESS.md` (16), `audit/REFERENCE_AUDIT_FINAL_REPORT.md` (3), `audit/REFERENCE_SYSTEM_ARCHITECTURE.md` (1), `audit/SECURITY_AND_RELIABILITY.md` (3), `audit/TRACKING_AND_ANALYTICS.md` (39).

**Design:** `design/PHASE_4A_ARCHITECTURE_AND_STATE_MACHINES.md` (5), `design/PHASE_4A_DECISIONS.md` (23), `design/PHASE_4A_INDEX.md` (13), `design/PHASE_4A_PRODUCT_REQUIREMENTS.md` (10), `design/SERVER_INSTALLATION_V1.md` (2).

**Docs:** `docs/implementation/BLOCKERS.md` (172), `docs/implementation/PARITY_REPORT.md` (332), `docs/implementation/RECOVERY_REPORT.md` (5), `docs/implementation/TEST_REPORT.md` (372), `docs/reference/API_CONTRACTS.md` (3), `docs/reference/BUSINESS_RULES.md` (142), `docs/reference/EVIDENCE_REGISTER.md` (631), `docs/reference/FEATURE_MATRIX.md` (119).

## Change ledger

| ID | Component | Baseline finding | Planned action | Verification | Rollback / compatibility |
|---|---|---|---|---|---|
| ID-001 | Live product identity | Live page label and footer say AlphaLink. | Preserve AlphaLink identity in current user-facing shell. | Authenticated browser check of key pages and a source scan for user-facing DMS labels. | Revert any UI change independently. |
| ID-002 | Multi-ISP/list/geo workflow | Models support multiple ISP records and lists; send setup is one ISP and country, with multiple segments. | Document current per-send limit accurately; do not imply cross-ISP batch sending. | Campaign catalog, request validation, and focused multi-list/country tests. | No data migration. |
| ID-003 | Former-service archive | Active installer used a pinned opaque ZIP and patched its PHP files by source pattern. | Build a first-party tracking package and remove archive extraction/source surgery. | Standalone request tests, fresh-install digest contract, deployment-stage tests, and route behavior tests passed in the PHP 8.5.12 focused suite. | The old digest is preserved in the baseline record only; the archive is deleted from current tracked assets. The public offer-asset root remains in place. |
| ID-004 | Historical docs and migration compatibility | Intentional evidence and reversible upgrade mappings remain. | Clearly mark historical and superseded documents; preserve reversible migration behavior. | Migration tests and full bounded scan with classified exceptions. | Existing database ledger/import marker mappings remain reversible. |
| ID-005 | Tracking credential identity | The encrypted workspace field and settings API used the internal name `mini_app_key`, despite the user-facing label being a tracking key. | Rename the persisted field and application payload to `tracking_ingest_key`; add a reversible column rename without changing ciphertext; prefer `ALPHALINK_TRACKING_INGEST_KEY` while retaining the old environment name as a temporary fallback. | Migration test verifies schema up/down and ciphertext preservation; settings, install, and tracking-auth tests verify callers after rename. | Rollback renames the column back; the encrypted value remains byte-for-byte unchanged. Remove the environment fallback only in a later release after existing `.env` files have migrated. |

## Acceptance criteria

- User-facing AlphaLink pages contain no former-service product name or former-service-branded assets.
- No active installer/runtime path downloads, unpacks, patches, or otherwise executes the former-service tracker archive.
- AlphaLink’s first-party tracker preserves required redirect, open, click, unsubscribe, token validation, long-link routing, and callback behavior with testable source.
- Historical audit material is clearly labeled and retained only where it provides traceability; migrations preserve old database compatibility.
- Fresh installation and deployment manifests reference only reviewed AlphaLink-owned assets.
- Focused PHP 8.5 tests, required PHP formatting, the install contract, and the browser identity check pass. The repository-wide suite retains one unrelated reproducible failure. The active relay binary has unresolved DMS release-asset provenance, and a clean-host installation/deployment has not been performed. No production changes have been made.
