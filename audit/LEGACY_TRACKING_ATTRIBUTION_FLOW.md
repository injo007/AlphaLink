# DMS Tracking and Attribution Flow

**Status: Static pipeline reconstruction refreshed against the application source and shipped mini-app. Runtime events, records, counters, live PMTA configuration, and external reports were not accessed.**

## Lifecycle diagram

~~~mermaid
flowchart LR
  Delivery[Expected email delivery] -. actual transport link unknown .-> Reader[Recipient mailbox]
  Reader --> Event[Open / click / unsubscribe request]
  Event --> API[POST /api/v1 event handlers]
  API --> Jobs[Visitor and email-log jobs]
  Jobs --> VisitorRows[visitors / drop_visitors]
  Jobs --> Lifecycle[Recipient state changes]
  Lifecycle --> Lists[Tracked list movement / offer exclusions]
  VisitorRows --> Aggregate[Daily campaign/drop aggregators]
  Aggregate --> Reports[Dashboard / statistics UI]
  PMTA[PMTA delivery/bounce CSV files] --> Ingest[Scheduled SFTP processor]
  Ingest --> Counters[Delivery/bounce counters and recipient changes]
  Affiliate[Affiliate sales reports] --> SponsorLead[Scheduled SponsorLead import]
  SponsorLead --> Revenue[Lead visitor/revenue and recipient changes]
  Counters --> Reports
  Revenue --> Reports
~~~

The event and import handlers are source-verified. The relationship between actual sent messages and recipient-side events depends on the unrecovered message/token construction and is not runtime-verified.

## Engagement event path

1. API route families for subscribe, opt-out, contact, campaign/drop redirects, stats and bounce-limit checks are declared in routes/api.php:23-36.
2. Subscribe/opt-out/contact mutate subscriber/contact/recipient state and have explicit throttle 2,1. Campaign/drop/stats route families are not shown with route-specific throttles in the inspected route set; default API throttling is commented out.
3. Redirect handlers validate configured application key, identifiers/token/request metadata, target state and redirect/unsubscribe type. They resolve offer/sponsor redirect data and can dispatch recipient-state or email-log jobs.
4. Stats handlers accept click/unsubscribe/open/bot dimensions and dispatch AddVisitorLogJob or AddDropVisitorLogJob.
5. Visitor jobs parse IP/user agent, suppress selected blocked-organization opens, persist visitor/drop visitor events and dispatch state/log work. Inspected jobs allow up to five tries.
6. ChangeEmailTypeJob and ChangeDropEmailTypeJob apply lifecycle updates; tracked lists may move records into GEO-specific lists, and unsubscribe adds the current offer to excluded_offers.

The installed mini-app has distinct campaign and drop routes for click redirect, unsubscribe, open pixel, and bot events. Click and offer-unsubscribe first POST to the main app for redirect/unsubscribe information, then queue stats; open sends stats and returns a static PNG. Requests include a configured shared mini-app key. The campaign token is Base64 encoded and its Adler-32 checksum covers selected token fields; these mechanisms are source-observed, not proof of a cryptographic signature or token confidentiality. The parsed attribution fields carry campaign/send/recipient/list/ISP/server/IP/domain/from/subject/creative identifiers and a random token component.

Campaign offer-unsubscribe and public global opt-out are different paths. A campaign offer-unsubscribe records an unsubscribe event and redirects to the offer's unsubscribe URL. The public form POST submits global opt_out when it has no u flag; with u present it skips that global API call. Treating both as one suppression scope would misstate DMS behavior.

Sources: RedirectHandlerController.php:38-340; AddVisitorLogJob.php:20-93; AddDropVisitorLogJob.php:20-94; ChangeEmailTypeJob.php:24-124; ChangeDropEmailTypeJob.php:22-124.

### IP geolocation in redirect targeting

The shipped mini-app's campaign/drop handlers obtain the IP with Laravel `Request::ip()`, then send it to the main API for redirect resolution and click, open, unsubscribe and bot statistics. `AddVisitorLogJob` and `AddDropVisitorLogJob` call `IpGeo::check()` for visitor events and persist the resulting country/state/city with the event. The landing mini-app similarly passes `Request::ip()` for subscribe, contact and opt-out forms; `SubscriberAction`, `ContactUsAction` and `EmailAction::opt_out` geolocate those submitted IPs.

Separately, campaign and drop redirect handlers call `IpGeo::check(false)` when an offer has a nonempty `geo_target`. This checks country code against the offer's allowed country list when `settings.filter_geo` is enabled; with a nonzero email ID, proxy/hosting results and Morocco are also used for recipient-state and email-log actions. The provider is ip-api Pro, with a MaxMind country-database fallback. If both sources return no result, the handler skips the geo rejection check, so this path fails open.

The mini-app's `TrustProxies` middleware declares forwarded-header names but leaves the proxy list unset; no `trustedproxy` config file appears in the shipped archive. The main API validates redirect `ip_address` values with an IP rule but accepts landing-form `ip` values as strings. Runtime ingress/proxy configuration and the actual client address therefore remain unverified. `IpGeo` is also called by `SponsorLead` for validated affiliate-sale IPs and by `ServerCheckStatus` for servers missing a country value. See [EXTERNAL_INTEGRATIONS.md](EXTERNAL_INTEGRATIONS.md#ip-geolocation-ip-api-pro-with-maxmind-fallback) for the adapter, cache and provider details. No provider request or scheduled command was run.

## Persistence and aggregation

- visitors and drop_visitors hold event type and dimensions including campaign/drop, recipient, list, ISP, server/IP/domain, creative and device/geo context.
- email_logs has a unique token index and write paths use insertOrIgnore. The inspected visitor schema lacks a unique event key, so retries may duplicate visitor rows while token-keyed log writes can collapse.
- StatisticsCampaignsUpdate groups visitor events by date and dimensions and updates daily open/click/unsubscribe/bot/opt-out/revenue totals. Drop and seed aggregators update parallel daily tables. Commands are scheduled and were not run.
- PMTA log processing separately increments daily delivery and hard/soft-bounce counters and queues delivered/hard-bounce recipient changes. It moves source files across processing/archive paths.

Sources: migrations under database/migrations; StatisticsCampaignsUpdate.php:28-129; StatisticsDropsUpdate.php; StatisticsSeedsUpdate.php; ServerProcessPmtaLogs.php:38-285.

## Delivery, bounce, complaint, and conversion joins

- PMTA delivery/bounce records encode campaign/drop/warmup dimensions; the scheduled processor maps those dimensions to daily statistics and recipient IDs. Hard categories visible in source include bad-mailbox, bad-domain, quota-issues and inactive-mailbox; other categories aggregate as soft bounces.
- The PmtaBounce command reads remote bounce files and masks recipient values in its returned fields, but the command was not run.
- CentOS 9 install source writes `/fbl@<owned-domain>/` PMTA feedback-loop addresses and directs inbound append-mbox files to `/etc/pmta/inbound/{domain}`. The manual reply tool downloads the mailbox over SFTP, extracts only From address, Message-ID and date, looks up an existing email by that sender address for the regular mix (including the `unsubscribe` type), carries the message ID into the mix file, and creates a `reply-<name>` virtual audience. It does not parse ARF original-recipient fields, classify complaints, change recipient lifecycle state, or apply complaint suppression. A fresh bounded literal-term scan found FBL/complaint terms only in `ServersInstall/Centos9.php` under app/routes/database; `.git`, `vendor`, `node_modules`, `storage`, `public`, and `bootstrap` directories were excluded. Live routing and any other deployment or external processing remain unknown; the source does not establish whether FBL messages reach this parser.
- **Derived suppression risk:** campaign creation passes selected lists through `Suppression`. The virtual-list branch reads stored mix rows without refreshing the current email lifecycle type or `excluded_offers`; because the reply extractor places `unsubscribe` rows in that mix, such an address can enter the send snapshot unless the separate offer suppression file removes it. Later native-sender filtering is unknown. No campaign or send was run.
- SponsorLead runs every five minutes, up to five concurrent adapter processes. It fetches active sponsor sales reports, parses the app-generated s2 payload, validates a campaign attribution component, and deduplicates conversions on revenue_id. Campaign/drop conversions create or update visitor/revenue records and recipient/email-log state.
- The app's generated Base64/Adler-32 token uses a non-cryptographic checksum. No expiry is visible in the inspected helper. A shared application key is also sent to the main tracking API; key rotation, exposure and production handling are unknown.
- A likely aggregation mismatch exists: global opt-out writes an Email log and changes state to opt_out, while campaign/drop daily aggregators count visitor type optout. The inspected global opt-out path does not create a Visitor row. Confirm by metric reconciliation before treating displayed opt-out totals as accurate.

Sources: SponsorLead.php:44-360; CampaignAction.php:519-563; adapter classes indexed in EXTERNAL_INTEGRATIONS.md.

## Recipient-protection limits evidenced

- Source shows offer exclusions, suppression-file filtering, unsubscribe/opt-out type changes and some landing-route throttles.
- Source migrations do not establish an explicit consent-status field on the subscriber table, and no consent evidence/retention model was found in the inspected paths.
- Visitor deduplication, late-event reconciliation, global cross-campaign suppression, complaint FBL processing, and suppression freshness for already materialized audience files are not fully established.
- No recipient address, event, conversion, message or live aggregate was read.

## Confidence boundary

**VERIFIED:** route names/methods, source-level handler/job/persistence relationships, migration-defined event dimensions, and scheduled aggregate/import command definitions.

**DERIVED:** event jobs lead to state changes/logs; PMTA and affiliate imports contribute to reporting; source movement plus counters create partial-retry risk.

**UNKNOWN:** actual delivery-to-token binding, live event completeness, production metrics, global suppression semantics, complaint classification and suppression, event ordering/timezone, conversion correction, and duplicate behavior under retries.
