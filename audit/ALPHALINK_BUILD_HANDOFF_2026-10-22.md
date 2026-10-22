# AlphaLink SaaS build handoff from the DMS inspection

**Status:** Reference inspection complete at the authorized read-only boundary. No AlphaLink implementation or stack has been selected or started.

## Reference workflows to specify

| Domain | DMS reference behavior established | AlphaLink decision to make before coding |
|---|---|---|
| Accounts and access | Admin/owner gates, users, ISP/list/sponsor permissions | Tenant boundary, role matrix, audit trail |
| Audience | One-list-per-address relational recipient, virtual mix files, state/type filters, offer suppression | Membership model, consent evidence, suppression freshness, immutable send snapshot |
| Campaigns | Eight standard modes, schedule or immediate launch, file config, native worker detail fetch | Job lifecycle, pacing, pause/cancel, idempotency, recovery |
| Drops and warmups | Separate configurations, mode/test variants, progress/status | Whether to unify execution behind one job contract |
| Mail transport | Server IP plus PMTA listener port, SMTP commands, VMTA/job/envelope fields | Provider abstraction, submission acknowledgments, TLS/auth, per-recipient result model |
| Gmail | Separate OAuth/Gmail API path | Whether supported, limits, account isolation |
| Delivery feedback | PMTA delivery/bounce CSV import updates metrics and states | Event ID, replay safety, accepted/delivered/bounced distinctions |
| Engagement | Tracking mini-app pixel, redirect and opt-out endpoints; shared-key API events | Signed token, privacy/consent controls, bot filtering, replay protection |
| Affiliate | Five adapters, offer import/refresh, suppression downloads, conversion polling | Adapter contracts, callback/report reconciliation, refunds and attribution windows |
| Infrastructure | PMTA installation/control, DNS, cloud, SMTP credentials | Safe provisioning, least privilege, rollback, separate operations plane |
| Reporting | Scheduled aggregates across campaign/drop/seed and conversion events | Source-of-truth event model, uniqueness, timezone and late corrections |

## Requirements that the reference does not settle

1. A sender progress count is not a delivery receipt. Define attempted, submitted, accepted, delivered, bounced, complained and engaged as separate event states with durable identifiers.
2. Suppression must be checked against current recipient and offer state when a send is committed, including virtual mixes. Define global opt-out and complaint policy before launch.
3. Specify checkpoint/retry/idempotency and cancellation before choosing a worker architecture. DMS's inspected delay path does not prove per-recipient reply handling or reliable terminal reconciliation.
4. Specify final MIME/personalization, attachments, TLS/auth, DKIM and provider-specific submission using controlled fixtures or approved live observation if parity is required. Static DMS evidence cannot certify these bytes or live behavior.
5. Define tenant isolation, roles, credential storage, audit logs and infrastructure operations separately from the DMS implementation. Do not copy its plain HTTP PMTA command surface or destructive installer flow as a default.
6. Decide which integrations are in AlphaLink's first release; DMS source presence does not prove an account is active or an API is functioning.

## Evidence to use

Start with [the complete DMS conclusion](DMS_COMPLETE_CONCLUSION_2026-10-22.md), then [the sender resolution](DMS_REA_SENDER_RESOLUTION_2026-10-22.md). The former links the detailed feature reports; the latter corrects the older sender unknowns. [REA evidence](DMS_REA_REFRESH_EVIDENCE_2026-10-22.json) pins managed metadata and CIL to the exact deployed artifact. No secret values or production data were transferred into these handoff files.

**Ready next step:** turn the table above into an AlphaLink product specification and delivery plan, choosing feature scope and operational requirements explicitly. This handoff is evidence for that work; it is not a mandate to reproduce DMS behavior where the audit found gaps.
