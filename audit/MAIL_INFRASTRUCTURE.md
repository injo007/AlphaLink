# DMS Mail and Server Infrastructure

**Status: Static application and host metadata inspected. Live PMTA configuration and inventory values remain unknown. Managed sender CIL establishes TCP text I/O with SMTP-shaped reply parsing; SMTP compatibility is the leading inference, but commands, peer identity and message transport semantics remain unverified. No operational action was executed.**

## Application host and runtime boundary

- **VERIFIED:** Linux kernel `4.18.0-513.11.1.el8_9.x86_64`; PHP CLI and FPM both report `8.1.27`. Nginx serves `/var/app/public` through `/run/php-fpm/www.sock`.
- PHP modules include `pdo_pgsql`/`pgsql`, `redis`, `openssl`, `sockets`, `imap`, `curl`, and `pcntl`. This is runtime capability metadata, not proof of active use.
- Read-only service metadata reports Nginx, PHP-FPM, Redis, and Supervisor as active. Supervisor and Redis unit files are enabled; PHP-FPM’s unit file was listed disabled despite the service reporting active (an observed configuration/status discrepancy). No service was controlled.
- The host’s root crontab contains a `schedule:run` entry on a one-minute cadence; the exact command arguments were deliberately withheld from output. Systemd timer names include certificate renewal, package metadata refresh, temporary-file cleanup, filesystem trim, and an Unbound anchor timer. Root cron also showed a certificate client entry. These are configured schedules, not evidence that any job was run by the audit.

## Queue, scheduler, and workers

- `config/queue.php` defaults to Redis; it defines `default`, `long_running`, and `schedule` queues with retry-after 90, 1200, and 90 seconds respectively. All shown queue connections use `after_commit=false`.
- Horizon defaults define one process each for the `default`, `long_running`, and `schedule` supervisors, with `tries=1` and automatic balancing; production overrides raise their respective maximum process counts to 30, 3, and 2. The app’s active environment and effective runtime config were not queried, so these are **configured values**, not verified live worker limits.
- Supervisor service is active, but no matching program file was found in the configured `/etc/supervisord.d/*.ini` include directory during the bounded metadata read. The precise worker launch configuration and actual Horizon process state therefore remain **UNKNOWN**.
- `app/Console/Kernel.php:60-140` schedules minute-level stats/cache/bot work; PMTA log processing every two minutes; sponsor lead sync every five minutes; ISP/list/email/server/domain/offer/SPF/Outlook/Workspace maintenance at varied cadences; backups twice daily; and dynamic PMTA actions loaded from `PmtaScheduler` records. Many scheduled commands can mutate DMS, remote mail servers, DNS, or provider state. None was executed.

## Campaign sender and PMTA

- Campaign orchestration launches a configured external ELF at `storage/app/engine/send`; a second `send1` artifact exists. Fresh private copies were hash-checked and inspected offline with REA. Neither binary nor any managed assembly was executed; the managed CIL establishes a TCP text path and SMTP-shaped reply parser, while literal commands, peer identity and message semantics remain unresolved. See [DMS_NATIVE_SENDER_ANALYSIS.md](DMS_NATIVE_SENDER_ANALYSIS.md).
- A PHP raw SMTP helper is present, but its relationship to the external sender and the user-confirmed PHPMailer integration remains unverified. Details are in [PHPMAILER_SENDING_ENGINE.md](PHPMAILER_SENDING_ENGINE.md).
- PMTA routes are in `/var/app/routes/mailer.php:281-307` and inherit the authenticated `/mailer` route group. They include action, scheduling, restart, configuration push/edit, VMTA/SMTP push and restore, bounce, and history operations. Controller/source paths reveal several routes issue state-changing operations; none were called.
- `PmtaAction::execute_url` sends a PMTA command as a query parameter in an HTTP GET to `http://<server-ip>:<configured-admin-port>/command`; the inspected call site shows no TLS or request authentication. Network isolation and PMTA-side ACLs are **UNKNOWN**. `restart_pmta` invokes remote shell commands including port-25 process termination, DNS-cache clearing, and PMTA/PMTA-HTTP service restarts (`/var/app/app/Console/Commands/pmta/PmtaAction.php:68-99`).
- PMTA push/reconfigure source uses the server SFTP adapter to upload files and reload services. These paths are operationally mutating and were not invoked. The PMTA version, actual server list, VMTA/SMTP payloads, license/config, and service topology were not read.

## Server installation and PowerMTA setup

The server schema defaults username to root and stores a password or SSH private-key value in a mediumText column. The Server model exposes that field as fillable but has no application-level encryption cast. Storage-level encryption and key management were not inspected. The installation factory uses phpseclib SFTP to connect using the configured port and password/private-key login; no explicit host-key pin is visible at that factory call site.

**Credential boundary:** the root password/private key is the DMS application's remote server-management credential used by the installation path. The source maps that path to OS setup and PMTA package/configuration work. It is distinct from both the PMTA HTTP admin command URL and the managed sender's unresolved SMTP-like peer credentials. No inspected source shows the root password being installed as a PMTA SMTP credential or used to authenticate an SMTP session. This is a source-derived separation; it does not establish the current credential value, storage-at-rest encryption, or whether any installation was performed on a live server.

The server install command probes the target OS/architecture, then runs host setup, package cleanup, service setup, optional PHP and tracking mini-app setup, DNS setup for owned domains, PMTA install, VMTA/domain/IP/ISP configuration, optional IMAP/proxy/HTTPS features, and firewall configuration. Source supports CentOS 7 and CentOS 9-derived implementations. This ordered workflow is static source evidence; it was not invoked.

Both CentOS PMTA installers remove old PMTA package/config/state directories and kill processes listening on TCP port 25 before installing from local RPM/patched-binary/license/template assets. Service and firewall/SELinux behavior is also configured in source. No transaction, preflight backup, staged switch, automatic rollback, or post-install mail-health gate is visible. A failed run can therefore leave a partially changed server; this is a source-based risk, not a failure that was induced.

## Delivery and bounce feedback

`ServerProcessPmtaLogs` is scheduled every two minutes and fans out over up to five server tasks with a file-cache lock. It opens remote SFTP sessions, moves delivery/bounce CSV files into processing directories, parses encoded campaign/drop/warmup dimensions, updates daily statistics, dispatches recipient-type changes, and archives the files (`/var/app/app/Console/Commands/servers/ServerProcessPmtaLogs.php:38-69,74-184,187-285`). It has state-changing remote file operations and database writes; it was not run.

The source classifies `bad-mailbox`, `bad-domain`, `quota-issues`, and `inactive-mailbox` as hard-bounce categories and aggregates other bounce categories as soft bounces. It dispatches recipient changes for delivery and hard-bounce IDs and updates the matching campaign/drop/warmup daily counters.

The complaint/FBL path is bounded more precisely by a fresh, strict-host-key-verified source read. The CentOS 9 installer builds `/fbl@<owned-domain>/` feedback-loop addresses and writes PMTA `append-mbox` routing to `/etc/pmta/inbound/<domain>`. DMS's manual reply tool downloads that file over SFTP, invokes `MboxParserJs`, and extracts only the message `From` address, `Message-ID`, and date. It then looks up an existing email row by the extracted sender address (eligible types include `fresh`, `delivery`, `open`, `click`, `lead`, and `unsubscribe`), carries the extracted message ID into a mix file, and creates a virtual audience named `reply-<name>`. The test variant builds a test mix directly from the extracted sender address. This is a reply-audience flow; it does not parse ARF fields such as the reported original recipient, classify a complaint, change recipient state, or apply complaint suppression. A bounded literal-term scan for feedback-loop/FBL/ARF/complaint/abuse-report terms found one matching file, `ServersInstall/Centos9.php`, within `/var/app/app`, `/var/app/routes`, and `/var/app/database`; `.git`, `vendor`, `node_modules`, `storage`, `public`, and `bootstrap` directories were excluded. This bounds the source search, not other deployments or external processing. Live PMTA routing remains unknown. Separately, campaign suppression reads a virtual list's stored mix file without refreshing current recipient state or offer exclusions; therefore an `unsubscribe` row in a selected reply audience can reach the PHP send snapshot unless the offer suppression file removes it. The native sender's later filtering is unknown. CentOS 7 source writes inbound mbox destinations, but this review did not establish equivalent feedback-loop processor generation there.

## DNS, domain and IP controls

GoDaddy and Namecheap adapters support reading and writing DNS host records and nameservers; cloud adapters support provider-specific IP and reverse-DNS operations. See [EXTERNAL_INTEGRATIONS.md](EXTERNAL_INTEGRATIONS.md). No DNS record, host, IP, server, MTA, or provider value was read or changed. SPF analysis and DKIM-related setup exist in migrations and install source, but live DNS/DKIM/DMARC configuration and key material were not inspected.

## Unknowns and read-only boundary

| Area | Status |
|---|---|
| Actual active PMTA versions, servers, VMTA pools, SMTP configuration | **UNKNOWN** — requires sanitized configuration and/or authorized metadata. |
| Effective queue connection/environment and Horizon process state | **UNKNOWN** — configuration defaults are known; live queue/process data was not queried. |
| Active service supervisor program definitions | **UNKNOWN** — Supervisor service is active, but configured include directory had no matching program files. |
| SMTP compatibility, TLS, auth, retry and failover path | **PARTIAL/UNKNOWN** — reply-parser grammar supports SMTP compatibility as inference; commands/peer unknown; raw PHP helper caller and PHPMailer call path unresolved. |
| MTA/provider/network ACLs, firewall and DNS | **UNKNOWN** — no live configs, data rows, DNS queries, or external probes. |
| Complaint feedback, bounce source completeness, late-event repair | **PARTIAL/UNKNOWN** — PMTA FBL config and manual inbound reply extraction are mapped; complaint classification, recipient matching, suppression and live routing are unverified. |

No cron, Laravel scheduler, Horizon worker, PMTA command, remote managed-server command, SMTP session, or integration endpoint was run. No remote files, services, queues, DNS, or mail infrastructure were changed intentionally.
