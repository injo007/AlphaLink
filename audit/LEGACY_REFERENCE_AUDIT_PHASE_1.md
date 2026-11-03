# DMS Reference Application Audit — Phase 1

**Date:** 2026-10-20  
**Status:** Historical Phase 1 frontend review; the current source-level backend audit is documented in the Phase 2 reports.  
**Purpose:** Understand the production DMS reference application for a later, separate AlphaLink design discussion. No AlphaLink stack or architecture is selected here.

## Executive summary

The authenticated administrative frontend identifies itself as **DMS** and is reachable at the configured reference host. The page title is “Main Dashboard - DMS.” Its navigation exposes reporting, users, ISPs, lists, email search, domains, servers, offers, subscribers, campaign and drop workflows, warmups, global tests, PMTA controls, cloud-provider pages, tools, health, logs, and settings.

The frontend appears to be a server-rendered PHP application with jQuery-style inline AJAX and a Bootstrap/DataTables/Chart.js interface. This is an inference from form conventions, session/CSRF naming, the Horizon page, and asset paths. The user reports PHPMailer; its implementation and version have not been verified from source.

The UI contains state-changing actions for campaign sending, drop suppression, PMTA, scheduling, server reconfiguration, record edits, and uploads. No such controls were intentionally submitted. Inline JavaScript was inspected as text and not executed. Server-rendered table placeholders did not contain records or column headings, so production data and actual table schemas remain unknown. **Phase 2 source inspection later established that the visited `/mailer/drops` GET route calls `DropAction::init_drop`, which can create a draft row if one does not already exist. Whether the earlier visit created a row is unknown; no query or reversal was attempted.**

The session cookie observed on the login response was HttpOnly and SameSite=Lax, but lacked Secure. HSTS and CSP were absent from that response. X-Content-Type-Options and X-Frame-Options were present. Login rate limiting, MFA, reset flows, session renewal/expiry, and role enforcement were not tested.

**Historical Phase 1 state:** SSH host identity had not yet been verified and no authentication had occurred at that point. This statement is superseded by the Phase 2 verification and source audit below.

**Phase 2 status update (2026-10-20):** The user supplied a trusted ED25519 fingerprint and explicitly authorized direct SSH. The presented fingerprint matched before authentication; subsequent sessions used strict host-key checking. The backend audit and its remaining blockers are in [PROGRESS.md](PROGRESS.md) and [REFERENCE_AUDIT_FINAL_REPORT.md](REFERENCE_AUDIT_FINAL_REPORT.md).

## Scope and safety

Investigation was limited to the authenticated frontend and static assets available through the already-confirmed proxy route. Several short-lived authenticated sessions were established to inspect separate page groups; each used the normal login flow, with session cookies held in memory only. Page HTML and same-origin JavaScript were fetched and parsed without executing JavaScript. Structural details were retained; values from settings, tables, cookies, and records were not included in this report.

No campaign, test, warmup, suppression, scheduler, PMTA, server, domain, provider, user, list, offer, or upload action was intentionally submitted. No dynamic AJAX endpoint identified in source was invoked. No data rows were requested by the audit script, and no exports were downloaded. The `/mailer/drops` initial page GET is a potential state-changing exception discovered later from backend source; see the caveat above.

**Potential side-effect exception:** the App Health page was opened once. It displayed “last run: just now” and 11 check rows. Without server source, it is not possible to establish whether the page reads a prior result or triggers a health check on access. This route is marked **UNVERIFIED** and was not reopened.

No business, campaign, infrastructure, or configuration change was intentionally requested during Phase 1. The potential draft-drop creation on the `/mailer/drops` GET is unverified and was not reversed. Authenticated sessions were created. `.env` and Firefox were not modified. The local reports are sanitized. Earlier preflight and access-gate documents remain historical; current connection and audit state is in the Phase 2 reports.

## Evidence ledger

| ID | Evidence | Confidence and limits |
|---|---|---|
| E-01 | Authenticated dashboard returned HTTP 200 at /dashboard/main with title “Main Dashboard - DMS.” | Direct frontend observation. |
| E-02 | Login response and session-cookie attributes; response security headers. | Direct HTTP observation for this response only; no broader security test. |
| E-03 | Dashboard navigation labels and same-origin paths. | Direct DOM observation; navigation labels do not prove backend relationships. |
| E-04 | Read-only page titles, headings, form field names, buttons, and rendered table structure. | Direct HTML observation. DataTables/AJAX content was not loaded. |
| E-05 | Method and path declarations from inline JavaScript on selected pages. | Static source candidates only. No endpoint was called; server-side behavior and authorization remain unknown. |
| E-06 | Three same-origin JavaScript assets and shared table/plugin asset paths. | Static asset observation; no JavaScript executed. |
| E-07 | REA session identity and tool catalog. | Direct target-free REA response: server rea 6.7.0, 207 tools, no target open. No DMS binary or browser target was available to REA. |
| E-08 | SSH target trust state at the end of Phase 1. | Historical local configuration observation: no expected fingerprint and no matching known_hosts entry; no SSH attempt had occurred then. Superseded by the verified Phase 2 connection record above. |

## Frontend security observations

### Authentication and session

- The configured administrative frontend redirected to a same-host login page; one normal login POST succeeded and reached the dashboard.
- The login flow included hidden CSRF field(s). The settings form also exposes a CSRF field named underscore-token.
- The session cookie was named pxlrbldlnk_session. Observed flags: HttpOnly and SameSite=Lax; **Secure was absent**.
- The XSRF-TOKEN cookie had Secure and SameSite=Lax; HttpOnly was absent.
- HSTS and Content-Security-Policy were absent from the login response. X-Content-Type-Options was nosniff, X-Frame-Options was SAMEORIGIN, and Referrer-Policy was no-referrer-when-downgrade.
- Login rate limiting, MFA, password recovery, account lockout, session rotation, expiry, logout invalidation, and role-based route enforcement are **UNVERIFIED**.

### Frontend assets

Observed same-origin assets include:

- DataTables 1.11.3 with Buttons 2.0.1, ColVis 2.0.1, FixedHeader 3.2.0, Responsive 2.2.9, RowGroup 1.1.4, and Select 1.3.3.
- Chart.js 3.
- Bootstrap Select CSS/JavaScript and custom script.js, app.js, and tables_datatables.js.
- The shared table initializer references Buttons; the asset path includes the column-visibility extension. A separate large shared script contains export/import/print markers, but specific production workflows and exported formats were not established. No export or import was triggered.
- Pages contain inline AJAX declarations. These were read as text only; the browser did not execute them during this investigation.

## Observed application areas

### Dashboards and reporting

The dashboard presents today delivery/open/click metrics and MTD/YTD revenue or action summaries, including revenue by affiliate, ISP, mailer, and top offers. Additional pages are titled All Statistics, ISP Dashboard, and Mailers Revenue. The ISP dashboard includes a RUN control; it was not activated.

Other visible reporting/history pages include campaign statistics and logs, drop monitoring and statistics, warmup statistics, global-test histories, list history, PMTA action/config history, and app errors. Their rendered table shells did not provide records to this investigation. Result sets and metric definitions remain unknown.

### Users, lists, and audience records

The navigation exposes Users, ISPs, Lists, List History, Search Emails, and Subscribers. Page headings were observed, but client-side data tables were not populated. User roles, list fields, subscriber attributes, consent evidence, suppression semantics, retention rules, deduplication rules, import/export behavior, and entity relationships remain **UNVERIFIED**.

The list page exposes a LOAD LISTS control and source code contains load, lock, and tracking route candidates. List-history code contains list-loading and history-loading candidates. Search Emails has a POST search candidate and a separate PUT action candidate. None was invoked.

### Campaign and delivery workflows

The navigation separates these areas:

- Campaign sending (a SEND entry at /mailer/campaigns; not opened because it is action-oriented).
- Gmail campaigns, with project and account pages; their send entry was not opened.
- Negatives and a “My Mix” history page.
- Campaign statistics and logs.
- Drops, drop monitoring, suppression, and drop statistics.
- Warmups, seeds, and statistics; the send entry was not opened.
- Global tests and histories; the send entry was not opened.

Static declarations show send/test/body-generation actions for drops; pause/resume, kill, auto-test, restrict, and stats operations for monitored drops; and start/kill operations for suppression. Campaign logs expose a restrict action. Gmail projects/accounts and warmup seeds expose status/edit/delete operations. These are route candidates, not verified server behavior.

Templates, personalization, recipient selection, segment logic, scheduling rules, campaign state transitions, queue handoff, delivery retries, bounce classification, unsubscribe processing, and final delivery attribution are **UNVERIFIED**. No message or test email was sent.

### Domains, servers, IPs, and PMTA

The UI has domain/provider, server/provider, cloud-provider, server, IP, and PMTA pages. The cloud menu names Linode, ServerSpace, DigitalOcean, Vultr, Reg.ru, Amazon EC2, Azure Compute, and Hetzner. These names show frontend categories only; no provider connection or infrastructure action was performed.

PMTA pages expose admin actions, a scheduler, settings, VMTA, SMTP, bounces, action history, and config history. The PMTA admin page shows controls for pause/resume/delete/reset/clear/schedule/stop/restart. Scheduler and PMTA settings expose add-task, edit, push, restore, and other management controls. No queue or scheduler task values were loaded, and no PMTA command or push/reset action was invoked.

The app also exposes server install, bulk install, domain change, reinstall, and VMTA reconfiguration areas. These were not opened or used. Server operating system, MTA version, SMTP topology, DNS records, DKIM/SPF/DMARC configuration, bounce processing, IP pools, queue behavior, cron jobs, and failover are **UNVERIFIED**.

### Offers, sponsors, tools, and integrations

Admin and mailer offer pages and sponsor pages are accessible. Their data rows were not loaded. Visible tool categories include blacklist checking, encoding, SPF scanning, Outlook-related checks, workspace, shortlinks, reply extraction, spam checking, Imgur, Afraid, Firebase, and TextMechanic. Checker/tool forms were not submitted because they can upload data, query external services, or create links.

The administrative settings form exposes field names for application identity, third-party tokens, IP/firewall/proxy settings, suppression and campaign expiry, DKIM/hash configuration, bot engagement settings, dormant-email movement, webmail, and backups. Sensitive field values were deliberately not recorded. Settings were not saved or changed.

The user confirms that DMS uses PHPMailer. The package version, configuration, transport choices, retry/error handling, and call sites could not be verified without source access. No billing page was visible in the authenticated navigation; billing functionality elsewhere is **UNVERIFIED**.

## Navigation relationship sketch

This represents the observed menu taxonomy, not a verified database or service architecture. Dashed edges indicate inferred groupings only.

~~~mermaid
flowchart LR
  DMS[DMS admin UI]
  DMS --> Dash[Dashboards and statistics]
  DMS --> Admin[Users, ISPs, lists, emails]
  DMS --> Net[Domains, servers, IPs, providers]
  DMS --> Campaigns[Campaign and delivery menus]
  DMS --> PMTA[PMTA controls and histories]
  DMS --> Tools[Tools and external-service pages]
  Admin -. grouping only .-> Lists[Lists and list history]
  Campaigns -. grouping only .-> Gmail[Gmail projects and accounts]
  Campaigns -. grouping only .-> Drops[Drops, monitoring, suppression]
  Campaigns -. grouping only .-> Warmups[Warmups, seeds, statistics]
  Campaigns -. grouping only .-> Tests[Global tests and histories]
  Net -. grouping only .-> PMTA
~~~

## Phase 1 risks and open questions (superseded by later source evidence where noted)

1. **Session transport flags:** the session cookie lacked Secure, and HSTS was absent on the observed HTTPS login response. Verify deployment-wide behavior from source and reverse-proxy configuration before drawing a final security conclusion.
2. **Content Security Policy:** CSP was absent on the observed response. JavaScript is extensive and includes many action routes; CSP and script-integrity posture are unverified.
3. **Action surface:** UI code declares numerous mutation routes. Authorization, CSRF enforcement, idempotency, audit logging, and server-side method checks need source review. No actions were called.
4. **Health route:** one visit displayed “last run: just now.” Whether access triggers checks or writes a health record is unknown; treat that route as unverified.
5. **Data model:** page shells do not reveal actual table columns or rows. Database schema, foreign keys, data lifecycle, privacy controls, and tenant boundaries remain unknown.
6. **Delivery correctness:** PHPMailer is user-reported; campaign-to-queue-to-MTA-to-bounce/unsubscribe paths are not verified.
7. **Infrastructure:** SSH server identity is not trusted yet. No backend source, database schema, service configuration, or logs were read remotely.

## Phase 1 investigation snapshot

The table and next-evidence gate below record the state at the end of Phase 1 only. For current SSH/backend status, use [PROGRESS.md](PROGRESS.md) and [REFERENCE_AUDIT_FINAL_REPORT.md](REFERENCE_AUDIT_FINAL_REPORT.md).

| Area | Status |
|---|---|
| Proxy-authenticated frontend reachability | Confirmed in the preceding access check; not repeated here. |
| DMS identity and authenticated dashboard | Confirmed. |
| Frontend module/navigation inventory | Partially mapped from authenticated HTML. |
| Session/security headers | Partially observed; no penetration or mutation testing. |
| Campaign, list, domain, server, PMTA action map | Static frontend candidates identified; none invoked. |
| Table contents and record-level business logic | Unverified; data-loading operations were not called. |
| Backend source, API route implementation, database schema | **Phase 1:** blocked pending SSH host-key verification. **Current:** source inspected; live DB metadata remains blocked. |
| PHPMailer implementation | **Phase 1:** user-reported; source verification pending. **Current:** discrepancy remains unresolved after source/package search. |
| Infrastructure, scheduler/queue configuration, DNS, SMTP, service state | **Phase 1:** unverified pending trusted SSH access. **Current:** selected source and host metadata inspected; live PMTA/provider config remains unknown. |
| Billing and tenant/role model | No billing entry visible; role and tenant model unverified. |
| AlphaLink design or technology decisions | Not started, as requested. |

**Phase 1 next-evidence gate (completed):** verify the SSH host key from the trusted fingerprint, then inspect backend source under strict read-only controls. The current remaining evidence blockers are listed in [PROGRESS.md](PROGRESS.md); do not repeat this Phase 1 gate or follow its historical status as current.

