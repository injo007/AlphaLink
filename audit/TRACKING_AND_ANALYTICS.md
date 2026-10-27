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
- Visitor jobs parse device/platform/browser/mobile with Jenssegers Agent, resolve country/state/city through IpGeo, skip opens from configured blocked organizations, write visitor/drop-visitor rows, and synchronously dispatch recipient-state/email-log jobs after the visitor insert. Visitor jobs allow up to five retries (`/var/app/app/Jobs/AddVisitorLogJob.php:20-93`, `AddDropVisitorLogJob.php:20-94`). AlphaLink persists location and common-agent fields for ingested campaign and Drop events, applies the saved workspace `blocked_open_traffic` organization-substring filter to both event pipelines, and dispatches retryable five-attempt jobs before returning from the callback. Synthetic campaign and Drop tests verify matching opens are discarded before visitor/list-state writes while clicks remain accepted (E-TRACK-09). The small local parser and running queue-worker behavior remain unverified against DMS library/queue behavior.
- Recipient transitions include `fresh → delivery → open → click → unsubscribe` and special states such as `opt_out`, `proxy`, `bot`, `blacklist`, `hard_bounce`, and `lead`. Tracked lists can move recipients to a country-code list when that country is enabled on the ISP; the destination copies source activity, access, and recipient-type locks. SponsorLead marks campaign and Drop conversions as leads and passes conversion IP country into that move (`ChangeEmailTypeJob.php:24-124`, `ChangeDropEmailTypeJob.php:22-124`, `SponsorLead.php:156-172,243-259`). AlphaLink now moves the current workspace membership into the configured country-code List for campaign/Drop events and imported campaign/Drop leads. Later callbacks from immutable snapshots resolve the one current membership enforced by the import path; ambiguous rows with multiple active memberships are left untouched. The destination copies the DMS access/type-lock fields, while DMS global email uniqueness remains tenant-scoped in AlphaLink (E-TRACK-10). Unsubscribe adds the offer to `excluded_offers`.
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


### Dashboard All Stats source and target boundary — 2026-10-26

The captured DMS All Stats action (`/tmp/dms-rea-refresh-20261022/source/app/Actions/DashboardAction.php:883-963`) unions campaign, Drop and seed daily aggregates and groups by date, user, ISP, list/type, server, IP, domain, sponsor, offer, From, Subject and creative; the dashboard Blade exposes the pivot and rates. AlphaLink's current All Stats table resolves dimensions backed by stored workspace event/conversion rows but does not recreate DMS seed/daily inputs, delivery/bounce measures, calculated rates, pivot chooser or `share_stats` behavior. The DMS campaign body-generation helper (`app/Actions/CampaignAction.php:519-561`) builds a preview token with zero send and recipient IDs; its controller caller serves body generation and does not establish the live sender's per-recipient token contract. The user's AlphaLink multi-list fan-out policy is resolved in E-DB-03, but DMS sender token/callback mapping, runtime event delivery, and live campaign attribution remain UNKNOWN until that sender path is captured and connected.


### DMS sender artifact boundary — E-TRACK-08

**Observed artifact:** `/tmp/dms-rea-refresh-20261022/send-send.dll`, SHA-256 `92d5332ea4f10e87302c418ac25b4245f6c68cc9544ae1e8b0f8ccb9b74c3564`, 711,680 bytes. REA Evidence `ev_0d785eb8dad4350da57c3084ce01d89b053e9a4925f41acc6ed461d7a4231607` identifies assembly `send` 1.0.0.0 as .NET 7 CIL, x86_64, MVID `2bea6143-dcf2-4ea0-5004-7876afa8b989`; metadata is complete. Managed-member Evidence `ev_5e77b4dd0fab5bbe1de474f01ce0aa214f766122c6855d04687f0b6b4dd2b82a` covers 396 type rows, 2,089 methods, 44,638 call-edge rows, 22,254 field-access rows, and nine user strings. Only eight type names in the complete type page were printable ASCII; the user-string values were opaque.

**Limit:** REA inspected metadata and managed-member relationships without loading or executing the assembly; its analysis did not decompile method bodies to source. These observations do not establish that the sender lacks tracking behavior. The per-recipient token generation and callback linkage remain UNKNOWN. No DMS route, send, or product action was invoked.

### Search Emails event stream — E-AUD-11 — 2026-10-26

Read-only DMS `EmailController::logs` returns the recipient's related EmailLog rows through DataTables. The captured `AddDropEmailLogJobs` path records Drop event type, token, offer, IP, geo/device/browser details and timestamp. AlphaLink's workspace contact logs now combine campaign engagement rows, Drop visitor rows (including sponsor-attributed Drop leads), and campaign lead conversions; Drop token search stores/matches SHA-256 only. Synthetic tests verify paging, IP search, counts, both lead sources and Drop-token lookup. The additive hash migration ran on the local AlphaLink database; authenticated AlphaLink browser verification loaded only the empty Search Emails index. No contact search or DMS data-bearing request was made. Sender token/callback generation, DMS identifiers/permissions and full event enrichment remain unknown or different. Keep DMS-AUD-004 TESTING and continue dashboard/event-data work before returning to Suppression.

### Dashboard and event/list foundation regression — E-TRACK-11 — 2026-10-26

Read-only DMS `/api/v1/stats` and `/stats-drop` validate the event action and campaign/Drop dimensions, enqueue five-retry visitor jobs, and return HTTP 204. DMS accepts open, click, unsubscribe and bot visitor actions; SponsorLead separately supplies lead conversions. AlphaLink's `php artisan dev:list` includes the framework `queue:listen` process, but the app and worker are currently stopped. A disposable PHP 8.3 run with synthetic configuration and an in-memory SQLite database passed **64 tests / 600 assertions** across campaign and Drop tracking, campaign lead reconciliation, dashboard reports, campaign statistics, campaign visitors, Search Emails, List Management and List History. The All Stats pivot's Node tests pass **2/2** and `npm run build` succeeds. These runs did not send mail or call providers.

REA follow-up views of `send-send.dll` returned nine opaque `#US` strings (`ev_848b7e264f988fdbf915d13a7317f47addfdaee16771989397a5004ca38fd330`) and sampled control-character type names (`ev_ab283b9450a3882d1a743c04a6592cbb797d79d34cbaa2dd9b0df383fe69e835`). The artifact was not executed. This still does not establish how the sender generates a per-recipient token or calls the stats endpoints. The target's callback, queue-worker runtime and populated browser behavior remain unverified; dashboard/list features stay TESTING and Suppression stays queued behind populated, verified opens, clicks, leads and unsubscribes.

### Dashboard and list browser verification — E-DASH-14 / E-AUD-15 — 2026-10-26

The dashboard and list surfaces were rechecked in AlphaLink using an isolated temporary SQLite workspace and synthetic daily list-history values. Main, All Stats, ISP Stats and Mailer Revenue rendered their controls and explicit empty states; the Lists grid and List History rendered the synthetic open/click/lead/unsubscribe and backed membership counters. The temporary PHP router was corrected to serve static assets directly; no application source, saved `.env`, or saved database was changed. `WorkspaceAnalyticsDashboardTest`, `CampaignTrackingTest`, `CampaignLeadReconciliationTest`, `DropTrackingTest`, `DmsAudienceListHistoryTest`, `DmsAudienceListManagementTest`, and `AudienceListImportTest` pass **63 tests / 493 assertions**. This is rendering and synthetic query evidence only. It does not verify live sender attribution or queue-worker execution; do not resume Suppression until connected event and conversion inputs populate the lists.


### Opt-out membership history counts — E-AUD-16 — 2026-10-26

Read-only DMS EmailAction::get_email_counts and ListCalculateHistory establish that the hourly, date-keyed list snapshot counts all 11 current email types. AlphaLink already stores opt_out as a possible membership state, but the history calculator classified it as unavailable. AlphaLink now initializes opt_out to zero, counts stored membership rows, returns the value from List History, and exposes it in the Lists grid after the normal daily recalculation. Existing nullable snapshot rows still show NA until recalculated; no historical backfill was run.

The public DMS opt-out input remains a separate missing route (E-PUB-01). This change makes saved opt_out states reportable; it does not claim that an external opt-out request can yet create those states. The focused PHP 8.3 synthetic dashboard/tracking/list regression passes 64 tests / 496 assertions, and scoped Pint passes. No browser check was performed in this turn, no saved AlphaLink records were used, and DMS stayed read-only. DMS-AUD-002 remains TESTING.
### Sender network-call inspection — E-TRACK-14 — 2026-10-26

The complete retained REA managed metadata for `send-send.dll` includes opaque methods that call low-level `TcpClient.Connect(string,int)`, `GetStream`, `Close`, and `NetworkStream.Flush`. The managed inventory did not expose readable HTTP/SMTP client or callback names. This evidence narrows the implementation shape to low-level socket use, but cannot establish whether or how a campaign token reaches `/api/v1/stats`, how recipient events are mapped, or how callback failures behave. The DLL was not loaded or executed; no reference action, send, or state change occurred. Keep the native sender callback contract UNKNOWN and dashboard/tracking TESTING.
### Daily dashboard statistics persistence — E-DASH-15 — 2026-10-26

Read-only DMS `StatisticsCampaignsUpdate` and `StatisticsDropsUpdate` run every minute with non-overlap/background scheduling. Each command rebuilds daily rows for a default lookback spanning yesterday and today, grouped by send dimensions, and upserts open/click/unsubscribe/bot/opt-out/revenue totals; seed statistics are updated by a parallel scheduled command. AlphaLink Main currently aggregates raw campaign events, Drop visitors and attributed campaign conversions at query time, then caches the result; no persistent campaign/Drop daily statistics table or equivalent scheduled row refresh is present. This is a material persistence/freshness difference. The DMS command was not executed and no reference rows were accessed.


### All Stats CTOR/EPC summaries — E-DASH-18 — 2026-10-26

Read-only DMS `resources/views/dashboard/stats.blade.php:224-249` defines CTOR as clicks divided by opens, with zero when opens are zero, and EPC as earnings divided by clicks, with zero when clicks are zero. AlphaLink now implements those custom pivot summaries and tests both nonzero and zero-denominator cases. CTOR uses the same inputs and formula. AlphaLink's EPC uses attributed campaign/Drop lead revenue because its current All Stats query does not expose DMS daily `earns`; it is therefore a clearly labeled partial data proxy, not confirmed earnings parity. Node tests pass 3/3 and Vite builds. A focused strict Premium audit of the changed JavaScript reports 0 findings; scanning the whole DMS-reference asset tree reports 8 existing findings in unchanged `script.js`, `jstree.min.js`, and `theme.css`. The app on port 8766 refused connections, so no browser parity check ran. DMS remained read-only; no production rows, PMTA servers, or sends were accessed. Keep DMS-DASH-002 TESTING; delivery-based rates and full earnings remain unavailable.


### Persisted daily engagement rollup — E-DASH-20 — 2026-10-26

AlphaLink now mirrors the DMS minute-level daily recalculation for the available target event inputs. WorkspaceDailyAnalyticsRebuilder groups campaign tracking events, CampaignLeadConversion rows, and Drop visitor rows by date, owner, campaign/Drop, ISP, source List/type, server, IP, domain, sponsor, offer, From, Subject, and Creative dimensions, then upserts workspace_daily_analytics. The scheduled Main dashboard warmer runs the rebuild before warming member-scoped cache values. Main reads persisted daily opens/clicks/leads/unsubscribes and lead revenue. This target table does not claim DMS PMTA delivery/bounce, seed, opt-out or full earnings values; the other reporting pages still use raw event/conversion sources. The focused synthetic regression passes 71 tests / 593 assertions. The saved app key and browser runtime remain unavailable, so no live event or saved-workspace parity check is claimed.


### Daily aggregates across Dashboard reports — E-DASH-21 — 2026-10-26

All Stats, ISP Stats, Mailer Revenue and Campaign Statistics now query the persisted `workspace_daily_analytics` rows, matching the DMS use of precomputed daily statistics for available local event inputs. Workspace and operator filters remain on the aggregate query; All Stats continues to expand positive bucket metrics into event-type rows, while ISP, mailer and campaign reports sum their relevant daily measures. `workspace:analytics-rebuild-daily --all` provides an explicit first-time backfill from the earliest stored campaign, lead or Drop event; normal minute recalculation remains limited to today and yesterday. This does not supply DMS PMTA delivery/bounce, seed, opt-out or full earnings data. The focused regression passes 74 tests / 607 assertions. No live browser or saved-workspace verification was possible because APP_KEY is empty and port 8766 is unavailable.

### Tracker bundle retrieval follow-up — E-TRACK-16 — 2026-10-26

The authenticated read-only DMS session returned HTTP 404 for four candidate `mini_app.zip` URLs under `/storage/install`, `/storage/app/install`, `/install`, and `/storage/app/public/install`. This confirms those paths do not expose the bundle; it does not prove there is no private copy or alternate route. REA static member evidence shows low-level `TcpClient.Connect` and stream I/O in `send-send.dll`, but still does not establish how the standard sender creates recipient tokens or calls the stats endpoints. No binary was loaded/executed and no DMS product request, event row, or state-changing action occurred. Keep live sender attribution UNKNOWN and Dashboard/Tracking TESTING.

### Sender callback static-search follow-up — E-TRACK-17 — 2026-10-26

Read-only Ghidra inspection of the retained `send-send.dll` returned a complete 1,942-entry procedure inventory. A bounded procedure-name regex covering HTTP/SMTP/tracking/statistics/pixel/redirect/token/campaign terms matched only `get_newStatus`; a bounded defined-string search for API paths, event terms and payload fields returned no matches. Evidence IDs are `ev_72e89caf7396cfb95d5acf1ded82bebc508e55c8c64ed8d32a241cf0a55547e0`, `ev_743b894e9183759d9879eef4f1615f3b5a99fdf0f8c2e4860b8afc4100c3bfc6`, and `ev_6da56805159507dc3fa3129c3d2248de2ff758c124c255e76707d9206cc883db`. This is limited to provider-reported names and Ghidra-defined strings; runtime-built/obfuscated data may be absent. No target code was executed. The sender-generated token/callback contract remains UNKNOWN, so local synthetic event tests cannot verify live sender-to-list population.

## E-DASH-29 — Analytics Pivot security policy and browser check — 2026-10-26

The local synthetic browser check exposed a CSP routing defect: Mailer Revenue and Campaign Statistics load DMS-pinned DevExpress assets, but the shared layout had added the CDN origin to script/style policy only on All Stats. The layout now scopes that origin to those three PivotGrid routes, and feature tests assert the `script-src` and `style-src` directives on the two affected pages.

The focused suites pass **21 tests / 291 assertions** and targeted Pint passes. In the disposable local browser workspace, Mailer Revenue loads a populated pivot (24 opens, 8 clicks, 3 leads, 1 unsubscribe, 5 bots, $42.75 attributed lead revenue); Campaign Statistics loads the pivot and an explicit empty result for the selected synthetic workspace/date. This proves local asset loading and rendering only. The saved workspace, authenticated sender callbacks, event queue execution and connected opens/clicks/leads/unsubscribes remain unverified; return to Suppression only after those connected list counts are populated and checked.

### Cross-surface data/list check — E-DASH-30 — 2026-10-26

The nine-suite local regression now links DMS-shaped `/api/v1/stats` campaign callbacks and the separately reconciled sponsor lead to the daily dashboard aggregates and audience-list state. It verifies one open, one click, one lead and one unsubscribe with $6.00 attributed revenue in Main, All Stats, Mailer Revenue and Campaign Statistics, while List History and the Lists grid report one current lead and one current unsubscribe. This confirms local source-to-report wiring and the DMS distinction between event totals and current membership types.

The regression passes **78 tests / 752 assertions** in isolated SQLite memory with synthetic workspace/provider data and `QUEUE_CONNECTION=sync`. It does not establish asynchronous queue-worker behavior, actual sender callbacks, saved-workspace state or DMS live behavior. The saved app key is empty and port 8766 is stopped, so no fresh browser check was possible. The sender token/callback contract remains UNKNOWN; no campaign send or DMS operation ran. Keep dashboard, event-ingestion and list-management rows TESTING and defer Suppression until real AlphaLink events populate and verify list state.


### All 11 audience-list counters — E-AUD-18 — 2026-10-26

Read-only DMS `EmailAction::get_email_counts` and `ListCalculateHistory` count the 11 current recipient types for each list and save date-keyed snapshots. AlphaLink’s regression now writes synthetic memberships across all 11 types, recalculates today’s snapshot, and checks exact counts through both the Lists grid API and List History data endpoint. The focused list-management, history and lead-reconciliation suites pass **19 tests / 202 assertions**; scoped Pint passes. This confirms local projection from stored membership state. It does not verify the sender callback contract, asynchronous worker execution, saved-workspace event population, or fresh browser parity. Keep DMS-AUD-002 TESTING and leave Suppression queued until connected list states are verified.


### Database-queue callback to List History — E-TRACK-18 — 2026-10-26

DMS `RedirectHandlerController::stats` validates the callback, queues `AddVisitorLogJob`, and returns HTTP 204. The job records visitor context and synchronously applies the recipient type and email-log changes. AlphaLink’s feature test sends a synthetic DMS-shaped `/api/v1/stats` callback into the database queue, confirms no state changed before processing, runs Laravel’s real `queue:work --once`, and checks the saved open event, current `open` membership, and List History count. `CampaignTrackingTest` passes **21 tests / 140 assertions**; scoped Pint passes. The contact, callback and SQLite database are synthetic. This verifies the local asynchronous job path, not native sender emission or the saved worker configuration. The sender callback contract remains UNKNOWN; no DMS state, saved AlphaLink data, PMTA host, provider or email was touched. Keep DMS-TRACK-003 and DMS-AUD-002 TESTING.
