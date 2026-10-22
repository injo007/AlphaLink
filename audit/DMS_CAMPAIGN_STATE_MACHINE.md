# DMS Campaign State Machine — Evidence-Bounded Reconstruction

**Status: Partial state transitions are visible in PHP; native completion and recovery transitions remain unknown.**

## Standard campaign lifecycle

~~~mermaid
stateDiagram-v2
  [*] --> DraftOrForm
  DraftOrForm --> SnapshotReady: store / suppression builds recipient file
  SnapshotReady --> Progress: store_send or send launches ELF
  SnapshotReady --> Scheduled: schedule dispatches ScheduleJob
  Scheduled --> Progress: due job revalidates and launches
  Scheduled --> RejectedOrStale: missing file, restricted/inactive, timestamp mismatch
  Progress --> ResetAfterKill: kill path issues kill -9 and clears tracked state
  Progress --> Completed: UNKNOWN native completion reconciliation
  Progress --> Failed: UNKNOWN exit/failure transition
  SnapshotReady --> Updated: update source persists edited fields/history
  Updated --> SnapshotReady: audience snapshot rebuild not observed
~~~

The labels DraftOrForm, SnapshotReady, RejectedOrStale, and ResetAfterKill are explanatory groupings, not literal database enum values. progress and scheduled are source-observed values. Completion/failure edges are explicitly **UNKNOWN**.

## Source-backed transitions

| Transition | Trigger and checks | Source evidence | Confidence |
|---|---|---|---|
| Form/create → persisted campaign | Validates selected list(s), content, offer/ISP, routing, server/IP, pacing and message settings; creates suppression output before persisting. store_send can immediately launch. | CampaignController.php:189-326; CampaignAction.php:22-110 | **VERIFIED** static behavior; endpoint not called. |
| Persisted campaign → progress | Owner-gated send validates send/kill, one of eight modes, expiry, existing PID, required inputs and send-file presence; writes history, builds config, starts process and stores PID/parameters/status. | CampaignController.php:699-807; CampaignAction.php:293-328,448-470,565-569 | **VERIFIED** source path. |
| Persisted campaign → scheduled | Queues a unique ScheduleJob on schedule with delay and persists schedule fields/status. | CampaignAction.php:486-517 | **VERIFIED** source path; queue runtime unknown. |
| scheduled → launch or no-op | Job rechecks existence, schedule flag/time, activity/restriction and file presence, then dispatches campaign/Gmail/drop/warmup launch path. | app/Jobs/ScheduleJob.php:22-180 | **VERIFIED** source checks; execution not observed. |
| progress → cleared state | Kill path sends kill -9 to stored PID and clears PID/status/schedule fields. | CampaignAction.php:473-484 | **VERIFIED** source; no identity validation/graceful shutdown visible there. |
| Content/routing update | Persists fields and an update history record. The inspected update path does not rebuild the audience snapshot. | CampaignAction.php update method and campaign controller update route; see CAMPAIGN_ORCHESTRATION.md | **DERIVED** from create/update code. |

## Related campaign families

- **Drops:** use a parallel Drop model/action and drop_<mode> / drop_test_<mode> launcher contracts. Owner gates, schedule checks, pause/kill/restrict paths exist in source. GET /mailer/drops, GET clone, and GET clear mutate draft state; these routes are unsafe to revisit.
- **Warmups:** use SeedCampaign and the same configured native sender with warmup_<mode>. Updating a progressing seed campaign first kills the active process. Seed-list import/clean/delete operations mutate production state and were not used.
- **Global tests:** POST /mailer/global-tests synchronously invokes global_test, may send a real message, and stores output history.
- **Google-linked campaigns:** distinct states/progress are maintained by an Artisan sender that calls Gmail API. Its send/draft modes do not establish native ELF state transitions.

## Native process boundary evidence

REA disassembly of both approved ELF artifacts shows the startup routine passing `argc`/`argv` to internal code at `0x180b0`; the main-function pointer is the relative relocation target `0x18910`. The native code also has a `dlopen`/`dlsym` loader path. Managed CIL now establishes that Main receives the PHP-supplied `send_<mode>` as its first argument and the generated config-file path as its second argument. Obfuscated mode-to-branch meaning remains unknown. No native state transition for recipient acceptance, retry, progress, completion, or failure could be recovered. See [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md).

## State integrity questions

- **UNKNOWN:** how the parent process discovers successful/failed completion and sets terminal campaign status.
- **UNKNOWN:** whether a stale PID can refer to a different process before kill; source method does not visibly validate process identity.
- **UNKNOWN:** transaction boundaries across campaign history, config-file creation, process launch, and state persistence.
- **PARTIAL:** scheduler uniqueness is visible for ScheduleJob; no universal idempotency key across send launch and native recipient batches is established.
- **UNKNOWN:** what happens when snapshot/config creation succeeds but launch fails, or when a schedule fires during a concurrent edit/restriction.
- **UNKNOWN:** how state is reconciled after host/process failure or partially sent batches.

No state transition was triggered. This map records controller/action/job behavior only.
