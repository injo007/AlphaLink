# DMS Security Boundaries — Source-Level Review

**Status: Static review only; not a penetration test or security certification.**

## Trust-boundary diagram

~~~mermaid
flowchart LR
  Browser[Admin browser] -->|HTTPS/session/CSRF/auth| App[Laravel web application]
  Public[Public API clients] -->|shared key; partial throttling| API[API handlers]
  App -->|application DB identity| DB[(PostgreSQL)]
  App -->|Redis credentials/config| Queue[(Redis / Horizon)]
  App -->|shell command + local files| ELF[.NET apphost]
  ELF -->|loads bundled assembly| Managed[send.dll]
  Managed -->|TCP text I/O; SMTP compatibility inferred; peer unknown| MTA[SMTP / PMTA]
  App -->|plain HTTP command URL in inspected path| PMTA[PMTA control plane]
  App -->|phpseclib SFTP| Servers[Managed mail hosts]
  App -->|provider tokens/API keys| Vendors[Affiliate, cloud and DNS providers]
  Vendors -->|reports / callbacks / OAuth| App
~~~

Edges describe code/configuration boundaries. Credential values, network ACLs, live grants, and runtime enforcement were not inspected.

## Verified boundaries and weaknesses

### Web identity and administrative controls

- RouteServiceProvider groups dashboard/admin/mailer routes under web and auth middleware. Admin routes have management role gates; selected mailer areas have role gates; campaign send/update paths use owner authorization.
- The web middleware stack includes session, CSRF, route binding, Spatie HTTP logging, inactive-user handling and an IP restriction middleware.
- The role policy is statically visible but no access-control test was performed. Tenant isolation and every model ownership check remain **PARTIAL/UNKNOWN**.
- Phase 1 observed a session cookie without Secure and no HSTS/CSP in one login response. It is a single-response observation and may reflect edge behavior; it was not retested.
- Unsafe state-changing GET handlers exist for drop draft initialization, clone, and clear. The first route was visited during Phase 1; possible incidental draft creation is unverified.

### Public API and event integrity

- Default API throttle middleware is commented out; only subscribe, opt-out, and contact routes showed explicit throttle 2,1. External WAF/rate controls are unknown.
- Several endpoints mutate recipient state or queue tracking jobs; HTTP GET/POST method alone does not establish safety.
- Some route families validate a shared application key. Key secrecy, rotation, scope, timing-safe comparison, and management are unknown.
- Email-log tokens are unique in the migration, but visitor rows lack a unique event key. Retry and idempotency protection is uneven.
- The source-generated campaign attribution token uses Base64 and Adler-32, not a cryptographic MAC in inspected code. Sender-side verification is unknown.

### Recipient lifecycle and campaign snapshots

- The manual reply extractor's mix builder accepts existing `unsubscribe` rows. Campaign creation passes selected lists through `Suppression`; its virtual-list branch loads stored mix rows without rechecking current recipient type or `excluded_offers`. An unsubscribe row in a selected reply audience can therefore enter the PHP send snapshot unless the separate offer suppression file removes it. This is a source-derived risk, not evidence of a completed send; later native-sender filtering is unknown. See `CAMPAIGN_ORCHESTRATION.md` and `DMS_TRACKING_ATTRIBUTION_FLOW.md` for source anchors.

### Process and infrastructure controls

- Campaign launch and stop use shell command construction; process output is sent to /dev/null; kill uses a stored PID without visible process identity verification in the inspected method.
- PMTA controller action reaches a path that sends admin commands through plain HTTP GET query parameters. No TLS or request authentication is visible at that call site; network ACLs are unknown.
- A PMTA action controller method lacks a visible per-server allowlist check found in its schedule/restart peers. This is a static inconsistency, not an exploit test.
- Managed server SFTP uses phpseclib3 and model-stored address/credentials; no explicit application-level host-key pin is visible in the inspected factory.
- NewHitPath disables TLS peer verification in inspected API and login-session clients.
- Domain adapter failures may log response bodies or submitted record data. Logs were not read, so secret/PII exposure is not established.

### Google OAuth and sender

- Google campaign code has broad Gmail mailbox/settings scopes and can send or draft messages; a separate operation sends existing account drafts.
- Token encryption, least-privilege scope justification, revocation and account isolation were not established.
- Approved offline REA disassembly confirms that the apphost's `dlopen`/`dlsym` path resolves .NET `libhostfxr.so` / `hostfxr_main`, a declared `$ORIGIN/netcoredeps` RUNPATH, C++ exception/unwind support, and no direct socket/TLS/HTTP/SMTP call targets in the apphost import/disassembly set. Managed CIL separately verifies a reachable TCP text helper with SMTP-shaped reply parsing; SMTP compatibility is inferred, while peer identity and literal commands remain unknown.
- No debug sections were observed, and deep decompiler providers were unavailable. Static analysis therefore could not establish how sender credentials are sourced/protected, whether transport security is configured, or how message headers/body are validated. The fixed marker scan found no tested MIME/SMTP terms in `.rodata`, which is limited negative evidence rather than proof of absence.

## Operational identity and audit caveat

SSH host identity was verified against the user-supplied fingerprint before each authenticated audit session, using strict checking and direct routing. The authenticated identity was UID 0, which exceeds least privilege. Phase 2/3 issued no intentional remote write or operational action.

The prior Phase 1 browser activity may have caused incidental session seen_at/HTTP-log effects; the health page may also have side effects. The drops page visit may have created a draft. These unknown possible effects were not queried or reversed.

## Confidence and exclusions

**VERIFIED:** the cited source-level controls and missing checks at inspected call sites.

**INFERRED:** their practical exposure depends on reverse proxy rules, network segmentation, runtime config, database grants, external identity systems and provider ACLs.

**UNKNOWN:** exploitable reachability, secrets at rest, external callback authentication, actual TLS termination, consent evidence, global suppression, data retention, incident logging, native module purpose, and sender transport/message behavior.

No credentials were extracted. No exploit, fuzzing, dynamic instrumentation, endpoint probe, service control, message send, or database query was performed.
