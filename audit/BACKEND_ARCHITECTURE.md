# DMS Backend Architecture

**Status: Source-level architecture mapped with explicit production-state limits.** All findings below come from static source/configuration or bounded runtime metadata. No DMS application action or database query was executed.

## Runtime and project

- **OBSERVED:** Linux host reports kernel `4.18.0-513.11.1.el8_9.x86_64`; PHP CLI and FPM report **8.1.27**. Nginx uses `/var/app/public` with FastCGI socket `/run/php-fpm/www.sock`.
- **OBSERVED:** Laravel project `emailing-solution/app`, PHP requirement `^8.1`, locked Laravel Framework `10.38.2`, Horizon `5.21.4`, Sanctum `3.3.2`, Yajra DataTables `10.11.2`, and 156 Composer runtime packages. The source tree follows Laravel conventions: `app/`, `routes/`, `config/`, `database/migrations/`, `resources/`, `public/`, and `vendor/`.
- The CLI module set includes PostgreSQL/PDO PostgreSQL, Redis, OpenSSL, sockets, IMAP, curl and pcntl. Module availability does not establish runtime use.
- No application bootstrap, Artisan command, Composer command, test, migration, job, or PHP source script was run.

## Entry points, routing and middleware

- Nginx document root is the Laravel `public` directory; request dispatch then follows Laravel route registration in `/var/app/app/Providers/RouteServiceProvider.php:30-49`.
- `web.php` registers login/profile/logout, external callbacks, root redirect, and an ungrouped `/t` `TestController` route (`/var/app/routes/web.php:23-44`). `/t` behavior is **UNKNOWN** and it was not called.
- `dashboard.php`, `admin.php`, and `mailer.php` are grouped under `web` plus `auth`. Admin modules add `have-role` gates; mailer includes selected role-gated groups. Campaign controller paths also use owner authorization. See `/var/app/app/Providers/RouteServiceProvider.php:38-49`, `/var/app/routes/admin.php`, `/var/app/routes/mailer.php`, and the topic reports.
- The `web` middleware group includes cookies, sessions, session authentication, CSRF, route binding, HTTP logging, inactive-user handling, and IP restrictions (`app/Http/Kernel.php:60-79`). Authenticated page reads can therefore have incidental `seen_at`/logging effects; see the audit caveat in [PROGRESS.md](PROGRESS.md).
- API routes are under `/api/v1`; default API throttling is commented out in `Kernel.php` and `RouteServiceProvider.php`. Explicit per-route throttles are present for subscribe, opt-out, and contact only. Other handlers use a shared application key in relevant source paths.
- `AuthServiceProvider` defines user type/role and owner/allowed-user gates. Admin bypass behavior is present in the role gate implementation. Static gate existence is not proof of tenant isolation; many models store user IDs and allowlists, but cross-tenant access was not tested.

## Main modules and boundaries

| Module | Observed implementation boundary |
|---|---|
| Identity/admin | Laravel auth/session/CSRF middleware; role gates in `AuthServiceProvider`; user, ISP, settings, domain/server/provider and offer management routes. |
| Audience | Eloquent `Email`/`Lists`/`Subscriber`; recipient types and list/offer exclusions; segmentation actions; file-backed virtual mixes and campaign snapshots. |
| Campaigns/drops/warmups | Controllers, actions, models and queued schedule jobs construct file-based send inputs and launch an external sender. Campaign/drop/warmup state is tracked in PostgreSQL-configured models. |
| Analytics/tracking | Public API handlers and redirect/stat routes dispatch jobs; visitor/email-log models store events; console commands aggregate statistics. |
| Mail/server control plane | PMTA controller/commands, phpseclib SFTP adapter, server/cloud/domain provider managers, and provisioning/configuration workflows. |
| Affiliate offers | Sponsor manager factory selects five adapters; a scheduled command fetches conversions and writes lead events. |
| Utility integrations | Route families for SPF, shortlinks, Firebase, Workspace/Outlook, image hosting, callbacks, blacklist/SpamAssassin and other tools. Live configuration unknown. |

These are code ownership areas, not isolated services. The inspected application is a single Laravel deployment with shared configuration and a PostgreSQL-configured database; no microservice or tenant-database boundary was established.

## Queue and scheduled execution

- Queue configuration defaults to Redis, with `default`, `long_running`, and `schedule` Redis connections and `after_commit=false`. Horizon config has one-process defaults, `tries=1`, and production maximums 30/3/2. Active environment, queue connection override, supervisor program definitions, and live queue state are not verified.
- Root crontab metadata confirms a `schedule:run` entry every minute, without disclosing command arguments. Laravel Console Kernel defines recurring jobs from one-minute to weekly; dynamic PMTA scheduling reads `PmtaScheduler` records at schedule construction (`app/Console/Kernel.php:60-140`).
- Nginx, PHP-FPM, Redis and Supervisor reported active. This is a point-in-time service-state observation, not a workload/process or health check. No service command changed state.
- Scheduled commands include statistics/cache aggregation, PMTA log ingestion, sponsor conversion sync, domain/server/offer maintenance, SPF, backups, and PMTA actions. Some are explicitly mutating and were not run.

## Data and campaign flow (source-derived)

Campaign creation validates audience/content/routing, constructs suppression, and writes a point-in-time recipient file. Sending builds a base64 JSON runtime config, then uses a shell launcher to start `storage/app/engine/send` with a validated mode; schedule jobs later revalidate state. PMTA logs are periodically moved/parsed into delivery/bounce statistics and recipient-state jobs. Tracking endpoints dispatch visitor/log jobs; aggregate commands update daily statistics. Sponsor conversion reports become lead events. See [CAMPAIGN_ORCHESTRATION.md](CAMPAIGN_ORCHESTRATION.md) and [TRACKING_AND_ANALYTICS.md](TRACKING_AND_ANALYTICS.md).

The DMS data/config boundary is Laravel migrations and models plus file-backed send/mix/suppression artifacts, Redis-configured queues/cache, and external PMTA/provider APIs. This is a **source-derived model**; live database schema and active runtime settings were not verified.

## Error handling, logs and unresolved controls

- Campaign process output is redirected to `/dev/null`; PHP captures a PID and persisted state. Process completion/result reconciliation remains unknown.
- Several queue jobs define retries/uniqueness, but retry and idempotency are inconsistent across inspected event jobs. Exact job coverage is in the topic reports.
- Laravel exception/reporting and Spatie HTTP logging packages are present; production log content was not read. Domain adapters may log remote response bodies and submitted data on failure.
- The application has a configured custom raw SMTP helper, external sender ELF binaries, and user-confirmed PHPMailer use, but the relationship between them is unresolved.
- No live DB catalog, least-privilege database grants, application role matrix validation, active worker config, PMTA files, DNS values, or provider settings were accessed.

## Source locations and confidence

Primary paths: `/var/app/app/Providers/RouteServiceProvider.php`, `/var/app/routes/{web,api,admin,mailer,dashboard}.php`, `/var/app/app/Http/Kernel.php`, `/var/app/app/Providers/AuthServiceProvider.php`, `/var/app/app/Console/Kernel.php`, `/var/app/config/{database,queue,horizon,system}.php`, `/var/app/database/migrations/`, and the campaign/tracking/integration classes linked from topic reports.

**VERIFIED/OBSERVED** means visible in source or non-secret config. **DERIVED** means an explicit static call/data relationship. **INFERRED** means runtime behavior depends on environment or uninspected sender code. **UNKNOWN/BLOCKED** marks the evidence ceiling. No AlphaLink technology or architecture decision is made here.
