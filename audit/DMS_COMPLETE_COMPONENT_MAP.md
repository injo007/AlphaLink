# DMS Complete Component Map

**Scope:** Consolidated evidence from all existing Phase 1/2 audit reports and Phase 3 source plus offline REA static ELF analysis. This is a reconstruction map, not a claim that every production component or runtime connection is known.

## Confidence labels

- **VERIFIED / OBSERVED:** directly present in inspected source, configuration, or bounded runtime/artifact metadata.
- **DERIVED:** a static call/data path follows from multiple inspected sources.
- **INFERRED:** plausible from configuration or indirect evidence, but not verified as active.
- **UNKNOWN / BLOCKED:** evidence is unavailable or further access would cross the approved safety boundary.

## Architecture overview

~~~mermaid
flowchart LR
  UI[Authenticated DMS web UI] -->|web routes, session, CSRF, auth| Laravel[Laravel 10.38.2 / PHP 8.1.27]
  API[Public /api/v1 handlers] --> Laravel
  Laravel --> Eloquent[Eloquent models and actions]
  Eloquent --> PG[(PostgreSQL configured)]
  Laravel --> Redis[(Redis queues and cache configured)]
  Cron[Root cron each minute] --> Schedule[Laravel Console Kernel]
  Schedule --> Jobs[Queued jobs and scheduled commands]
  Jobs --> PG
  Laravel --> Snapshot[Recipient and send files]
  Snapshot --> Native[send / send1 ELF]
  Native -->|CLI dispatch; loads managed payload| Managed[send.dll]
  Managed -->|TCP text I/O; SMTP-shaped replies, inference| MTA[SMTP peer; PowerMTA identity unknown]
  MTA -->|delivery and bounce files via SFTP| PMTAReader[PMTA log processor]
  API --> Events[Visitor/log jobs]
  Events --> PG
  Affiliate[Affiliate adapters] -->|SponsorLead reports| Events
  Laravel --> Affiliate
  Laravel --> Cloud[Cloud / registrar / server adapters]
  Cloud -->|SFTP and provider APIs| Infra[Managed mail and DNS plane]
~~~

Solid arrows represent source/configuration-backed paths. The managed sender has a reachable line-oriented TCP path; its SMTP-shaped reply parser makes SMTP compatibility the leading static inference, while the literal commands and PowerMTA peer remain unknown. Redis is the configured default; active runtime overrides were not verified.

## Components and evidence map

| Component | Frontend/backend relationship | Models or data | Principal source evidence | Confidence / boundary |
|---|---|---|---|---|
| Web identity and admin | Dashboard, admin, and mailer pages route through Laravel web/auth middleware; role gates protect selected groups. | users, access roles, settings | /var/app/app/Providers/RouteServiceProvider.php; /var/app/app/Http/Kernel.php; /var/app/app/Providers/AuthServiceProvider.php; routes web/admin/dashboard/mailer | **VERIFIED source policy**; runtime permission enforcement not tested. |
| Audience, lists, and ISP grouping | List, subscriber, search, negative, and mix screens map to recipient/list models and actions. A list belongs to one ISP and carries access/sponsor controls, activation, recipient-type locks, and tracking flags; the inspected recipient schema has one `lists_id` and a globally unique email address. | lists, emails, virtual_lists, subscribers, negatives, isps | `app/Models/Email.php`, `Lists.php`, `Isp.php`, `Subscriber.php`; `app/Actions/EmailAction.php`, `ListsAction.php`, `IspAction.php`; `app/Libraries/Suppression.php` | **VERIFIED schema/code**; multi-list membership, consent, tenant isolation, and live records unknown. See [database architecture](DATABASE_ARCHITECTURE.md) and [final report](DMS_REVERSE_ENGINEERING_FINAL_REPORT.md#how-are-lists-recipients-and-isps-managed). |
| Standard campaigns | Campaign editor routes to store/update/send/schedule controller actions. | campaigns, campaign_logs, email_logs, visitors | CampaignController.php:189-326,699-807; CampaignAction.php:22-110,293-328,448-517,519-569 | **DERIVED** from source; sender internals unknown. |
| Drops | Drop pages and controls map to DropController/DropAction; some GET routes mutate drafts. | drops, drop_visitors, histories/statistics | DropController.php:35-40,222-305,307-376; DropAction.php:24-36,329-369,455-605 | **VERIFIED source**; Phase 1 visit to GET /mailer/drops may have created a draft. |
| Warmups and seed lists | Warmup/seed screens map to seed-campaign actions; store_send may launch native sender. | seed_campaigns, seed_lists, seed_emails | WarmupController.php:218-314,391-550; SeedCampaignAction.php | **VERIFIED source paths**; send-mode internals unknown. |
| Google-linked campaigns | Separate gated project/account and campaign UI; command sends through Google Gmail API. | projects, accounts, sub_accounts, campaigns | routes/mailer.php:129-186; GcampaignAction.php:16-97; GcampaignSend.php; GmailLib.php | **VERIFIED source path**; token/account/API runtime uninspected. |
| Scheduling and background work | ScheduleJob and recurring Kernel commands serve campaign, analytics, PMTA, and integration flows. | Redis queues configured; scheduled model for PMTA | app/Jobs/ScheduleJob.php:22-180; app/Console/Kernel.php:60-140; config/queue.php; config/horizon.php | **VERIFIED configured paths**; active workers, queue contents, and overrides unknown. |
| Tracking and attribution | Public API routes and redirects enqueue visitor/log/state jobs; SponsorLead imports affiliate conversions. | visitors, drop_visitors, email_logs, daily statistics | routes/api.php:23-36; RedirectHandlerController.php:38-340; AddVisitorLogJob.php; SponsorLead.php:44-360 | **DERIVED** data flow; no live events or provider reports read. |
| Affiliate networks and offer catalogue | Sponsor adapters support five named providers. Admin workflows manually import selected provider offer IDs or create offers; daily jobs refresh metadata for existing active/live offers and refresh stale/missing suppression files. `SponsorLead` separately imports conversion reports every five minutes with a daily month-to-date reconciliation. | sponsors, offers, assets, visitors, email_logs | `SponsorManagerFactory.php:11-24`; `OfferController.php:73-121`; `OfferAction.php:24-150`; `OffersUpdateInfo.php`; `OffersUpdateSuppression.php`; `SponsorLead.php:44-360`; `Kernel.php:96-100` | **VERIFIED source workflows**; inspected scheduled offer jobs do not discover new provider IDs; active provider types, credentials, records, and provider-side state unknown. See [external integrations](EXTERNAL_INTEGRATIONS.md#affiliate-and-sponsor-networks). |
| Delivery feedback | PMTA delivery/bounce files are read over SFTP, parsed, archived, and reflected in counters and recipient types. CentOS 9 install also generates FBL/inbound-mbox configuration; a manual reply extractor parses From/Message-ID/date, looks up the sender address for a reply mix, then creates a virtual audience. The mix builder accepts unsubscribe rows, and campaign suppression reuses virtual-list rows without a current-state refresh. | campaign/drop/warmup statistics and recipient states; reply audience | ServerProcessPmtaLogs.php:38-285; ReplyExtract.php; ReplyExtractorController.php; EmailAction.php:141-175; Suppression.php:92-134; ServersInstall/Centos9.php | **PARTIAL:** reply-audience path verified; potential unsubscribe snapshot bypass; ARF parsing, complaint attribution/suppression, and live FBL routing remain unknown. |
| Mail/server control plane | PMTA, SFTP, server install, cloud provider, registrar, and DNS adapters appear under management UI. | servers, IPs, domains, MTAs, providers, history | PmtaAction.php; ServersInstallFactory.php; CloudManagerFactory.php; DomainsProviderFactory.php | **VERIFIED source capabilities**; active inventory and provider state unknown. |
| Native sender artifacts | Campaign/drop/warmup controllers launch an external ELF. REA verifies the apphost argument handoff and managed `Main(string[])`; Main reads text, applies a string predicate, Base64-decodes to UTF-8, and parses JSON. A managed core enumerates a constructor-populated `JArray`, starts a task per item, and waits for all tasks. New CIL resolves worker fields into TCP host/port values and exposes SMTP-shaped reply parsing. | Files under storage/app/engine/ and PHP-generated config/snapshot files | `send` and `send1` hashes; REA CIL evidence; CampaignAction.php:293-328,448-470; DropAction.php:528-605; BackgroundProcess.php:41-63. | **PARTIAL:** SMTP compatibility is the leading static inference; exact commands, PowerMTA peer identity, whether array items are recipients, MIME, batching and recovery remain unresolved. See [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md#follow-up--resolver-cache-and-smtp-shaped-reply-parser-2026-10-21). |
| PHP SMTP / PHPMailer | A raw SMTP helper is present; owner confirms PHPMailer, but current app-tree source and Composer search found no PHPMailer path. | No established sender model | app/Libraries/Send/Helpers/Smtp.php:19-89; Composer manifests/lock and app-tree search | **UNRESOLVED**; helper caller and PHPMailer installation scope unknown. |

## Data and execution relationships

1. **Audience to campaign:** list-owned email rows and virtual mix files are filtered by recipient type, offer exclusions, and suppression files; a recipient snapshot is materialized before sending. The snapshot appears point-in-time and is not rebuilt by the inspected campaign update path.
2. **Campaign to process:** controller validation and authorization lead to CampaignAction configuration generation, a Base64 JSON send configuration, shell launch, and PID/progress state. The configured PHP launcher passes the generated config path as `args[1]`; managed `Main` reads that same file, Base64-decodes its text, and parses the JSON. Exact mode-to-branch and JSON-property mappings remain unresolved. Delayed sends use a queued ScheduleJob that revalidates state before launching.
3. **Process to delivery feedback:** the application starts an ELF apphost, which loads a managed sender with reachable TCP text I/O and SMTP-shaped reply parsing. SMTP compatibility is a static inference; the command sequence, PowerMTA peer, and direct relationship to PMTA control/configuration are not established. Separately, the scheduled PMTA processor ingests remote delivery/bounce CSV files and changes statistics and recipient state.
4. **Engagement:** API handlers dispatch visitor/log jobs; daily aggregators group stored events into statistics. Most API families lack route-level throttling in inspected routes.
5. **Revenue attribution:** SponsorLead retrieves affiliate sales reports through provider adapters, parses an app-generated attribution payload, deduplicates on revenue ID, then updates visitor/revenue and recipient state.
6. **Offer catalogue:** an admin-selected provider ID list drives offer import; scheduled metadata/suppression refreshes operate on known offers, while automatic discovery of new offer IDs was not found in the inspected schedule.
7. **Separate Gmail path:** gated Google campaigns use OAuth and Gmail API send/draft calls, not the native ELF/PMTA path established for standard campaigns.

## Known contradictions and unresolved relationships

| Question | Evidence on each side | Resolution |
|---|---|---|
| Is PHPMailer the standard native campaign transport? | Owner confirms DMS uses PHPMailer; inspected Composer/PHP app tree has no package/call site; separate raw socket helper and two ELF files exist. | **UNKNOWN.** No explanation is assumed. |
| Does the ELF apphost itself speak SMTP? | The apphost has no direct socket/TLS/HTTP call target in inspected imports/disassembly; its bundled managed `send.dll` has a reachable `TcpClient` path and SMTP-shaped reply parser. | **No direct transport call was recovered in the apphost layer.** The managed path is SMTP-compatible by static inference; exact commands and whether the peer is PowerMTA remain unknown. |
| Are visitor events idempotent? | email_logs.token unique; visitor event schema has no event unique key; some state jobs have uneven uniqueness interfaces. | **Partial inconsistency verified in source**; duplicates not observed. |
| Is Redis active? | Queue config defaults to Redis; environment/process overrides and live Horizon state not read. | **INFERRED**, not verified. |
| Did the earlier drops page change data? | The Phase 1 page visit is recorded; source shows it creates a draft when none exists. | **UNKNOWN**; no state query or reversal was made. |

## Operational boundary

The authenticated SSH identity is root, but Phase 2/3 remote actions were restricted to bounded reads and ELF metadata tools after strict host-key verification. No application endpoint, database query, job, sender, SMTP/PMTA operation, or provider action was executed. Prior Phase 1 browser reads may have generated incidental middleware effects; see PROGRESS.md.
