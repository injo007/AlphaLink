# AlphaLink project instructions

AlphaLink is a new implementation of the existing DMS reference SaaS. The reference application defines product behavior; AlphaLink is a separate local target. Never deploy AlphaLink to, or intentionally change, the reference system. Reference frontend, backend, database, server, credentials, mail infrastructure and integrations are read-only. Do not use live send, install, test-connection, or other potentially mutating reference actions to discover behavior.

Before changing a feature, read `ALPHALINK_MASTER_SPEC.md`, `ALPHALINK_PROGRESS.md`, `docs/reference/FEATURE_MATRIX.md`, `docs/implementation/PARITY_REPORT.md`, `docs/implementation/BLOCKERS.md`, the relevant `audit/` evidence, and recent Git history. The root Git repository starts at `5797a6f` (`Start evidence-backed AlphaLink reconstruction`); it is a new checkpoint, not recovered upstream history. Inspect and preserve existing local changes before committing.

Use the configured `.env` proxy for approved read-only DMS browser inspection; the authenticated reference path was restored on 2026-10-22 through a local relay that allows browser GETs only. Its server-side session bootstrap uses the configured login POST; no DMS product form or state-changing request is allowed. Never print `.env` values, session cookies, credentials, tokens, or production row data. Preserve sanitized status/origin traces, exact source paths, asset hashes where available, limitations, and observed-versus-inferred distinctions. The current live source capture does not include hashes for remote PHP files. Use REA for shipped artifacts and runtime evidence when applicable, with ordinary source analysis for accessible PHP. Never invent a product workflow to fill a reference gap. Keep reference and target credentials, data, and runtime strictly isolated; use synthetic local fixtures.

## Feature acceptance gate

No AlphaLink feature is accepted until its feature row names the corresponding reference feature and route, explains the actual reference business logic and failure paths with traceable evidence, identifies the AlphaLink implementation, and records executed tests demonstrating equivalent inputs, outputs, validations, state transitions, permissions, persistence, and UI behavior. For connected operations, use isolated adapters and compare only against read-only reference evidence. Document every difference as BUG, JUSTIFIED_MODERNIZATION, UNKNOWN, or APPROVED_DEVIATION. Only explicit user approval can authorize a product deviation. Do not mark a row `PARITY_CONFIRMED` while any material difference is UNKNOWN or any relevant test is unexecuted.

The status vocabulary is `NOT_DISCOVERED`, `INVESTIGATING`, `VERIFIED`, `IMPLEMENTING`, `TESTING`, `PARITY_CONFIRMED`, `BLOCKED`. Code presence, passing local tests, visual similarity, or an existing audit summary alone does not establish parity. Update the feature matrix, parity report, and evidence register with each vertical slice.

The application-level `apps/api/AGENTS.md` also applies to changes inside that tree. Its Laravel setup requirements do not authorize reference-system changes.

## Real AlphaLink verification rule

For installation and other user-facing connected workflows, execute the relevant action in the running AlphaLink app with its saved real resources whenever a real test is needed. Synthetic fixtures and fake transports may support development, but they cannot substitute for an AlphaLink app test or establish acceptance. Record the actual app response and resulting state. If a safety gate blocks before host changes, report that boundary and do not claim an installation succeeded.
