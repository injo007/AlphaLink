# Verification record — 2026-10-22

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
