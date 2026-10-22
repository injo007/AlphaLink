# Workspace recovery report — 2026-10-22

| Existing work | Classification | Disposition |
|---|---|---|
| DMS source, route, sender and integration audits under `audit/` | VERIFIED within each report's static/observed limits | Keep as reference evidence; retain hashes and caveats. Do not infer live outcomes from source alone. |
| Laravel 13 application, migrations, access middleware, audit events, encryption and local tests | INFRASTRUCTURE / PARTIAL | Preserve. Verify each workflow against DMS before acceptance. |
| Workspace/contact/many-to-many audience model | UNVERIFIED as DMS parity | DMS evidence instead describes ISP → list → recipient and one-list-per-address behavior; reconcile data semantics before extending it. |
| Campaign draft/revision/outbox shell | PARTIAL / UNVERIFIED | Keep recoverable; DMS campaign modes, offer/snapshot inputs, schedule and sender path are not yet represented. Do not present it as campaign parity. |
| Server/domain/installation preview | CONFLICTING | `audit/DMS_TO_ALPHALINK_PARITY_2026-10-22.md` documents form, eligibility, option, topology and execution differences. Retain security safeguards, then rebuild the reference-shaped workflow. |
| Phase 4A independent product requirements and advanced reference system | UNVERIFIED product proposals | Treat as proposals only where they change DMS behavior; require explicit product approval for deviations. |

The repository has no `.git` metadata at its root or in `apps/api`. Historical decisions and commits cannot be audited here. No existing code was removed during recovery.
