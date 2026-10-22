# AlphaLink Improvement Opportunities — No Technology Decisions

**Purpose:** Convert verified DMS findings into product and engineering questions for later review. These are improvement opportunities, not a selected architecture, framework, schema, or implementation plan.

## Preserve conceptually

- **Campaign lifecycle clarity:** separate draft, validated, scheduled, running, paused, completed and failed concepts with explicit, auditable transitions.
- **Audience intent and reproducibility:** preserve the ability to define segments and exclusions and capture the exact audience criteria for a campaign.
- **Operational control visibility:** show queue position, per-batch progress, accepted/rejected counts, health and recovery state instead of relying on a PID/progress field alone.
- **Multi-channel boundaries:** keep direct SMTP/MTA delivery, provider APIs such as Gmail, tracking, and infrastructure management as distinct paths with their own policies and observability.
- **Attribution dimensions:** campaign, recipient, offer, creative, source, event and revenue relationships are useful; preserve explainable attribution and correction history.
- **Administrative safeguards:** role/owner authorization and configuration histories are valuable concepts when consistently applied.

## Improve

### Deliverability, consent, and recipient protection

- Make consent evidence, acquisition source, collection time, purpose, and policy/version explicit where applicable; distinguish confirmed, pending, withdrawn and legally suppressed states.
- Treat unsubscribes, complaints, hard bounces and account-wide suppressions as durable enforcement constraints, not merely one campaign's transient filter.
- Track suppression provenance, freshness, scope and effective time; explain excluded-recipient counts without exposing addresses.
- Require auditable sender/domain authentication posture and keep campaign approval separate from transport operations.

### Campaign execution and state

- Use explicit state transitions and durable events for scheduling, start, per-recipient/batch outcomes, pause/resume/cancel and terminal status.
- Make retries idempotent with stable message/batch keys and persist accepted/rejected/unknown outcomes; provide recovery without duplicate sends.
- Capture structured logs and error classifications; never discard all child output or use PID existence as the only progress truth.
- Use graceful cancellation with process/job ownership checks, bounded shutdown, and a reconciler for crashes/orphaned runs.
- Version and hash message configuration and audience snapshots so delayed sends can explain exactly what was selected and what changed.

### Message construction and transport

- Treat content as a versioned message object with explicit HTML/text alternatives, charset, encoding, headers, attachments, and personalization rules.
- Validate and safely encode personalization values and header inputs; apply standards-aware MIME assembly and test against independent parsers.
- Isolate secrets and transport credentials from campaign content and logs; support clear TLS/auth policy and certificate validation.
- Make transport responses and retryability explicit; distinguish queued, accepted by MTA, delivered, bounced, deferred and unknown outcomes.
- Keep provider-specific APIs behind capability-scoped adapters; ensure OAuth scopes and token lifecycle follow least privilege.

### Tracking, analytics, and integration reliability

- Give every engagement event a stable idempotency key and separate event identity from aggregation; make late events and corrections replay-safe.
- Record event-time and ingestion-time, timezone/source confidence, privacy basis and retention policy.
- Authenticate and rate-limit public webhooks and tracking endpoints, rotate/scoped keys, verify signatures and apply abuse controls.
- Reconcile delivery, engagement, complaint, suppression and revenue counts with a documented definition and correction path.
- Make affiliate conversions reversible and auditable for refunds, duplicate reports, attribution expiry, and delayed corrections.

### Infrastructure and security

- Treat PMTA/server control as a privileged control plane with authenticated encrypted APIs, strict host-key verification, scoped service identities, approvals and complete audit records.
- Prohibit state-changing GET endpoints; use explicit authorization, CSRF protection, idempotency and confirmation for operations.
- Apply consistent authorization and tenant boundaries across every controller, job, API and export.
- Default-deny API throttling and add abuse, quota, and resource bounds appropriate to the action.
- Avoid logging provider response bodies or submitted DNS/account records without explicit redaction.
- Use least privilege for application, worker, database and infrastructure identities.

### Product and developer experience

- Provide safe preview/render validation that cannot send, mutate contacts or contact live providers.
- Make campaign form defaults and estimated audience effects transparent before scheduling.
- Provide accessible, keyboard-operable screens, clear labels, responsive tables, progress announcements and recoverable validation.
- Document route contracts, state transitions, data retention and provider behavior; keep integrations testable with deterministic sandbox fixtures.
- Use migration and event-contract review to prevent source/live schema drift; provide runbooks for pause, retry, reconciliation and incident response.

## Redesign or avoid

- Avoid hidden sends during generic create/save operations; keep creation and irreversible sending separate.
- Avoid state-changing GET routes, shell commands assembled from mutable data, unvalidated PID kills and discarded process output.
- Avoid Base64/checksum values as authentication or integrity proofs; require cryptographic integrity where a token's authenticity matters.
- Avoid file snapshots without versioning, ownership, atomic creation, expiry and reconciliation.
- Avoid treating open/click signals as definitive human engagement; bots, proxies and privacy relays require uncertainty-aware reporting.
- Avoid treating all bounces or recipient state changes as identical; preserve evidence, source, scope and reversibility.
- Avoid cloning DMS code or assuming its unverified internals are requirements. AlphaLink should independently define its contracts and safeguards after the audit is reviewed.

## Decisions intentionally deferred

No language, framework, database, queue, mail transfer product, cloud provider, deployment topology, or final architecture is selected here. These opportunities should be prioritized with product, compliance, operational, and engineering stakeholders before technology selection.
