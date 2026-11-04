# AlphaLink master specification

**Authority:** AlphaLink's current product requirements, verified behavior, and explicit user decisions govern this application. Archived engineering records remain historical evidence only; they do not define current branding, workflows, or release acceptance.

**Systems:** `apps/api` is AlphaLink. `baseheavy.shop` is AlphaLink production. Keep production credentials, databases, users, recipient data, and server access isolated from local fixtures and historical records.

**Product identity:** Use **AlphaLink** in product-facing copy, page titles, navigation, APIs, services, logs, and newly introduced technical identifiers. Use `alphalink` for technical names and `ALPHALINK_` for new environment variables. Do not rename persisted database or public identifiers until a reversible compatibility migration is proven.

**Evidence order:** Current AlphaLink behavior and tests; explicit user requirements; dated, isolated engineering records; then labeled hypotheses. Keep observations separate from inference and mark unresolved behavior as unknown.

**Current architecture:** AlphaLink is a workspace-oriented Laravel application using PostgreSQL and Redis. It provides audience lists, campaigns, tracking, suppression, provider integrations, server installation, and PMTA operations. The current campaign workflow selects one ISP and country per campaign and supports multiple lists or segments within that selection. Multi-ISP or multi-country sending requires separate campaigns.

**Release acceptance:** A feature is accepted only after its validations, state transitions, permissions, persistence, failure behavior, and UI are covered by executed tests. Connected operations require isolated adapters or an authorized AlphaLink runtime check. Do not infer delivery, queue, analytics, or infrastructure success from source presence alone. Record unresolved differences and keep safety gates intact.

**Implementation loop:** Inventory → classify → plan → implement → test → verify → update the migration ledger. Preserve existing records, relationships, permissions, and operational behavior. Avoid blind replacements; every identity change must be traceable and reversible where persisted compatibility is involved.

**Active priorities:** Maintain AlphaLink's identity and independent runtime, complete campaign analytics and audience-list state verification, and keep send/infrastructure operations behind their existing release gates. Historical comparison evidence belongs in the archive and must not be presented as current application identity.
