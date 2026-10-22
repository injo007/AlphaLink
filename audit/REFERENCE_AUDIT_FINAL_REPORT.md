# DMS Reference Audit — Phase 1 and Phase 2 Findings

**Status: Source-level backend investigation completed to the safe evidence boundary; live database and operational runtime details remain blocked/unverified. Submitted for review.** DMS is the existing production reference. AlphaLink is a separate new application; no technology selection or implementation is included.

## Executive findings

DMS is a Laravel 10.38.2 application on PHP 8.1.27, served by Nginx/PHP-FPM. It configures PostgreSQL and Redis/Horizon. Campaign creation builds a suppression-filtered, file-backed audience snapshot; sending then launches a separate x86-64 ELF process. Campaign scheduling uses queued Laravel jobs. Tracking, PMTA log ingestion, sponsor conversion imports, daily analytics and many infrastructure operations are code-backed.

**Historical Phase 2 report.** The requested PHPMailer relationship is not resolved: the user confirms PHPMailer, but the deployed Composer metadata and searched PHP call sites did not reveal the package or a call. A separate raw SMTP helper and an opaque sender ELF are present. Later Phase 4B static CIL analysis established a reachable managed TCP text path with SMTP-shaped reply parsing, making SMTP compatibility the leading inference; MIME construction, literal commands, peer identity, and PHPMailer linkage remain unresolved. The current reassessment is [DMS_PHASE_4B_REASSESSMENT.md](DMS_PHASE_4B_REASSESSMENT.md).

This is not a full production architecture verification. We did not inspect live database catalogs or rows, effective runtime secrets/configuration, queue contents, PMTA files, provider records, or production logs. A dedicated least-privilege DB account was not established. These are explicit evidence blockers, not assumed facts.

## Verified access and safety record

- The SSH endpoint’s presented ED25519 host fingerprint was independently compared to the trusted server-console fingerprint and matched before authentication. Every authenticated session used strict host-key checking and direct routing, with SSH proxy/jump disabled.
- The authorized SSH identity ran as UID 0. Remote reads were limited to source, non-secret configuration fields, executable metadata, and sanitized OS/service/scheduler metadata.
- No database connection/query, application script, Artisan command, queue worker, scheduler, sender, SMTP/PMTA action, provider API, DNS action, service control, or production endpoint was invoked during Phase 2. No remote source/configuration/data write was issued intentionally.
- Backend source review found that the Phase 1 visit to `GET /mailer/drops` calls `DropAction::init_drop` and may create a draft if none existed. Whether this happened is **UNKNOWN**. No row was queried or reversal attempted; this is a possible incidental Phase 1 state change and is documented in [DMS_REFERENCE_ROUTE_EVIDENCE.md](DMS_REFERENCE_ROUTE_EVIDENCE.md).
- A first SSH authentication attempt failed due to a local askpass quoting error; the fingerprint gate had passed and no credential value was emitted. The helper was corrected and a later session authenticated. Authentication log entries are an incidental OS effect.
- Earlier frontend visits may have caused `seen_at` or HTTP-log middleware side effects; the health page may also have side effects. This cannot be ruled out or reversed, and no attempt was made to do so. See [PROGRESS.md](PROGRESS.md).

## Main findings by area

| Area | What is established | Evidence limit |
|---|---|---|
| Runtime/application | PHP CLI/FPM 8.1.27; Laravel 10.38.2; 156 locked runtime packages; Nginx front controller; middleware and route groups mapped. | Active PHP-FPM config values and deploy version drift not checked. |
| Campaigns/sending | Campaign create/send/update/schedule paths, eight native send modes, suppression snapshot, state fields, custom sender launch and stop behavior traced. Google-linked campaigns use a separate OAuth/Gmail API sender path. | Native sender ELF not executed or transferred; internal batching/rendering/retry/completion unknown. Gmail accounts/tokens and API behavior were not accessed. |
| PHPMailer | User-reported use; no package/call site found in searched current Composer/PHP tree. Raw SMTP helper found. | Exact package, call path, MIME, headers, DKIM and active transport unresolved. |
| Database | PostgreSQL driver configured; migration-defined models/tables, relationships and JSONB patterns documented. | No verified read-only identity, so no live catalog/version/grant query and no rows. |
| Queue/scheduler | Redis queue defaults, Horizon supervisor definitions, root `schedule:run` cron presence, Console Kernel tasks, and several service/timer names inspected. | Active environment, worker/process state, live queue and scheduled DB task records unknown. |
| Tracking/delivery | API handlers, redirects, event jobs, recipient lifecycle changes, aggregates, PMTA delivery/bounce processing, CentOS 9 FBL/inbound-mbox configuration, manual reply extraction and sponsor lead imports mapped statically. | Complaint-specific classification, recipient matching and suppression remain unknown; no event/campaign data or endpoint accessed. |
| Infrastructure | PMTA control/push and remote SFTP operation paths inspected; managed provider class matrix mapped. | No PMTA configuration, server/IP/domain/provider rows, DNS, MTA version or runtime ACLs read. |
| Integrations | HitPath/NewHitPath/Cake/HasOffers/EverFlow; GoDaddy/Namecheap; eight cloud providers; named utility routes mapped. | No provider state, credentials, API responses, webhooks or external requests. |

## Material source-level concerns for review

1. NewHitPath disables TLS peer verification in API and login-session code.
2. PMTA commands are sent to an admin HTTP endpoint as GET query parameters in the inspected call site; no TLS or request authentication is visible there. Network/PMTA ACLs are unknown.
3. The inspected PMTA `action` controller method has no visible per-server authorization gate, while `schedule` and `restart` perform an admin/server-allowlist check. Static source review only; no bypass was tested.
4. The managed-server phpseclib factory shows no explicit host-key fingerprint validation at its application call site.
5. Campaign sending relies on shell command construction and opaque native sender binaries; stop uses `kill -9` on a stored PID. Input escaping, graceful shutdown, and state reconciliation deserve review.
6. PMTA log ingestion moves remote files and increments database statistics; its scheduled execution and partial-retry/idempotency behavior were not tested.
7. The app’s API group lacks default throttle middleware; only subscribe/opt-out/contact have route-specific throttles in inspected routes.
8. The SSH audit account is root rather than a constrained read-only account. No write was made, but future access should use least privilege.

Full context and confidence classifications are in [SECURITY_AND_RELIABILITY.md](SECURITY_AND_RELIABILITY.md).

## Evidence report index

- [PROGRESS.md](PROGRESS.md) — coverage ledger, decisions, chronology and blockers.
- [DMS_REFERENCE_AUDIT_PHASE_1.md](DMS_REFERENCE_AUDIT_PHASE_1.md) and [DMS_REFERENCE_ROUTE_EVIDENCE.md](DMS_REFERENCE_ROUTE_EVIDENCE.md) — frontend evidence and routes intentionally not called.
- [BACKEND_ARCHITECTURE.md](BACKEND_ARCHITECTURE.md) and [REFERENCE_SYSTEM_ARCHITECTURE.md](REFERENCE_SYSTEM_ARCHITECTURE.md) — backend module map and evidence-bounded data flow.
- [PHPMAILER_SENDING_ENGINE.md](PHPMAILER_SENDING_ENGINE.md), [CAMPAIGN_ORCHESTRATION.md](CAMPAIGN_ORCHESTRATION.md), [DATABASE_ARCHITECTURE.md](DATABASE_ARCHITECTURE.md), and [TRACKING_AND_ANALYTICS.md](TRACKING_AND_ANALYTICS.md) — core application paths.
- [MAIL_INFRASTRUCTURE.md](MAIL_INFRASTRUCTURE.md), [EXTERNAL_INTEGRATIONS.md](EXTERNAL_INTEGRATIONS.md), and [SECURITY_AND_RELIABILITY.md](SECURITY_AND_RELIABILITY.md) — operations and risks.
- [BACKEND_READ_ONLY_PLAN.md](BACKEND_READ_ONLY_PLAN.md) — safety gate and remaining evidence requirements.

## Remaining evidence requirements

- Obtain a separately verified, least-privilege read-only PostgreSQL identity before inspecting live schema catalogs. Do not use the root application/SSH credential for database queries.
- Reconcile user-confirmed PHPMailer with an authoritative package/call-site/source path. The current lockfile/PHP evidence and external ELF do not resolve it.
- Analyze the sender only from an explicitly supplied or otherwise authorized artifact that can be reviewed without executing it, disclosing embedded secrets, or transferring protected production data. REA currently reports no open target; no remote artifact was imported.
- Confirm effective queue environment and worker configuration through sanitized configuration evidence if needed; do not inspect job payloads or run workers.
- Verify current PMTA configuration, provider state, domains/DNS, server/IP inventory, role grants, and complaint/FBL path only through safe, sanitized source/config/metadata access. Do not call operational routes or external systems.

## Phase boundary

The reference findings are ready for user review. Stop here. No AlphaLink framework, language, database or architecture has been selected, and no AlphaLink code or deployment has begun.
