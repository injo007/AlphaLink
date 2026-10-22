# AlphaLink progress

**Checkpoint:** 2026-10-22. The root Git repository starts at `5797a6f` (`Start evidence-backed AlphaLink reconstruction`). This is a fresh local checkpoint, not recovered upstream history. Current validated work includes the server registration/list slice and audience import slice; the in-progress SSH behavior correction removes operator fingerprint input while preserving automatic host-key pinning for read-only preflight.

## DMS access and safety

Read-only DMS browser access is restored through the existing project `.env` proxy and the local relay that allows browser GETs only. The relay made one normal login/session POST upstream. The sanitized redirect trace and inspected pages are recorded as E-SRV-LIVE-01. For the latest list inspection, the relay returned synthetic empty DataTables JSON without making the production row-data request; it also hid the production account label. No DMS product form was submitted, no SSH session to a DMS-managed host was opened, and no product or operational state was changed. The relay is stopped and does not expose `.env` values.

## Current implementation slice

DMS-SRV-001 (server registration) and DMS-SRV-002 (server list/returned rows) are in `TESTING`, not accepted. AlphaLink now has a provider-backed DMS-shaped registration form, multi-IP API flow, encrypted credential storage, provider dropdown, expiry and separate Test Connection UI, a workspace-scoped list endpoint, DataTables toolbar/search, returned-row filter, and DMS-style Add modal. The local IP guard blocks private, reserved and documentation ranges before any socket creation. A local synthetic registration was submitted in the browser, displayed in the list, and fully removed after verification.

The owner confirmed that DMS does not request an SSH fingerprint during Test Connection or installation. AlphaLink no longer displays or accepts fingerprint input: Test Connection logs in directly over SSH/SFTP, and preflight automatically pins the first host key internally so later key changes are rejected without an operator prompt. DMS live SSH/install actions remain untested and prohibited. The installation execution route remains disabled.

DMS-INST-001 bulk-install planner is now `IMPLEMENTING`: AlphaLink has a DMS-shaped Cloud → Bulk Install Server page, PMTA version and seven switch controls, exact source-backed server/domain eligibility filters, candidate rows, APPLY-to-all domain behavior, removal controls, and the matching empty-grid message. The DMS page and its eligible list have been captured with synthetic values through the relay; the empty state was compared in the browser. APPLY copies a domain to all rows and does not choose servers; in DMS, INSTALL removes rows with no domain and submits every remaining row and all options. AlphaLink has no install payload/worker/log endpoint and keeps INSTALL disabled. Existing account username and provider/status mappings are missing, so no existing records are guessed eligible. No DMS row values, installation action, or SSH connection were used.

The live DMS and AlphaLink form/modal and filtered list empty state were compared in-browser at an equal 863×892 CSS viewport. The Add fields/order/defaults and modal structure are closely matched, and the no-match table state uses the same label. Parity remains open: DMS credential-storage and partial-failure rules, exact Test Connection outcomes, row relations, and row/bulk actions are not fully reconstructed. Local row-level controls remain disabled where DMS behavior has not been implemented.

DMS-AUD-002 is now `IMPLEMENTING`. The local import service applies lowercase normalization, email validation, conflict-do-nothing behavior and one-list assignment within a workspace. It writes encrypted contacts, append-only membership events, audit records and outbox events. The source Lists page was inspected through the existing `.env` proxy with synthetic ISP options and an empty table; its `/admin/lists/:id/load` GET remains uncalled because safety is unknown. The AlphaLink ISP entity and matching Lists UI are still missing, so no audience feature is accepted.

## Verification

Docker PHP 8.3 full test suite now passes: **23 tests, 278 assertions**. Eight DMS relay privacy tests pass. Pint and `git diff --check` pass. The local bulk-install empty state matches the sanitized DMS empty grid. The local INSTALL control remains intentionally disabled; this slice is not parity acceptance. The Add Servers page contains no fingerprint prompt, and no connection test was submitted. See `docs/implementation/TEST_REPORT.md` and `docs/implementation/PARITY_REPORT.md`.

## 80% target

The requested 80% means implemented, evidence-backed frontend and backend workflows that work locally and meet the acceptance gates. The feature inventory is incomplete, complex workflow weights are not yet defined, and no feature is `PARITY_CONFIRMED`; therefore no honest 80% percentage is available. Do not count screens, routes, documents, or code files as completed coverage. Continue with reference evidence and one dependency-ready vertical slice at a time.
