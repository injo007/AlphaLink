# AlphaLink master specification

**Authority:** The original DMS application's verified behavior controls AlphaLink product semantics. This supersedes the independent product redesign proposed in `design/PHASE_4A_PRODUCT_REQUIREMENTS.md` and `audit/ALPHALINK_ADVANCED_REFERENCE_SYSTEM_2026-10-22.md` wherever those proposals diverge from reference behavior. Security and engineering improvements may preserve legitimate outcomes; product changes require explicit approval.

**Systems:** DMS is the remote read-only reference. `apps/api` is the local AlphaLink target. No production DMS data, credentials, or sending operations are imported into or executed by AlphaLink development.

**Evidence order:** Verified reference backend logic; schema and read-only records; frontend runtime; API/network behavior; consistent project documents; labeled hypotheses. A conflict requires investigation, not silent selection.

**Acceptance:** Every feature must pass the gate in `AGENTS.md`. The canonical tracking files are `docs/reference/FEATURE_MATRIX.md`, `docs/reference/EVIDENCE_REGISTER.md`, `docs/implementation/PARITY_REPORT.md`, and `docs/implementation/BLOCKERS.md`. `audit/` contains the earlier detailed reference investigations and remains evidence, with each report's stated limits.

**Current product boundary:** Source inspection establishes DMS modules for authentication/administration, ISP and list recipients, campaigns/drops/warmups, native and Gmail sending paths, tracking/statistics, affiliates, and server/domain/PMTA operations. It does not establish every live configuration or runtime outcome. The current AlphaLink code implements a separate workspace-oriented foundation and a blocked installation preview. These are not accepted as DMS-equivalent features.

**Implementation loop:** Observe reference read-only → trace frontend/API/backend/data and failure paths → specify rule with evidence → implement one complete local slice → execute deterministic local tests → compare behavior with the reference evidence and safe browser observations → correct differences → update the matrix. Never exercise mutation-based comparison against DMS.
