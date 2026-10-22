# DMS Reverse Engineering — Phase 3 Report (Historical)

**Status: Consolidated source reconstruction and approved offline REA/Ghidra static analysis completed through the embedded managed payload. The two senders are .NET 7.0 apphosts with distinct `send.dll` CIL builds and identical dependency assemblies. Static analysis verifies CLI dispatch, task fan-out over a `JArray`, obfuscated worker field access, database/parallel helpers, SFTP helpers, TCP text-stream I/O, and SMTP-shaped reply parsing. SMTP compatibility is the leading protocol inference; literal commands, peer identity, message/MIME semantics, recipient outcomes, and recovery remain unknown.** No AlphaLink code or technology selection has begun.

**Phase 4B supplement:** fresh PHP-to-managed CIL evidence confirms `send_<mode> <config-path>` maps to managed `args[0]` mode dispatch and `args[1]` file read; the file text is Base64-decoded to UTF-8 before `JObject.Parse`. In one mode branch, Main passes that parsed object to a constructor that selects the `JArray` used by the task fan-out. The obfuscated property key and array-item meaning remain unknown. A later static CIL crosswalk ties the JArray worker to a TCP-backed object that writes bytes and parses replies from the same connection; the parser accepts first-character `2`/`3` classes and `-` continuation lines. SMTP compatibility is now the strongest protocol inference for that worker, while a complete SMTP transaction, command text and PowerMTA peer identity remain unproven. Current evidence and source hashes are in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md#php-configuration-handoff-cross-check--2026-10-21) and the critical status in [DMS_PHASE_4B_REASSESSMENT.md](DMS_PHASE_4B_REASSESSMENT.md).

The current source reassessment is [DMS_PHASE_4B_REASSESSMENT.md](DMS_PHASE_4B_REASSESSMENT.md). It supersedes stale evidence-status statements below where it explicitly records a newer transfer or a corrected source finding.

## Executive summary

DMS is source-evidenced as a Laravel 10.38.2 / PHP 8.1.27 web application behind Nginx/PHP-FPM, configured for PostgreSQL and Redis/Horizon. The application handles administrative workflows, lists and recipient segmentation, campaign/drop/warmup records, scheduling, tracking, daily analytics, PMTA log processing, affiliate conversion imports, and provider/infrastructure control.

The standard campaign path is reconstructed through PHP: owner-gated validation → suppression-filtered file-backed audience snapshot → Base64-encoded JSON configuration → optional ScheduleJob → shell-launched .NET apphost and persisted PID/progress state. Ghidra verifies argument forwarding through `libhostfxr.so`; REA verifies the managed `Main(string[])`, dotenv/JSON helpers, Npgsql/Dapper calls, reachable parallel processing, and a TCP text-stream helper. SMTP-compatible response handling is the leading static protocol inference; exact commands, PowerMTA peer identity, MIME construction, recipient batching, retries, and terminal-state reporting remain unknown.

Google-linked campaigns use a separate OAuth-backed Gmail API sender path. The project owner states PHPMailer is used, but the inspected app dependency/source search did not identify its package or call site. The discrepancy remains unresolved.

Approved private copies of only `send` and `send1` matched the previously recorded SHA-256 values and were inspected offline with REA 6.7.0 and Ghidra 12.1.3/JDK 21. Ghidra decompiled the entry, apphost dispatcher, `libhostfxr.so` resolver and `dlopen`/`dlsym` helper. No direct network/SMTP/TLS call target or tested generic MIME/SMTP marker was recovered from the native apphost layer. Both apphosts contain the `send.dll` application-name string and take a bundle-startup path. Phase 3G parsed the bounded bundle manifests and inspected managed metadata/CIL. The Phase 3H note that did not perform a fresh transfer was accurate for that checkpoint; Phase 4B later re-transferred the two artifacts and revalidated their recorded hashes. Current custody and detailed managed findings are in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md).

## Consolidated architecture

~~~mermaid
flowchart TD
  UI[Admin UI] --> Web[Laravel web routes and role/owner checks]
  Web --> Models[Eloquent actions and models]
  Models --> DB[(PostgreSQL configured)]
  Web --> Audience[Relational lists + JSONB criteria + files]
  Audience --> Snapshot[Suppression-filtered recipient snapshot]
  Snapshot --> Config[Base64 JSON runtime configuration]
  Web --> Schedule[Redis-configured ScheduleJob]
  Schedule --> Launch[BackgroundProcess and shell launch]
  Config --> Launch
  Launch --> ELF[send / send1 .NET apphost]
  ELF --> Hostfxr[libhostfxr.so / libcoreclr.so]
  Hostfxr --> Managed[Embedded send.dll CIL; metadata inspected]
  Managed --> Dispatch[Obfuscated CLI dispatch]
  Dispatch --> Data[DotEnv / JSON and Npgsql / Dapper helpers]
  Dispatch --> Parallel[Parallel.ForEach processing]
  Dispatch --> SFTP[SSH.NET SFTP helpers]
  Dispatch --> TCP[TcpClient and text streams]
  TCP -->|SMTP-shaped reply grammar; compatibility inferred| MTA[SMTP peer; PowerMTA identity unknown]
  API[Tracking and landing API] --> EventJobs[Visitor, state and email-log jobs]
  EventJobs --> DB
  MTA --> PMTAFiles[Delivery/bounce files]
  PMTAFiles --> SFTP[Scheduled SFTP ingestion]
  SFTP --> DB
  Affiliates[Affiliate network reports] --> SponsorLead[Scheduled conversion importer]
  SponsorLead --> DB
  Google[Separate Google campaign command] --> Gmail[Gmail API]
~~~

This is a source-derived map, not an observation of live event traffic, data, or active workers. Redis is the configured queue default; actual active runtime configuration is unknown.

## What is understood

- Web entry, middleware, selected authorization gates, route families and visible frontend modules.
- PHP campaign create/update/send/schedule paths; drop/warmup/global-test branches; Google-linked sender separation.
- Migration-defined entities and relationships for users, lists, recipients, campaigns, events, statistics, offers, providers and infrastructure.
- Queue/Horizon configuration, root scheduler cadence, recurring commands, PMTA log ingestion and sponsor conversion source paths.
- Tracking handlers, visitor/state/email-log jobs, daily aggregators, PMTA bounce categories and revenue attribution fields.
- Affiliate, registrar, cloud, managed-server and PMTA source capabilities without contacting those services.
- Source-level security/reliability concerns and previously observed frontend side-effect caveats.
- Offline REA static ELF evidence: startup dispatch address, dynamic module loading, file/runtime APIs, limited network/MIME negative findings, and provider limitations.
- Offline REA managed metadata/CIL evidence: bundle manifest and inventory, .NET 7 target, obfuscated entry-point dispatch, dotenv/JSON, PostgreSQL/Dapper, parallel-processing, SFTP, subprocess, logging, and TCP text-stream boundaries.

Detailed source evidence and confidence labels are indexed in DMS_RECONSTRUCTION_EVIDENCE.md and DMS_COMPLETE_COMPONENT_MAP.md.

## Sending-engine sufficiency

**Partly understood; still insufficient for implementation-level conclusions.** Verified: exact artifact identities; bundle contents and hashes; .NET 7 managed entry signature; switch-based CLI dispatch; dotenv/JSON helpers; Npgsql/Dapper access; `Parallel.ForEach`; SSH.NET SFTP; Serilog; subprocess setup; and a reachable direct TCP text-stream helper. Unknown: exact mode/config mapping, recipient data flow and batch size, message headers/MIME, whether TCP is SMTP/PMTA/custom, TLS/auth, transport rejection semantics, retry/checkpoint behavior, PHPMailer linkage, and parent/child terminal-state reconciliation. `DMS_NATIVE_SENDER_ANALYSIS.md` contains the managed inventory, call graph, hashes, and evidence limits.

No sender code was executed, patched, dynamically instrumented, uploaded, or committed. No credential, customer record, or message body was extracted. At the earlier Phase 4B close, private copies and the ILSpy archive/extraction were removed and verified absent, and REA was closed without a snapshot. A later static follow-up re-transferred only the approved sender artifacts and reopened REA on `send.dll`; its last recorded checkpoint showed the session open. The current REA session query reports no open target and no cleanup pending; the referenced private files are absent, and a target-SHA-filtered Evidence query returned no retained records. No production endpoint or data was contacted.

## Highest-impact architecture gaps for review

1. **Opaque delivery core:** static analysis recovered the .NET hostfxr and bundled-app boundary, but not the managed sender's message/transport guarantees, per-recipient outcomes or recovery model.
2. **Weak process observability:** output is discarded and completion/state reconciliation is not established.
3. **Replay and idempotency gaps:** visitor uniqueness is uneven; PMTA file movement/counter updates need replay-safety evidence.
4. **Control-plane security:** plain HTTP PMTA command path, disabled TLS verification in NewHitPath, no visible managed-host fingerprint pin, shell execution and API throttle gaps.
5. **Consent and suppression evidence:** subscriber schema lacks an explicit consent-status field; global suppression and snapshot freshness remain open. Fresh strict-pin source reads verify CentOS 9 FBL routing and a manual reply-audience builder. That builder reads From/Message-ID/date and looks up the sender address; no ARF original-recipient parsing, complaint classification, recipient-state update or complaint suppression was found in the reviewed application source scope. The builder accepts unsubscribe rows, and the campaign virtual-list suppression branch reuses stored rows without rechecking current state, creating a potential unsubscribed-recipient snapshot bypass; later native-sender filtering remains unknown.
6. **Schema/runtime verification:** no read-only database identity was established; active workers, actual schema, provider state and production logs remain unverified.

Non-stack-specific opportunities are in [ALPHALINK_IMPROVEMENT_OPPORTUNITIES.md](ALPHALINK_IMPROVEMENT_OPPORTUNITIES.md). No technology choices are made.

## Safety and unresolved evidence

- SSH sessions used strict checking after the supplied ED25519 fingerprint matched, direct routing, and no proxy/jump.
- Phase 2/3F did not intentionally modify DMS, query production data, call endpoints, run application jobs, execute a sender, contact SMTP/PMTA/providers, or control services. Phase 3F used strict direct SSH only for read-only host/file metadata, SHA-256 verification and copying the two authorized files.
- Phase 1 may have caused incidental effects: GET /mailer/drops can create a draft; the App Health page and authenticated middleware may also write/log. These were not checked or reversed.
- Live PostgreSQL catalog/grants, queue payloads, process state, actual PMTA files/config and routing, provider records, and complaint-specific FBL handling remain unknown or blocked.
- PHPMailer may exist outside the inspected app component, but no other server or installation was accessed.

## Phase boundary

At Phase 3G close, authorization covered the exact two-file boundary; strict SSH fingerprint and recorded hashes matched before copy. The bundle parser verified both v6.0 manifests and extracted only the 17 embedded assemblies plus dependency/runtime manifests into a private mode-`0700` directory with mode-`0600` files. The later Phase 3H CIL-only pass reused already-present private copies and extracts; it did not perform a new transfer. Phase 4B performed a fresh source and sender transfer, exported REA evidence, closed the REA session, and removed its temporary working copies after report reconciliation. No sender/runtime was executed and no software was installed.

The common dependencies include Dapper, DotNetEnv, Newtonsoft.Json, Npgsql, SSH.NET, Serilog, Sprache, and support assemblies. `send1` has an additional `System.Net.NetworkInformation` reference and a changed managed entry-point body; their dependency assemblies and manifests are otherwise hash-identical. Internal identifiers are obfuscated. The JArray worker's concrete TCP object both writes bytes and parses SMTP-shaped replies on the same connection; this makes SMTP compatibility the leading inference for that path, while literal commands, complete transaction semantics and peer identity remain unknown. PHPMailer remains unresolved because the managed assembly has no PHP-library relation and the inspected PHP source did not establish a call site.

Live database questions remain blocked pending a read-only identity. The approved Phase 3G managed-payload work is complete; retrieving any additional production artifact still requires separate approval. No sender/runtime was executed, no software was installed, and AlphaLink implementation and technology selection have not begun.

## Phase 3H managed logic reconstruction checkpoint

REA's retained managed CIL graph narrows the sending-engine boundary but does not reconstruct its algorithms. Verified references include JSON/CLI dispatch, Npgsql and Dapper, a direct TCP line-I/O helper, SMTP-shaped reply parsing, Parallel.ForEach, SSH.NET/SFTP, subprocess stdout capture, and Serilog. SMTP compatibility is inferred; literal commands, peer identity, exact database contracts, message bytes, recipient batching, retries, and completion semantics remain unknown. Evidence IDs, build-local method tokens, hashes, sanitized pseudocode, and per-workstream boundaries are in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md), with focused updates in the dataflow, message, failure, and evidence reports.

The managed evidence shows changed send.dll bodies and metadata between send and send1, but no supported behavioral difference. Both builds retain matching dependencies/manifests and the same counts for the principal database, TCP, and concurrency APIs; send1 adds metadata and network-interface helpers outside entry-rooted direct-call reachability. This is a static call-graph conclusion, not behavioral equivalence.

The CIL-only logic pass is complete. It confirms a task fan-out over elements of a helper-returned `JArray`, repeated obfuscated JSON token access in the worker, and worker exception logging. It does not establish that the elements are recipients or recover config keys, SQL, message/MIME bytes, TCP commands/protocol, per-item outcomes, retry behavior, or terminal-state reconciliation. The user authorized this CIL-only route by asking to resume. High-level C# decompilation was not performed; NuGet/ILSpy findings above remain historical tool-selection context. No DMS change, sender execution, or AlphaLink implementation occurred.

### Phase 3H authorized unblock checkpoint — 2026-10-21

The user authorized the official ILSpy 11.0.0.9375 Linux x64 release archive. Its downloaded SHA-256 matched the expected digest and it is extracted in a private mode-0700 directory outside the repository. The archive contains the desktop ILSpy app and decompiler library but no ilspycmd CLI. The single bounded direct SSH host-key scan exited 1 without an ED25519 key; the fingerprint was therefore not established, and the sanitized result cannot distinguish connectivity, timeout, or protocol negotiation. No sender artifact was transferred or analyzed. No DMS mutation, production execution, or AlphaLink implementation occurred.

### Phase 3H resumed analysis status — 2026-10-21

At the Phase 3H checkpoint, the authorized CIL-only pass used existing local copies and complete retained member records; local artifact hashes matched prior records, with no new transfer or remote custody check. It added verified task fan-out and obfuscated worker JSON-token findings. REA and temporary files were closed/removed at that checkpoint. Phase 4B later refreshed custody and re-transferred the approved sender artifacts; subsequent static CIL work identified SMTP-shaped reply parsing and the leading SMTP-compatibility inference. Literal commands, message construction, recipient association, and recovery semantics remain unknown. Current static session status is in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md).
