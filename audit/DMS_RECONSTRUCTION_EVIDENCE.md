# DMS Reconstruction Evidence Index

**Purpose:** Consolidated index across Phase 1–4B reports and evidence. This is the navigation and contradiction register for the DMS reconstruction.

## Evidence classes

- **VERIFIED / OBSERVED:** directly seen in source, non-secret configuration, page metadata, or bounded remote ELF metadata.
- **DERIVED:** static call/data relationships connect multiple source components.
- **INFERRED:** a configured or indirect relationship suggests behavior but active production state is unverified.
- **UNKNOWN:** not established from available evidence.
- **BLOCKED:** further evidence needs an approval or credential/safety condition not met.

## Component and source index

| Component | Verified source paths / call sites | Frontend relationship | Data models | Confidence and open limit |
|---|---|---|---|---|
| Laravel entry and auth | app/Providers/RouteServiceProvider.php; app/Http/Kernel.php; app/Providers/AuthServiceProvider.php; routes/web.php, admin.php, dashboard.php, mailer.php | Dashboard/admin/mailer page groups use web/auth and selected role/owner gates. | users and settings | **VERIFIED source**; no dynamic role testing or tenant-boundary proof. |
| Campaign validation and process launch | CampaignController.php:189-326,699-807; CampaignAction.php:22-110,293-328,448-517,519-569; BackgroundProcess.php:41-63 | Campaign forms and action endpoints map to controller; store_send may launch immediately. | campaigns, campaign_logs, email_logs, visitors | **VERIFIED source path**, native behavior unknown. |
| Drop and warmup | DropController.php:35-40,222-376,482-529; DropAction.php:24-36,329-369,455-605; WarmupController.php:218-314,391-550; SeedCampaignAction.php | Visible drop/warmup/seed UI; GET drop init/clone/clear may mutate. | drops, drop_visitors, seed_campaigns, seed_lists, seed_emails | **VERIFIED source**, no route called in Phase 2/3; Phase 1 GET caveat remains. |
| Native sender | `/var/app/storage/app/engine/send` and `send1`; caller contracts in CampaignAction and config/system.php | Send UI launches a configured ELF with mode/config inputs. Phase 4B ties PHP's generated Base64-JSON file to managed `args[1]`; one mode passes its parsed object into a JArray worker path. | file-backed recipient/config artifacts; recipient-file contents were not read | **PARTIAL:** config handoff, managed metadata, worker fan-out, TCP host/port lookup, and an SMTP-shaped reply parser are verified; SMTP is the leading protocol inference, while exact commands, PMTA peer identity, array semantics, MIME, recipient outcomes, retries and completion remain unresolved. Hashes and evidence limits are in DMS_NATIVE_SENDER_ANALYSIS.md. |
| Gmail sender | routes/mailer.php:129-186; GcampaignAction.php; GcampaignController.php; GcampaignSend.php; GmailLib.php | Separate Google campaign/project/account UI and role group. | projects, accounts, sub_accounts, campaigns | **VERIFIED source**, no OAuth or Gmail API call. |
| Audience/suppression | EmailAction.php:21-110; Suppression.php:49-164; MixWriter.php | List/mix/subscriber/admin surfaces. | emails, lists, virtual_lists, subscribers, negatives | **VERIFIED source/migration design**; no recipient rows; consent lifecycle partial. |
| Database schema | database/migrations; app/Models; config/database.php | Frontend table schemas are not populated from read-only audit. | 2014–2026 migration-defined entities | **PARTIAL**; no independently verified read-only DB identity, no catalog/version/grants query. |
| Queue and schedule | app/Jobs/ScheduleJob.php:22-180; app/Console/Kernel.php:60-140; config/queue.php; config/horizon.php | Schedule/status screens are action-oriented and were not invoked. | queue config and PMTA scheduler model | **VERIFIED configured source**, live queue/workers unknown. |
| Engagement tracking | routes/api.php:23-36; RedirectHandlerController.php:38-340; AddVisitorLogJob.php; AddDropVisitorLogJob.php; ChangeEmailTypeJob.php; ChangeDropEmailTypeJob.php | Stats, redirects and unsubscribe paths feed UI/reporting. | visitors, drop_visitors, email_logs, emails | **DERIVED source flow**; no endpoint/events accessed. |
| PMTA delivery feedback | ServerProcessPmtaLogs.php:38-285; PmtaBounce.php:22-114; ServersInstall/Centos9.php:350-397,413-458; ReplyExtract.php; ReplyExtractorController.php | PMTA delivery/bounce files and inbound mbox/reply extraction; no operations used. | daily stats and recipient type; virtual reply audience | **PARTIAL:** PMTA FBL/inbound configuration and manual reply extraction are source-verified; complaint classification, recipient matching, suppression and live routing unknown. |
| Revenue attribution | SponsorLead.php:44-360; SponsorManagerFactory.php; sponsor adapters | Sponsor/offers and revenue dashboard surfaces. | visitors/revenue fields, campaign/drop, email logs | **DERIVED source flow**; providers and conversion rows not queried. |
| Infrastructure/provider plane | PmtaAction.php; ServersInstallFactory.php; CloudManagerFactory.php; DomainsProviderFactory.php and adapter classes | Server/domain/IP/provider/PMTA admin pages. | servers, providers, domains, IPs, MTAs and histories | **VERIFIED source operations**; no provider/server record or external action. |

## Frontend-to-backend reconciliation

- **VERIFIED:** DMS identifies itself in the authenticated dashboard; visible navigation includes campaigns, drops, warmups, Google campaigns, lists/subscribers, statistics, PMTA, servers/domains/providers and utility pages. See DMS_REFERENCE_AUDIT_PHASE_1.md and DMS_REFERENCE_ROUTE_EVIDENCE.md.
- **VERIFIED:** backend route declarations map selected frontend families to controllers and middleware. Several UI candidates are state-changing; no candidate is presumed safe from its HTTP method alone.
- **DERIVED:** campaign UI → controller validation/owner gate → suppression snapshot → base64 JSON config → shell launch → external ELF.
- **DERIVED:** API tracking requests → queued visitor/state jobs → event tables → daily aggregators → reporting UI.
- **DERIVED:** PMTA CSVs → SFTP processing/archive → counters and recipient-state changes.
- **DERIVED:** affiliate sales reports → SponsorLead parser → revenue visitor/state rows → revenue reporting.
- **VERIFIED separate path:** Google-linked campaigns → OAuth-backed Gmail API send/draft command; not the standard ELF path.

### Native sender evidence added in Phase 3 and 3F

- **VERIFIED:** both copied ELF hashes matched remote metadata before REA; both were statically inspected and never executed. `__libc_start_main` receives a function pointer relocated to `0x18910`; that routine hands `argc`/`argv` to internal code at `0x180b0`.
- **VERIFIED:** Phase 3F used REA 6.7.0 with Ghidra 12.1.3/JDK 21 and a 2 GiB headless heap. The linked entry `0x18910`, dispatcher `0x180b0`, dynamic loader `0x12b30`, and resolver caller `0x17d80` were decompiled at Ghidra addresses `0x118910`, `0x1180b0`, `0x112b30`, and `0x117d80` after the ELF load-base adjustment.
- **VERIFIED:** the entry forwards `argc`/`argv` to a .NET 7.0.13 apphost dispatcher. The dynamic loader's observed caller resolves `libhostfxr.so` / `hostfxr_main`; the dispatcher takes the bundle-startup path for a nonzero bundle-header field. Both artifacts contain the apphost application-name string `send.dll`.
- **LIMITED NEGATIVE EVIDENCE:** the native apphost disassembly/import evidence exposes no direct socket/TLS/HTTP/SMTP call targets. This does not characterize the bundled managed application.
- **Historical Phase 3F checkpoint:** the bundle manifest and managed payload had not yet been parsed. Phase 3G supersedes that checkpoint: the managed assemblies and CIL were inspected as summarized below. At the Phase 3G cutoff, mode/config field mapping, actual transport protocol, message headers/MIME, recipient flow, pacing, retries, PHPMailer linkage, and Laravel completion reconciliation remained unknown. Later Phase 4B CIL narrows transport to a reachable TCP text path with SMTP-shaped reply parsing; SMTP compatibility is the leading inference, while literal commands and peer identity remain unresolved.
- **VERIFIED cleanup:** REA was closed with `open=false` and `cleanup_pending=false`; the two 0600 copies, 0700 private directory, and temporary transfer helper were removed. No sender was executed.

### Phase 3G approved managed-payload analysis

- **VERIFIED:** strict direct SSH reconfirmed the trusted ED25519 fingerprint and previously recorded SHA-256 values before copying only `send` and `send1`. The local originals were mode `0600` in a mode `0700` directory outside Git. No DMS file or service was modified.
- **VERIFIED:** a bounded parser validated both bundle-v6 manifests, host/payload boundaries, file ranges, hashes and path safety. Each contains 17 assemblies, matching `.deps.json` and `.runtimeconfig.json`, no native-binary/symbol entry, and a terminal 995-byte manifest. Exact assembly hashes and metadata are recorded in DMS_NATIVE_SENDER_ANALYSIS.md.
- **VERIFIED:** REA reports complete managed metadata coverage for both `send.dll` assemblies. `Main(string[])`, dotenv/JSON and Npgsql/Dapper helpers, reachable `Parallel.ForEach`, SSH.NET SFTP, Serilog, subprocess, and TCP text-stream calls are present. Internal names are heavily obfuscated.
- **VERIFIED:** the two dependency manifests, runtime configurations, and all 16 dependency assembly hashes match. The main `send.dll` differs; `send1` has one extra managed type, five MethodDefs, 34 call edges, and a `System.Net.NetworkInformation` assembly reference. The entry-point CIL body differs. This does not establish semantic behavior differences.
- **INFERRED:** the raw TCP helper is very likely SMTP-compatible: it connects to a host/port, writes UTF-8 lines ending in CRLF, sends an initial decoded string concatenated with the local DNS host name, and parses `2xx`/`3xx`-style status lines with `-` multiline continuation. The command text remains obfuscated, so this is not a literal-command confirmation. No SMTP/MIME library is used by the inspected path, and no PMTA-specific peer behavior is established.
- **VERIFIED STATIC CALL PATH:** the JArray worker constructs a wrapper whose constructor creates the concrete class implementing the observed TCP interface; the matching interface method calls the TCP connection helper. This establishes the worker-to-TCP path in the inspected `send.dll` build, without establishing what each array item represents or observing a live connection. Hash-bound method-view Evidence IDs and the raw metadata-table cross-check are recorded in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md#follow-up--worker-interface-dispatch-to-concrete-tcp-helper-2026-10-21).
- **UNKNOWN:** exact mode/config fields, recipient/batching relationship, message/MIME construction, TLS/auth, PMTA relationship, retry/checkpoint/completion semantics, and PHPMailer linkage.
- **VERIFIED cleanup:** REA reports `open=false`, `cleanup_pending=false`; private copies, extracted files, and temporary parser/transfer helpers were removed and the destination was verified absent. No sender or managed assembly was executed; no additional software was installed.

## Data model evidence

Migration-defined domains include users/roles; lists/emails/virtual lists/subscribers; campaign/drop/warmup entities and histories; visitor/drop visitor/email logs; daily statistics; sponsors/offers/assets; server/cloud/domain providers, domains, IPs and MTAs; Gmail/Outlook accounts/projects; and SPF graph tables.

Migration source shows mixed enforcement: 34 constrained calls, two explicit foreign-key declarations counted in inspected source, JSONB arrays and file paths, and event rows with uneven uniqueness. Live constraints, actual schema state and grants remain **UNKNOWN/BLOCKED**.

## Contradiction and unknown register

| Question | Evidence | Status |
|---|---|---|
| PHPMailer location/version/caller | Owner confirms use; app Composer/PHP search found no call/package; managed imports contain no PHPMailer, `System.Net.Mail`, MailKit, or MimeKit. Raw SMTP helper and Gmail API sender are distinct paths. | **UNKNOWN**; managed reply grammar supports SMTP compatibility as an inference but does not establish PHPMailer, and other PHP/global paths were not inspected. |
| Native sender mode and message behavior | PHP provides mode/config contract; REA verifies managed `Main(string[])` with string comparisons/switches and a reachable TCP text-stream helper whose reply parser accepts SMTP-shaped status lines. | **PARTIAL / UNKNOWN**; SMTP compatibility is the leading inference, but mode mapping, literal commands, peer identity, message/MIME format and delivery semantics remain unresolved. |
| Managed transport | Reachable `TcpClient.Connect(host,port)`, UTF-8/CRLF network writes, `StreamReader.ReadLine`, response-class checks for `2`/`3`, and `-` multiline handling. | SMTP-compatible behavior is the leading inference; exact commands, TLS/auth mode, MIME, and whether the connected server is PMTA remain unknown. |
| Active queue backend | Redis is default in config; runtime override/process state unavailable. | **INFERRED**. |
| Live database shape | Migration source inspected; read-only identity not established. | **BLOCKED** at migration-defined schema. |
| Complaint/FBL processing | PMTA feedback-loop config and inbound mbox reply extraction exist; no complaint-specific classifier or suppression update found in the inspected path. | **PARTIAL/UNKNOWN:** actual FBL routing and complaint-to-recipient suppression remain unverified. |
| Phase 1 side effects | Drop GET can create draft; app-health behavior and middleware writes are uncertain. | **UNKNOWN**, no state check or reversal. |

## Report links

- DMS_COMPLETE_COMPONENT_MAP.md — component map and main execution relationships.
- DMS_NATIVE_SENDER_ANALYSIS.md — hashes, custody, REA static findings, and native evidence limits.
- DMS_MESSAGE_COMPOSITION.md — message input vs final MIME boundary.
- DMS_CAMPAIGN_STATE_MACHINE.md — observed and unknown lifecycle transitions.
- DMS_SENDING_DATAFLOW.md — source-derived sending flow.
- DMS_TRACKING_ATTRIBUTION_FLOW.md — event, feedback and revenue flows.
- DMS_FAILURE_RECOVERY_ANALYSIS.md — retry and recovery evidence.
- DMS_SECURITY_BOUNDARIES.md — trust boundaries and security observations.
- DMS_ARCHITECTURAL_WEAKNESSES.md — functional and operational assessment.
- ALPHALINK_IMPROVEMENT_OPPORTUNITIES.md — non-stack-specific product/design lessons.
- DMS_REVERSE_ENGINEERING_FINAL_REPORT.md — consolidated phase findings and remaining evidence boundaries.

Prior evidence remains in the Phase 1/2 reports indexed from PROGRESS.md.

## Phase 3H — retained managed CIL evidence

- VERIFIED at the historical Phase 3H checkpoint: REA's binary session was closed (`open=false`, `cleanup_pending=false`). That resumed pass inspected already-present private copies/extracts and did not make a new transfer or revalidate remote custody. Phase 4B later refreshed custody and opened a new static session; see the Phase 4B follow-up below and the current status in DMS_NATIVE_SENDER_ANALYSIS.md.
- VERIFIED: retained main-entry member Evidence IDs are ev_5d59948236d2478963d81886e8601e906ab2425fe0115dbe0b36275d868d7102 (send) and ev_8ede0c07baca0f31d6df72fd89097ab23e2aa9258bc91e7f77fdda38c067cabe (send1). Entry TypeDef/MethodDef coordinates are build-local: 0x02000159/0x060005FA and 0x0200015A/0x060005FF.
- VERIFIED: each obfuscated entry accepts string[], parses a JSON object, contains string comparisons and five switches; both bodies are 2,690 bytes and 728 decoded instructions. Exact body hashes, focused view IDs, and whole-graph counts are in DMS_NATIVE_SENDER_ANALYSIS.md.
- VERIFIED: each entry-rooted graph reaches Npgsql/Dapper, dotenv/JSON, direct TCP text I/O, Parallel.ForEach, SSH.NET/SFTP, subprocess stdout capture, and Serilog paths.
- INFERRED: Enumerable.Skip/Take may support slicing; no recipient or batching relationship was recovered. The sender's reply grammar strongly suggests SMTP compatibility, but the obfuscated command text and server identity do not establish a PowerMTA-specific exchange.
- UNKNOWN: exact Laravel config mapping; SQL/data models; recipient selection/state updates; message construction/MIME; TCP commands and acceptance semantics; retries, cancellation, progress, completion reconciliation; PHPMailer location/relationship.
- BLOCKED after authorization, at verification/tool identity: algorithm-level reconstruction needs decoded managed method bodies. The fresh approved transfer attempt stopped when strict host-key preflight could not establish/attest the key before authentication; no files were copied or extracted. Official NuGet metadata did not list ILSpy CLI 11.0.0.9283 and its version-specific package hash endpoint returned 404. The official GitHub v11.0 Linux x64 full archive is 11.0.0.9375 and was not substituted. No tool was acquired or installed. See the execution checkpoint in DMS_NATIVE_SENDER_ANALYSIS.md.

### Phase 3H authorized unblock — 2026-10-21

- VERIFIED: exact official ILSpy Linux x64 11.0.0.9375 archive SHA-256 matched 046eecc9e37390a117dcf1aa2a198ba628d07e05ac86fb3bc03599adaa4302f3; archive extracted to a mode-0700 directory outside the repository.
- VERIFIED: archive includes the ILSpy apphost and ICSharpCode.Decompiler.dll; inventory contains no ilspycmd CLI.
- BLOCKED: a single bounded direct ssh-keyscan exited 1 with no ED25519 key, so the trusted fingerprint could not be compared. Sanitized output was insufficient to distinguish connectivity, timeout, or protocol negotiation. No authenticated SSH, transfer, extraction of sender artifacts, or managed analysis occurred.
- UNKNOWN: sender algorithm findings remain at the Phase 3G evidence boundary documented above.

### Phase 3H resumed CIL checkpoint — 2026-10-21

- HISTORICAL Phase 3H: REA's managed provider completed metadata/CIL inspection of both existing private assembly copies without loading or executing target code. The local sender artifact hashes matched previously recorded remote values; there was no fresh transfer or custody revalidation at that checkpoint. Phase 4B later re-transferred both artifacts and revalidated the same hashes; see [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md).
- VERIFIED: the core method enumerates a helper-returned `JArray`, creates one action per element, invokes `Task.Run(Action)`, collects tasks, and calls `Task.WaitAll`. This establishes task fan-out across JSON-array elements only; their business meaning is unknown.
- VERIFIED: the worker has repeated obfuscated JSON token field lookups and string conversions, with exception logging. Exact field names, item meaning, and outcome semantics remain unknown.
- VERIFIED at the historical Phase 3H checkpoint: REA was closed (`open=false`, `cleanup_pending=false`). Private copies and the downloaded ILSpy archive/extraction were removed after report reconciliation; a follow-up check confirmed both temporary directories absent. Later Phase 4B work re-transferred the approved sender artifact and reopened REA for static analysis.
- BOUNDED LIMIT: NuGet/ILSpy package findings and the successful strict SSH diagnostic above explain the earlier blocker. High-level C# decompilation was not performed and is no longer required for this CIL-only checkpoint.

No production sender or managed application code was executed. The resumed CIL analysis used existing private local copies; it generated no new SSH traffic, production data operation, or system mutation.

## Phase 4B fresh sender-input cross-check — 2026-10-21

- **VERIFIED PHP source:** campaign and drop actions write Base64-encoded JSON files and launch the configured sender with a mode and config path. Campaign payload omits recipient snapshot/list/message fields; drop payload includes list/filter settings and message fields but no resolved recipient rows or addresses. Source identities and exact hashes are in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md#php-configuration-handoff-cross-check--2026-10-21).
- **VERIFIED managed CIL:** entry `0x060005FA` has direct `File.Exists`, `File.ReadAllText`, and `JObject.Parse` calls. Main also calls core `0x060005AA`; core getter `0x060005A6` reads field `0x04000D80`, which is written by setter `0x060005A7` inside constructor `0x06000593`. Core enumerates the returned value using `JArray.GetEnumerator`, creates a `Task.Run(Action)` per item, and calls `Task.WaitAll`. Evidence `ev_b709885456c46084bb0e21506cacad1d923a6ce2698258d2799ad1fee4814cdd` is bound to `send.dll` SHA-256 `92d5332ea4f10e87302c418ac25b4245f6c68cc9544ae1e8b0f8ccb9b74c3564` / MVID `2bea6143-dcf2-4ea0-5004-7876afa8b989`.
- **VERIFIED managed CIL:** new evidence `ev_56a7652dce0343c257bebbfbc314c1401de725bcecc1e8284bfce8e404153a52` binds to `send.dll` SHA-256 `92d5332ea4f10e87302c418ac25b4245f6c68cc9544ae1e8b0f8ccb9b74c3564` / MVID `2bea6143-dcf2-4ea0-5004-7876afa8b989`. Main stores `File.ReadAllText` output, calls `0x0600028B` as a string/regex predicate, and on its true path passes the same string to `0x0600028D`; that helper calls `Convert.FromBase64String`, decodes with UTF-8, and Main passes the result to `JObject.Parse`.
- **VERIFIED managed CIL/metadata:** the string helper in `send.dll` first checks a concurrent dictionary; its fallback builds a resource name and calls `Assembly.GetExecutingAssembly` / `GetManifestResourceStream` (`ev_db83e43784f838792014258c71e200c8ce6375368e224dcc7dcc9f50302c41de`). Static reconstruction matches the generated 11-byte name to the first embedded resource's raw metadata name; the 149-byte helper body is a custom back-reference decompressor. The fallback CIL exposes a per-string 32-bit state recurrence, bytewise XOR pass, and conditional repeated-token-byte XOR pass; exact record indexing and decoded strings remain unresolved (fresh REA evidence: `ev_031d3a0052c494000de66ca46725d1fbb53e51f48be2b82991f1277bfc52c569`, `ev_1b3dff5984618ef1e0bebf4260952f793dd06ba0d3f948b27e1182658049d9ff`, `ev_d3fb2c6c075f9b567e77f37108b960156918de943be1de2dffa10f189b3e433d`). The assembly declares `.NETCoreApp,Version=v7.0`; bundle format 6.0 is a separate container fact (`ev_cc73592693c9bddf70cadfcece846478324fd8042abeea67d0d91de252ed13a2`).
- **VERIFIED managed CIL:** constructor `0x06000593` accepts an object, uses dynamic `Microsoft.CSharp.RuntimeBinder.Binder.GetIndex` call sites with strings returned by the obfuscated helper, and passes an indexed result to the setter for the `JArray` field consumed by the task-fan-out core (`ev_4916f8486a8afa3923d6dc520339bdd00a3853a35f6206b247f241a6fca3e166`).
- **VERIFIED:** fresh Main-body CIL resolves the PHP-to-managed handoff: `args[0]` selects the mode, `args[1]` is read as the config-file path, its text is Base64-decoded to UTF-8 JSON, and that result is passed to `JObject.Parse`. PHP launches `send_<mode> <config-path>`, so the generated file is directly linked to this read. In one branch, Main's parsed `JObject` local is passed to constructor `0x06000593`, which dynamically selects the `JArray` consumed by the task-fan-out core. The helper's exact regex and property keys remain obfuscated; whether array elements are recipients remains unknown.
- **DERIVED, same-thread iterator path:** in the string resolver, the constructor state expression evaluates to `-2`; the constructor and immediate `GetEnumerator` path both read `Thread.CurrentThread.ManagedThreadId`. Under the ordinary synchronous call path, the iterator reuses its instance rather than taking the clone branch that copies a captured field to the working field. This narrows the state-machine replay. An earlier position calculation failed bounds checks, but it omitted `add` at IL `0x0251` and is invalidated; no offset or plaintext is claimed until the arithmetic is rerun from corrected CIL.
- **UNKNOWN:** how each integer string ID maps to a record in the selected first resource, the decoded string values, the role of the second embedded resource, and whether any decoded value names a recipient field. Static resource presence does not establish that the resource contents are JSON or recipient data.
- The corrected SFTP client successfully re-transferred the pinned sender artifact read-only; its SHA-256 matched the recorded remote hash. No sender execution or mail operation occurred.
