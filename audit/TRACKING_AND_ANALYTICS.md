# DMS Tracking and Analytics

**Status: Source paths mapped statically; no tracking/action endpoint, event row, job, or analytics command was invoked.** No recipient records, message content, production event rows, or live statistics were read.

## API surfaces and mutation boundary

`/var/app/routes/api.php:23-36` declares `POST /api/v1` routes:

| Route family | Handler | Static behavior |
|---|---|---|
| `subscribe`, `opt-out`, `contact` | `LandingHandlerController` | Creates subscriber/contact data or changes recipient state. Each is throttled at `2,1`. **Mutating.** |
| `campaign`, `drop` | `RedirectHandlerController` | Resolves campaign/drop offer links and may dispatch recipient-type and email-log jobs for proxy/hosting or GEO decisions. **Potentially mutating.** |
| `stats`, `stats-drop` | `RedirectHandlerController` | Accepts open/click/unsubscribe/bot event inputs and queues event jobs. **Mutating.** |
| `bounce-limit-campaign`, `bounce-limit-drop`, `bounce-limit-warmup` | `BounceHandlerController` | Validates a shared configured key and reads one target’s bounce threshold; no bounce event is ingested in these methods. |

The API middleware group contains route binding only; the framework `throttle:api` entry is commented out. Only the landing routes show explicit route throttling. No API route was requested.

## Redirect and tracking flow

- Campaign/drop redirect handlers validate the shared application key, campaign/drop/recipient identifiers, token, request IP/user-agent and a redirect/unsubscribe type. They enforce restricted/missing target checks, resolve offer/sponsor redirect or unsubscribe links, and apply GEO logic. Selected objects are cached until end-of-day. The handler may queue recipient-state and email-log work based on proxy/hosting or country decisions (`/var/app/app/Http/Controllers/Api/RedirectHandlerController.php:38-254`).
- Stats handlers accept `click`, `unsubscribe`, `open`, or `bot`, plus event and dimension fields, then dispatch `AddVisitorLogJob` or `AddDropVisitorLogJob` (`RedirectHandlerController.php:256-340`).
- Visitor jobs parse the request IP and user agent, skip opens from configured blocked organizations, write visitor/drop-visitor events, and dispatch corresponding recipient-state/email-log work. They allow up to five job tries (`/var/app/app/Jobs/AddVisitorLogJob.php:20-93`, `AddDropVisitorLogJob.php:20-94`).
- Recipient transitions include `fresh → delivery → open → click → unsubscribe` and special states such as `opt_out`, `proxy`, `bot`, `blacklist`, `hard_bounce`, and `lead`. Tracked lists can move recipients to country-specific lists; unsubscribe adds the offer to `excluded_offers` (`ChangeEmailTypeJob.php:24-124`, `ChangeDropEmailTypeJob.php:22-124`).
- Email-log persistence uses `insertOrIgnore`; the migration gives `email_logs.token` a unique index. Visitor rows have no unique event key in the inspected migrations, so retries can duplicate visitor rows while token-based logs can collapse multiple actions. `ChangeEmailTypeJob` implements `ShouldBeUnique`; the drop counterpart defines `uniqueId()` but does not implement that interface in the inspected source. This is a **DERIVED idempotency inconsistency**, not an observed duplicate.

## Subscribers, list segmentation and suppression

- `subscribe` writes a separate `Subscriber` record containing email, IP, geo and device/browser fields. Its migration has no list ID or explicit consent-status field. Whether a separate verification/promotion workflow exists is **UNKNOWN**.
- `opt-out` finds a recipient by email, adds geo information, dispatches an `opt_out` type change and an email-log write. It was not invoked.
- `EmailAction::create_user_mix` segments list-owned `emails` by lifecycle type, sex, creation date, email-domain extension, tracked device and included/excluded event/action/date filters. `EmailAction::suppression` excludes list/type recipients whose JSONB `excluded_offers` contains the current offer (`/var/app/app/Actions/EmailAction.php:21-110`).
- Campaign suppression materializes a point-in-time recipient file. See [CAMPAIGN_ORCHESTRATION.md](CAMPAIGN_ORCHESTRATION.md). No list, recipient or suppression row was read.

## Affiliate conversion attribution

`SponsorLead` runs every five minutes by schedule and fetches reports from active sponsor adapters in up to five concurrent processes. It parses provider sales fields and the app-generated `s2` attribution payload. Campaign conversion processing checks a decoded token component and de-duplicates on `revenue_id`; campaign and drop branches write lead visitor/revenue records, update recipient type, and write email logs. See `/var/app/app/Console/Commands/stats/SponsorLead.php:44-360` and [EXTERNAL_INTEGRATIONS.md](EXTERNAL_INTEGRATIONS.md). No affiliate API/report was requested and no conversion record was read.

## PMTA delivery and bounce feedback

`ServerProcessPmtaLogs` is scheduled every two minutes and processes up to five servers concurrently under a file-cache lock. Its source moves remote PMTA delivery/bounce files from treated to process directories, parses encoded campaign/drop/warmup dimensions, updates daily statistics, dispatches recipient-type changes for delivered/hard-bounced IDs, and archives processed files (`/var/app/app/Console/Commands/servers/ServerProcessPmtaLogs.php:38-69,74-184,187-285`). It classifies `bad-mailbox`, `bad-domain`, `quota-issues`, and `inactive-mailbox` as hard bounces; other parsed categories are aggregated as soft bounces. This command mutates remote files and DMS state; it was not run.

The separate `PmtaBounce` command reads PMTA bounce archive/process/treated files through the application SFTP adapter and masks recipients in its returned fields (`/var/app/app/Console/Commands/pmta/PmtaBounce.php:22-114`). It was not run. The API bounce-limit handler only returns thresholds.

PMTA CentOS 9 installer source writes `/fbl@<owned-domain>/` feedback-loop addresses and inbound mbox destinations. DMS's manual reply extractor downloads the mbox, parses only `From`, `Message-ID`, and date, looks up an existing email row by the sender address for the regular reply mix, and creates a `reply-<name>` virtual audience; the test path creates a test mix. The lookup accepts lifecycle types including `unsubscribe`, and its downstream use is not established. This source path does not parse ARF complaint fields, attribute a complaint to its reported original recipient, change recipient state, or apply complaint suppression. A bounded literal-term scan found complaint/FBL terminology only in the CentOS 9 installer among `/var/app/app`, `/var/app/routes`, and `/var/app/database`; `.git`, `vendor`, `node_modules`, `storage`, `public`, and `bootstrap` directories were excluded. Separately, the campaign virtual-list branch reads the saved mix rows without rechecking lifecycle state or `excluded_offers`, so an unsubscribe row may enter a campaign snapshot unless the selected offer's separate suppression file removes it; later native-sender filtering is unknown. Complaint ingestion and suppression therefore remain **UNKNOWN**; active PMTA routing and other deployments or external processors were not inspected.

## Aggregates and reporting

- `StatisticsCampaignsUpdate` groups visitor events by event date and user/campaign/ISP/list/server/IP/domain/sponsor/offer/from/subject/creative dimensions, then `updateOrCreate`s daily totals for opens, clicks, unsubscribes, bots, opt-outs and lead revenue. Current-day aggregate cache lifetime is 25 hours (`/var/app/app/Console/Commands/stats/StatisticsCampaignsUpdate.php:28-129`).
- `StatisticsDropsUpdate` performs analogous daily aggregation from `drop_visitors`; `StatisticsSeedsUpdate` refreshes warmup statistics. These commands are scheduled and were not run.
- PMTA delivery/bounce file ingestion updates separate daily campaign/drop/warmup counters with `updateOrCreate(...)->increment(...)`; reconciliation if files are retried or partially archived remains unknown.
- UI metrics were not compared to live rows; definitions, time zone and late correction behavior are only as shown in source.

## Safety and limits

No pixel/redirect/unsubscribe/bounce/postback URL, job, scheduled command, PMTA reader, provider API or report endpoint was invoked. All operations described as mutating are identified from source only. No production event, recipient, subscriber, conversion or aggregate data was queried.
