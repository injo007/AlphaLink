# AlphaLink Phase 4A — Decisions Register

**Status:** The owner authorized a bounded implementation kickoff on 2026-10-22. The decisions below remain open and are not assumed approved.

These decisions bound product scope and policy. The initial API foundation uses Laravel/PHP, PostgreSQL, Redis, and Sanctum provisionally. The decisions below still gate sending, external integrations, infrastructure operations, and production rollout.

## Approval decisions

| ID | Decision | Why it matters / audit evidence | Decision needed |
|---|---|---|---|
| D-01 | **Target markets and sending purposes** | Applicable sending and privacy obligations depend on recipient location, sender location, purpose, channel, and audience type. DMS audit found no explicit consent status in the inspected subscriber migration and did not verify legal compliance. | Which jurisdictions, audience types, and message purposes must the first release support? Which policy owner/counsel approves the rule set? |
| D-02 | **AlphaLink’s legal/data role** | Workspace ownership, contracts, deletion, data residency, subprocessors, and incident duties depend on whether AlphaLink is a controller, processor, service provider, or another role. This was not established for DMS. | What is AlphaLink’s intended legal role, and who is accountable for customer data and compliance decisions? |
| D-03 | **Workspace and tenancy hierarchy** | DMS stores user/owner identifiers but audit did not establish tenant isolation. Phase 4A proposes workspace scope everywhere. | Is one workspace equal to one customer, brand, business unit, or agency client? Are parent/child workspaces, delegated agency access, or cross-workspace reporting required? |
| D-04 | **Consent evidence and eligibility policy** | DMS source has recipient state and suppression paths, but consent evidence and purpose model remain unknown. | Which acquisition evidence is required? Is double opt-in required for any audience? What makes consent expire? What is the policy for imported legacy records and unknown consent? |
| D-05 | **Suppression scope and precedence** | DMS has offer exclusions and suppression files; global scope, freshness, and propagation are unresolved. | Should unsubscribe/complaint apply at recipient, list, sender identity, brand, workspace, or organization level? Which source wins when scopes conflict? How is a correction or re-consent authorized? |
| D-06 | **First-release product scope** | DMS exposes campaign, drops, warmups, tests, Gmail, tracking, affiliates, infrastructure and utility pages, but UI exposure is not proof of active use or user need. | Which modules are required in the first release? Which are later, explicitly excluded, or legacy migration-only? Is billing or customer self-service in scope? |
| D-07 | **Delivery channels** | DMS’s managed sender protocol and PHPMailer relationship are unresolved; Gmail API sending is a separate verified source path. | Which channel(s) must AlphaLink support first: customer-managed MTA, direct SMTP, managed provider API, Google/Gmail, or another channel? Who owns transport credentials and deliverability? |
| D-08 | **Infrastructure control plane** | DMS source exposes PMTA, SFTP, server, DNS, registrar and cloud operations, with high-risk control paths. No live inventory or operational state was read. | Should AlphaLink only integrate with existing mail infrastructure, or also manage server/domain/cloud configuration? If management is in scope, which targets and approval levels are allowed? |
| D-09 | **Campaign types and state policy** | DMS has standard, Gmail, drop, warmup, global-test, and scheduling paths; their exact overlap and semantics are partial. | Which campaign categories are supported, and which may share audience or consent policy? Are warmups and real test sends permitted? Should test messages require an allowlist and separate role? |
| D-10 | **Approval and separation of duties** | DMS has owner/role gates but there is no verified campaign approval workflow. | Can an author approve/send their own campaign? Which thresholds require a second approver (recipient count, new sender/domain, content category, audience import, schedule)? |
| D-11 | **Volume, concurrency, and pacing envelope** | DMS worker limits are configured but effective runtime and native sender capacity are unknown; Gmail source has a 100-fork setting, not a measured throughput result. | Expected tenants, recipients per campaign, concurrent campaigns, event volume, provider rate limits, and peak/burst periods? Which traffic growth horizon should be designed for? |
| D-12 | **Availability, recovery objectives, and budget** | DMS active worker/runtime state and failure recovery are unverified. Phase 4A lists candidate objectives only. | Approve or replace the proposed 99.9% monthly control-plane objective, 5-minute RPO, and 60-minute RTO. Which outages count, and what staffing/cloud cost is acceptable? |
| D-13 | **Metric definitions and privacy posture** | DMS aggregates opens/clicks, PMTA feedback, and revenue, but live definitions, correction windows, timezone, and bot classification are unverified. | Which metrics matter? Should opens be disabled or privacy-reduced? What constitutes delivered, conversion, attribution, and a reportable human engagement? |
| D-14 | **Feedback and complaint sources** | DMS PMTA file ingestion is source-mapped; no dedicated complaint/FBL path was found in a bounded search. | Which MTA/provider sources must report delivery, bounce, deferral, complaint, and unsubscribe? Who monitors missing or late feedback? |
| D-15 | **Retention, deletion, and data residency** | The audits did not establish DMS retention periods, live region, backup retention, or deletion behavior. | Retention for recipients, consent evidence, message content, events, audits, exports, logs and backups? Which data regions and legal-hold rules are required? |
| D-16 | **Identity provider and privileged access** | DMS MFA, account recovery, complete role enforcement, and tenant boundaries were not tested. | Which sign-in methods, SSO providers, MFA assurance, service credentials, and support access model are required? |
| D-17 | **Integrations and portability** | DMS source maps five affiliate adapters, registrar/cloud providers, Google, and utility integrations; none were contacted and active use is unknown. | Which integrations are needed at launch? Are data import/export formats, provider migration, and customer-managed credentials required? |
| D-18 | **Accessibility and language support** | Phase 1 observed page shells but did not test accessibility or localization. Phase 4A proposes WCAG 2.2 AA. | Confirm WCAG 2.2 AA as the release target. Which languages, locales, timezones, number formats, and right-to-left workflows are required? |
| D-19 | **Operator model and incident response** | DMS process completion, logs, live queue state, and recovery operations are partly unknown. | Who operates AlphaLink, what support hours/on-call response are expected, and who can pause all sending or revoke a sender credential? |
| D-20 | **Data migration and coexistence** | DMS live catalog and records were not accessed; copying its schema would preserve unknown assumptions. | Is migration from DMS required? Which data/history must be preserved, how will consent/suppression be validated, and is a parallel-run period needed? |
| D-21 | **Performance hypotheses** | Phase 4A includes provisional latency, event-rate, audience-preview, and scaling targets without DMS benchmark evidence. | Approve the workload assumptions and candidate targets in `PHASE_4A_PRODUCT_REQUIREMENTS.md` §4, or supply measured planning figures and service budgets. |

## Suggested review defaults

These are conservative proposals for discussion, not decisions already taken:

- Use workspace scope as the minimum mandatory authorization boundary; do not allow cross-workspace access unless a reviewed delegated model is approved.
- Treat missing, expired, or unprovable consent as ineligible for campaigns that require consent.
- Treat unsubscribe, complaint, and hard-bounce evidence as durable blocks until a reviewed correction/re-consent path allows reactivation.
- Keep infrastructure provisioning/control out of the first release unless it is an explicit customer need with dedicated operational ownership.
- Start with one or two clearly selected delivery channels and channel-specific outcome contracts; do not imitate DMS’s unknown native sender behavior.
- Keep the performance, SLO, RPO, and RTO values marked as hypotheses until the owner approves workload assumptions and a benchmark plan.

## Evidence still unavailable or intentionally excluded

The following DMS evidence remains unknown and should not be filled by assumption: live database catalog and rows; effective queue/worker configuration; actual PMTA configuration and topology; sender TCP protocol and message construction; PHPMailer installation/call site; live consent/suppression records; complaint feedback source; provider inventory/credentials; actual send throughput; and production analytics reconciliation. Phase 4A does not authorize new DMS access or reverse engineering.

## Approval gate

The foundation may proceed without activating outbound sending. Before adding send execution, provider traffic, infrastructure control, or production rollout, resolve or explicitly defer the relevant decisions above and update the implementation plan. No new production DMS access is authorized by the implementation kickoff.
