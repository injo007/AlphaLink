# DMS PHPMailer and Sending Engine

**Status: Campaign handoff is source-traced; PHPMailer integration and message construction remain unresolved.** Static CIL now shows the configured managed sender reaches TCP text I/O and parses SMTP-shaped replies, making SMTP compatibility the leading inference; literal commands, peer identity and message construction remain unverified. The user confirms DMS uses PHPMailer. Current deployed Composer metadata and searched PHP call sites did not identify the package or a construction/send call.

## Runtime and package evidence

- PHP CLI and FPM report **8.1.27**; Composer requires `php ^8.1`.
- Composer project `emailing-solution/app` locks Laravel `10.38.2`, Horizon `5.21.4`, Sanctum `3.3.2`, Yajra DataTables `10.11.2`, and 156 runtime packages.
- `phpmailer/phpmailer` was not present in the direct requirements or selected lockfile package entries inspected; `/var/app/vendor/phpmailer` was not found. The app-tree source token search found no PHPMailer construction/send call site. One broad token match was an unrelated Webklex IMAP header.
- **Interpretation:** this does not disprove the user’s PHPMailer confirmation. It may belong to a separate/untracked component, system/global package, another deployment, or be outside the searched PHP tree. Its exact installed version and runtime path are **UNKNOWN**.

## Campaign handoff boundary

The Laravel campaign controller validates and persists a send request, builds a base64 JSON configuration file under `storage/app/tmp/send_*`, and invokes a configured sender path with one of eight mode names. The two x86-64 ELF artifacts were privately copied with approval, hash-verified, and statically inspected in REA without execution. The ELF is a .NET apphost; its managed payload reaches a TCP text-stream helper and parses SMTP-shaped replies. SMTP compatibility is the leading inference, but literal commands, TLS/authentication behavior, MIME construction, delivery outcomes and relation to PHPMailer remain **UNKNOWN**. The apphost itself has no direct socket/SMTP/TLS call target, and tested generic MIME/header markers were not recovered. Deep decompiler providers were unavailable.

Source: `/var/app/app/Http/Controllers/Mailer/Campaign/CampaignController.php:699-807`; `/var/app/app/Actions/CampaignAction.php:293-328,448-470,565-569`; `/var/app/config/system.php:18-19`.

Google-linked campaigns are a distinct, source-verified delivery path: a gated Laravel command uses Google OAuth and the Gmail API `users_messages->send` / draft APIs (`/var/app/app/Libraries/GmailLib.php:23-47,164-242`; `/var/app/app/Console/Commands/gcampaigns/GcampaignSend.php`). This establishes Gmail API sending for that feature only; it does not reconcile or prove PHPMailer use in the native campaign path.

## PHP raw SMTP helper

`/var/app/app/Libraries/Send/Helpers/Smtp.php:19-89` contains a separate raw-socket SMTP helper:

- `fsockopen` to a supplied IP and port defaulted from a PMTA port setting, with a 10-second connection timeout.
- Writes `MAIL FROM`, `RCPT TO`, `DATA`, header/body bytes, and X-ENVID/X-JOB/X-VIRTUAL-MTA metadata.
- Reconnects after every 250 messages.
- Reads response text only during `QUIT` and loops until socket EOF. The inspected class has no visible TLS negotiation, SMTP AUTH, response-code parsing, or exception/retry classification.
- No source caller was established. It may be unrelated to the campaign ELF.

These are source observations; they are not evidence of active production transport. The helper is not evidence that it is PHPMailer.

## Message construction and delivery questions

| Concern | Status | Evidence limit |
|---|---|---|
| PHPMailer package/version/call site | **UNKNOWN** | A fresh deployed-source check found no Composer lock entry, expected vendor package directory, or PHPMailer call-site token in the Laravel PHP source; user confirmation remains unreconciled and may refer to another component or deployment. |
| HTML/plain alternatives, charset and transfer encoding | **UNKNOWN** | Static REA analysis did not recover a builder; tested generic markers were absent from `.rodata`, but runtime generation/module code remains possible. |
| Custom headers, Message-ID, envelope sender, Reply-To/Return-Path and bounce address | **PARTIAL** | Raw helper exposes envelope commands and three X-headers; no active path or full message builder. |
| DKIM signing/identity | **PARTIAL** | DKIM-related install/config references exist; signer, key lifecycle, and sending identity unknown. |
| Personalization, MIME structure, attachments and inline images | **UNKNOWN** | Campaign content/token substitutions are partly mapped; the ELF's final assembly path was not decompiled. |
| Managed sender protocol/auth/TLS/retries | **PARTIAL/UNKNOWN** | SMTP compatibility is inferred from reply parsing. Literal commands, peer, TLS/AUTH, and retries remain unresolved. The separate raw helper lacks visible TLS/AUTH/reply parsing and has no established caller. |
| Failure capture and bounce correlation | **PARTIAL** | PMTA CSV processor is mapped, but sender exit state, rejection feedback and PHPMailer exception path are not. |

## REA and safety boundary

REA 6.7.0 inspected both approved, hash-verified local copies using static ELF metadata and complete objdump disassembly. Ghidra was not configured, Java was absent, and Hopper, IDA, and Kuna providers were unavailable; no provider was installed. The copies were removed at the end of that earlier analysis; a later Phase 3H resume found private copies and extracted assemblies again under `/tmp`, as recorded in [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md). No PHPMailer object, message, SMTP connection, PMTA endpoint, test send, or campaign operation was used.

The PHPMailer discrepancy remains open. Resolution requires the authoritative package/call site within the inspected application component, or separate owner-authorized evidence from another component/server. No other server was accessed.

## Follow-up — fresh deployed PHPMailer source cross-check (2026-10-21)

- Revalidated the trusted ED25519 SSH host identity before the read-only source checks. Current SHA-256 values are `composer.json` `a7ad7350d9546406a8f885c6808e576d069e2ac669c25b0f789e233ba10ae375` and `composer.lock` `cb3ce5059ec21d9f06b1742dbdcf038064cf9e75bfa980d224310daffae41f33`.
- The current lockfile contains no `phpmailer/phpmailer` package entry. `/var/app/vendor/phpmailer/phpmailer` is absent. A PHP-source search across the deployed application's `app`, `routes`, and `config` directories found no `PHPMailer` or `phpmailer/phpmailer` token; a wider non-vendor PHP-source search under `/var/app` (excluding `storage`, `vendor`, `node_modules`, `bootstrap`, and `public`) also found no match. No separate global runtime or other application directory was inspected.
- This strengthens the finding only for the inspected `/var/app` deployment snapshot. It does **not** disprove the owner's confirmation that DMS uses PHPMailer elsewhere; package identity, component/deployment, call site, and relationship to the campaign ELF remain unresolved. No Composer command, PHP application bootstrap, package install, test, message, or mail connection was used.
