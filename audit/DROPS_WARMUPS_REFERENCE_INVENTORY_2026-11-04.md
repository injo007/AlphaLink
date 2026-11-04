# DMS Drops and Warmups reference inventory

**Captured:** 2026-11-04
**Reference access:** Authenticated frontend GETs through the configured proxy; source copied read-only to `/tmp/alphalink-dms-reference-20261104/source`.
**Status:** Phase 1 discovery; this is not an AlphaLink parity claim.

## Evidence boundary

The DMS host was authenticated through the proxy. The pages below returned HTTP 200: Drops Monitoring, Drop Suppression, Drop Statistics, Warmups, New Warmup, Seeds, Add Seed List, and Warmups Statistics. The capture retained only page headings, static field labels, and source paths; it did not retain table rows, IDs, emails, form values, JSON, or session material. The browser's dynamic table requests were not replayed. No send, test-send, schedule, suppression, process control, seed mutation, or DMS source/config/database write was made. Login created only an authenticated session.

Source references use the sanitized local copy and can be resolved from `audit/DMS_SOURCE_HASHES_2026-11-04.csv`.

## Drops

| Workflow | DMS route and source behavior | Permission / side effect boundary |
|---|---|---|
| Draft initialization | `GET /mailer/drops` calls `DropAction::init_drop`: resumes the latest unprocessed Drop for the signed-in user unless `fresh=true`, otherwise creates one, then redirects to its editor. | Mutating GET. Never used during reference inspection. |
| Composer and catalogs | `GET /mailer/drops/{drop}` owner-authorizes and renders sponsor, saved headers, domains, negatives, servers, countries, and the selected Drop. `GET /mailer/drops/assets` serves offer, ISP, asset, and list catalogs; list calculation accepts ISP, sponsor, offer, country, and active email-type filters. | Drop owner; `is-mailer` and share-header roles affect catalog visibility. Catalog requests are read-only. |
| Body generation | `POST /mailer/drops/{drop}/generate-body` validates body/offer/optional ISP and updates the Drop's `offer_id` before returning generated tracking/body links. | Mutating request; not invoked. |
| Test | `POST /mailer/drops/{drop}/test` validates the test destination, header, selected server/IP and MTA mode, persists parameters, then starts a native test process. `POST .../test-auto` starts from saved parameters. | Owner-authorized; starts sender process. Not invoked. |
| Send and schedule | `POST /mailer/drops/{drop}/send` validates sponsor/offer, sender options, server/IP, ISP, lists, duplicate handling and count bounds; checks suppression, persists parameters, then either launches the sender or dispatches `ScheduleJob`. | Owner-authorized; sends mail or schedules work. Not invoked. |
| Monitor, stats and visitors | `GET /mailer/drops/monitoring` renders the monitor; its JSON branch filters date range, Drop type and role/user scope. `GET /mailer/drops/{drop}/monitor` returns status/count/progress. Per-Drop stats return open/click/lead-count/revenue/unsubscribe/bot. Visitors expose a row table and country aggregate. Aggregate stats use `share_stats` for user scoping. | Monitor/stat pages are read-only; row payloads may contain operational or recipient data, so no JSON data endpoints were requested. |
| Row actions | `GET /mailer/drops/{drop}/pmta` redirects to PMTA with the Drop's server IDs. `GET .../clone` creates a clone. `GET .../clear` clears an unprocessed Drop. Pause/resume, kill and restrict are `PUT` controls that affect process/redirect state. | PMTA redirect is navigation; clone and clear are mutating GETs; control routes are operational. Clone, clear, and controls were not invoked. |
| Suppression | `GET /mailer/drops/suppression` renders the Sponsor/Offer/ISP/List selector and its JSON branch supplies the catalog/history. `POST /mailer/drops/start-suppression` starts the external suppression command. A monitor endpoint reads status; `POST /mailer/drops/kill-suppression` stops it. | Start and kill are operational actions; neither was invoked. Suppression worker reads uploaded offer files, exports selected recipient data, compares hashes, updates exclusions, and removes its temporary table/files. |

The Drop model stores sender settings and selections as JSON arrays, including server/IP, lists, countries, filters, headers, subjects and placeholders. `DropVisitor` stores action, location/device, recipient ID and related send dimensions. `StatisticsDrop` stores daily delivery/bounce/open/click/unsubscribe/bot/opt-out/revenue aggregates and dimensions for user, Drop, ISP, list, server/IP/domain, sponsor/offer and message assets. These are source-defined contracts; their live DMS records were not queried.

## Warmups and seed lists

| Workflow | DMS route and source behavior | Permission / side effect boundary |
|---|---|---|
| Warmup list/create/detail | `GET /mailer/warmups` renders a date-filtered list; its JSON branch includes sender progress, selected server/IP, seed counts and send parameters. `GET /mailer/warmups/create` renders a new form; `GET /mailer/warmups/{warmup}` renders an existing form; `GET .../monitor` returns process state. | Page GETs are read-only. JSON/list rows were not fetched. Detail requires an actual ID, so source/template review was used instead of guessing one. |
| Warmup form | Static labels on the live New Warmup page include Return Path, Saved Emails, Email Test, Saved Headers, Header, Domain, Negative, Body, combination options and IP type. Source adds MTA option, servers/IPs, seed lists, send mode, total send, sleep per email/IP, seed interval, IP fraction, start position and bounce limit. | Source-backed field inventory; no form was submitted. |
| Warmup create/update | `POST /mailer/warmups` validates and stores the form; the `store_send` option can immediately build config and launch a native warmup process. `PUT /mailer/warmups/{warmup}` updates fields; `SeedCampaignAction::update_seed_campaign` kills a currently progressing process before saving. | Create can start a send; update can stop a live send. Neither was invoked. |
| Test/send lifecycle | `POST /mailer/warmups/test`, `POST /mailer/warmups/{warmup}/test-auto`, `PUT .../send`, `PUT .../resend`, and `PUT .../schedule` have distinct test/send/resend/schedule handling. Send modes are `delay`, `delayp`, `delayps`, `hs`, `hsp`, `hsps`, `pickup`, and `pickups`. The process list has a KILL action; PMTA redirects to the selected servers. | These routes execute sender/process or queue behavior. None was invoked. Warmup deletion is `DELETE /mailer/warmups/{warmup}`. |
| Warmup processes/statistics | `GET /mailer/warmups/processes` displays active runs; `GET /mailer/warmups/statistics` renders date/filter controls, with statistics detail and row endpoints. `share_stats` gates user visibility. | Static page navigation only; no operational rows or statistics payloads were fetched. |
| Seed list create/upload/inspect | `GET /mailer/warmups/seeds` renders the list table. `GET /seeds/create` and `POST /seeds` create an active list. `GET,POST /seeds/{seed}/upload` displays and inserts email addresses after `FILTER_VALIDATE_EMAIL`; duplicates are preserved. `GET /seeds/{seed}/show` renders the list; its JSON branch includes seed email rows. | List create/upload and email JSON expose or change recipient data; no such requests were made. |
| Seed status/edit/remove | `PATCH /seeds/{seed}/status` toggles active state; `GET,PUT /seeds/{email}/edit-email` edits one address; `DELETE /seeds/{seed}/emails` removes selected emails; `DELETE /seeds/{seed}` removes the list and is admin-only. | Status/edit/remove mutate data. Source says owner gates apply to list-level actions, but the exact live owner mapping remains unresolved. |
| Seed cleaning/loading | `GET /seeds/clean` renders server/date selectors. Its `POST` reads PMTA archived bounce files over SSH and removes matches from seed lists. `GET /seeds/load` returns selected seed addresses for composing a send. | Clean is a connected SSH/data mutation; load returns recipient addresses. Neither was invoked. |

`SeedCampaign` stores the Warmup configuration, process state, selected seeds, and send parameters; `SeedList` belongs to a user and has many `SeedEmail` rows; `StatisticsSeed` provides separate Warmup statistics. Admin bypasses owner gates; other users are scoped through `is-warmup-owner` and `is-seed-list-owner`. Server cleaning checks `is-server-allowed`. Warmup statistics use `share_stats` for cross-user visibility.

## Tracking API and unresolved sender contract

The DMS API declares `POST /api/v1/drop`, `POST /api/v1/stats-drop`, `POST /api/v1/bounce-limit-drop`, and `POST /api/v1/bounce-limit-warmup`. The PHP layer dispatches visitor/log jobs and updates email/list state, but the full native binary contract and end-to-end result mapping remain unresolved. The x86-64 sender was copied for static inspection and not executed; REA native analysis providers are unavailable. Do not infer delivery, retry/checkpoint, event payload, or exit-code parity from the UI or PHP wrapper.

## AlphaLink comparison checkpoint

The current AlphaLink worktree has partial Drops monitoring, visitor/statistics, and suppression workflows, and partial seed-list management. It has no complete Drop draft/composer/Test/Send/Schedule lifecycle and no Warmup campaign lifecycle, process monitor, or statistics module. Existing statuses remain INVESTIGATING or TESTING. A local implementation can continue behind an isolated sender adapter using synthetic fixtures, but native-send parity stays blocked until the sender contract is independently established.
