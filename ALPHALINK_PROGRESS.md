# AlphaLink progress

**Checkpoint:** 2026-10-22. The root Git repository starts at `5797a6f` (`Start evidence-backed AlphaLink reconstruction`). This is a fresh local checkpoint, not recovered upstream history. The validated server-slice checkpoint is committed as `54df0b1` (`Implement evidence-backed DMS server slice`).

## DMS access and safety

Read-only DMS browser access is restored through the existing project `.env` proxy and the local relay that allows browser GETs only. The relay made one normal login/session POST upstream. The sanitized redirect trace and inspected pages are recorded as E-SRV-LIVE-01. DMS production rows were filtered from view during list comparison; no row values were retained. No DMS product form was submitted, no SSH session to a DMS-managed host was opened, and no product or operational state was changed. The relay is stopped and does not expose `.env` values.

## Current implementation slice

DMS-SRV-001 (server registration) and DMS-SRV-002 (server list/returned rows) are in `TESTING`, not accepted. AlphaLink now has a provider-backed DMS-shaped registration form, multi-IP API flow, encrypted credential storage, provider dropdown, expiry and separate Test Connection UI, a workspace-scoped list endpoint, DataTables toolbar/search, returned-row filter, and DMS-style Add modal. The local IP guard blocks private, reserved and documentation ranges before any socket creation. A local synthetic registration was submitted in the browser, displayed in the list, and fully removed after verification.

The live DMS and AlphaLink form/modal and filtered list empty state were compared in-browser at an equal 863×892 CSS viewport. The Add fields/order/defaults and modal structure are closely matched, and the no-match table state uses the same label. Parity remains open: DMS credential-storage and partial-failure rules, exact Test Connection outcomes, row relations, and row/bulk actions are not fully reconstructed. Local row-level controls remain disabled where DMS behavior has not been implemented.

## Verification

Docker PHP 8.3 full test suite: **16 passed, 202 assertions**. Pint passed on changed PHP files; Node syntax checks passed for the three DMS server scripts; `git diff --check` passed. The IANA-address regression cases now block special-use addresses before socket creation. See `docs/implementation/TEST_REPORT.md` and `docs/implementation/PARITY_REPORT.md`.

## 80% target

The requested 80% means implemented, evidence-backed frontend and backend workflows that work locally and meet the acceptance gates. The feature inventory is incomplete, complex workflow weights are not yet defined, and no feature is `PARITY_CONFIRMED`; therefore no honest 80% percentage is available. Do not count screens, routes, documents, or code files as completed coverage. Continue with reference evidence and one dependency-ready vertical slice at a time.
