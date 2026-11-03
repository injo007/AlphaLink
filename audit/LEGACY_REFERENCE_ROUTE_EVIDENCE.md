# DMS Reference Route and Control Evidence

**Collected:** 2026-10-20  
**Scope:** Same-host frontend page metadata and inline JavaScript declarations. This is not a server route audit. Methods below are client-side declarations only; they were not invoked and do not prove server-side behavior.

## Page paths visited during Phase 1

The page paths below returned HTTP 200 during the authenticated inspection. HTML structure was read without executing JavaScript. Dynamic table requests were not invoked, so row contents and final column schemas were not obtained. **Phase 2 source review found that `GET /mailer/drops` calls `DropAction::init_drop`; it may create a draft record when no unprocessed draft exists. Because this route was visited in Phase 1, an incidental draft creation is possible but unverified. No production row was queried and no reversal was attempted.**

| Area | Pages viewed |
|---|---|
| Dashboard | /dashboard/main; /dashboard/all-stats; /dashboard/isp; /dashboard/mailer |
| Administration | /admin/users; /admin/isps; /admin/lists; /admin/list-history; /admin/emails; /admin/blacklist-checker; /admin/domains-providers; /admin/domains; /admin/servers-providers; /admin/servers; /admin/cloud-providers; /admin/offers; /admin/sponsors; /admin/miscellaneous/subscribers; /admin/miscellaneous/horizon; /admin/miscellaneous/app-health; /admin/miscellaneous/settings; /admin/miscellaneous/errors |
| Campaign/reporting | /mailer/gcampaigns/projects; /mailer/gcampaigns/accounts; /mailer/negatives; /mailer/mixs/history; /mailer/campaign-stats; /mailer/campaign-logs |
| Drops/warmups/tests | /mailer/drops; /mailer/drops/monitoring; /mailer/drops/suppression; /mailer/drops/stats; /mailer/warmups/seeds; /mailer/warmups/statistics; /mailer/global-tests/histories |
| Sending infrastructure | /mailer/servers; /mailer/ips; /mailer/pmta; /mailer/pmta/scheduler; /mailer/pmta/settings; /mailer/pmta/vmta; /mailer/pmta/smtp; /mailer/pmta/bounces; /mailer/pmta/action-history; /mailer/pmta/config-history |
| Offers/sponsors | /admin/sponsors; /mailer/sponsors; /mailer/offers |

The App Health page displayed “last run: just now” and 11 check rows. Because its behavior on access is unknown, that one page visit is disclosed in the main report and the route was not reopened.

## Client-declared action candidates

**Safety rule applied:** no route below was called by this audit. “Unspecified” means the method could not be determined from the inspected source excerpt. IDs are normalized to :id; no production record identifiers are retained here.

### Administration

| Candidate | Declared method | Evidence / boundary |
|---|---|---|
| /admin/users/:id | PATCH | User-row edit candidate; no role values or records loaded. |
| /admin/isps/:id | PATCH | ISP-row edit candidate. |
| /admin/lists/:id/load | Unspecified | LOAD LISTS control; safety not established. |
| /admin/lists/:id/lock; /admin/lists/:id/track; /admin/lists/:id | PATCH | List status/tracking/edit candidates; not invoked. |
| /admin/list-history/load-lists | GET | Selector/data-load candidate; not invoked. |
| /admin/list-history/load_history | POST | History load candidate; not invoked. |
| /admin/emails/search | POST | Search candidate; not invoked. |
| /admin/emails/action | PUT | Email action candidate; not invoked. |
| /admin/domains | Unspecified, DELETE | Domain list/action candidates; not invoked. |
| /admin/domains/:id | PUT, PATCH | Domain edit candidates. |
| /admin/domains/:id/status | PATCH | Domain status candidate. |
| /admin/domains-providers/:id | PATCH | Provider edit candidate. |
| /admin/servers | Unspecified, DELETE | Server list/action candidates. |
| /admin/servers/reconfigure-vmta | POST | Infrastructure action; not invoked. |
| /admin/servers/:id/lock; /admin/servers/:id; /admin/servers/:id/restore | PATCH | Server mutation candidates. |
| /admin/servers-providers/:id | DELETE | Provider deletion candidate. |
| /admin/offers | Unspecified | Offer listing/filter candidate. |
| /admin/offers/:id; /admin/offers/:id/status; /admin/offers/:id/restore | PATCH | Offer edit/status/restore candidates. |
| /admin/offers/:id | DELETE | Offer deletion candidate. |
| /admin/cloud-providers/:id | DELETE | Provider deletion candidate. |
| /admin/blacklist-checker/upload | POST | File-upload form; not used. |
| /admin/blacklist-checker/:id | PUT | Checker result/action candidate; not invoked. |
| /admin/miscellaneous/settings/load-domains-settings | GET | Settings data-load candidate; not invoked. |
| /admin/miscellaneous/settings/update-domains-settings | POST | Settings update candidate; not invoked. |

### Campaigns, drops, and tests

| Candidate | Declared method | Evidence / boundary |
|---|---|---|
| /mailer/gcampaigns/projects/status; /mailer/gcampaigns/projects/:id | PATCH | Gmail project status/edit candidates. |
| /mailer/gcampaigns/projects | DELETE | Gmail project deletion candidate. |
| /mailer/gcampaigns/accounts/status | PATCH | Gmail account status candidate. |
| /mailer/gcampaigns/accounts | DELETE | Gmail account deletion candidate. |
| /mailer/negatives/:id | DELETE | Negative-list deletion candidate. |
| /mailer/mixs/history | Unspecified | History data candidate. |
| /mailer/mixs/:id/remix | POST | Remix action; not invoked. |
| /mailer/campaign-stats/stats | Unspecified | Statistics data candidate. |
| /mailer/campaign-logs | Unspecified | Log data candidate. |
| /mailer/campaign-logs/:id/restrict | PUT | Restriction action; not invoked. |
| /mailer/drops/assets | GET | Asset-data candidate. |
| /mailer/drops/:id/send; /mailer/drops/:id/test; /mailer/drops/:id/generate-body | POST | Send/test/body-generation actions; not invoked. |
| /mailer/headers | POST, DELETE | Header create/delete candidates; not invoked. |
| /mailer/headers/:id | DELETE | Header deletion candidate. |
| /mailer/drops/:id/monitor; /mailer/drops/:id/stats | GET | Monitoring/statistics candidates. |
| /mailer/drops/monitoring | Unspecified | Monitoring data candidate. |
| /mailer/drops/:id/pause-resume; /mailer/drops/:id/kill; /mailer/drops/:id/restrict | PUT | Drop control actions; not invoked. |
| /mailer/drops/:id/test-auto | POST | Automated test action; not invoked. |
| /mailer/drops/start-suppression; /mailer/drops/kill-suppression | POST | Suppression start/stop actions; not invoked. |
| /mailer/drops/suppression/:id/monitor | GET | Suppression monitor data candidate. |
| /mailer/drops/stats | Unspecified | Statistics data candidate. |
| /mailer/warmups/seeds/:id/status | PATCH | Seed status candidate. |
| /mailer/warmups/seeds/:id | DELETE | Seed deletion candidate. |
| /mailer/warmups/statistics | Unspecified | Statistics data candidate. |
| /mailer/email-tests | GET | Shared inline request candidate; JavaScript was not executed. |

The SEND pages at /mailer/campaigns, /mailer/gcampaigns, /mailer/warmups, and /mailer/global-tests were not opened. Their navigation labels are action-oriented, and no source-level safety evidence was available for those screens.

### Servers and PMTA

| Candidate | Declared method | Evidence / boundary |
|---|---|---|
| /mailer/ips/servers; /mailer/ips/get | GET | IP/servers data candidates. |
| /mailer/ips/:id/webmail | POST | Webmail action candidate; not invoked. |
| /mailer/pmta/action | POST | PMTA action candidate; not invoked. |
| /mailer/pmta/:id/schedule; /mailer/pmta/:id/restart | POST | Scheduling/restart actions; not invoked. |
| /mailer/pmta/scheduler | POST, DELETE | Scheduler add/delete candidates; not invoked. |
| /mailer/pmta/settings; /mailer/pmta/file | GET | Settings/config data candidates; no config file was fetched. |
| /mailer/pmta/edit; /mailer/pmta/push | POST | Edit/push actions; not invoked. |
| /mailer/pmta/remove-vmta | DELETE | VMTA removal candidate. |
| /mailer/pmta/push-vmta; /mailer/pmta/restore-vmta; /mailer/pmta/push-vmta-v2; /mailer/pmta/restore-vmta-v2 | POST | VMTA push/restore actions; not invoked. |
| /mailer/pmta/remove-smtp | DELETE | SMTP removal candidate. |
| /mailer/pmta/push-smtp; /mailer/pmta/restore-smtp | POST | SMTP push/restore actions; not invoked. |
| /mailer/pmta/bounces | POST | Bounce-check action; not invoked. |
| /mailer/pmta/action-history; /mailer/pmta/config-history | Unspecified | History data candidates. |

The PMTA admin page visibly offered pause, resume, delete, reset, clear, schedule, stop, and restart controls. PMTA VMTA/SMTP pages offered push/reset controls. None was activated.

## Modules visible in navigation but not exercised

The dashboard also lists server install, bulk install, domain change, reinstall, external cloud-provider actions, blacklist/SPF/spam tools, shortlinks, reply extraction, encoding, image hosting, Firebase, contacts, and send/composer pages. They were not submitted or queried because they can change infrastructure, upload/transform data, contact external services, or send messages.

No billing module was visible in the inspected navigation. This does not establish that billing functionality is absent.

## Phase 2 source-based route safety findings

These behaviors were discovered by reading controller/action source only. None of the routes below was called during Phase 2. The earlier Phase 1 visit to `/mailer/drops` is the possible incidental-write exception above.

| Route | Source behavior | Safety disposition |
|---|---|---|
| `GET /mailer/drops` | Calls `DropAction::init_drop`; returns the current unprocessed draft or creates one. | **State-changing GET.** Previously visited in Phase 1; creation is possible, not confirmed. Do not revisit. |
| `GET /mailer/drops/{drop}/clone` | Calls `DropAction::clone` and redirects to a newly cloned drop. | **State-changing GET.** Do not call. |
| `GET /mailer/drops/{drop}/clear` | Clears an unprocessed drop draft and redirects. | **State-changing GET.** Do not call. |
| `POST /mailer/global-tests` | Builds sender config, synchronously invokes `global_test`, and persists output history. | Sends a test message; do not call. |
| `PUT /mailer/gcampaigns/{campaign}/send` | Launches Gmail API sender; optional mode creates drafts. | Send/draft side effect; do not call. |

The exact route declarations, controllers and action logic are described in [CAMPAIGN_ORCHESTRATION.md](CAMPAIGN_ORCHESTRATION.md). A route’s HTTP method is not used as a safety guarantee.

