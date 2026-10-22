# AlphaLink project instructions

AlphaLink is a new implementation of the existing DMS reference SaaS. The reference application defines product behavior; AlphaLink is a separate local target. Never deploy AlphaLink to, or intentionally change, the reference system. Reference frontend, backend, database, server, credentials, mail infrastructure and integrations are read-only. Do not use live send, install, test-connection, or other potentially mutating reference actions to discover behavior.

Before changing a feature, read `ALPHALINK_MASTER_SPEC.md`, `ALPHALINK_PROGRESS.md`, `docs/reference/FEATURE_MATRIX.md`, `docs/implementation/PARITY_REPORT.md`, `docs/implementation/BLOCKERS.md`, the relevant `audit/` evidence, and recent Git history if Git becomes available. The workspace currently has no `.git` directory; do not pretend a checkpoint or commit exists.

Use REA for shipped artifacts and runtime evidence when applicable, with ordinary source analysis for accessible PHP. Preserve exact evidence identities, source paths, hashes, limitations, and observed versus inferred distinctions. Never invent a product workflow to fill a reference gap. Keep reference and target credentials, data, and runtime strictly isolated; use synthetic local fixtures.

## Feature acceptance gate

No AlphaLink feature is accepted until its feature row names the corresponding reference feature and route, explains the actual reference business logic and failure paths with traceable evidence, identifies the AlphaLink implementation, and records executed tests demonstrating equivalent inputs, outputs, validations, state transitions, permissions, persistence, and UI behavior. For connected operations, use isolated adapters and compare only against read-only reference evidence. Document every difference as BUG, JUSTIFIED_MODERNIZATION, UNKNOWN, or APPROVED_DEVIATION. Only explicit user approval can authorize a product deviation. Do not mark a row `PARITY_CONFIRMED` while any material difference is UNKNOWN or any relevant test is unexecuted.

The status vocabulary is `NOT_DISCOVERED`, `INVESTIGATING`, `VERIFIED`, `IMPLEMENTING`, `TESTING`, `PARITY_CONFIRMED`, `BLOCKED`. Code presence, passing local tests, visual similarity, or an existing audit summary alone does not establish parity. Update the feature matrix, parity report, and evidence register with each vertical slice.

The application-level `apps/api/AGENTS.md` also applies to changes inside that tree. Its Laravel setup requirements do not authorize reference-system changes.
