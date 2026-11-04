# AlphaLink development progress

**Updated:** 2026-11-04
**Active objective:** Complete Drops and Warmups reconstruction, then finish identity reconstruction.
**Status:** Workspace-scoped Drop and Warmup draft pages, navigation, and unique-action statistics are deployed in release `43a9a851d1f0b91d8e53e1562a9f663d6d26bb5d`. Draft/send parity remains incomplete because there is no verified AlphaLink-native Drop/Warmup execution path. No DMS changes or sender operations were made.

## Drops and Warmups gate

- The user updated the root `.env` with a DMS frontend URL, admin credentials, proxy credentials, and a second SSH credential block. Secrets remain undisplayed. `app.pxlrbldlnk.com` resolves to `157.90.79.168`, matching the second SSH host; SSH used the existing pinned key with strict checking. The first SSH host remains AlphaLink production. The duplicate `SSH_*` names are ambiguous to generic dotenv consumers; no `.env` values or variable names were changed during this inspection.
- Through the configured proxy over HTTPS, the DMS login/dashboard loaded and authenticated read-only GETs confirmed Drops Monitoring, Suppression, Statistics, Warmups, New Warmup, Seeds, Add Seed List, and Warmups Statistics screens. E-CAMP-154 rechecked all eight page routes in the current session and retained only headings/static labels; dynamic row endpoints and recipient values were not fetched. Route/controller/model/action/job/command/view source was read and 56 selected files were copied to `/tmp/alphalink-dms-reference-20261104/source`; the static sender executable was copied separately and not executed. Sanitized source hashes are in [`audit/DMS_SOURCE_HASHES_2026-11-04.csv`](audit/DMS_SOURCE_HASHES_2026-11-04.csv). No DMS business records, configuration, code, queues, or services were changed, and no send/test-send/schedule/suppression/process-control action was run. One empty login POST was inadvertently sent before the authenticated session; it did not alter product data.
- Existing source observations remain useful historical evidence, but the live source/UI inventory is still incomplete. The sanitized route, permission, form, model, and side-effect inventory is [`audit/DROPS_WARMUPS_REFERENCE_INVENTORY_2026-11-04.md`](audit/DROPS_WARMUPS_REFERENCE_INVENTORY_2026-11-04.md); remaining discovery work is tracked in [`audit/DROPS_WARMUPS_PHASE1_BLOCKER.md`](audit/DROPS_WARMUPS_PHASE1_BLOCKER.md).
- Drops now has an accessible workspace-scoped draft register and authoring form; Warmups now has draft list/create/edit pages plus a unique-action statistics report. These save encrypted immutable revisions and do not send, test, schedule, or claim delivery. Drop Test/Send/Schedule and Warmup execution/process lifecycle remain INVESTIGATING because the AlphaLink-native sender contract is unavailable. Drop monitoring/statistics/visitors/suppression and seed-list management remain TESTING with documented gaps. No feature is PARITY_CONFIRMED.
- A sanitized read-only DMS source bundle is available under `/tmp/alphalink-dms-reference-20261104/source`, with hashes in the repository audit manifest. Phase 2 can proceed after the source/UI inventory and isolated sender contract are complete; do not use AlphaLink production credentials as a substitute.

## Current product behavior

- E-DROP-STAT-001 implements the Drop Statistics report: a workspace-scoped aggregate with date, Drop, ISP, List, geo and send-type filters, a pivot and paginated fallback. Counts represent stored visitor rows, so repeated actions and scanner traffic may be present. E-CAMP-155 adds dedicated Drop and Warmup draft pages. Draft content is encrypted and revisioned; HTML survives edit unchanged. Drop target selections are validated against workspace/ISP/list/country scope. Warmup reports use the deduplicated `campaign_engagement_unique_actions` table. Both authoring workflows are draft-only; no sender, test, schedule, or delivery claim is available.

- AlphaLink is the visible product identity in the live application. The bounded scan found no DMS-name references in active application source, configuration, routes, views, public build assets, or installer assets.
- The API now requires PHP `^8.5` in both `composer.json` and `composer.lock`; the fresh installer also targets PHP 8.5. The workstation's default CLI is PHP 8.3.6, so validation commands use the isolated PHP 8.5.12 runtime explicitly.
- Four application paths retain old names solely for database/import compatibility: two migrations that normalize historical migration-ledger names and the `dms_csv` import-format marker, plus their two synthetic feature tests. Archived engineering reports preserve dated evidence and are labeled as historical.
- The data model supports multiple ISP records, audience lists, and countries. A campaign send currently selects one ISP and one country; it can include multiple lists or segments for that target. Several ISP/country targets require separate campaigns today.
- The tracking installer now uses AlphaLink-owned PHP source. It keeps `/var/mini/public` as the public media root, routes tracking-shaped paths and `/healthz` to the tracker, preserves existing offer media URLs, and blocks direct execution of other PHP files in that public directory.
- The opaque tracker archive was removed from tracked installer assets. The encrypted workspace key field is now named `tracking_ingest_key`, with a reversible column rename that preserves ciphertext. New installs prefer `ALPHALINK_TRACKING_INGEST_KEY`; the prior environment variable remains a transition fallback for existing installs.

## Verification

- E-CAMP-155 Drop/Warmup workflow suite: **5 tests / 45 assertions**; combined Drop monitoring/statistics/suppression, seed-list, campaign clone/test-navigation suites: **72 tests / 716 assertions**. Both ran under PHP **8.5.12**. Route caching, targeted Pint, and `git diff --check` pass. Release `43a9a851d1f0b91d8e53e1562a9f663d6d26bb5d` is pushed to GitHub and deployed; live route checks are recorded in E-CAMP-155.

- E-DROP-STAT-001 final verification: PHP 8.5.12 full suite **1,052 tests, 9,786 assertions**, 15 PHPUnit notices and one skipped test; focused Drop Statistics/Monitoring **14 tests, 135 assertions**; JavaScript **91/91**; Vite production build, Pint, and `git diff --check` passed.
- Validation ran in an isolated PHP **8.5.12** container, matching the fresh installer target. Focused PHP coverage passed: **154 tests, 1,376 assertions**. PHP syntax checks and Pint passed.
- Additional checks on the current worktree passed in PHP 8.5.12: **91 focused tests, 826 assertions**, including workspace tools, audience upload, campaign tracking, and Drop tracking. Pint passed for all **100 changed PHP files**. A repository-wide Pint scan also reports a pre-existing formatting issue in unchanged `apps/api/bootstrap/providers.php`.
- The full PHP 8.5.12 suite now passes: **1,048 tests, 9,739 assertions**, with 15 PHPUnit notices and one skipped test. The prior failure was a stale assertion: delivery writes both the membership transition and its action-segment event; the test now checks the correct initial and resulting counts. No PMTA production behavior was changed.
- Additional release checks pass: JavaScript **89/89**, standalone AlphaLink tracker **6/6**, PHP 8.5 Vite production build, Composer metadata validation, PHP formatting, shell syntax, deploy-plan, and `git diff --check`.
- The tracking-key migration passed against a disposable PostgreSQL 18 database: **1 test, 7 assertions**, including down/up reversibility and exact encrypted-value preservation. The disposable database and container were removed after the test.
- The first-party tracker’s standalone suite passed **6 synthetic checks**. The PHP 8.5 Vite build passed. `git diff --check` is clean.
- The bounded AlphaLink scan found no DMS-name strings, former implementation labels, or former class identifiers in active PHP source/config/routes/views/public build or text installer assets. The only current application DMS-name strings are in two reversible data-compatibility migrations and their two synthetic tests. DMS was contacted only for the explicitly authorized, proxy-routed, read-only reference audit; no DMS production rows were retained and no DMS data/configuration/operational state was changed.
- The live AlphaLink page checked during this audit shows AlphaLink in the product shell. The current account snapshot has one Gmail ISP and one US list (`Gm_Repo`); that snapshot does not constrain the data model.

## Remaining gates

1. The bundled workspace relay remains an open provenance gate. Commit `0b90f2a` records it as a “DMS release asset”; the active binary has no source or reproducible build recipe in this repository. Static inspection does not prove it connects to the former service, but it also cannot prove its code is independent. Replace or independently verify it before claiming full decontamination.
2. Complete Drops and Warmups workflow reconstruction and acceptance; native sender behavior remains blocked by the unavailable reproducible contract.
3. Complete final review of installer manifest coverage and tracker contract tests. No production install, deployment, or send has been run as part of this audit.

## Historical record

The previous detailed progress log is preserved in [`audit/ALPHALINK_DEVELOPMENT_HISTORY.md`](audit/ALPHALINK_DEVELOPMENT_HISTORY.md). It records dated implementation and verification evidence; this file is the current status summary.
