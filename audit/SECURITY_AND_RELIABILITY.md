# DMS Security and Reliability Observations

**Status: Static source review plus bounded host metadata. This is not a penetration test or security certification.** No exploit testing, dynamic scanning, production data query, or action endpoint was used.

## Access, identity and audit boundary

- **VERIFIED:** the direct SSH endpoint’s presented ED25519 fingerprint matched the independently supplied value before authentication. All SSH sessions used a pinned temporary `known_hosts`, `StrictHostKeyChecking=yes`, ED25519-only host-key negotiation, `-F /dev/null`, and explicit proxy/jump disablement.
- The authorized SSH account executes as UID 0. That provides broad access and is a material least-privilege concern for future operations. The audit used bounded listing, source reads, and metadata-only commands; no remote writes or application commands/jobs were issued.
- One initial authentication attempt failed because a local askpass quoting error prevented the helper from returning the credential. The fingerprint gate had passed; no credential value was printed. The helper was corrected and a later session authenticated. SSH authentication logging may be an incidental server-side effect.
- **Frontend read caveat:** DMS web middleware includes HTTP logging and `LogoutIfUserNotActive`, which may update authenticated users’ `seen_at` state on reads. Earlier browser page visits may therefore have generated incidental application writes. Their occurrence/value was not checked and no reversal was attempted. The health page was opened once in Phase 1 and may also have a side effect; it is marked do-not-revisit.
- No intentional DMS business, campaign, database, configuration, provider, DNS, service, queue, or infrastructure write was issued during this backend audit. The previous frontend side-effect caveat prevents a blanket claim that no incidental application write occurred at any time.

## Application access controls

- `RouteServiceProvider` places dashboard, admin and mailer route files behind `web` and `auth`; admin routes also use management-role gates, and selected mailer groups use role gates. Campaign send/update has an owner gate. This is **OBSERVED source policy**, not tested enforcement.
- The web middleware group includes sessions, CSRF, bindings, HTTP logger, inactive-user handling, and `AppAllowedIps`. Its behavior depends on runtime settings that were not read.
- API middleware contains only model binding; the framework API throttle is commented out. Three landing routes declare `throttle:2,1`, while the other API route families do not show route-specific throttling in `/var/app/routes/api.php`. External proxy/WAF controls are unknown.
- `PmtaController::action` is authenticated by the enclosing `/mailer` group and validates action/queue/tags; it checks a role only for wildcard tags. In the inspected method there is no visible server-specific `isAllowed` check, unlike `schedule` and `restart`, which call `isAllowed` or check admin. This is a **potential authorization inconsistency** from static code, not a tested access-control bypass.
- **Unsafe GET handlers:** `/mailer/drops` calls `DropAction::init_drop` and can create an unprocessed drop row; GET clone creates a row and GET clear modifies a draft. A Phase 1 visit to `/mailer/drops` is therefore a possible unverified incidental write. This is disclosed in [DMS_REFERENCE_ROUTE_EVIDENCE.md](DMS_REFERENCE_ROUTE_EVIDENCE.md); no confirmation query or reversal was attempted.
- **Google OAuth scope:** `GmailLib` configures broad Gmail mailbox and settings scopes, while the Google campaign sender can send or draft messages and a separate operation sends all existing drafts. Scope necessity, token storage/encryption, and revocation are unverified. No account/token was inspected.

## High-priority source-level risks

- **PMTA control channel:** `PmtaAction::execute_url` sends administrative commands over plain HTTP GET query parameters to an IP/configured admin port. The inspected call site shows no request authentication or TLS. PMTA-side ACLs and network segmentation are unknown. Restart and push paths execute remote shell/service operations (`PmtaAction.php:68-99`; push/reconfigure commands). No request or operation was made.
- **Managed-server SSH identity:** `ServersInstallFactory` creates phpseclib3 `SFTP` from server model address/port and authenticates, but no explicit application-level host-key fingerprint check appears in the factory. This finding is limited to the inspected call site; other deployment/network controls were not inspected.
- **Affiliate TLS:** `NewHitPath` uses `withoutVerifying()` for API requests and creates its browser client with peer verification disabled. This removes server certificate validation in those source paths (`NewHitPath.php:49-53,241-262,295-350`).
- **Custom SMTP helper:** raw `fsockopen` SMTP code has no visible TLS/AUTH negotiation or reply-code processing and reconnects at fixed message intervals. It has no located call site; active transport is unknown.
- **Shell command construction:** campaign send/stop and suppression helpers use shell execution. Some path/argument values originate in application state; escaping and complete input provenance are not verified. This is a static review concern, not evidence of exploitability.
- **Provider error logs:** domain adapters log API response bodies, and GoDaddy logs submitted record data with failed responses. The contents of production logs were not read; retention and redaction are unknown.

## Reliability and data integrity

- Horizon config defaults to one process and `tries=1` for each of three queues; production max-process values are 30/3/2. The active environment, worker process state, queue lag and failed-job records were not queried.
- Scheduler entries use `runInBackground` frequently; overlap locks are applied to many but not all scheduled commands. The scheduler also reads `PmtaScheduler` records and dynamically schedules PMTA actions. No job was run.
- `ServerProcessPmtaLogs` uses a 10-minute file-cache lock, up to five concurrent server tasks, moves remote PMTA files between treated/process/archive paths, increments statistics, and dispatches recipient-state jobs. If interrupted, file movement and database increments need idempotency/partial-retry review; this audit did not run it or inspect production files.
- Visitor rows lack a unique event key in the inspected migration while email log tokens are unique; event/job retry paths may therefore have uneven deduplication. The full transaction and retry semantics remain incomplete.
- The live PostgreSQL catalog and a least-privilege DB identity were unavailable, so grants, deployed constraints and transaction isolation are unknown.

## Frontend response observations retained from Phase 1

One observed login response had a session cookie with HttpOnly and SameSite=Lax but without Secure; the XSRF token was Secure and SameSite=Lax but not HttpOnly. HSTS and CSP were absent on that response. This single observation is historical, may reflect edge termination, and was not retested. The original details and scope are in [DMS_REFERENCE_AUDIT_PHASE_1.md](DMS_REFERENCE_AUDIT_PHASE_1.md).

## Confidence and limitations

Source-level findings are marked **OBSERVED** or **INFERRED** in topic reports. No dynamic authorization test, SMTP/PMTA probe, provider call, database query, log review, service control, or scheduler execution was performed. These observations should inform design review for AlphaLink only after the reference audit is reviewed; they do not select its technology stack.
