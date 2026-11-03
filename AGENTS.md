# AlphaLink project instructions

AlphaLink is an independently operated application. `baseheavy.shop` is AlphaLink production. No access to the original legacy service is permitted, including read-only access. Never contact it or use its credentials, proxy, database, servers, mail systems, assets, or APIs. Treat prior captures as static historical evidence and use synthetic fixtures for any new local tests. Do not deploy AlphaLink to or modify the original system.

Before changing a feature, read `ALPHALINK_MASTER_SPEC.md`, `ALPHALINK_PROGRESS.md`, `docs/reference/FEATURE_MATRIX.md`, `docs/implementation/PARITY_REPORT.md`, `docs/implementation/BLOCKERS.md`, relevant local audit evidence, and recent Git history. The root Git repository starts at `5797a6f` (`Start evidence-backed AlphaLink reconstruction`); it is a new checkpoint, not recovered upstream history. Inspect and preserve existing local changes before committing.

Never read, display, or reuse legacy-system credentials, sessions, proxy settings, or live production rows. Preserve sanitized status/origin traces, source paths and asset hashes already recorded in local evidence, plus limitations and observed-versus-inferred distinctions. Use REA for shipped artifacts and runtime evidence when applicable, with ordinary source analysis for accessible PHP. Never invent a product workflow to fill an evidence gap. Keep AlphaLink production credentials and data isolated from historical captures.

## Feature acceptance gate

No AlphaLink feature is accepted until its feature row names the corresponding historical workflow and route, explains the behavior and failure paths using traceable local evidence, identifies the AlphaLink implementation, and records executed tests demonstrating inputs, outputs, validations, state transitions, permissions, persistence, and UI behavior. For connected operations, use isolated adapters and synthetic fixtures. Document every difference as BUG, JUSTIFIED_MODERNIZATION, UNKNOWN, or APPROVED_DEVIATION. Only explicit user approval can authorize a product deviation. Do not mark a row `PARITY_CONFIRMED` while any material difference is UNKNOWN or any relevant test is unexecuted.

The status vocabulary is `NOT_DISCOVERED`, `INVESTIGATING`, `VERIFIED`, `IMPLEMENTING`, `TESTING`, `PARITY_CONFIRMED`, `BLOCKED`. Code presence, passing local tests, visual similarity, or an existing audit summary alone does not establish parity. Update the feature matrix, parity report, and evidence register with each vertical slice.

The application-level `apps/api/AGENTS.md` also applies to changes inside that tree. It does not authorize access to the original legacy service or bypass AlphaLink safety gates.

## Real AlphaLink verification rule

For installation and other user-facing connected workflows, execute the relevant action in the running AlphaLink app with its saved real resources whenever a real test is needed. Synthetic fixtures and fake transports may support development, but they cannot substitute for an AlphaLink app test or establish acceptance. Record the actual app response and resulting state. If a safety gate blocks before host changes, report that boundary and do not claim an installation succeeded.
