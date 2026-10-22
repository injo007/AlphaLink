# DMS External Integrations

**Status: Static source mapping refreshed across the application and shipped tracking mini-app. No external integration was contacted.** Integration records, credentials, live provider state, and provider-side permissions remain unexamined.

## Affiliate and sponsor networks

`SponsorManagerFactory::init` maps sponsor `type` to `HitPath`, `NewHitPath`, `Cake`, `HasOffers`, or `EverFlow` (`/var/app/app/Libraries/SponsorsManager/SponsorManagerFactory.php:11-24`). The common `Network` interface defines offer lookup, suppression, creatives, earnings, sales-report formatting, and login-session methods. `SponsorLead` is scheduled every five minutes and queries active sponsor adapters concurrently (up to five); it parses sales records into campaign/drop lead events and updates revenue/state. See `/var/app/app/Console/Commands/stats/SponsorLead.php:44-180` and the tracking report.

| Adapter | Static contract observed | Security/reliability observations |
|---|---|---|
| `HitPath` | Builds API and reporting URLs from a sponsor URL; form POST for offer/assets/suppression calls and CSV sales reports; caches several offer calls for 30 minutes; maps conversion fields including encoded `s1`/`s2` values. | API key is passed in form fields. Offer calls parse XML. The legacy login method returns a deprecated response. No call was made. `/var/app/app/Libraries/SponsorsManager/HitPath.php:23-59,153-212,221-291`. |
| `NewHitPath` | Bearer-token JSON GETs for campaign, suppression, campaign reports and paginated sales detail; parses creative assets and conversion fields. | The adapter repeatedly calls `withoutVerifying()` and its login-session browser client sets `verify_peer=false`, disabling TLS peer verification in these code paths. This is a **VERIFIED source risk**, not a tested exploit. `/var/app/app/Libraries/SponsorsManager/NewHitPath.php:23-27,49-53,241-262,295-350`. |
| `Cake` | JSON GETs for campaign offers, suppression, creative content, performance/conversion reports; login-session flow uses a browser client. | Static adapter; no provider records or requests inspected. `/var/app/app/Libraries/SponsorsManager/Cake.php`. |
| `EverFlow` | API-key header on affiliate offer/suppression requests; paginated conversion-report POSTs; creative downloads. | Static adapter; no provider records or requests inspected. `/var/app/app/Libraries/SponsorsManager/EverFlow.php`. |
| `HasOffers` | JSON GETs for offers, redirect/unsubscribe, GEO/vertical and earnings/conversion reports; creative method exists. | Static adapter; no provider records or requests inspected. `/var/app/app/Libraries/SponsorsManager/HasOffers.php`. |

`SponsorLead` correlates campaign/drop conversions using provider sales fields and an application-generated `s2` payload. The campaign path checks a decoded token component and de-duplicates by `revenue_id`; both campaign and drop branches persist visitor lead/revenue records and update recipient type/email-log state. Source mapping is partial; no sponsor API, callback, report, or database record was requested. User-confirmed HitPath use is consistent with these adapters, but active sponsor types and configuration are unknown.

Offer acquisition and refresh have separate behavior:

- Admin offer import requires a sponsor and a submitted array of offer IDs, calls that sponsor adapter's `get_offers(ids)`, and adds offers that do not already exist for that sponsor/provider ID. Manual offer creation is also available.
- The daily offer-info refresh iterates existing active/live offers and updates available name, paused/live state, payout, redirect, unsubscribe URL and creative metadata. It does not enumerate or import newly available provider IDs.
- A separate scheduled task refreshes suppression files for existing active/live offers when the file is missing or stale. This is list synchronization, not offer-catalog discovery.
- `SponsorLead` imports conversion reports every five minutes (up to five concurrent sponsor processes); a daily month-to-date reconciliation is also configured. The inspected de-duplication is an application query by `revenue_id`; a database uniqueness guarantee was not established.

Sources: `/var/app/app/Http/Controllers/Admin/OfferController.php:73-121`; `OfferAction.php:24-150`; `OffersUpdateInfo.php`; `OffersUpdateSuppression.php`; `app/Console/Kernel.php:96-100`.

## Domain registrars

`DomainsProviderFactory` supports GoDaddy and Namecheap through a common interface (`/var/app/app/Libraries/DomainsProvider/DomainsProviderFactory.php:11-21`). It covers domain/detail/list lookup, DNS host records and parsed forms, host-record writes, nameserver lookup, custom nameservers, and reset.

- **GoDaddy:** HTTPS API client with a 60-second timeout and two retries; auth header assembled from the provider’s API-key/secret fields. Reads domain and record data; writes DNS records with `PUT` and nameservers with `PATCH`. Error handling logs response text plus submitted records (`Godaddy.php:17-24,27-42,83-111,139-172,174-223`).
- **Namecheap:** XML/form API client; parameters include account/API identity and the request IP. It lists domains in 100-item pages and reads DNS/nameserver data. Host records and custom/default nameservers are submitted through mutating provider commands. Timeout is 60 seconds with two retries; failed response bodies are sent to application logging (`Namecheap.php:21-30,47-87,115-139,181-197,244-305`).

No domain purchase, DNS query, DNS update, registrar login, or API request was made. The code-level interfaces reveal write operations and therefore these routes/adapters are explicitly outside this audit’s execution scope.

## Cloud and server providers

`CloudManagerFactory` maps eight types to adapters: Linode, ServerSpace, DigitalOcean, Vultr, Reg.ru, Amazon, Azure, and Hetzner (`/var/app/app/Libraries/CloudManager/CloudManagerFactory.php:11-26`). The shared class declares list-images/regions/types/servers, create-servers, and delete-servers operations (`CloudManager.php:13-27`). Source symbol inspection confirms provider API calls for list/create/delete; several adapters also expose IP linking, unlinking or reverse-DNS operations. Azure source includes resource-group, virtual-network, security-group, VM and public-IP operations. DigitalOcean, Hetzner and Reg.ru include logic that reads provider SSH keys and can POST a key if it is missing. These are source capabilities only; none were run.

The mailer routes include a `manage_clouds` role group for cloud-management operations (`/var/app/routes/mailer.php:424-523`). Administrative routes similarly group server/provider and domain operations under management roles (`/var/app/routes/admin.php:132-245`). Cloud/server integration calls may also update DMS inventory records. Provider credentials and live resource IDs were not read.

The server-operation adapter uses phpseclib3 SFTP (`/var/app/app/Libraries/ServersInstall/ServersInstallFactory.php:10-16`) and exposes remote `exec`, upload, download, install/configure/uninstall operations (`ServersInstall/Ssh.php:57-165`). Its factory authenticates with credentials held on the `Server` model, probes OS/architecture, and chooses supported CentOS 7/9-derived implementations (`ServersInstallFactory.php:18-53`). The factory shows no explicit host-key fingerprint pin/verification at the application call site; this is a **code review gap**, not proof that network or package layers provide no controls. No managed server was contacted.

## Other named or routed tools

Google-linked campaigns are a verified separate integration: the gated `gcampaigns` route group uses OAuth-backed accounts and a distinct sender command. `GmailLib` invokes Gmail `users_messages->send` and draft APIs using app-stored refresh tokens. Its configured scopes include broad mailbox access/modification and Gmail settings scopes (`/var/app/app/Libraries/GmailLib.php:23-47,164-242`). The optional send-drafts operation iterates and sends account drafts. No OAuth flow, profile request, draft, or Gmail API request was performed.

The wider source inventory includes these code-supported paths:

| Area | Observed integration | Evidence boundary |
|---|---|---|
| Google mail | Gmail API send/draft/profile operations through OAuth-backed accounts; a separate campaign sender path. | Gmail is a source-verified direct send path; active OAuth accounts and scope grants were not read. |
| Google Workspace | Admin SDK Directory account listing/synchronization using service-account credentials and domain-wide delegation. A scheduled path can load credential JSON via public S3 object URLs and synchronize user accounts. | The credential object and account data were not read. Public object listing/download behavior and delete-on-empty handling warrant security/operations review. |
| Microsoft | Microsoft OAuth refresh/token flow; Outlook account configuration can be pushed to PMTA-connected relay configuration. | A direct Graph sendMail call was not established. Token values and live accounts were not read. |
| DNS and cloud | GoDaddy, Namecheap, Afraid.org FreeDNS; Linode, ServerSpace, DigitalOcean, Vultr, Reg.ru, AWS EC2, Azure and Hetzner. | Source supports mutating DNS/server operations; none was called. |
| Affiliate | HitPath, NewHitPath, Cake, HasOffers and EverFlow. | Adapters and scheduled conversion import are source-visible; live networks and records are unknown. |
| Utilities | Firebase Auth, Bitly, TinyURL/is.gd/ns.hn shortlinks, Imgur, Telegram, ip-api Pro with a MaxMind fallback, Kickbox/disposable-domain checks, SpamAssassin checks and SPF/DNS scans. | The `IpGeo` call paths are source-verified. Its configured key, runtime flags, database files and active provider account were not read; no API request was made. Other integrations remain source-level inventory. |
| Suppression sources | Affiliate suppression URLs, Optizmo, Dropbox and Google spreadsheet/file sources. | Downloader source includes TLS verification disabled and a request without a finite timeout in inspected paths; no file was fetched. |
| Mail reply tools | IMAP reply parsing and a separate PMTA inbound mbox reply extractor. | These create reply audiences; the inspected paths do not establish complaint/FBL classification or suppression. |

### IP geolocation: ip-api Pro with MaxMind fallback

`App\Libraries\IpGeo` sets its endpoint to `https://pro.ip-api.com/json/` and reads the API key through `config('settings.ip_api_key')`. It makes a GET for the supplied IP with the key in the query string, a numeric `fields` mask, and a five-second timeout. Successful responses are cached under an IP-derived cache key until `now()->endOfDay()`; the source comment calls this 24 hours, but the actual expiry is the end of the current day. The adapter returns country code/name, region, city, coordinates, proxy/hosting flags and organization. The code reports provider failure response bodies before falling back.

The adapter also has local MaxMind country and city database fallbacks. `check(false)` selects the country database; default `check()` selects the city database. Both MaxMind paths return `proxy=false`, `hosting=false`, and an empty organization. Whether the database files exist and are current is unknown.

Observed call paths:

- `AddVisitorLogJob` and `AddDropVisitorLogJob` geolocate the request IP for visitor events and persist country, state and city with the visitor row. For opens, the job can filter configured blocked organizations using the returned `org`; MaxMind fallback records have no organization, so that filter cannot match on fallback data.
- The shipped tracking mini-app obtains the IP with Laravel `Request::ip()` for campaign/drop redirect lookup and click, open, unsubscribe and bot stats. Its landing form controller uses the same method for subscribe, contact and opt-out requests. It POSTs those values to the main API. Main campaign/drop handlers validate `ip_address` with Laravel's `ip` rule; the landing handler validates `ip` only as a string.
- Campaign and drop redirect handlers also make a country-only `check(false)` call when an offer has a nonempty `geo_target`. They apply the country allowlist when `settings.filter_geo` is enabled (default true in source), and can classify a recipient as proxy/blacklist for proxy, hosting or Morocco conditions. Proxy/hosting detection is disabled by default in source (`settings.block_proxies=false`) and is unavailable from MaxMind fallback data. If both the API and fallback return no result, the redirect handler skips geo rejection (fail-open).
- `SubscriberAction`, `ContactUsAction` and `EmailAction::opt_out` geolocate their submitted IPs and pass country/state/city into subscriber, contact or recipient-state/event handling. The landing API accepts these IP fields as strings, not syntactically validated IP addresses.
- `SponsorLead` geolocates a provider conversion's `sale['ip']` after IP validation in both campaign and drop conversion branches. `ServerCheckStatus` geolocates a server's configured IP only when its country field is empty, then saves the country code. Neither scheduled path was run.

The integration is therefore used for engagement tracking (including opens/clicks/unsubscribes), landing-form submissions, offer geo-targeting, affiliate conversions and server country completion—not just recipient click targeting. The mini-app source derives the value from `Request::ip()`, but its `TrustProxies` middleware leaves the proxy list unset and no `trustedproxy` config file was found in the archive. The effective proxy/ingress configuration was not inspected, so whether this resolves to the recipient's network IP or an intermediary remains unverified. The Pro endpoint choice matches the provider's published HTTPS/commercial offering; the free endpoint is HTTP-only and disallows commercial use. The source's GET query carries the API key, as required by the provider's Pro API. See [IP-API JSON endpoint documentation](https://ip-api.com/docs/api%3Ajson) and [Pro FAQ](https://members.ip-api.com/faq).

**Unknown:** whether the Pro key is valid/active, effective `ip_api_key`, `filter_geo`, `block_proxies` and blocked-open-organization settings, effective trusted-proxy/ingress configuration, MaxMind file presence/version, cache backend/retention, and whether failure reports containing provider response bodies are persisted or expose IP data. No API, setting value, database row or log was read.

Source anchors: `/var/app/app/Libraries/IpGeo.php:16-74,77-135` (SHA-256 `22b48b0986cc229d1fc0ac48f68f8f1429fe9ecc1653ca8e8183dffcdcbf4853`); `/var/app/app/Jobs/AddVisitorLogJob.php:37-82`; `/var/app/app/Jobs/AddDropVisitorLogJob.php:37-82`; `/var/app/app/Actions/EmailAction.php:109-129`; `/var/app/app/Actions/SubscriberAction.php:13-35`; `/var/app/app/Actions/ContactUsAction.php:13-35`; `/var/app/app/Http/Controllers/Api/RedirectHandlerController.php:38-51,83-130,147-160,192-239`; `/var/app/app/Console/Commands/stats/SponsorLead.php:104-109,192-197`; `/var/app/app/Console/Commands/servers/ServerCheckStatus.php:27-35`; shipped mini-app `app/Http/Controllers/RedirectController.php:17-35,85-95`, `app/Http/Controllers/LandingController.php:46-49,94-98,121-125`, `app/Helper.php:38-54,68-84,110-155`, `app/Http/Middleware/TrustProxies.php:8-27` (archive SHA-256 `be4c6fc20e109f57f532715727c9e305521fb6c8c9cad9438da9cd51412f921a`).

Dependency manifests may include SDKs that have no active source call site. Treat an adapter or library as implemented only where a call path was found, and treat implemented capability separately from a configured/active integration. No callback or tool endpoint was invoked.

## Safety, confidence, and open questions

- **VERIFIED:** adapter factories, supported provider types, selected call paths/API verbs, offer import-versus-refresh behavior, the ip-api request/fallback/caller paths, and the NewHitPath/suppression-download TLS-verification settings.
- **UNKNOWN:** active providers, credentials/permissions, live domain/server state, provider-side rate limits, response schemas in production, idempotency, reconciliation, timeouts across all adapters, and webhook signatures.
- **BLOCKED:** end-to-end validation without production requests or a sanitized provider sandbox.

No provider API, webhook, callback, DNS operation, upload, campaign sync, or infrastructure change was performed. The reference system remains untouched by this integration investigation.
