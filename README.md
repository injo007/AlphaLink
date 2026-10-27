# AlphaLink

AlphaLink is a new, independent implementation of the existing DMS reference SaaS. Reference behavior is the product source of truth. The current Laravel foundation contains provisional design choices; none is accepted as feature parity until its original business rules and equivalent target behavior are evidenced and tested. See [the master specification](ALPHALINK_MASTER_SPEC.md), [feature matrix](docs/reference/FEATURE_MATRIX.md), and [parity report](docs/implementation/PARITY_REPORT.md).

## Current code

The first application slice is in [apps/api](apps/api/README.md). It uses Laravel 13/PHP 8.3, Sanctum, PostgreSQL, and Redis as the provisional baseline. It includes authenticated workspaces, masked contacts, audience lists and membership history, append-only consent and suppression evidence, encrypted campaign drafts, audit events, outbox storage, and a session-authenticated read-only browser console for workspace, campaign, and audience review. These local behaviors have not yet passed reference parity. No outbox dispatcher or sending path is implemented yet.

Outbound sending, provider calls, server operations, and production DMS access are not implemented or enabled. Consent/legal policy, first transport, workload, hosting, and several other release decisions remain open.

## Fresh Ubuntu 26.04 LTS installation

Use the single-file installer in [`install-ubuntu-26.sh`](install-ubuntu-26.sh) on a clean Ubuntu 26.04 LTS host. Point the application hostname to that host before starting, ensure inbound SSH/HTTP/HTTPS are reachable, then copy the script to the server and run `sudo bash install-ubuntu-26.sh`. It installs PHP 8.5, PostgreSQL 18, local Valkey, Nginx and the locked application dependencies; creates a new PostgreSQL database and protected Laravel environment; runs migrations; bootstraps the first owner; and configures HTTPS, queue/scheduler services, and a local daily database dump.

The installer is fresh-install only: it refuses to replace an existing AlphaLink checkout, environment, PostgreSQL role, or database. It prompts for a GitHub token only when the repository is private, uses it for cloning, then removes the temporary credential. Do not commit `.env` or pass tokens on a command line. Keep an encrypted off-server copy of `/etc/alphalink/.env`; its keys are needed to read encrypted application values. The scheduled database dump is local to that host and is not disaster recovery by itself. This installs the AlphaLink web application; it does not configure a production campaign sender or deploy PMTA.

## Reference material

- [Advanced AlphaLink reference system](audit/ALPHALINK_ADVANCED_REFERENCE_SYSTEM_2026-10-22.md)
- [DMS conclusion](audit/DMS_COMPLETE_CONCLUSION_2026-10-22.md)
- [DMS sender resolution](audit/DMS_REA_SENDER_RESOLUTION_2026-10-22.md)
- [Phase 4A decisions register](design/PHASE_4A_DECISIONS.md)

The top-level `.env` is excluded from source control and was not copied into the application.
