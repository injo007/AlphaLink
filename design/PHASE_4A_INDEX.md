# AlphaLink Phase 4A — Architecture and Product Requirements

**Status:** Design reference; bounded API implementation has started in `apps/api` using Laravel 13/PHP 8.3, PostgreSQL, Redis, and Sanctum. Product, legal, transport, and operational decisions below remain open.

## Proposed document structure and evidence map

The specification is split into three documents so the reference evidence, product requirements, architecture contracts, and approval decisions can be reviewed independently.

| Section / deliverable | Location | Existing evidence mapped into it |
|---|---|---|
| Scope, evidence labels, document structure, cross-document navigation | This index | `PROGRESS.md`; `DMS_RECONSTRUCTION_EVIDENCE.md`; `DMS_REVERSE_ENGINEERING_FINAL_REPORT.md`; `REFERENCE_AUDIT_FINAL_REPORT.md` |
| DMS feature inventory, with verified/partial/inferred/unknown distinctions | Product and requirements §2 | Phase 1 menu and page observations in `DMS_REFERENCE_AUDIT_PHASE_1.md` and `DMS_REFERENCE_ROUTE_EVIDENCE.md`; source map in `DMS_COMPLETE_COMPONENT_MAP.md`; module reports for campaigns, tracking, integrations, infrastructure, data, and security |
| Evidence-bounded DMS architecture reconstruction | Architecture §2 | `REFERENCE_SYSTEM_ARCHITECTURE.md`; `BACKEND_ARCHITECTURE.md`; `DMS_COMPLETE_COMPONENT_MAP.md`; `DMS_REVERSE_ENGINEERING_FINAL_REPORT.md`; `DMS_NATIVE_SENDER_ANALYSIS.md` |
| AlphaLink vision, actors, scope and functional requirements for major modules | Product and requirements §§3–5 | DMS feature evidence plus redesign recommendations in `ALPHALINK_IMPROVEMENT_OPPORTUNITIES.md`; weak points in `DMS_ARCHITECTURAL_WEAKNESSES.md` |
| Nonfunctional requirements and measurable candidate acceptance targets | Product and requirements §6 | `SECURITY_AND_RELIABILITY.md`; `DMS_SECURITY_BOUNDARIES.md`; `DMS_FAILURE_RECOVERY_ANALYSIS.md`; `MAIL_INFRASTRUCTURE.md`; targets are proposed hypotheses, not measured DMS performance |
| Proposed logical module boundaries and language-neutral communication contracts | Architecture §§3–4 | `DMS_COMPLETE_COMPONENT_MAP.md`; `CAMPAIGN_ORCHESTRATION.md`; `DMS_SENDING_DATAFLOW.md`; `DMS_TRACKING_ATTRIBUTION_FLOW.md`; `EXTERNAL_INTEGRATIONS.md` |
| Campaign, consent/recipient, sending, tracking, and recovery state machines | Architecture §5 | `DMS_CAMPAIGN_STATE_MACHINE.md`; `DMS_FAILURE_RECOVERY_ANALYSIS.md`; `TRACKING_AND_ANALYTICS.md`; `DMS_NATIVE_SENDER_ANALYSIS.md` |
| Data ownership, access control, privacy and auditability | Architecture §6 | `DATABASE_ARCHITECTURE.md`; `DMS_SECURITY_BOUNDARIES.md`; `SECURITY_AND_RELIABILITY.md`; `DMS_REFERENCE_AUDIT_PHASE_1.md` |
| Operational/failure scenarios and measurable acceptance criteria | Product and requirements §7 | `DMS_FAILURE_RECOVERY_ANALYSIS.md`; `MAIL_INFRASTRUCTURE.md`; `TRACKING_AND_ANALYTICS.md`; `CAMPAIGN_ORCHESTRATION.md` |
| DMS-to-AlphaLink improvement matrix with evidence, severity, impact and redesign | Product and requirements §8 | `DMS_ARCHITECTURAL_WEAKNESSES.md`; `ALPHALINK_IMPROVEMENT_OPPORTUNITIES.md`; `SECURITY_AND_RELIABILITY.md`; topic evidence reports |
| Open questions and owner decisions before implementation or technology selection | Decisions register | Gaps and blockers throughout Phase 1–3H, especially `PROGRESS.md`, `REFERENCE_AUDIT_FINAL_REPORT.md`, `DMS_RECONSTRUCTION_EVIDENCE.md`, and the topic reports |

## Evidence language

Reference claims and AlphaLink proposals use separate labels:

- **DMS — VERIFIED / OBSERVED:** directly visible in inspected source, configuration, a static artifact, or bounded metadata. It does not imply active use unless the report says so.
- **DMS — DERIVED:** follows from a static call/data path across inspected sources.
- **DMS — INFERRED:** plausible, but depends on a runtime setting or relationship that was not checked.
- **DMS — UNKNOWN / UNVERIFIED:** evidence is missing, a bounded search did not establish the behavior, or investigating further would cross the audit boundary.
- **ALPHALINK — PROPOSED:** a product or architecture requirement for the new system. It is not a claim about DMS and is not an implementation choice.
- **ALPHALINK — HYPOTHESIS:** a measurable candidate target that needs owner approval and benchmarking.
- **DECISION REQUIRED:** an owner/product/compliance decision is needed before the requirement can be finalized or implementation can begin.

Static source evidence establishes code paths and configured defaults; it does not establish production traffic, effective runtime configuration, deployed schema, provider state, regulatory compliance, or real-world outcomes. The Phase 3H CIL pass establishes a task fan-out over JSON array items, but does not establish that those items are recipients or identify the TCP protocol.

## Review boundary

The Phase 4A documents remain the design and evidence reference. The owner has since authorized the initial implementation slice. It selects Laravel/PHP, PostgreSQL, Redis, and Sanctum as a provisional baseline, while leaving provider, hosting, deployment, volume, consent policy, and service objectives undecided. Performance and availability targets remain provisional. Legal obligations depend on target markets, sender identity, audience, message purpose, and channel; those inputs require owner and qualified compliance review.

The initial API slice adds workspace-scoped account, contact, consent/suppression evidence, draft campaign revisions, audit, and outbox foundations. It has no send, provider, or infrastructure operation. Resolve the relevant decisions before adding those capabilities or connecting production DMS state.

## Documents

1. [Product and requirements](PHASE_4A_PRODUCT_REQUIREMENTS.md) — DMS inventory, AlphaLink functional and nonfunctional requirements, operational acceptance criteria, and improvement matrix.
2. [Architecture and state machines](PHASE_4A_ARCHITECTURE_AND_STATE_MACHINES.md) — supported DMS reconstruction, proposed AlphaLink modules/contracts, lifecycle models, ownership, access, and audit requirements.
3. [Decisions register](PHASE_4A_DECISIONS.md) — unresolved questions and approval gates.
