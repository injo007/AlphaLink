# AlphaLink Phase 4A — Product and Requirements

**Status:** Proposed requirements for review. They do not select a technology stack and do not assert that uncertain DMS behavior is correct or desirable.

## 1. Product intent and principles

AlphaLink should help authorized operators manage consented audiences, compose and schedule campaigns, execute delivery through explicit channel adapters, observe outcomes, and recover safely from partial failures. It should make the audience, message version, policy decision, execution progress, and delivery evidence explainable after the fact.

Product principles:

1. Consent and durable suppression are enforced before campaign convenience.
2. Preview, validation, scheduling, approval, and sending are separate actions.
3. “Accepted by a transport” is not displayed as “delivered to an inbox.”
4. Retries preserve prior attempt evidence and do not silently duplicate uncertain sends.
5. Every user-visible count has a definition, source, and freshness timestamp.
6. Privileged infrastructure operations are isolated from campaign authoring.
7. Every module has an explicit contract and can be tested without contacting production providers.
8. AlphaLink is independently designed. DMS route names, data structures, implementation, and unknown sender behavior are not copied as requirements.

## 2. DMS feature inventory and evidence limits

This inventory separates what the audit observed from what it could not establish. “Verified” below means static or bounded evidence in the cited audit, not proof of active production use.

| Area | DMS evidence status | Established by the audit | Uncertain or unverified |
|---|---|---|---|
| Admin dashboard and reports | **PARTIAL** | Phase 1 observed dashboard/statistics page titles and metric labels. Source defines daily campaign/drop/seed aggregation and revenue-import paths. | Live rows, metric definitions as displayed, timezone, freshness, KPI accuracy, and whether dashboards reconcile with delivery feedback. |
| Identity and administration | **SOURCE VERIFIED / ENFORCEMENT UNTESTED** | Laravel web/auth middleware, sessions, CSRF, role gates, selected owner checks, user/admin routes. | Full role matrix, tenant isolation, MFA, account lifecycle, effective proxy security headers, and access behavior on every route. One observed login response lacked Secure on the session cookie and lacked HSTS/CSP; this was a single observation. |
| Lists and recipient records | **SOURCE VERIFIED / DATA UNREAD** | Eloquent recipient/list/subscriber models; recipient types; list and offer exclusions; virtual-list/mix files; event-based filters. | Live records, import/export semantics, retention, global suppression, tenant boundaries, and whether an external consent system exists. The inspected subscriber migration has no explicit consent-status field. |
| Segmentation and snapshots | **SOURCE VERIFIED** | List/type/date/device/event filters and offer/suppression filtering write local point-in-time campaign recipient files. | Snapshot freshness, file format consumption, recipient order/batching, atomicity, and whether edited campaign inputs refresh the audience. |
| Standard campaigns | **SOURCE VERIFIED / SENDER PARTIAL** | Create/update/send/schedule paths; eight accepted native mode labels; base64 JSON config file; external `send` process; PID/progress fields; preconditions and a scheduled revalidation path. | Meaning of modes/config fields, delivery behavior, MIME, actual transport, per-recipient results, retries, exit reconciliation, and effective runtime config. |
| Drops, warmups, and global tests | **SOURCE VERIFIED / ENGINE UNKNOWN** | Separate source paths use the sender for drops/warmups; seed operations and test/history paths exist. The global-test POST synchronously invokes a sender and may send a real message. Drop draft/clone/clear GET routes can mutate data. | Sender semantics and full seed lifecycle. UI “test” labels do not guarantee a harmless preview. A Phase 1 visit to the drops GET may have created a draft; this is unverified. |
| Google campaigns | **SOURCE VERIFIED / LIVE USE UNKNOWN** | Separately gated OAuth/Gmail API send/draft command; source personalizes content and has a parallel path configured for up to 100 concurrent forks. | Active accounts/tokens, provider outcomes, least-privilege scope need, partial-failure semantics, and actual concurrency. |
| Message composition and PHPMailer | **UNRESOLVED** | PHP stores header/body/assets and builds an attribution token; a separate raw socket SMTP helper is present. The owner reports PHPMailer use. | PHPMailer package/call site and relation to native sender are not found. Native MIME, headers, personalization safety, attachments, TLS/auth, and SMTP/PMTA/custom protocol remain unknown. |
| Suppression and unsubscribe | **PARTIAL** | Source checks suppression files, offer exclusions, and recipient state changes; unsubscribe/opt-out job paths exist. | Consent provenance, suppression scope/freshness, complaint ingestion, policy precedence, and effect on already materialized files. |
| Tracking and analytics | **SOURCE VERIFIED / DATA UNREAD** | API route families, redirect/event jobs, recipient-state jobs, daily aggregators, revenue attribution and PMTA counters are mapped. | Live event volume, delivery-to-token binding, bot classification accuracy, metric correction, event-time policy, and complaint/FBL ingestion. Visitor-event uniqueness and job idempotency are uneven in inspected source. |
| Bounces and delivery feedback | **SOURCE VERIFIED / RUNTIME UNTESTED** | Scheduled SFTP processor moves PMTA CSV files, parses dimensions, updates counts, changes recipient types for delivered/hard-bounce records, and archives files. | Active PMTA systems, file replay/recovery safety, full soft-bounce handling, late corrections, and complaint channels. |
| Affiliates and attribution | **SOURCE VERIFIED / PROVIDER STATE UNKNOWN** | Factories and five sponsor adapters are present; a scheduled import maps conversion reports and deduplicates on revenue ID in inspected paths. | Active sponsors, webhook validation, provider pagination, refunds/reversals, attribution windows, and reconciliation. |
| PMTA/server/domain/cloud control | **SOURCE VERIFIED / LIVE INVENTORY UNKNOWN** | Source contains PMTA actions, SFTP server operations, registrar and eight cloud-provider adapters, and DNS/server/IP management paths. | Actual servers/configuration, network ACLs, provider credentials/state, SSH host identity enforcement, and which controls are actively used. Some source paths are high risk: a PMTA command uses plain HTTP GET query parameters; a provider adapter disables certificate verification. |
| Background work and operations | **CONFIG VERIFIED / EFFECTIVE RUNTIME UNKNOWN** | Redis/Horizon defaults, scheduled commands, root `schedule:run` cadence, and selected active host service metadata were inspected. | Active queue override, worker processes, queue contents, queue lag, failures, and environment-specific settings. |
| Data model and schema | **MIGRATION SOURCE VERIFIED / LIVE DB BLOCKED** | PostgreSQL is configured; migrations/models describe campaigns, lists, recipients, events, statistics, integrations and infrastructure. | Live catalog, grants, drift, production data, transaction isolation, and row-level tenant enforcement were not inspected. |
| Utility integrations and health/log pages | **FRONTEND/SOURCE SURFACES PARTIAL** | UI labels and source routes for tools/settings/errors/health are present. | Each integration’s active configuration and behavior. An App Health page visit may have triggered work; this is unknown. |

Primary evidence: [Phase 1 audit](../audit/DMS_REFERENCE_AUDIT_PHASE_1.md), [route evidence](../audit/DMS_REFERENCE_ROUTE_EVIDENCE.md), [component map](../audit/DMS_COMPLETE_COMPONENT_MAP.md), [campaign orchestration](../audit/CAMPAIGN_ORCHESTRATION.md), [sender analysis](../audit/DMS_NATIVE_SENDER_ANALYSIS.md), [tracking](../audit/TRACKING_AND_ANALYTICS.md), [integrations](../audit/EXTERNAL_INTEGRATIONS.md), and [final reconstruction report](../audit/DMS_REVERSE_ENGINEERING_FINAL_REPORT.md).

## 3. AlphaLink users and product areas

Proposed roles are capability groups, not yet an approved business role matrix:

- **Workspace owner/admin:** manage workspace membership, policy, and integration access.
- **Campaign author:** manage audiences and campaign drafts; cannot grant themselves transport privileges.
- **Approver:** review audience impact, content, compliance checks, and schedule/send requests.
- **Deliverability operator:** manage approved sender configuration and inspect transport health.
- **Compliance/privacy operator:** inspect consent/suppression evidence and respond to privacy requests.
- **Analyst:** view defined reports with recipient identity masked by default.
- **Platform operator:** tightly scoped, time-limited support access with approval and audit.

### 3.1 Functional requirements

The identifiers below are requirement IDs for later traceability; they do not prescribe tables, services, endpoints, or code structure.

#### Identity, workspace, and administration

- **FR-IAM-01:** Authenticate each human or service identity and bind every request, job, export, and event to a workspace security context.
- **FR-IAM-02:** Enforce authorization at every data and action boundary, including background jobs and exports; never rely on hidden UI controls.
- **FR-IAM-03:** Support role-based capabilities and scoped resource access. A role may not bypass workspace ownership boundaries.
- **FR-IAM-04:** Require step-up approval for high-impact actions, such as enabling a sender, changing a suppression policy, or changing a live transport configuration.
- **FR-IAM-05:** Provide safe account recovery, session revocation, access review, and service identity rotation.
- **FR-IAM-06:** Provide a searchable administrative audit trail for access grants, policy changes, campaign approvals, sends, exports, and infrastructure operations.

#### Audience, imports, consent, and suppression

- **FR-AUD-01:** Import recipients with source, collection timestamp, stated purpose, policy/version, workspace ownership, and consent evidence where required by the approved policy.
- **FR-AUD-02:** Represent consent as explicit states with provenance; unknown or invalid consent must not be eligible for a send that requires consent.
- **FR-AUD-03:** Maintain durable suppressions for unsubscribe, complaint, hard bounce, legal restriction, and operator request, with source, scope, reason, and effective time.
- **FR-AUD-04:** Define suppression precedence and scope (recipient, sender identity, workspace, brand, campaign, and jurisdiction) in an owner-approved policy. A narrower campaign rule cannot override a broader mandatory suppression.
- **FR-AUD-05:** Support list membership and explainable segments with inclusion/exclusion rules, event filters, and reproducible criteria versions.
- **FR-AUD-06:** Preview audience size and exclusion reasons before approval; disclose data freshness and do not expose raw addresses in aggregate preview by default.
- **FR-AUD-07:** Create immutable, versioned audience snapshots bound to the campaign and policy version. Recheck consent and suppression at dispatch so a stale snapshot cannot override a later unsubscribe.
- **FR-AUD-08:** Support authorized correction, export, retention, and deletion workflows, with legal holds and audit evidence where policy requires.
- **FR-AUD-09:** Deduplicate recipient identity within an explicitly selected identity scope and preserve source records and merge history.

#### Campaign authoring, content, preview, and approval

- **FR-CMP-01:** Keep draft creation and editing separate from scheduling and sending. Save operations must never start delivery as a side effect.
- **FR-CMP-02:** Version campaign content, headers, assets, sender identity, destination, audience rules, and policy checks as one reviewable revision.
- **FR-CMP-03:** Provide a safe preview/render operation that cannot contact the live transport or mutate recipient state.
- **FR-CMP-04:** Validate address, sender identity, required content, personalization fields, URL safety, header values, unsubscribe path, and approved sending policy before a campaign can be scheduled.
- **FR-CMP-05:** Show the approver the exact campaign revision, audience estimate, suppressed/excluded counts, intended send window, per-channel limits, and unresolved warnings.
- **FR-CMP-06:** Make test sends a separate capability with an explicit allowlist, visible test labeling, and limits; preview-only and test-send modes must be distinct.
- **FR-CMP-07:** Keep campaign types such as bulk, transactional, warmup, and internal test separate in policy, reporting, and recipient eligibility. Whether all are in product scope requires approval.

#### Scheduling and execution

- **FR-EXE-01:** Require a durable campaign approval and valid send authorization before creating a run.
- **FR-EXE-02:** Make each execution a versioned run with a stable run ID, immutable input revision, policy version, channel, and idempotency key.
- **FR-EXE-03:** Enforce per-workspace, per-sender, and provider rate limits with backpressure; never infer safe throughput from DMS configuration or an unbenchmarked worker count.
- **FR-EXE-04:** Provide pause, resume, and cancel as explicit, auditable state transitions. Cancellation must stop new work and report the disposition of in-flight attempts.
- **FR-EXE-05:** Persist recipient/message attempt outcomes and transport correlation identifiers before declaring run completion.
- **FR-EXE-06:** Distinguish accepted, delivered, deferred, rejected, bounced, complained, suppressed, and unknown outcomes. A transport acknowledgment alone must not be labeled inbox delivery.
- **FR-EXE-07:** Make retries bounded, policy-controlled, and idempotent. If a prior transport result is ambiguous, record `unknown` and reconcile or ask for an approved resolution rather than silently resending.
- **FR-EXE-08:** Create a new run for an intentional retry of a terminal or failed run; preserve the earlier run and all outcomes.
- **FR-EXE-09:** Provide operator-visible progress based on durable attempt records, not only a PID, queue position, or estimated count.

#### Channel and integration management

- **FR-CHN-01:** Isolate channel-specific behavior behind documented adapters with capability and policy declarations.
- **FR-CHN-02:** For each adapter, expose verified identity, TLS/auth requirements, limits, retry classification, idempotency support, response mapping, and health state.
- **FR-CHN-03:** Fail closed on invalid certificates, unapproved endpoints, expired credentials, or missing required authentication.
- **FR-CHN-04:** Keep channel credentials separate from campaign content and recipient data; scope each secret to the minimum required action.
- **FR-CHN-05:** Treat direct MTA/SMTP, hosted email APIs, and infrastructure provisioning as separate channels/control planes. Their inclusion and order are owner decisions.
- **FR-CHN-06:** Provide deterministic sandbox adapters and fixtures so campaign and recovery flows can be tested without live provider calls.

#### Tracking, feedback, and analytics

- **FR-TRK-01:** Accept only authenticated, integrity-protected, rate-limited event requests appropriate to their source; reject malformed or replayed events without mutating recipient state.
- **FR-TRK-02:** Store event identity, event time, ingestion time, source, campaign/run/recipient references, policy/privacy basis, and attribution confidence.
- **FR-TRK-03:** Make event ingestion idempotent by stable event ID; retain correction/reversal events rather than rewriting history.
- **FR-TRK-04:** Support unsubscribe, complaint, bounce, delivery, click, open, and conversion event sources as separately classified evidence. Complaint and bounce provider selection remains a decision.
- **FR-TRK-05:** Treat opens and clicks as signals with confidence and bot/proxy classification, not incontrovertible human actions.
- **FR-TRK-06:** Define each metric, its source evidence, deduplication rule, timezone, correction window, and freshness; allow a report to be rebuilt from source events.
- **FR-TRK-07:** Provide privacy-safe dashboards and allow restricted recipient-level drill-down with an audit reason.

#### Integrations and operational control plane

- **FR-OPS-01:** Separate campaign operations from infrastructure operations, with distinct roles, credentials, approvals, network policies, and audit streams.
- **FR-OPS-02:** Make infrastructure changes explicit commands with target allowlists, preflight, dry-run/preview where feasible, approval, execution result, and rollback plan.
- **FR-OPS-03:** Authenticate provider callbacks and webhooks, verify signatures/timestamps, and make ingestion replay-safe.
- **FR-OPS-04:** Track provider cursors, pagination, request IDs, retries, and corrections so imports can resume without losing or duplicating business events.
- **FR-OPS-05:** Make health, backlog, delayed schedules, rejected sends, unresolved outcomes, and integration failures visible without exposing secrets or raw PII.
- **FR-OPS-06:** Provide documented incident, suppression, pause/cancel, reconciliation, and recovery workflows.

#### Reporting, exports, and user experience

- **FR-UX-01:** State whether displayed values are estimates, live counts, accepted outcomes, inferred outcomes, or rebuilt aggregates.
- **FR-UX-02:** Give every destructive or irreversible operation a dedicated action, clear scope/impact summary, and confirmation bound to the current revision.
- **FR-UX-03:** Make all primary workflows keyboard operable, usable with screen readers, and responsive at supported viewport sizes.
- **FR-UX-04:** Exports must be purpose-limited, permission-checked, logged, scoped, and protected from formula injection and accidental bulk exposure.
- **FR-UX-05:** Provide accessible validation errors, progress announcements, and recoverable forms that retain safe user input after a failure.

## 4. Nonfunctional requirements

All numeric goals are **ALPHALINK HYPOTHESES** until workload, budget, and risk tolerance are approved and representative benchmarks are run.

| Category | Proposed requirement | Candidate acceptance measure |
|---|---|---|
| Security | Tenant/workspace context is mandatory in every query and asynchronous message; default deny; no privilege escalation through admin role. | Automated negative-access matrix: zero cross-workspace reads or writes across UI, API, jobs, exports, and provider callbacks. All privileged operations produce an audit record. |
| Security | TLS is required for external/control-plane traffic; certificates and SSH host identities are validated; secrets are scoped and redacted. | In integration tests, invalid/expired cert, wrong host key, expired token, or unauthorized endpoint causes a failed operation and no state mutation. Secret canary strings never appear in logs, traces, or error responses. |
| Security | No state-changing behavior is exposed through an unauthenticated or unsafe method; public event endpoints have limits and abuse controls. | Route/action inventory shows zero mutating GET handlers. Rate-limit test at configured threshold returns a bounded response and does not enqueue excess work. |
| Privacy/compliance | Consent provenance and suppression are enforced at the last safe dispatch boundary; privacy export/deletion and retention policies are documented per data class. | A later unsubscribe or complaint blocks all not-yet-accepted attempts in that policy scope; audit shows the source/effective time. Retention-expired test data is removed or de-identified per approved policy. |
| Performance | Campaign authoring/read APIs respond within a candidate p95 of 500 ms under a representative 200-user mix, excluding provider calls and long-running exports. | Load test against an agreed, anonymized dataset; report p50/p95/p99, error rate, and resource use. **Hypothesis.** |
| Performance | Audience preview for up to 1 million candidate records returns within a candidate p95 of 10 seconds and provides an explicit freshness timestamp. | Benchmark with realistic selective and nonselective filters; no full recipient addresses in the default response. **Hypothesis.** |
| Throughput/scaling | Event ingestion sustains a candidate 1,000 events/s for 30 minutes with a 5,000 events/s 1-minute burst; event acknowledgment p95 ≤300 ms. | Load test shows no lost accepted events after drain, bounded backlog, and correct idempotent totals. **Hypothesis; capacity depends on selected stack.** |
| Send-rate safety | Delivery dispatch never exceeds configured tenant/sender/provider rate limits and backs off on provider signals. | A controllable fake transport records every attempt; all rolling 1-minute windows remain within policy (allowing documented clock tolerance), with no backlog-induced burst. Throughput target awaits provider and volume decisions. |
| Reliability | Control-plane availability candidate objective is 99.9% monthly, excluding explicitly agreed maintenance and external transport outages. | SLO report based on successful authorized read/write workflows, not host uptime alone. **Hypothesis.** |
| Reliability/recovery | Campaign metadata has candidate RPO ≤5 minutes and RTO ≤60 minutes; accepted send attempts are reconciled separately from metadata restore. | Restore exercise meets RPO/RTO and replays jobs without duplicate attempt records. **Hypothesis; approval required.** |
| Reliability | Retries, duplicate events, stale schedules, and worker crashes are safe and observable. | Failure-injection scenarios in §5 pass; no accepted outcome is silently dropped, counted twice, or automatically resent when acceptance is ambiguous. |
| Scalability | Work can be partitioned by workspace/campaign and scaled without shared mutable campaign state or unbounded fan-out. | At 2× worker capacity, throughput should improve by at least 1.6× until a documented external/storage bottleneck, while correctness and rate limits hold. **Hypothesis, to benchmark.** |
| Observability | Every campaign, run, message attempt, event, and integration call has correlation identifiers and a defined outcome; dashboards use stable metric definitions. | For a sampled run, an operator can trace campaign → run → attempt → transport response → feedback event with no raw address in the default trace view. Unknown outcomes appear in a dedicated backlog. |
| Observability/privacy | Logs and metrics exclude credentials, full message bodies, and recipient addresses by default; access to exceptional PII views is audited. | Automated log scan of test flows finds no seeded secret or recipient value; audit can identify who viewed/exported a recipient-level record and why. |
| Accessibility | Target WCAG 2.2 AA across primary authenticated workflows; W3C identifies WCAG 2.2 as a Recommendation. [W3C WCAG 2.2 overview](https://www.w3.org/WAI/standards-guidelines/wcag/) | Independent manual keyboard/screen-reader review plus automated checks for all critical campaign, recipient, approval, and operations workflows; zero known critical/serious blocker in release sign-off. |
| Maintainability | Module contracts are versioned and documented; state changes and policy decisions are testable independently of external providers. | Every cross-module contract has schema/compatibility tests and a deterministic fake; every state transition has positive, negative, replay, and authorization coverage. |
| Maintainability | Data migrations and event schema changes have forward/rollback or repair plans and compatibility windows. | Release review records migration impact, backfill/replay plan, and recovery procedure; no unreviewed destructive schema migration. |

## 5. Operational and failure scenarios

The checks below are release acceptance requirements. They exercise an isolated test environment and deterministic fake transports, not DMS or live providers.

| Scenario | Expected AlphaLink behavior | Measurable acceptance criterion |
|---|---|---|
| Unsubscribe arrives after audience preview but before dispatch | Durable suppression wins over an old snapshot. The event is acknowledged, later attempts are blocked, and the campaign estimate is marked stale/recomputed. | 100% of not-yet-accepted attempts are blocked after the unsubscribe effective time in the selected scope; no worker can bypass the dispatch gate. |
| Same schedule/start request is submitted repeatedly | Idempotency and state-version checks create one run. | 100 repeated requests with the same key produce exactly one run and one set of queued work. Reuse with a different payload is rejected. |
| Worker crashes before sending | Lease expires; work can resume without losing prior state. | After worker restart, every eligible item is either attempted once or remains explicitly queued; no duplicate attempt record. |
| Transport accepts a message but the response is lost | Record an ambiguous outcome and reconcile through a stable transport ID or approved manual policy; do not blindly resend. | The item becomes `submission_unknown` within the recovery window; it is absent from automatic retry until reconciled. |
| Database write fails after transport acknowledgment | Preserve transport response evidence, retry persistence idempotently, and keep run in reconciliation state. | Replayed response creates one accepted outcome and one counter increment. |
| Provider rejects a batch or returns backpressure | Pause/reduce dispatch within policy; classify retryability and preserve response metadata without PII. | No rate-limit breach after the provider response; retry only after the adapter policy permits it. |
| PMTA/bounce/event file is replayed after partial ingestion | File/object identity and event IDs prevent duplicate side effects. | Reprocessing the same input yields byte-for-byte identical aggregate totals and no duplicate recipient transition. |
| Event webhook is forged, replayed, or malformed | Reject before mutation and emit a privacy-safe security event. | Zero state changes for invalid signature, stale timestamp, or duplicate event ID; valid replay returns the original result. |
| Cross-workspace ID is submitted | Deny without revealing whether the other workspace object exists. | Every UI/API/job/export path denies it; no object details appear in response, logs accessible to the caller, or side effects. |
| TLS/SSH identity validation fails | Fail closed; require credential/endpoint remediation. | No data is sent and no infrastructure state changes after certificate or host-key failure. |
| Operator pauses/cancels a busy run | Stop new attempts, drain or classify in-flight attempts, and show honest state. | No new dispatch within 5 seconds of acknowledged pause/cancel; in-flight attempts reach a known or `submission_unknown` state within 30 seconds. **Hypothesis subject to transport constraints.** |
| Backup restore occurs while runs were active | Restore control data, reconcile attempt records with transport receipts, then resume only safe work. | Candidate RPO/RTO in §4 met; no run is marked complete while an attempt is unknown. |
| Accessibility regression in primary workflow | Release is blocked until critical paths can be completed with keyboard and screen reader. | WCAG 2.2 AA review has no unresolved critical/serious issue in campaign creation, approval, suppression, pause/cancel, or report access. |

## 6. DMS-to-AlphaLink improvement matrix

Severity/design priority indicates the potential consequence if the issue is not covered by other controls and the proposed order for AlphaLink redesign. It is not a finding that DMS is exploitable or noncompliant. The evidence is source-level and may be mitigated by controls outside the inspected code.

| DMS evidence | Severity / design priority | Likely user/operator impact | AlphaLink redesign |
|---|---|---|---|
| Campaign process output is redirected to `/dev/null`; PID/progress are stored, while terminal reconciliation is unknown. (`CAMPAIGN_ORCHESTRATION.md`, `DMS_FAILURE_RECOVERY_ANALYSIS.md`) | High | Operators may not know whether a run completed, partially sent, or failed. | Durable run/attempt ledger, structured outcomes, reconciliation queue, operator-visible unresolved state. |
| Stop path uses `kill -9` on a stored PID without visible process-identity validation. (`CAMPAIGN_ORCHESTRATION.md`, `DMS_SECURITY_BOUNDARIES.md`) | High | Abrupt termination can leave a partial batch and ambiguous send outcome. | Owned worker leases, graceful pause/cancel, bounded drain, explicit in-flight outcome and process identity checks. |
| Audience snapshots are point-in-time; the inspected update path does not rebuild the file. (`CAMPAIGN_ORCHESTRATION.md`) | High | Audience or suppression changes may not be reflected in delayed work. | Version and hash snapshots; recheck consent/suppression at dispatch; invalidate or require reapproval when inputs change. |
| Subscriber migration has no explicit consent-status field; broader consent flows are unknown. (`TRACKING_AND_ANALYTICS.md`, `DMS_ARCHITECTURAL_WEAKNESSES.md`) | High | Teams may be unable to prove why a recipient was eligible. This is an evidence gap, not a compliance conclusion. | Consent evidence and purpose are first-class; unknown consent is ineligible where required; export evidence for review. |
| Suppression semantics include offer exclusions/files, while global scope and freshness are unresolved. (`CAMPAIGN_ORCHESTRATION.md`, `DMS_TRACKING_ATTRIBUTION_FLOW.md`) | High | Unsubscribe or complaint may not be explainable across channels and delayed jobs. | Central policy engine, explicit scope and precedence, freshness display, atomic dispatch check, audit of decisions. |
| Drop init/clone/clear operations are state-changing GET routes. (`DMS_REFERENCE_ROUTE_EVIDENCE.md`, `DMS_CAMPAIGN_STATE_MACHINE.md`) | High | Link previews/crawlers or accidental page access may mutate drafts. | No mutating GETs; explicit command, CSRF, authorization, idempotency, revision-bound confirmation. |
| API default throttling is commented out; only selected endpoints showed route-specific limits. (`BACKEND_ARCHITECTURE.md`, `SECURITY_AND_RELIABILITY.md`) | High | Abuse can overload event handlers or cause excessive queued work. | Default-deny quotas, endpoint-specific limits, per-workspace budgets and queue backpressure. |
| Campaign attribution token uses Base64 and Adler-32 rather than a cryptographic MAC in inspected source. (`DMS_TRACKING_ATTRIBUTION_FLOW.md`, `DMS_SECURITY_BOUNDARIES.md`) | High | Integrity/authenticity assumptions may be too weak if the token authorizes attribution. The sender-side check is unknown. | Signed, expiring, purpose-bound opaque identifiers; key rotation; replay handling; avoid embedding raw PII. |
| Visitor event uniqueness is uneven; PMTA ingestion moves files and increments counters with partial-retry semantics unknown. (`TRACKING_AND_ANALYTICS.md`, `DMS_FAILURE_RECOVERY_ANALYSIS.md`) | High | Duplicate counts or recipient transitions may occur on replay or recovery. | Stable event/file identity, idempotent state handlers, append-only source ledger, rebuildable aggregates. |
| PMTA command path uses plain HTTP GET query parameters; ACLs/authentication outside the call site are unknown. (`MAIL_INFRASTRUCTURE.md`, `DMS_SECURITY_BOUNDARIES.md`) | High | Privileged command exposure could lead to unauthorized operational actions if network controls fail. | Separate authenticated encrypted control plane, allowlisted targets, scoped identity, approvals, complete audit, no command in URL. |
| Server SFTP factory lacks explicit host-key pinning at the inspected call site. (`EXTERNAL_INTEGRATIONS.md`, `DMS_SECURITY_BOUNDARIES.md`) | High | Risk of connecting to an unintended server if external controls are insufficient. | Verify server identity against managed trust; fail closed on key change; rotate trust through reviewed workflow. |
| NewHitPath code disables TLS certificate verification. (`EXTERNAL_INTEGRATIONS.md`) | High | Provider responses may be exposed to interception in those code paths. | Require normal certificate validation; no permissive fallback; test TLS failures in adapters. |
| Google integration config includes broad mailbox/settings scopes; need is unverified. (`EXTERNAL_INTEGRATIONS.md`, `DMS_MESSAGE_COMPOSITION.md`) | Medium–High | Greater impact if credentials are misused or leaked. | Capability-specific scopes, isolated account binding, explicit token lifecycle and revocation controls. |
| Some provider failures log response bodies or submitted data. (`EXTERNAL_INTEGRATIONS.md`, `SECURITY_AND_RELIABILITY.md`) | Medium–High | Personal or credential-like data could enter operational logs; actual log exposure was not inspected. | Structured allowlisted error fields, redaction tests, short retention, audited privileged access. |
| Active queue backend, worker limits, live schema, and PMTA topology were not verified. (`MAIL_INFRASTRUCTURE.md`, `DATABASE_ARCHITECTURE.md`) | Medium | Capacity and support procedures may be built on incorrect assumptions. | Treat config as intended state; expose runtime configuration fingerprint and health; capacity-test before commitments. |
| UI data tables and accessibility were not audited; only page shells were observed. (`DMS_REFERENCE_AUDIT_PHASE_1.md`) | Medium | Operators may miss impact, errors, or accessible navigation needs. | User research, keyboard/screen reader testing, accessible progress/error feedback, clear metric definitions. |
| PHPMailer confirmation is not reconciled with searched package/call-site evidence; native protocol is unknown. (`PHPMAILER_SENDING_ENGINE.md`, `DMS_NATIVE_SENDER_ANALYSIS.md`) | Medium | Transport assumptions may mislead support, security review, and migration. | Define the AlphaLink channel contract independently; do not infer implementation from DMS labels or helpers. |

## 7. Source traceability

| Requirement area | Primary evidence |
|---|---|
| Campaign requirements and execution | [CAMPAIGN_ORCHESTRATION.md](../audit/CAMPAIGN_ORCHESTRATION.md), [DMS_CAMPAIGN_STATE_MACHINE.md](../audit/DMS_CAMPAIGN_STATE_MACHINE.md), [DMS_SENDING_DATAFLOW.md](../audit/DMS_SENDING_DATAFLOW.md) |
| Audience, consent and suppression | [DATABASE_ARCHITECTURE.md](../audit/DATABASE_ARCHITECTURE.md), [TRACKING_AND_ANALYTICS.md](../audit/TRACKING_AND_ANALYTICS.md), [DMS_TRACKING_ATTRIBUTION_FLOW.md](../audit/DMS_TRACKING_ATTRIBUTION_FLOW.md) |
| Message/channel uncertainty | [DMS_MESSAGE_COMPOSITION.md](../audit/DMS_MESSAGE_COMPOSITION.md), [PHPMAILER_SENDING_ENGINE.md](../audit/PHPMAILER_SENDING_ENGINE.md), [DMS_NATIVE_SENDER_ANALYSIS.md](../audit/DMS_NATIVE_SENDER_ANALYSIS.md) |
| Security, integration and operations | [DMS_SECURITY_BOUNDARIES.md](../audit/DMS_SECURITY_BOUNDARIES.md), [SECURITY_AND_RELIABILITY.md](../audit/SECURITY_AND_RELIABILITY.md), [EXTERNAL_INTEGRATIONS.md](../audit/EXTERNAL_INTEGRATIONS.md), [MAIL_INFRASTRUCTURE.md](../audit/MAIL_INFRASTRUCTURE.md) |
| Product redesign concepts | [ALPHALINK_IMPROVEMENT_OPPORTUNITIES.md](../audit/ALPHALINK_IMPROVEMENT_OPPORTUNITIES.md), [DMS_ARCHITECTURAL_WEAKNESSES.md](../audit/DMS_ARCHITECTURAL_WEAKNESSES.md) |

## 8. Decisions still open

See the [Phase 4A decisions register](PHASE_4A_DECISIONS.md). In particular, market/jurisdiction, consent policy, tenant model, launch channels, volume targets, infrastructure-control scope, and service objectives must be settled before implementing sends, activating providers, or deploying to production.
