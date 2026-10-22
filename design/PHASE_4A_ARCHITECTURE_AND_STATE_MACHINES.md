# AlphaLink Phase 4A — Architecture and State Machines

**Status:** Logical architecture proposal and implementation reference. The initial API foundation now uses Laravel 13/PHP 8.3, PostgreSQL, Redis, and Sanctum provisionally. This is not a deployment design; mail product, cloud, and service topology remain undecided.

## 1. Design constraints

- Keep reference facts and proposed AlphaLink behavior visibly separate.
- Treat every sending, recipient, and provider operation as an auditable state transition.
- Make consent and suppression policy part of dispatch authorization, not just audience filtering.
- Separate campaign business state, individual message attempts, transport evidence, engagement events, and aggregate reports.
- Use durable, versioned interfaces even if multiple logical modules are initially deployed together.
- Preserve an explicit `unknown` outcome when a transport result cannot be established. Never turn uncertainty into an automatic resend decision.
- Use deterministic fake adapters and isolated data for integration and recovery testing.

## 2. DMS architecture reconstruction — supported evidence only

The following describes the audited DMS source/configuration and bounded artifact metadata. It is not a claim about live workload or effective runtime configuration.

~~~mermaid
flowchart LR
  Browser[Authenticated admin browser] -->|Observed web/session route group| Nginx[Nginx + PHP-FPM]
  Nginx --> Laravel[Laravel 10.38.2 / PHP 8.1.27]
  API[Public API handlers] --> Laravel
  Laravel --> Models[Eloquent actions and models]
  Models --> PG[(PostgreSQL configured; live catalog not queried)]
  Laravel --> Redis[(Redis/Horizon configured; active override unknown)]
  Cron[Root schedule:run each minute] --> Kernel[Laravel scheduler]
  Kernel --> Jobs[Queued jobs and scheduled commands]
  Jobs --> PG
  Laravel --> Files[Audience, suppression and send files]
  Files --> Sender[send / send1 .NET 7 apphosts]
  Sender --> Managed[Bundled obfuscated managed CIL]
  Managed --> DbCalls[Npgsql / Dapper references]
  Managed --> Sftp[SSH.NET SFTP references]
  Managed --> Tcp[TcpClient and text-stream calls]
  Tcp -. protocol / mail relationship unknown .-> MTA[SMTP / PMTA / custom TCP unknown]
  Laravel --> Gmail[Separate OAuth/Gmail API campaign path]
  MTA -->|delivery and bounce files via SFTP| Ingest[Scheduled PMTA file processor]
  Ingest --> PG
  API --> EventJobs[Tracking and recipient-state jobs]
  EventJobs --> PG
  Laravel --> Providers[Affiliate, registrar, cloud and utility adapters]
  Kernel -->|scheduled conversion import| Providers
  Providers --> PG
  Laravel --> Infra[PMTA/server/domain control paths]
~~~

**Confidence:** the Nginx/PHP-FPM/Laravel source boundary, configured PostgreSQL/Redis paths, scheduler, file-backed campaign handoff, separate Gmail path, event jobs, PMTA file processor, and provider adapters are **OBSERVED / DERIVED** in source/config. The active queue backend and worker settings are **INFERRED / UNKNOWN**. Managed metadata verifies the .NET 7 apphost and static references to Npgsql/Dapper, SFTP, parallel helpers, and direct TCP text I/O. The transport protocol, message construction, item meaning, send outcome, and recovery logic remain **UNKNOWN**. The architecture audit did not establish microservice or tenant-isolation boundaries.

Sources: [evidence-bounded architecture](../audit/REFERENCE_SYSTEM_ARCHITECTURE.md), [component map](../audit/DMS_COMPLETE_COMPONENT_MAP.md), [backend architecture](../audit/BACKEND_ARCHITECTURE.md), [campaign orchestration](../audit/CAMPAIGN_ORCHESTRATION.md), [tracking and analytics](../audit/TRACKING_AND_ANALYTICS.md), and [Phase 3H sender analysis](../audit/DMS_NATIVE_SENDER_ANALYSIS.md).

## 3. Proposed AlphaLink logical architecture

These are logical ownership boundaries. They may initially share a deployment; physical separation is a later decision based on load, trust boundaries, team ownership, and operating cost.

| Logical module | Owns | Does not own |
|---|---|---|
| Identity and access policy | Human/service identities, workspace membership, roles, session policy, authorization decisions. | Recipient consent or sending outcomes. |
| Audience and consent | Recipient identity, list membership, source records, consent evidence, privacy requests, imports, segment definitions. | Campaign approval or transport connection. |
| Suppression policy | Durable suppression evidence, scope/precedence, eligibility decisions, policy version. | Campaign content or delivery result. |
| Campaign management | Draft, content revision, channel intent, schedule, approvals, audience reference and run request. | Direct transport I/O or aggregate truth. |
| Content rendering and validation | Safe previews, personalization validation, immutable rendered-message revision, sender/header checks. | Consent decisions or provider credentials. |
| Execution coordinator | Run lifecycle, scheduling, work partitioning, rate/quota enforcement, pause/cancel, run reconciliation. | Recipient source-of-truth or channel-specific protocol. |
| Delivery workers and channel adapters | One delivery attempt, transport-specific submission, response mapping, request correlation, credential use. | Campaign editing, user roles, analytics definitions. |
| Outcome and feedback ingestion | Delivery receipts, bounce/complaint/unsubscribe/provider feedback ingestion, replay protection and source evidence. | Campaign approval or message authoring. |
| Event ledger and analytics | Append-only engagement/conversion events, metric definitions, rebuildable projections, freshness. | Transport submission or mutable consent authority. |
| Integration adapters | Affiliate/provider data mapping, cursors, provider auth, sandbox fixtures, retry classification. | Cross-tenant policy or privileged infrastructure decisions. |
| Infrastructure control plane | Approved sender/domain/server/provider configuration changes, preflight, scoped credentials and execution audit. | Routine campaign authoring or audience imports. |
| Operations and audit | Health/SLO views, structured audit records, unresolved-outcome queue, runbooks, alerts and retention controls. | Unreviewed mutation of business state. |

### 3.1 Communication model and contracts

Use synchronous request/response only for bounded reads and validations. Long-running actions—imports, schedules, sending, feedback ingestion, rebuilds, and infrastructure operations—must create durable commands and return a correlation/run identifier. Modules communicate through versioned contracts; no module writes another module’s private data directly.

Every command/event envelope should carry:

| Field | Purpose |
|---|---|
| `contract_version` | Backward-compatible schema evolution and validation. |
| `message_id` | Stable identity for deduplication. |
| `workspace_id` | Mandatory authorization and data-ownership scope. |
| `aggregate_type`, `aggregate_id`, `aggregate_version` | Entity identity and optimistic concurrency. |
| `correlation_id`, `causation_id` | End-to-end trace and causal relationship. |
| `occurred_at`, `received_at` | Separate source event time from ingestion time. |
| `actor_id`, `actor_type`, `authorization_decision_id` | Who or what initiated the transition and which policy allowed it. |
| `idempotency_key` | Deduplicate a retried command or submission where supported. |
| `data_classification` | Apply logging, retention and access rules. |

Do not include an email address, full message body, password, token, or provider secret in a general event envelope. The delivery adapter may receive the minimum plaintext needed for submission through a protected interface; its logs and events use a recipient reference and redacted response data.

| Contract | Required input | Result/event and failure contract |
|---|---|---|
| `AudiencePreviewRequested` | Workspace, segment revision, campaign revision, channel intent, policy version, freshness requirement, requester, idempotency key. | Returns estimate, eligible/excluded counts by reason, snapshot reference/digest/expiry, warnings, and freshness. A stale or incomplete preview is marked as such. |
| `CampaignValidationRequested` | Campaign revision, audience snapshot, sender identity, channel, requested schedule and policy version. | Returns stable validation findings and blocking/warning severity. Does not send or mutate recipients. |
| `CampaignApprovalRecorded` | Exact revision digest, approver, decision, reason, timestamp and policy context. | Appends an immutable approval/rejection record. Approval for an older revision cannot authorize a changed revision. |
| `CampaignRunRequested` | Approved revision digest, snapshot/policy versions, scheduled time or immediate intent, rate constraints, actor and idempotency key. | Returns one run ID and initial state. Repeated identical command is idempotent; same key with changed payload is rejected. |
| `DeliveryAttemptRequested` | Run ID, recipient reference, rendered-message revision, channel, attempt number, stable message key, eligibility proof and deadline. | Adapter returns a correlated attempt record and one classified result: accepted, rejected, deferred, permanently failed, or unknown. |
| `SuppressionChanged` | Recipient reference, workspace/scope, reason, source evidence, effective time, actor/source and stable event ID. | Updates the durable suppression projection and emits a policy-version change; queued but not accepted work must re-evaluate eligibility. |
| `DeliveryFeedbackReceived` | Source/provider, stable source event ID, provider correlation, recipient reference, event type, event time and normalized evidence. | Deduplicated append-only feedback event; bad signature/schema is rejected before state mutation. |
| `EngagementEventReceived` | Event ID, event type, campaign/run/recipient references, event/ingestion times, source, integrity evidence and privacy basis. | Append-only accepted/rejected result; duplicate returns prior result. Aggregate projections are rebuildable. |
| `IntegrationIngestionRequested` | Provider connection reference, cursor/page, data scope, policy, idempotency key. | Returns durable import run and cursor; partial failures are resumable without duplicating conversions or records. |
| `InfrastructureChangeRequested` | Target allowlist reference, desired change, preflight evidence, approvals, rollback procedure and idempotency key. | Returns change run; no execution until authorization/preflight succeeds. Result includes before/after identifiers and rollback status, not secrets. |

### 3.2 Cross-module invariants

1. Commands are authorized both when accepted and when executed; queued work can be revoked by a changed policy.
2. Every transition checks the expected aggregate version and returns a conflict rather than silently overwriting newer state.
3. At-least-once delivery of an internal command is assumed; idempotency must be enforced by the receiving module.
4. A module commits its source-of-truth change and outgoing event atomically or through an equivalent durable handoff.
5. Event time and ingestion time are both retained; reporting never rewrites source events to “fix” totals.
6. Retryability is a channel-adapter result plus policy decision, not a generic exception rule.
7. `unknown` is a first-class state; dashboard totals disclose it separately.
8. Retention, deletion, and legal hold policies apply consistently to primary records, snapshots, backups, logs, and derived data.

## 4. Proposed lifecycle specifications

State names below are proposed AlphaLink domain concepts, not DMS enum values.

### 4.1 Campaign lifecycle

An editable **Campaign** and an immutable **CampaignRun** are separate aggregates. Editing a campaign never mutates a started run.

~~~mermaid
stateDiagram-v2
  [*] --> Draft
  Draft --> Validating: validate revision
  Validating --> NeedsChanges: blocking findings
  NeedsChanges --> Draft: edit new revision
  Validating --> AwaitingApproval: validation passes
  AwaitingApproval --> Rejected: rejected
  Rejected --> Draft: revise
  AwaitingApproval --> Approved: approved exact digest
  Approved --> Scheduled: schedule
  Approved --> Starting: start now
  Scheduled --> Starting: due and revalidated
  Scheduled --> Cancelled: cancel before start
  Starting --> Running: work accepted
  Starting --> Failed: startup failure
  Running --> PauseRequested: pause
  PauseRequested --> Paused: no new work; in-flight classified
  Paused --> Starting: resume with valid approval/policy
  Running --> CancelRequested: cancel
  CancelRequested --> Reconciling: stop new work; drain/classify attempts
  Running --> Reconciling: all work dispatched
  Reconciling --> Completed: all outcomes resolved successfully
  Reconciling --> CompletedWithErrors: mixed resolved outcomes
  Reconciling --> Failed: no safe continuation
  Reconciling --> Cancelled: cancellation reconciled
  Failed --> [*]
  Completed --> [*]
  CompletedWithErrors --> [*]
  Cancelled --> [*]
~~~

Rules:

- Any change to audience criteria, content, channel, sender identity, or send policy creates a new campaign revision and invalidates approval for the prior digest.
- At scheduled start, verify approval, schedule ownership/version, sender health, rate limits, audience snapshot validity, and current consent/suppression policy. If any fail, do not start; return to review or mark a blocking failure with a clear reason.
- A new attempt to send after `Failed`, `CompletedWithErrors`, or `Cancelled` creates a new run linked to the prior run. It does not reset or erase prior attempt evidence.
- `Completed` means all planned work has a terminal classified outcome under the chosen policy. It does not mean all messages reached an inbox.
- The UI shows `submission_unknown` separately from rejected, deferred, or accepted results.

### 4.2 Consent, suppression, and recipient eligibility

Consent evidence is append-only; a current eligibility projection is computed from consent, purpose, channel, scope, source, time, and current policy. Suppression is an overriding block when applicable.

~~~mermaid
stateDiagram-v2
  [*] --> ConsentUnknown
  ConsentUnknown --> ConsentPending: evidence submitted
  ConsentPending --> ConsentActive: validation/policy satisfied
  ConsentPending --> ConsentInvalid: evidence rejected or expired
  ConsentActive --> ConsentWithdrawn: unsubscribe or withdrawal
  ConsentActive --> ConsentExpired: purpose/time limit reached
  ConsentInvalid --> ConsentPending: corrected evidence
  ConsentExpired --> ConsentPending: new evidence
  ConsentWithdrawn --> ConsentPending: new affirmative consent evidence
~~~

Eligibility for a specific campaign is a decision, not a mutable label: `eligible` only if current consent is valid for the purpose/channel/region and no applicable suppression or policy block exists. Record the decision, inputs, policy version, and effective time. Suppression removal requires source evidence and elevated authorization; an old segment snapshot cannot restore eligibility.

**DECISION REQUIRED:** determine whether suppression is workspace-specific, sender-identity-specific, brand-specific, organization-wide, or a combination, and define where withdrawal is legally required to propagate. The model must support the selected scope without silently broadening or narrowing it.

### 4.3 Message-attempt and delivery-feedback lifecycle

Delivery state is scoped to a recipient-message-run attempt and is separate from consent state and engagement events.

~~~mermaid
stateDiagram-v2
  [*] --> Eligible
  Eligible --> Suppressed: final policy check blocks
  Eligible --> Queued: dispatch authorized
  Queued --> Submitting: worker lease acquired
  Submitting --> AcceptedByTransport: positive transport acknowledgment
  Submitting --> Deferred: transient response classified
  Submitting --> Rejected: permanent response classified
  Submitting --> SubmissionUnknown: acknowledgment ambiguous
  Submitting --> FailedBeforeSubmission: proven no submission occurred
  Deferred --> Queued: policy permits retry after delay
  SubmissionUnknown --> AcceptedByTransport: reconciliation confirms acceptance
  SubmissionUnknown --> Queued: reconciliation proves no acceptance and retry approved
  AcceptedByTransport --> DeliveredConfirmed: authoritative delivery receipt
  AcceptedByTransport --> Bounced: authoritative bounce feedback
  AcceptedByTransport --> Expired: feedback window closed with no confirmation
  Rejected --> [*]
  Suppressed --> [*]
  FailedBeforeSubmission --> [*]
  DeliveredConfirmed --> [*]
  Bounced --> [*]
  Expired --> [*]
~~~

Complaint and unsubscribe feedback are independent durable events and suppression triggers, not merely delivery statuses. A provider acknowledgment maps to `AcceptedByTransport`; only a channel-specific authoritative receipt can map to `DeliveredConfirmed`. No transport behavior is inferred from the DMS sender analysis.

### 4.4 Tracking and analytics lifecycle

Tracking is append-only: `received → authenticated/validated → deduplicated → accepted` or `rejected`. Accepted events are immutable; corrections are new events that reference the corrected event and reason. Aggregate views are derived and rebuildable. Event time and ingestion time remain separate. Open/click signals carry source and confidence classification; privacy relays, bots, and prefetchers are not represented as certain human behavior.

### 4.5 Recovery lifecycle

~~~mermaid
stateDiagram-v2
  [*] --> Pending
  Pending --> Leased: worker claims with fencing token
  Leased --> Attempting: preflight and eligibility pass
  Attempting --> Checkpointed: result durably recorded
  Attempting --> Unknown: outcome cannot be established
  Leased --> Reclaimable: lease expires before attempt
  Reclaimable --> Pending: safe requeue
  Checkpointed --> Complete: terminal result
  Checkpointed --> RetryWait: classified retryable result
  RetryWait --> Pending: policy window opens
  Unknown --> Reconciling: provider lookup / receipt matching
  Reconciling --> Complete: acceptance or failure resolved
  Reconciling --> RetryWait: proven unaccepted and retry allowed
  Reconciling --> ManualReview: evidence insufficient
~~~

Every worker lease has an owner, expiry, and fencing generation so an expired worker cannot commit late results after a replacement worker takes over. Retry budget, backoff, deadline, per-channel constraints, and policy version are stored with the run. Operators can retry only a proven-safe unit or explicitly accept duplicate risk through an approved decision; the default is no automatic retry from `Unknown`.

## 5. Data ownership, access, and auditability

### 5.1 Ownership and classification

Proposed ownership is workspace-scoped. Each campaign, audience, recipient reference, consent record, suppression, message revision, integration connection, run, event, export, and audit record has an explicit workspace owner or a reviewed platform-owned classification. Every contract carries that scope. Shared platform configuration is read-only to tenant operators unless delegated.

| Data class | Examples | Default access rule |
|---|---|---|
| Public/non-sensitive | Product help, non-tenant health state | Unauthenticated or authenticated according to product policy. |
| Tenant operational metadata | Campaign names, counts, run state, schedule, provider health | Workspace members with module capability; analyst/report roles may be read-only. |
| Personal data | Email address, IP, consent evidence, engagement identifiers | Purpose-limited access; masked in list/report views; direct lookup/export separately authorized and audited. |
| Message content | Subject, body, template, personalization values | Campaign author/approver and narrowly scoped rendering/delivery process; exclude from general logs. |
| Secrets | OAuth refresh token, SMTP/API credentials, signing keys | Secret-management interface only; never returned in general APIs or logs; least-privilege service identity. |
| Security/audit evidence | Access grants, approval decisions, provider request IDs, policy decisions | Append-only; only authorized audit/security roles; retention governed by approved policy. |

Whether AlphaLink acts as a controller, processor, service provider, or another legal role is **DECISION REQUIRED**. Contracts, privacy notices, data residency, legal holds, export/deletion, and retention periods depend on that choice and target jurisdictions.

### 5.2 Authorization model

- Enforce workspace and resource scope in every command/query, job, export, webhook, callback, search, and support interface.
- Use capability checks (`campaign.approve`, `campaign.send`, `audience.export`, `suppression.override`, `integration.manage`, `infrastructure.execute`) rather than one broad administrator bypass.
- Separate author, approver, sender/operator, compliance reviewer, analyst, integration administrator, and platform support duties. Require two-person approval for the highest-impact operations after policy is approved.
- Re-authorize asynchronous work at execution time. Revoked users, changed policy, deleted integrations, or newly blocked recipients invalidate queued work.
- For support access, require time-limited grant, reason/ticket, target workspace, least privilege, visible banner, and automatic expiry. Cross-workspace access is disabled by default.
- Return the same denial shape for missing and unauthorized resource IDs where revealing existence would expose another tenant.

### 5.3 Audit record requirements

Record immutable entries for authentication/security events, access changes, audience imports/exports, consent/suppression decisions, campaign revisions/approvals, schedule/start/pause/cancel/retry actions, provider credential changes, transport outcomes, integration imports, report definitions, and privileged data views.

Each entry records actor/service identity, workspace, action, target reference, time, reason, prior/new version or digest, authorization decision, correlation ID, outcome, and source evidence. Exclude secret values, raw bodies, and raw recipient addresses. Audit records are append-only, access controlled, retained according to an approved schedule, and exportable for investigation with integrity evidence.

### 5.4 Privacy and compliant sending controls

Before a campaign can be approved, AlphaLink must know the intended sender identity, purpose, channel, audience scope, policy/jurisdiction profile, consent requirements, unsubscribe mechanism, suppression sources, and retention settings. When any required field is unknown, sending is blocked or routed for compliance review; it is not inferred from DMS behavior.

Recipient handling requirements:

1. Store consent evidence with source, time, collection context, stated purpose, applicable policy version, and withdrawal history.
2. Provide a visible and usable unsubscribe mechanism appropriate to the selected channel and policy; apply it promptly to all not-yet-accepted attempts in its defined scope.
3. Maintain complaint and hard-bounce suppression, with source evidence and correction path.
4. Keep suppression records after deletion of other recipient attributes where policy requires; separate the minimum necessary suppression identifier from the rest of the profile.
5. Support data minimization, retention expiry, access/export/deletion requests, and evidence-preserving legal holds.
6. Validate sender identity and domain authentication posture through an approved process before use; do not claim a domain is authenticated merely because settings exist.
7. Keep every channel-specific legal/compliance rule versioned and reviewable. Applicable jurisdictions and rules require owner and qualified counsel approval.
