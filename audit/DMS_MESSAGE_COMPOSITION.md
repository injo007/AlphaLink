# DMS Message Composition — Evidence and Unknowns

**Status: Application-side inputs are mapped, and Phase 4B refreshed the managed sender's static metadata/CIL. Main reads the second PHP-supplied argument as the Base64-JSON config file and parses it; the PHP-to-file handoff is established. One obfuscated mode branch fans out over a JSON array, and a reachable direct TCP/text-stream path with SMTP-shaped reply parsing is verified. SMTP compatibility is the leading static inference; array-item meaning, command text, peer identity, message construction and MIME format remain unknown. No sender or assembly was executed.**

Phase 3F Ghidra analysis recovered the native apphost boundary. Phase 3G then extracted only the managed assemblies and dependency manifests embedded in the two approved sender files and inspected them statically. See [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md) for bundle boundaries, hashes, managed call graph, and evidence limits.

## Phase 3F apphost finding

The apphost contains `.runtimeconfig.json`, `.deps.json`, `libhostfxr.so`, `libcoreclr.so`, and hostfxr startup markers. Its nonzero bundle-header field selects `hostfxr_main_bundle_startupinfo`; `send.dll` is the observed application-DLL name. Phase 3G verified the bundle manifest and inspected the resulting `send.dll` PE/CLI metadata and CIL.

## Standard native campaign path

### Inputs established from PHP source

Campaign creation validates content and routing fields including header/body/assets, offer and ISP, selected lists, from/subject placeholders, MTA/VMTA, server/IP selection, pacing, seed cadence, bounce limit, send mode, and duplicate-data setting (CampaignController.php:189-326).

CampaignAction::create_campaign persists these settings and asks the suppression layer to produce a recipient file. CampaignAction::build_send later builds an application-side config with campaign/send/user identifiers, mode, pacing and related options, serializes JSON, base64-encodes it, and writes a temporary file (CampaignAction.php:22-110,293-328).

CampaignAction::Build_token creates a Base64 token containing campaign/send/recipient/type/list/ISP/server/IP/domain/from/subject/creative identifiers, a uniqid component, and an Adler-32 check. generate_body replaces [token] and applies a random() transformation (CampaignAction.php:519-563). This is evidence of token input generation, not evidence of the sender's final output or verification behavior.

### Raw SMTP helper (caller unknown)

App/Libraries/Send/Helpers/Smtp.php:19-89 contains a separate raw socket helper that emits MAIL FROM, RCPT TO, DATA, caller-supplied header/body bytes, and X-ENVID/X-JOB/X-VIRTUAL-MTA metadata; it reconnects after 250 messages. The inspected class does not visibly negotiate TLS or SMTP AUTH, parse normal server response codes, or classify/retry failures. No call site linking this helper to the native sender was established.

### PHPMailer discrepancy

The owner confirms DMS uses PHPMailer. The inspected app Composer requirements/lock entries, vendor path, and PHP source search did not identify its package or a construction/send call site. The managed bundle also has no PHPMailer, `System.Net.Mail`, MailKit, or MimeKit reference. The search does not prove absence from every global PHP path, separate deployment, server, or uninspected module. Exact version, initialization, transport, and relationship to the native campaign path remain **UNKNOWN**. Do not infer PHPMailer from the raw SMTP helper or the managed TCP path.

## Separate Google-linked composition and delivery

Google campaigns use a separately gated command and Gmail API path (routes/mailer.php:129-186; app/Console/Commands/gcampaigns/GcampaignSend.php; app/Libraries/GmailLib.php:23-47,164-242). Source shows rotation/personalization of From, Subject, placeholders, header and body fields, followed by Gmail users_messages->send or draft creation. GmailLib builds a base64url raw message from its header and body inputs. This is not evidence of PHPMailer or the native ELF's message format.

No Gmail account, token, draft, message, or API call was accessed. The configured scopes include broad Gmail mailbox and settings permissions; necessity, consent and token lifecycle are unknown.

## MIME, headers, sender identity, attachments

The static native-apphost pass found none of the fixed generic header/MIME markers tested in either ELF's `.rodata` section and no direct network/SMTP/TLS imports in the complete disassembly/import evidence. The managed CIL does contain a reachable `TcpClient(host, port)` path with `StreamWriter.WriteLine` and `StreamReader.ReadToEnd`, and a response parser accepts `2xx`/`3xx` status classes with hyphen continuation. This supports SMTP compatibility as a static inference, but no literal command sequence or SMTP/MIME construction was recovered; a custom protocol using the same reply grammar remains possible.

| Concern | Evidence status |
|---|---|
| HTML and plain-text alternatives, MIME boundaries, charset, transfer encoding | **UNKNOWN** for native path; not recovered from the ELF. |
| Subject/from personalization | **PARTIAL** in PHP config/token/Gmail source; final native interpolation and escaping **UNKNOWN**. |
| Custom headers, Message-ID, Reply-To, Return-Path | **UNKNOWN** for native path. Campaign schema includes header/body/return-path fields but schema fields do not prove emitted headers. |
| Envelope sender, bounce address, X-headers | **PARTIAL** only in the unlinked raw SMTP helper. |
| Managed network boundary | **VERIFIED** direct TCP connection, line-oriented writes, read-to-end, client close, and SMTP-shaped reply parsing; SMTP compatibility is the leading inference, while literal commands and peer identity remain **UNKNOWN**. |
| DKIM signing, key selection, signing domain | **UNKNOWN**; install/config references exist, but active signer call path and identity were not established. |
| Attachments, inline images, encoding transformations | **UNKNOWN** in native path; image/asset entities exist but final MIME handling was not reconstructed from the inspected sender metadata/CIL. |
| Gmail raw MIME | **PARTIAL/OBSERVED** for the separate Gmail wrapper; complete RFC 5322/MIME correctness and provider outcomes were not tested. |

The ELF's `dlopen`/`dlsym` path resolves `libhostfxr.so` / `hostfxr_main`, identifying it as .NET bootstrap logic rather than an SMTP or MIME module. The managed `send.dll` metadata/CIL has now been inspected. Its direct TCP path parses SMTP-shaped replies, so SMTP compatibility is the leading static inference; exact commands, the peer identity, and whether a custom protocol reuses that grammar remain unresolved. No PHPMailer linkage was established in the analyzed apphost, managed references, or inspected PHP dependency/call-site evidence; PHPMailer's role elsewhere in DMS remains **UNKNOWN**.

## Message-construction confidence

- **VERIFIED:** PHP validates and persists message-related fields; audience suppression produces a file; the native launcher receives a mode/config path; token-generation source exists; the Gmail wrapper submits raw encoded content to Gmail API.
- **DERIVED:** The PHP app delegates native message assembly beyond the controller/action boundary because those call sites launch an external ELF.
- **VERIFIED:** managed code has a reachable TCP text-stream helper; its entry point uses CLI string-array dispatch and the two bundle variants differ in main CIL.
- **UNKNOWN:** body rendering, header folding, RFC compliance, MIME safety, personalization escaping, address validation, DKIM, literal TCP commands and peer identity (SMTP compatibility is inferred from reply parsing), PHPMailer usage, and delivery feedback.

No preview/body-generation endpoint, SMTP connection, test send, campaign, or Gmail API operation was used during this investigation.

## Phase 3H managed message-path refinement

Retained REA call-edge records confirm reachable TcpClient(string,int), stream reader/writer setup, auto-flushing line writes, read-to-end, and close. They do not expose line literals or show that the TCP helper receives the campaign body. This establishes a TCP text-I/O boundary only. No managed direct-call edge to System.Net.Mail, MailKit, MimeKit, or an SMTP client API was recorded.

The managed entry parses a JSON object and branches through five switch instructions. Retained CIL summaries do not recover the message object, personalization fields, tracking-token interpolation, header formatting, MIME boundaries, address encoding, attachment handling, or final serialization. PHP config fields and token construction remain input-side evidence, not proof of final bytes.

The fresh Phase 4B cross-check confirms that managed `Main` requires two arguments, uses `args[0]` for mode dispatch, reads `args[1]` as the config-file path, Base64-decodes its text to UTF-8, and calls `JObject.Parse`. PHP launches the configured sender as `send_<mode> <config-path>`, directly linking its generated file to that read. In one branch, Main passes the parsed `JObject` to a constructor that selects the `JArray` used by the task fan-out. The obfuscated property key and array-item meaning remain unknown. This narrows the sender input boundary but does not establish message construction; see [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md#php-configuration-handoff-cross-check--2026-10-21).

PHPMailer remains UNKNOWN. No PHPMailer package/call site was established in the inspected Composer/PHP evidence or managed references. The separate raw SMTP helper, Gmail API composition path, or custom TCP transport does not identify where the owner's PHPMailer usage occurs. See the managed evidence IDs and protocol boundary in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md).

## Phase 3H authorized unblock checkpoint — 2026-10-21

The authorized ILSpy .9375 release archive was downloaded, hash-verified, and extracted privately. Its inventory contains the desktop app and decompiler library, but no CLI executable. The single bounded SSH scan observed no host key; no sender artifact was transferred or processed. No message-construction or MIME evidence was added.

## Phase 3H execution checkpoint — 2026-10-21

No fresh sender copy or managed assembly was available for decompilation: the approved transfer stopped at strict host-key preflight before authentication. The exact approved ILSpy CLI version was absent from the official package index, and no other version was acquired. Therefore this turn adds no verified message-construction, MIME, personalization, or PHPMailer evidence; those items remain UNKNOWN as classified above.

## Phase 3H resumed CIL checkpoint — 2026-10-21

The resumed CIL-only pass inspected existing private copies and added no message construction evidence. The worker performs repeated obfuscated `JToken` index lookups and string conversions, but keys and values are not semantically recoverable from the available bounded summaries. The core method fans out work over a helper-returned `JArray`; the relationship of its items to a message or recipient is unknown. Message objects, MIME serialization, headers, personalization, and PHPMailer linkage remain UNKNOWN.
