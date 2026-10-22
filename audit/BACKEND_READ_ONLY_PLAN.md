# DMS Backend Inspection Plan — Read Only, Host-Key Gated

**Purpose:** Record the bounded read-only backend procedure and its outcome. **Execution status (2026-10-20):** direct SSH host identity was rechecked against the trusted ED25519 fingerprint before authentication, then strict direct SSH sessions were used for bounded source/config/host metadata reads. The SSH account is UID 0. Selected Phase 2 reads are documented in [PROGRESS.md](PROGRESS.md). No database query, production row read, application script/job, endpoint, sender, PMTA/provider action, scheduler, or service control was run. The earlier proxy-routed CONNECT 403 is historical and is not the current backend blocker.

## 0. Mandatory identity gate

1. The expected ED25519 SHA-256 fingerprint was supplied from the reference server administrative console. An in-memory comparison confirmed that the `.env` pin matches. A direct unauthenticated key scan of the exact `.env` endpoint returned the same ED25519 fingerprint. The trusted value is not duplicated in this plan; no credential value is recorded.
2. The configured target was the SSH endpoint in `.env` on its configured port. The earlier proxy-routed CONNECT attempt returned HTTP 403 before SSH key exchange; that result is historical because the user has now explicitly authorized direct SSH. The endpoint value is omitted from this local report.
3. Connect directly to the `.env` endpoint without ProxyCommand or ProxyJump. Pin the observed key in a dedicated local `known_hosts` file only after its exact fingerprint comparison. Use `StrictHostKeyChecking=yes` and `HostKeyAlgorithms=ssh-ed25519` for authentication.
4. If the key differs at the next connection, stop without sending credentials. Do not disable strict checking or trust any unverified key. Do not put passwords or private-key material in command arguments or logs; use the existing protected authentication mechanism.

## 1. Session limits and evidence handling

- Use one verified SSH session and a named, allowlisted set of read-only commands. No `sudo`, shell write, remote editor, SCP/SFTP upload, redirection, `tee`, package manager, or remote script execution.
- Do not run application bootstrap commands, framework CLIs, tests, migrations, queue workers, schedulers, health checks, SMTP clients, or integration clients.
- Do not read or print raw `.env`, credential stores, private keys, SMTP passwords, API tokens, session secrets, recipient addresses, message bodies, logs containing personal data, or production table rows.
- For source/config findings, retain local notes containing path, line number, claim, confidence, and a sanitized excerpt only. Redact literal values for secret-bearing keys and personal/customer data. Hash inspected files when useful; do not copy the application tree into AlphaLink.
- If a path, command, route, config, or output may have side effects or expose secrets, skip it and mark it **UNVERIFIED**.

### Permitted read-only command allowlist

Phase 2 used selected commands from this list: host/runtime metadata, source filename and line-range inspection, Composer metadata, PHP version/module listing, sanitized cron/timer/service metadata. Remote output was bounded and secret-bearing values were not printed. The database metadata section below was **not run** because no independently verified least-privilege DB identity was established.

Only after the identity gate, use narrowly scoped commands such as:

- Host/runtime metadata: `hostname`, `id`, `uname -a`, `cat /etc/os-release`, and `pwd`.
- Confirmed source inventory: `find <confirmed-app-root> -maxdepth 2 -type f` or `rg --files <confirmed-app-root>`; inspect a specific file only after its path is established.
- PHP metadata: `php -v` and `php -m` (no application script execution).
- Dependency and call-site searches: `rg -n` over confirmed source files for `phpmailer/phpmailer`, `PHPMailer`, route declarations, queue dispatch/job names, tracking terms, and integration names. Filter/redact output locally before it reaches the report; do not print matching lines containing secrets or customer data.
- Scheduler metadata: `crontab -l`, `systemctl list-timers --all`, and targeted reads of confirmed cron/supervisor/systemd configuration files, with command arguments and secret-bearing values redacted. These are listings/reads only; no listed command may be run.
- Database metadata, only with an independently confirmed read-only account. DMS source/config evidence identifies PostgreSQL, so the bounded plan is read-only `SELECT` against `information_schema.columns`, `information_schema.table_constraints`, `information_schema.key_column_usage`, `information_schema.referential_constraints`, and `pg_catalog.pg_indexes`, restricted to the confirmed application schema. Record table/column types, nullability, primary/foreign keys, and index definitions only. Do not query application rows or population counts. The database identity and grants must be verified before connecting; no such identity was established in this audit, so these queries were not run.

Do not substitute framework commands such as `php artisan route:list`, `schedule:list`, migrations, or cache commands: they bootstrap application code and are outside this allowlist.

## 2. Identify the runtime and source layout

After host verification, first establish only host identity and basic operating-system context using read-only system metadata (for example, `hostname`, `id`, `uname -a`, and `/etc/os-release`). Discover the deployed application root from existing web-server configuration/process metadata or provider documentation; do not guess paths or execute application code.

Within the confirmed source root, read directory listings and dependency manifests. Record:

- PHP CLI/FPM version and enabled module names from version/module metadata, without dumping environment values.
- Framework/runtime indicators from `composer.json`, `composer.lock`, and existing source layout; do not run Composer.
- Web root and front-controller/config file names; do not run PHP against the application.
- PHPMailer package/version from the lock/install metadata, if present.

Safe source inspection is limited to text reads/searches of identified files. Avoid broad recursive output that could reveal embedded secrets or user data. First search filenames and symbol names, then inspect only relevant line ranges through a redacting display path.

## 3. Trace PHP, PHPMailer, and API controllers

Read route definitions/specifications, controllers, middleware, request validation, service classes, and relevant model/job call sites. Build a static route map containing method, route template, controller, middleware/authorization, and side-effect classification. Do not request the route over HTTP.

For PHPMailer, locate package metadata and code references to construction/configuration, transport selection, authentication, `send()` calls, exception handling, and wrapper services. Record code paths and non-secret option names only. Do not reveal hostnames or literal values that are credentials, sender/customer data, or infrastructure secrets. Trace whether the send call occurs inline or behind a queue dispatch.

Review authentication/authorization and tenant boundaries statically: route middleware, role checks, policies, CSRF/session settings, validation, idempotency, audit logging, and transaction boundaries. Treat configuration names as evidence only; do not infer runtime enforcement without the matching code path.

## 4. Database schema metadata only

Determine the database engine and schema name from sanitized configuration key names and manifests. Do not print connection strings, usernames, passwords, tokens, or full environment blocks.

Only if an explicitly read-only database account is available, inspect metadata with `SELECT` statements against the engine’s information-schema/catalog tables for table names, column names/types/nullability, primary keys, indexes, and foreign-key relationships. Use a least-privilege account with no write grants. Do not select application rows, counts that expose production populations, samples, or message/subscriber data. Do not run DDL, migrations, repair, analyze, or maintenance commands. If no read-only database identity can be confirmed, inspect schema definitions in source/migration files and mark live schema metadata **UNVERIFIED**.

## 5. Campaign and sending architecture

Static-trace the path through campaign controller/service/model/state transitions, recipient selection, personalization/rendering, approval/scheduling, queue dispatch, job retry/backoff, PHPMailer transport, SMTP/PMTA handoff, and failure/bounce state updates. Record:

- Whether campaigns are synchronous, queued, scheduled, or mixed, based on code.
- State names and permitted transitions, deduplication/idempotency keys, chunking and throttling logic, and retry/failure policy.
- How lists, exclusions, suppressions, segments, and sender/domain/IP selection are applied.

Do not invoke send/test/preview actions, generate a message body, resolve recipient data, run jobs, inspect live queue payloads, or contact SMTP/PMTA.

## 6. Queue, scheduler, cron, and service configuration

Read queue connection/configuration files and job classes, then inspect existing worker/supervisor/systemd/cron configuration as text or read-only listings. Normalize command paths and redact arguments that may contain secrets. Record worker count/configuration, queue names, schedule expressions, locking, retry policy, and service relationships only where source evidence supports them.

Do not run `php artisan schedule:*`, worker commands, cron commands, supervisors, service reload/restart/status hooks with side effects, scheduler tasks, or any mail queue operation. Listing a configured schedule is not permission to execute it. Do not inspect live queue payloads or production messages.

## 7. Tracking and delivery-event pipeline

Trace code and route definitions for open pixels, click redirects, unsubscribe/preferences, complaint feedback, bounce processing, suppression, campaign attribution, and affiliate conversion callbacks. Inspect relevant controllers, middleware, persistence calls, event/job classes, and deduplication logic statically.

Do not call tracking, unsubscribe, bounce, postback, webhooks, or callback URLs. Such requests can mutate subscriber/event state even when they use GET.

## 8. Integrations and infrastructure

Search source/manifests and sanitized configuration key names for HitPath, affiliate networks, offers/sponsors, domain registrars, cloud/server providers, blacklist tools, analytics, and other external services. Map adapter classes, route/controller callers, webhook/postback handlers, and error/retry behavior without making external requests.

For mail and server infrastructure, inspect only confirmed relevant configuration paths for web server, PHP-FPM, queue supervision, MTA/PMTA, SMTP, and DNS/authentication generation. Record sanitized topology and directive names; redact all credentials, private keys, tokens, user/recipient addresses, and exact infrastructure values unless they are explicitly non-secret and required for the audit.

Do not run provider APIs, DNS updates, server provisioning/reconfiguration, PMTA commands, firewall changes, service commands, health checks, certificate actions, or external verification tools.

## 9. Completion criteria and stop rules

For each finding, tag it **OBSERVED**, **DERIVED**, **INFERRED**, or **UNVERIFIED**, identify the evidence path/line or schema object, and state its coverage limit. Stop rather than extrapolate where source or safe read-only access is missing.

The backend audit may proceed only after the independent host-key gate. It is complete only when the PHP/PHPMailer, API/controller, schema metadata, campaign/send, queue/scheduler, tracking, integration, and infrastructure questions above have evidence or explicit **UNVERIFIED** dispositions. No AlphaLink design or technology choice is part of this phase.
