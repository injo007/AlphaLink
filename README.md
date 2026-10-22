# AlphaLink

AlphaLink is a new, independent implementation of the existing DMS reference SaaS. Reference behavior is the product source of truth. The current Laravel foundation contains provisional design choices; none is accepted as feature parity until its original business rules and equivalent target behavior are evidenced and tested. See [the master specification](ALPHALINK_MASTER_SPEC.md), [feature matrix](docs/reference/FEATURE_MATRIX.md), and [parity report](docs/implementation/PARITY_REPORT.md).

## Current code

The first application slice is in [apps/api](apps/api/README.md). It uses Laravel 13/PHP 8.3, Sanctum, PostgreSQL, and Redis as the provisional baseline. It includes authenticated workspaces, masked contacts, audience lists and membership history, append-only consent and suppression evidence, encrypted campaign drafts, audit events, outbox storage, and a session-authenticated read-only browser console for workspace, campaign, and audience review. These local behaviors have not yet passed reference parity. No outbox dispatcher or sending path is implemented yet.

Outbound sending, provider calls, server operations, and production DMS access are not implemented or enabled. Consent/legal policy, first transport, workload, hosting, and several other release decisions remain open.

## Reference material

- [Advanced AlphaLink reference system](audit/ALPHALINK_ADVANCED_REFERENCE_SYSTEM_2026-10-22.md)
- [DMS conclusion](audit/DMS_COMPLETE_CONCLUSION_2026-10-22.md)
- [DMS sender resolution](audit/DMS_REA_SENDER_RESOLUTION_2026-10-22.md)
- [Phase 4A decisions register](design/PHASE_4A_DECISIONS.md)

The top-level `.env` is excluded from source control and was not copied into the application.
