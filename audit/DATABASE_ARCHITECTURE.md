# DMS Database Architecture

**Status: PARTIAL — PostgreSQL configuration and migration-defined schema are verified; live catalog metadata and server version are blocked.** No database connection or production row query was made.

## Engine and verification boundary

- The deployed application `.env` exposes the non-secret setting `DB_CONNECTION=pgsql`; `/var/app/config/database.php` also defaults to `pgsql`. PostgreSQL is therefore **VERIFIED as the configured engine selection**.
- Application queries use PostgreSQL-specific constructs such as JSONB containment (`@>`, `::jsonb`) and aggregate `FILTER (WHERE ...)`, corroborating the configuration.
- PostgreSQL server version, actual database/schema name, current live migration state, grants, and runtime connection identity are **UNKNOWN**. No `DB_READONLY_*`/read-only credential variable name was found in the app `.env` key-name search. Because a dedicated least-privilege identity was not established, the live database was not contacted.
- The schema below is from deployed Laravel migration source, not the live catalog. It may differ from the database after untracked/manual migrations.

## Schema domains visible in migrations

| Domain | Tables and key structure | Evidence / confidence |
|---|---|---|
| Users and access | `users` (type, role JSON, active state, timestamps); user email tests, personal access tokens, failed jobs, headers, negatives, and global-test logs. | **OBSERVED** in `/var/app/database/migrations/`. User/role rows were not queried. |
| Lists and recipients | `lists` belongs to an ISP and stores access lists, sponsor access, activation and per-state locks, and tracking flags. `emails` stores recipient email/hash, first/last name, country, age, vertical, sex, lifecycle type, excluded-offer arrays, and `lists_id`. `virtual_lists` stores a file path plus JSON mix-list/parameter data. | **OBSERVED** in `create_lists`, `create_emails`, and `create_virtual_lists` migrations. Recipient data was not read. |
| Signup/contact | Separate `subscribers` rows contain email, IP, country/state/city, device/platform/browser/mobile, and timestamps; `contact_us` has a separate schema. The subscriber migration has no list/tenant or explicit consent-status field. | **OBSERVED** in migration source; lifecycle/consent use is not established. |
| Campaigns and event logs | `campaigns` contains ISP/offer/user IDs; JSONB list/from/subject/server/IP/placeholder/send-parameter fields; header/body/return-path/domain; send type/status/progress/count/PID; schedule/active/restricted state; and bounce limit. `campaign_logs` holds typed JSON history. | **OBSERVED** in `create_campaigns_table` and `create_campaign_logs_table`, with later campaign migrations adding blacklisted-count, campaign-type, and duplicate-data fields. |
| Drops and warmups | `drops` parallels campaign content/routing plus sponsor/offer, GEO countries, JSON filters/list selection, send state, schedule, and restrictions. `seed_campaigns`, `seed_lists`, and `seed_emails` support warmup/seed workflows. | **OBSERVED** in corresponding migrations. |
| Events and analytics | `visitors` and `drop_visitors` record event type, revenue/revenue ID, IP/geo/device, campaign/drop and recipient IDs, send/list/ISP/server/IP/domain/from/subject/creative dimensions. `email_logs` stores token/action, recipient, offer/ISP, geo/device data and has a unique token index. `statistics`, `statistics_drops`, and `statistics_seeds` store daily aggregates. | **OBSERVED** in migration source. No event rows or counts were retrieved. |
| Offers and affiliate network | `sponsors` stores provider type, URL, affiliate ID, allowed-user JSON and secret-bearing API-key/password fields; `offers` stores external offer ID, vertical, GEO target, payout, redirect/unsubscribe text, suppression-file metadata, access list, status and sponsor relationship. `assets` stores type/value and offer relationship. | **OBSERVED** as field names only. Secret values and offer/provider rows were not read. |
| Sending infrastructure | `servers`, `servers_providers`, `cloud_providers`, `domains_providers`, `domains`, `ips`, `mtas`, and server/domain/IP/MTA/PMTA history tables hold inventory and relationships. `servers` includes secret-bearing credential fields, install parameters, access scope, status and expiry. | **OBSERVED** as migration-defined structure only. No infrastructure row values were read. |
| Projects and accounts | `projects`, `accounts`, and Outlook group/account tables support Gmail/Outlook-linked workflows. `sub_accounts` links account and project. | **OBSERVED** in migrations; workflow details remain partial. |
| SPF analysis | Earlier SPF records were removed/recreated; later migrations add `spfs`, include/network/scan/missing tables and rebuild an SPF graph (`spf_roots`, `spf_hosts`, network/include edges, DNS checks). | **OBSERVED** from migration history through September 2026. The migration chain has both removal/recreation and graph rebuild steps; current live state is unverified. |

## Relationships, constraints, and storage patterns

- Migration source contains 34 `constrained()` calls and two explicit `foreign()` declarations. Several use cascade-on-delete/update behavior. Other identifier columns are indexed without a declared database foreign key; the code also relies on Eloquent relationships and JSONB arrays. This is a mixed enforcement model, not a fully normalized relational graph.
- Eloquent source relates Campaign to User, ISP, Offer, logs and visitors; Email to List and event logs; List to ISP; Visitor to Campaign; DropVisitor to Drop; and Statistics to Campaign, user, ISP, list, server, IP, domain, sponsor, offer and creative assets.
- Audience selection is partly relational (`emails.lists_id`) and partly denormalized into JSONB campaign/list/mix fields and file snapshots. Campaign creation builds a static recipient file; see [CAMPAIGN_ORCHESTRATION.md](CAMPAIGN_ORCHESTRATION.md).
- Migration-defined event rows carry numerous dimension IDs, but only selected campaign/drop links are declared as foreign constraints. Email-log uniqueness is on `token`; visitor event rows have no unique event key in the inspected migration. See [TRACKING_AND_ANALYTICS.md](TRACKING_AND_ANALYTICS.md).
- Schema evolution is Laravel timestamped migrations from 2014 through 2026. No migration command was run. The source set includes `failed_jobs`, but no `jobs` table creation was found in the inspected migration table declarations.

## Queue metadata (source/config only)

`config/queue.php` defaults to Redis and defines Redis queues for `default`, `long_running`, and `schedule` with retry-after values 90, 1200, and 90 seconds respectively; all listed connections have `after_commit=false`. There is no `QUEUE_CONNECTION` line in the application `.env` result, but process-level overrides were not inspected. Thus Redis is the configured default, while the active runtime connection is **INFERRED, not verified**. No queue contents, failed-job rows, or live worker data were read.

## Open questions and safe boundary

Live table/column/index/FK metadata, actual PostgreSQL version, migration currentness, read-only DB identity, data retention/cleanup, transaction boundaries, and production queue backend remain **UNKNOWN/BLOCKED**. If a separately authorized read-only database identity becomes available, use catalog metadata only. Until then, no database query is justified; migration source is the evidence ceiling. No customer rows, recipient lists, aggregate counts, or message content were accessed.
