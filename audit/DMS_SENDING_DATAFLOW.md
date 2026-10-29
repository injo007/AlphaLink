# DMS Sending Dataflow — End-to-End Reconstruction

**Status: Phase 4B refreshed the PHP-to-managed sender cross-check. PHP launches a command plus config path; managed `Main(string[])` requires two arguments and parses the second as Base64-JSON. Two separate JArray/TCP paths are now identified. The 11-character `g` branch reaches `0x06000593`/`0x060005AA`; PHP `global_test` is the strongest derived candidate by command shape and its `servers[]` payload. A 12-character `s` branch reaches `0x060003C9`/`0x060003E6` and uses the same unresolved top-level property and per-item host/port keys. `send_delayps` and `send_pickups` are shape candidates for the 12-character `s` branches in an unresolved order; the regular-campaign builder lacks `servers`, so a possible input-contract gap remains if either maps there. The 10-character `d` branch reaches Dapper-bearing handler `0x0600012F` and may match a drop command, but no mode is assigned. Another 12-character `s` branch reaches Dapper handler `0x06000130`. All command and JSON literals remain unresolved. For the `0x060005AA` path, the worker-created TCP object both writes bytes and reads SMTP-shaped replies: the parser checks first character `2` or `3` and treats `-` as continuation. This makes SMTP compatibility the strongest protocol inference for that path; a complete SMTP transaction is unproven. The second JArray/TCP path's reply semantics, both peer identities, and durable per-item outcomes remain unknown. No send path was executed.**

## End-to-end map

~~~mermaid
flowchart TD
  Editor[Campaign editor and request] --> Validate[Owner gate and request validation]
  Validate --> Audience[Selected list / virtual mix]
  Audience --> Filter[Recipient-type, offer exclusion, suppression filters]
  Filter --> Snapshot[File-backed recipient snapshot]
  Validate --> Persist[Campaign record, assets, routing, state]
  Snapshot --> Config[Base64 JSON send configuration]
  Persist --> Config
  Config --> Immediate[Immediate launch]
  Config --> Delayed[ScheduleJob on schedule queue]
  Delayed --> Recheck[Recheck due time, status, restrictions, activity and file]
  Recheck --> Launch[BackgroundProcess / shell]
  Immediate --> Launch
  Launch --> ELF[send / send1 .NET 7.0.13 apphost]
  ELF -->|argc / argv; bundled startup| Hostfxr[libhostfxr.so and libcoreclr.so]
  Hostfxr --> Payload[Managed send.dll; static metadata/CIL inspected]
  Payload --> Dispatch[Obfuscated CLI dispatch]
  Dispatch --> ConfigRead[DotNetEnv and JSON helpers]
  Dispatch --> Database[Npgsql and Dapper calls]
  Dispatch --> Parallel[Parallel.ForEach processing]
  Dispatch --> Sftp[SSH.NET SFTP helpers]
  Dispatch --> InternalProc[Separate managed Process.Start helper; branch purpose unknown]
  InternalProc --> CapturedOut[Reads and trims child stdout; returns string]
  Dispatch --> Tcp[TcpClient host and port]
  Tcp --> TextStream[StreamReader / StreamWriter]
  TextStream -. SMTP-compatible inference; peer unknown .-> SMTP[External transport]
  SMTP --> Feedback[PMTA delivery/bounce files]
  Feedback --> Import[Scheduled SFTP processor]
  Import --> Stats[Daily counters and recipient-state jobs]
  Stats --> UI[Monitoring and reports]
~~~

The frontend-to-ELF and PMTA-file ingestion edges are static-source-derived. Ghidra confirms that the ELF layer is a .NET apphost which forwards arguments to its bundled managed application through `libhostfxr.so`. Phase 3G recovered the bundle manifest and inspected `send.dll` metadata/CIL. Fresh REA call-edge and member views tie the configured `send` entry method to a raw TCP wrapper that constructs `TcpClient(host, port)`, writes line-delimited text through `StreamWriter.WriteLine`, reads to end-of-stream through `StreamReader.ReadToEnd`, and closes the client. A further worker path constructs a TCP-backed object, writes bytes through its connected stream, and reads replies through `TextReader.ReadLine` on that same object; the parser checks first character `2` or `3` and treats `-` as multiline continuation. SMTP compatibility is now the strongest static inference for this worker path; literal commands and whether the peer is PowerMTA remain unknown. See the evidence and limits below and in the [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md).

The managed assembly also has a distinct Main-reachable `Process.Start` helper. Static call-graph traversal reaches it through `0x060005FA → 0x060004D1 → 0x06000261 → 0x06000435`; it is not in the direct call closure of the JArray worker. The helper accepts two strings, configures a child process, reads and trims its standard output, closes the process object, and returns the captured string. Its caller checks whether the text is blank or equals a hidden sentinel and, otherwise, calls `JObject.Parse` on it. The executable, argument components, sentinel value, and business purpose remain unresolved. This is separate from PHP's `BackgroundProcess` launching the sender itself; no evidence connects the helper's returned JSON text to the JArray result list or PHP launcher. Evidence IDs and bounds are recorded in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md#follow-up--captured-child-stdout-and-json-parse-boundary-2026-10-22).

### Managed TCP text-I/O evidence

For the configured `send` build, `Main` has a direct static call edge to obfuscated method `0x060004D1`, which constructs a TCP wrapper and calls its line-writing and response/close helpers. The worker path is also now statically connected through interface dispatch: worker `0x060005B0` reads a string and integer from each array item, constructs wrapper `0x06000257`, and that wrapper creates concrete type `0x02000007`, which implements interface `0x020000F2`; its matching implementation reaches `TcpClient.Connect(host,port)`. The helper opens a network stream and wraps it with an auto-flushing writer and a reader. The worker's writer emits UTF-8 lines ending in CRLF, and its response path parses `ReadLine` responses as 2/3 status classes with hyphen continuation. That is strong SMTP-compatible response evidence, but exact command text, peer identity, endpoint property names/values, and whether the path represents campaign delivery remain unresolved. The separate `send1` artifact has a distinct five-string interpolated-text helper and TCP path, but inspected PHP source configures `send`, not `send1`. Tokens and complete REA evidence IDs are in the sender analysis section linked above.

The sender's obfuscated string resolver selects the 19,285-byte embedded resource by constructing its exact 11-byte metadata name, then applies a per-string 32-bit state recurrence and bytewise XOR pass, a conditional repeated-token-byte XOR pass, and a custom back-reference decompressor before UTF-16LE decoding and string interning. The state recurrence and pass structure are visible in CIL; the record index/offset mapping and decoded values, TCP command text, and JSON field names remain unresolved. The decompressor's CIL has been reconstructed and cross-checked against REA's instruction histogram. See the string-resource follow-up in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md#follow-up--static-string-resource-name-and-transform-trace-2026-10-21) for the raw metadata bytes, resource hashes, and REA evidence IDs.

## Stage contracts and evidence

| Stage | Input → output | Evidence and failure/security boundary |
|---|---|---|
| Campaign editor | Lists, message content/header/assets, offer/ISP, from/subject placeholders, routing server/IP/MTA, pacing, seeds, bounce limit and mode → validated controller request. | CampaignController.php:189-326. Validation is source-observed; consent evidence and approval workflow were not established. Create with store_send may send immediately. |
| Permissions | Authenticated session and owner relation → owner-approved send/update/schedule path. | CampaignController.php:699-807 and AuthServiceProvider. Static policy only; not dynamically tested. |
| Segmentation | Selected relational list rows or virtual-mix file → filtered recipient records. | EmailAction::create_user_mix; Suppression::load_emails; EmailAction::suppression. Filters include recipient type, offer exclusion, suppression file and mix criteria. |
| Snapshot | Filtered audience → local send file with duplicate keys collapsed. | Suppression.php:49-164 and CampaignAction.php:22-110. Snapshot is point-in-time; update path does not appear to rebuild it. PII file contents were not read. |
| Configuration | Campaign/identity/mode/routing/pacing parameters → JSON encoded as Base64 in a temporary configuration file. | CampaignAction.php:293-328. Secret-bearing fields and runtime config values were not examined. |
| Scheduling | Requested time and campaign parameters → delayed ScheduleJob and scheduled state. | CampaignAction.php:486-517; ScheduleJob.php:22-180. Queue config is Redis by default; live queue and active override unknown. |
| Process launch | Validated mode and generated config path → nohup sender process, PID, progress fields. | BackgroundProcess.php:41-63 and CampaignAction.php:448-470,565-569. This PHP-to-sender launch is distinct from the Main-reachable managed `Process.Start` helper described above. Shell launch output goes to /dev/null; shell escaping and completion reconciliation are open questions. |
| Native apphost / managed sender | ELF path + mode + config path → apphost `argc`/`argv` → .NET 7 `send.dll` entry → obfuscated switch/call graph. Reachable helpers include dotenv/JSON, Npgsql/Dapper, `Parallel.ForEach`, SSH.NET SFTP, and TCP text I/O. | Bundle manifest and managed metadata/CIL verified by REA; full evidence in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md). SMTP compatibility is the leading inference from its reply parser; exact commands, peer identity, modes, config fields, recipient contracts, MIME, throttling and outcomes remain **UNKNOWN**. |
| Gmail-linked sending | Campaign snapshot and Google account token → Gmail API send/draft and progress/stat updates. | GcampaignSend.php and GmailLib.php. Separate path; no token/API call made. |
| Delivery feedback | Remote PMTA CSV files → statistics updates, recipient state jobs, archive movement. | ServerProcessPmtaLogs.php:38-285. Scheduled command is state-changing and was not run; idempotency and partial-file recovery unknown. |

## Recipient, consent, and suppression model

- Migration-defined emails are associated with lists and have lifecycle type, GEO/vertical/sex/device-related attributes and JSONB offer exclusions.
- Subscriber signup records use a separate table with email and IP/GEO/device/browser fields; the inspected migration did not show a list relationship or explicit consent-status field. A separate consent/promotion subsystem may exist but is **UNKNOWN**.
- Segmentation mixes relational email rows, JSONB include/exclude criteria and file-backed virtual lists.
- Offer exclusions and suppression files are applied before the audience snapshot. Unsubscribe and opt-out jobs update recipient type and/or exclusions in the tracking path.
- Since campaign snapshots are files, suppression changes after snapshot generation may not affect an already created campaign file. This is a **DERIVED risk**, not a confirmed live incident.

## Outputs, observability, and recovery

The visible application state includes send status/progress/count/PID/parameters, history rows, daily statistics, visitor/email logs, and PMTA import counters. The PHP launcher discards the sender's child output to /dev/null. The separate managed `Process.Start` helper reads and returns a different child process's stdout to its managed caller; its command, arguments, caller purpose, and any downstream use remain unknown. Managed Main initializes a Serilog File sink under `AppDomain.BaseDirectory` with an obfuscated path suffix; worker exceptions call Serilog. The actual filename, rotation/retention settings, log contents, and sender exit-status/retry/completion contract remain unverified.

ScheduleJob performs pre-launch revalidation. The inspected kill path uses kill -9 and clears persisted state without visible process identity validation. There is no evidence in the available caller source of transactional outbox semantics or durable per-recipient send acknowledgments.

## Open contracts

- Exact line format, field order, encoding and versioning of the recipient snapshot.
- Full config schema consumption and compatibility between PHP code and each bundled managed payload. Managed code calls dotenv and JSON helpers, but exact keys, options and field mappings are unknown.
- Recipient chunk limits, ordering guarantees, duplicate handling and concurrency coordination. `Parallel.ForEach` and LINQ `Skip`/`Take` are present, but their role in recipient delivery is not established.
- TCP endpoint values, credentials, TLS/auth modes, literal commands, peer identity, and acceptance semantics. The managed client uses line-oriented TCP stream I/O and parses SMTP-shaped replies, so SMTP compatibility is the leading inference; a custom exchange reusing that grammar remains possible.
- The purpose and command/argument values for the separate managed child-process helper; its stdout capture is not evidence of SMTP replies or sender completion.
- Mapping from SMTP acceptance/rejection to campaign counters and recipient lifecycle.
- How partially sent files, worker crashes and process exits are retried or reconciled.

No SMTP transaction, preview/body-generation call, endpoint request, schedule, job, sender, or campaign was run.

## AlphaLink comparison: selected SMTP route and message tags — 2026-10-29

The read-only DMS Create Campaign UI presents IP/VMTA/SMTP route selection
separately from message-template tags. DMS source evidence confirms that the
chosen PMTA route alias is sent in `X-virtual-MTA` (E-CAMP-78), but the
available static and browser evidence does not establish that `[smtp]` or
`[smtp_name]` alone changes the DMS route. Treat that specific DMS behavior as
**UNKNOWN**.

AlphaLink's verified local behavior is separate: SMTP Settings persists
`PmtaSmtpConfig` records and pushes a PMTA vMTA named
`smtp-<IP>-<config id>`. The campaign catalog now exposes that same active,
user-owned record for the linked selected IP and as an explicit SMTP route.
For AlphaLink Campaign Test only, a rendered `[smtp]` or `[smtp_name]` on IP
mode chooses that route when exactly one active account is linked; zero or
multiple accounts fail before transport. This is an AlphaLink compatibility
behavior, not a claim of DMS tag-to-route parity. Normal native campaign SEND
is unavailable, and all tests in this slice use fake transport.

AlphaLink now records the resolved `outbound_route` and `X-Virtual-MTA` alias in the encrypted manual Campaign Test snapshot and displays them in the campaign test log. This makes IP ingress versus the PMTA outbound relay auditable after the test; it does not establish DMS tag-to-route parity or enable normal campaign sending.

## Phase 3H managed call-graph refinement

This section records retained REA metadata/CIL facts. Call-edge reachability does not establish execution order or message semantics.

~~~mermaid
flowchart TD
  PHP[PHP launch: mode + temporary Base64 JSON path] --> Main[Obfuscated Main string array]
  Main --> Switch[Five switch instructions and opaque helpers]
  Switch --> Env[DotNetEnv and JSON helpers]
  Switch --> DB[NpgsqlConnection and Dapper]
  Switch --> Work[Parallel.ForEach and Task helpers]
  Switch --> SFTP[SSH.NET SFTP helpers]
  Switch --> Child[Process.Start and stdout capture]
  Switch --> TCP[TcpClient host and port]
  TCP --> IO[StreamWriter WriteLine and StreamReader ReadToEnd]
  IO --> Protocol[SMTP compatibility inferred; commands, peer and delivery meaning unknown]
  Switch --> Log[Serilog warning/error calls]
~~~

The root is void(string[]); it directly parses a JSON object and uses string comparisons and five switches. The entry-rooted graph includes 1,341 methods in send and 1,342 in send1; the full assemblies have 44,638 and 44,672 direct call edges. Delegate, reflection, and virtual dispatch are not proven by this graph.

Each build has ten NpgsqlConnection(string) calls, six Dapper Execute, one Query, and three QueryFirstOrDefault calls in the reachable graph. One caller includes both Enumerable.Skip and Take, which does not identify a recipient batch. SQL, tables, field mappings, transactions, recipient selection, and state updates remain UNKNOWN.

Reachable transport references form a direct line-oriented TCP/text-I/O boundary: TcpClient(string,int), NoDelay, GetStream, auto-flushing StreamWriter, WriteLine, StreamReader.ReadToEnd, and close. A separate ReadToEnd belongs to captured child-process stdout. The selected worker also parses `2xx`/`3xx`-class status lines and `-` multiline continuation, making SMTP compatibility the leading static inference. The literal command sequence, connected peer identity (including whether it is PowerMTA), TLS/authentication, accepted-versus-delivered semantics, and per-item outcomes remain unknown.

PHP source documents a `send_<mode> <config-path>` handoff. Managed Main requires exactly two arguments and reads the second as that Base64-JSON temporary file; reachable helpers also load dotenv settings. Obfuscated mode mapping, keys, defaults, and field consumption remain UNKNOWN. Evidence IDs and build-local tokens are in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md).

### Fresh PHP-to-managed handoff cross-check — 2026-10-21

- `CampaignAction::build_send` (lines 293–328) writes Base64-encoded JSON with campaign/send/user IDs and pacing/configuration values. It omits the saved recipient snapshot path, selected list IDs, message content, offer/ISP and routing fields. `DropAction::send` (lines 528–605) supplies message and selection settings but no recipient rows or addresses. Both caller paths launch the configured sender with a mode and config-file path. The configured default executable is `send`; the separate `send1` file is not tied to a caller by inspected source.
- In fresh `send.dll` evidence `ev_b709885456c46084bb0e21506cacad1d923a6ce2698258d2799ad1fee4814cdd`, entry method `0x060005FA` directly calls `System.IO.File.Exists`, `System.IO.File.ReadAllText`, and `Newtonsoft.Json.Linq.JObject.Parse`. A focused raw-CIL review then confirmed the two-argument check and the `args[1]` file access. PHP's `send_<mode> <config-path>` launch passes that generated file as the second managed argument; the obfuscated mode and parsed-field meanings remain unknown.
- The same selected CIL ties fan-out to a stored value: `Main` invokes core `0x060005AA`, whose getter returns field `0x04000D80`, then the core enumerates it through `JArray.GetEnumerator`, starts a `Task.Run(Action)` per item, and waits with `Task.WaitAll`.
- New focused CIL evidence `ev_56a7652dce0343c257bebbfbc314c1401de725bcecc1e8284bfce8e404153a52` resolves the Base64 question: Main stores `File.ReadAllText` output in local 5, passes it to `0x0600028B` (a bool string/regex predicate), and on the true branch passes that same local to `0x0600028D`, which calls `Convert.FromBase64String` and UTF-8 `GetString`; Main immediately passes the decoded string to `JObject.Parse`. A fresh argument-index review links `args[1]` to this file read and PHP's config-path argument. Thus the generated PHP file is directly connected to the managed JSON parse path. The regex and property meanings remain unknown.
- The string resolver follows another obfuscated path: it checks a concurrent dictionary, then its fallback builds a resource name and calls `GetManifestResourceStream`. Static evaluation now matches the generated 11-byte name to the first embedded resource's raw metadata entry. Its byte transforms and decompressor are partly reconstructed, but decoded JSON keys remain unknown (REA IDs and hashes are in the [native sender analysis](DMS_NATIVE_SENDER_ANALYSIS.md#follow-up--static-string-resource-name-and-transform-trace-2026-10-21)). The assembly declares `.NETCoreApp,Version=v7.0`; bundle format 6.0 is a separate fact.
- A direct Main-body cross-check resolves the parsed-object link for one mode branch: local 3 holds the `JObject.Parse` result; Main loads local 3 to construct `0x06000593` at IL offset `0x097C`, then calls core `0x060005AA` at `0x0981`. The constructor dynamically selects an array using resolver ID `-1089654461`, but its plaintext property key is unknown. Worker `0x060005B0` reads a string and integer from each item using IDs `-1089657305` and `-1089657291`, and the selected values reach `TcpClient.Connect(host,port)`. PHP's `build_send_ips` output is a top-level `servers` array of records with `main_ip`, `main_port`, and nested per-IP VMTA data. The 11-character `g` branch makes PHP `global_test` the strongest source candidate for this worker because its command shape matches and its config carries `servers[]`; for that candidate, server-endpoint records are more likely than recipient rows, but the mode/property/item-key mapping is not verified. A second 12-character `s` JArray/TCP branch uses the same key IDs, and its PHP payload mapping remains unresolved; do not attribute either path generally to all drop/test or campaign modes.
- Core `0x060005AA` returns an owner `List<string>` after `Task.WaitAll`. The per-item worker adds a formatted string after its normal TCP read path and another formatted string in its `System.Exception` handler, then logs the exception message. Main joins the returned list and calls `Console.Write`; the inspected PHP launcher redirects child output to `/dev/null`, so this path does not establish durable result persistence. The strings are obfuscated and do not establish accepted/delivered state. Constructor `0x06000593` initializes this list once; the parallel workers share its owner and call `List<string>.Add` without a visible synchronization operation. This is a static concurrency risk because `List<T>` instance members are not guaranteed thread-safe ([Microsoft documentation](https://learn.microsoft.com/en-us/dotnet/api/system.collections.generic.list-1#thread-safety)); no runtime failure was induced. Main also initializes a Serilog File sink rooted at `AppDomain.BaseDirectory` with an obfuscated suffix; the actual path and retention behavior remain unknown.

### Dapper query/TCP branch `0x0600012F` (mode unresolved)

The complete managed CIL record `ev_1b3dff5984618ef1e0bebf4260952f793dd06ba0d3f948b27e1182658049d9ff` shows a distinct Main dispatch target, method `0x0600012F`, separate from the JArray `Task.Run`/`Task.WaitAll` path. That method calls Dapper-backed query helper `0x060000A9`, enumerates the result, reads obfuscated host/port fields from another JSON object, and later calls Dapper execute helpers `0x060000A8` and `0x060000A7`. The query path constructs a TCP client from that host/port pair and sets up text streams. Main dispatches this path from opaque ID `-1089664408` in a 10-character `d` branch. Separately, ID `-1089664358` in a 12-character `s` branch reaches JArray/TCP method `0x060003E6`, while ID `-1089664376` in an 11-character `g` branch reaches the `0x06000593`/`0x060005AA` JArray worker path. None of the resolver IDs is a recovered plaintext command. The query branch's method `0x0600055A` takes five string parameters, combines them with resource-backed text fragments, and calls `StreamWriter.WriteLine` twice. Main calls this helper at IL offsets `0x13ED`, `0x3002`, and `0x424D`; parameter meanings and whether each call corresponds to a particular query row remain unknown. The helper increments a counter and, on each 500th call, invokes `0x0600055B` followed by transport setup method `0x06000557`. `0x0600055B` writes another obfuscated line, calls `StreamReader.ReadToEnd`, and discards the returned string. This narrows the response behavior: the five-argument writer does not read or parse a reply per call, and the periodic helper discards its read result. The SQL and row meanings remain obfuscated; no evidence yet links those writes to per-recipient send outcomes, retries, or completion state. The caller discards the two Dapper helpers' Boolean returns and does not branch on the observed reply value. A direct MethodDef closure from `0x0600012F` reaches 87 methods and shows no `Parallel.ForEach`, `Task.Run`, `Task.WaitAll`, or `Thread.Sleep` call; indirect/dynamic concurrency remains possible. Do not carry the JArray worker's SMTP-shaped reply-parser finding over to this separate branch. Evidence: `ev_1b3dff5984618ef1e0bebf4260952f793dd06ba0d3f948b27e1182658049d9ff`, `ev_a9d969f0fac3757aed9d8da598b00bfbd42b4ec40d9dbc1c1309eb3802ef61e4`, `ev_df23c975a95a927ca06ef768e87e377e8a3857f4a18cd820bd33b2b5781b7083`.

The Dapper query's argument sources are now bounded to six config-object fields and one dictionary-derived integer. In constructor `0x0600010E`, opaque key IDs `-1089651879`, `-1089651965`, `-1089651955`, `-1089651843`, `-1089655253`, and `-1089651925` populate typed fields that `0x0600012F` passes to `0x060000A9`; the branch also reads integer field associated with ID `-1089651945` before querying. These IDs and types make the dataflow concrete while leaving JSON property names, query purpose, and row identity unresolved. Its two Dapper Execute wrappers construct hidden parameter objects from integer-converted values, but SQL text and parameter names are still unknown. A bounded read of the exact hash-matched CIL bytes corrects the earlier apparent receiver concern: simple `ldarg.0` instructions precede calls to instance accessors `0x06000129` and `0x0600010F` in all three repeated blocks. This does not identify the five `0x0600055A` text arguments, SQL, or their row/recipient meaning. See the [native sender analysis](DMS_NATIVE_SENDER_ANALYSIS.md) for tokens, offsets, evidence IDs, and limits.

### Worker exception propagation cross-check — 2026-10-21

Fresh REA evidence `ev_3c905ea7b32e40d8949a59aa2792e24f2f57eda46b72bf0221f88ed23133e84e` confirms an outer `System.Exception` handler on worker `0x060005B0`. Its handler appends an obfuscated formatted string, logs `Exception.Message`, and returns without rethrowing. Thus exceptions handled by this block do not themselves fault the associated worker task; the core still reaches `Task.WaitAll` and returns its result list. Main writes the joined strings to stdout, which the PHP launcher discards. In the Main branch after the core call at IL `0x0981`, the remaining path formats/times/joins the list, writes to `Console`, and logs warnings; no Dapper/Npgsql call or explicit file write appears in that tail. A follow-up over complete CIL evidence `ev_1b3dff5984618ef1e0bebf4260952f793dd06ba0d3f948b27e1182658049d9ff` traverses 131 methods from worker `0x060005B0` through direct MethodDef references and finds no direct Npgsql/Dapper query or execute call. Main's Dapper-bearing `0x0600012F` is a separate mode-dispatch call site from the `0x060005AA` JArray task branch. This narrows the explicit persistence path but does not exclude indirect dispatch, external TCP-side storage, or separate PMTA feedback reconciliation. The JArray item meaning and whether the other branch's SQL relates to campaign delivery remain unknown. No runtime failure or send was induced.

## Phase 3H authorized unblock checkpoint — 2026-10-21

The approved .9375 archive is now hash-verified and privately extracted, but it contains no `ilspycmd` CLI. The single bounded direct host-key scan returned no ED25519 key and no fingerprint; no transfer or target analysis followed. Recipient selection, SQL, batching, and state updates remain as previously classified. See the native analysis report for exact archive identity and SSH bounds.

## Phase 3H execution checkpoint — 2026-10-21

The approved fresh transfer stopped because a later strict host-key preflight could not establish/attest the key before authentication. No sender files were transferred or extracted. The requested ILSpy CLI `11.0.0.9283` could not be verified in the official package index; the official `v11.0` full Linux archive is `.9375` and was not substituted. No new database or recipient-flow evidence was obtained; the unresolved dataflow findings above remain unchanged. Details and provenance boundary: [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md).

## Phase 3H resumed CIL checkpoint — 2026-10-21

The user authorized the CIL-only route by asking to resume. Existing private assembly copies were inspected without a new transfer or remote custody check. New static evidence shows that a helper returns a `JArray`; the core sender method enumerates it, creates an action for each item, starts each action with `Task.Run`, collects the tasks, and waits with `Task.WaitAll`. This proves task fan-out over JSON-array elements, but does not identify those elements as recipients or show what work each element represents. The worker makes repeated obfuscated JSON token lookups and string conversions; exact keys, recipient selection, SQL/data mapping, and per-item outcome remain unknown. No sender or managed code was executed.

## Resolver record-reader refinement — 2026-10-21

Fresh REA evidence `ev_cd2a456c0b47d307b23f0e2e36497579b5c1f9bef8e87e10f19ac3a1f84f0d33` confirms that the string fallback's helper wraps the selected embedded-resource `Stream`, exposes that same stream to `0x0600048E`, and reads from it with `Stream.ReadByte` / `Stream.Read`. The fallback directly sets the stream position from `local14`; helper methods then assemble 16-bit and 32-bit values and read byte arrays. The control flow is now bounded more precisely, but the requested-ID-to-position replay remains unreconciled against the resource length. No decoded property key, host/port field text, or TCP line literal is claimed; see [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md#follow-up--resolver-resource-reader-boundary-2026-10-21).

## Follow-up — Main dispatcher and two JArray/TCP paths (2026-10-21)

- Main CIL evidence (`ev_a34531bc9f3468c65022bbbe33c2a6560ca04f97c9c4dc4f558369f569d32861`; focused page `ev_7d4b91936f76513174451b574c4651b464f5c978a92498fe20303ae917d44324`; branch view `ev_f004a25a6653f460b72f295bbcf549487befc49964d6593440d60d6f02588f11`) shows `-1089664376` in the 11-character `g` branch to `0x06000593`/`0x060005AA`. PHP `global_test` is the strongest source candidate by command shape and its `servers[]` config, but the literal/property/item keys are unresolved.
- A distinct 12-character `s` branch, ID `-1089664358`, reaches `0x060003C9`/`0x060003E6`. This second JArray/TCP path shares the same unresolved top-level property ID and two item-field IDs as the other worker. `send_delayps`/`send_pickups` are candidate source commands for the 12-character branches, not recovered assignments. The regular-campaign config has no `servers`, so keep the possible input-contract gap open conditionally.
- ID `-1089664345` reaches the separate Dapper handler `0x06000130`; ID `-1089664408` is in the 10-character `d` branch to handler `0x0600012F`, not `send_delay`. The earlier drop/test attribution for the 11-character `g` branch is withdrawn. Drop modes may match the `d` branch, but their handler mapping is unresolved. No sender or managed code was executed.
