# DMS to AlphaLink parity audit — correction

**Scope:** Read-only reconstruction of DMS's server registration and cloud bulk installation, compared with the current AlphaLink implementation. This is a reference map, not evidence that a DMS installation was run. No production install, DNS write, SMTP send, or PMTA command was issued.

## Confirmed DMS operator flow

1. An administrator adds one or more server IPs in **Servers**. The form selects a server provider and supplies name, IPs, SSH port, username, password or SSH key, and expiry. It has a separate **Test Connection** action. `ServerController::store` validates those fields; `ServerAction::add` creates one server per IP. Source: `resources/views/admin/servers/create.blade.php`, `app/Http/Controllers/Admin/ServerController.php:58-111`, `app/Actions/ServerAction.php:23-65` in the read-only DMS snapshot.
2. The cloud **Bulk Install Server** page lists active, uninstalled servers belonging to the signed-in user's naming pattern. Its domain selector uses active, free/new, **non-owned** domains with active providers. The administration bulk page is a distinct route and selection context. Source: `app/Http/Controllers/Mailer/Clouds/BulkInstallController.php:22-36`, `app/Actions/DomainAction.php:86-98`, `resources/views/mailer/clouds/bulk_install.blade.php:1-66`.
3. The bulk screen chooses **PMTA 4.0, 4.5, or 5.0**, and independent **Tracking, Proxy, IMAP, SPF, DKIM, DMARC, HTTPS** switches. It displays each server's name, ID, IP, selected domain, and apply control. Its JavaScript sends the selected rows plus the eight configuration values to a POST install endpoint; a separate endpoint is polled for installation logs every two seconds. Source: `resources/views/mailer/clouds/bulk_install.blade.php:69-252`; screenshot supplied by the owner.
4. The controller starts a `servers:install_bulk` Artisan process and returns its stdout/stderr and status. The command checks installed/domain state, connects by SSH/SFTP, discovers server IPs, assigns subdomains, configures host/packages/services, conditionally configures tracking and DNS records, **always installs the selected PMTA**, configures VMTAs, conditionally installs IMAP/proxy/HTTPS, and configures the firewall. It records IP rows, installation parameters, and installed state on success. Source: `BulkInstallController.php:47-103`, `app/Console/Commands/servers/ServerInstallBulk.php:29-365`.
5. DMS installer factory admits x86_64 CentOS 7, CentOS Stream 9, Rocky Linux 9, and AlmaLinux 9. Its CentOS 7/9 implementations contain PMTA 4.0/4.5/5.0 package paths. This is source support, not proof that every version works on every supported host. Source: `app/Libraries/ServersInstall/ServersInstallFactory.php:13-55`, `Centos7.php`, `Centos9.php`.

## Current AlphaLink divergence

| DMS reference | Current AlphaLink | Consequence |
|---|---|---|
| Provider is a selected catalogue entity; registration accepts multiple IPs, username, password or key, and expiry | Provider is free text; one public IP and root password per form | Server resources and registration workflow differ. |
| Install page starts from eligible uninstalled server rows and eligible domain rows | A three-step server/domain/preview wizard is the main screen | Navigation and task order differ from DMS. |
| Cloud bulk route uses non-owned free/new domains; owned-domain DNS automation is a separate branch | Domain must pass an AlphaLink TXT ownership challenge | Blocks a DMS-supported cloud bulk selection and conflates domain registration with installer eligibility. |
| Version + seven independent switches, including IMAP and HTTPS | Five options; HTTPS is tied to tracking; no IMAP option | Configuration cannot express DMS's actual choices. |
| All discovered IPv4/IPv6 addresses, generated subdomains, ISP-to-VMTA mapping, IP rows | Single server IP with DNS suggestions only | Missing the core mail-server topology. |
| Install process and timestamped per-server progress log | Immutable blocked preview; no install process/log | The requested outcome is absent. |
| CentOS 7 and 9 family handlers; version-specific PMTA assets | AlmaLinux 9 is a tentative candidate; all versions blocked | OS/version policy is not grounded in DMS's exact supported branches. |

The additional SSH host-key check, encrypted credential store, audit events, and preflight in AlphaLink are implementation safeguards. They must be placed inside the DMS-shaped registration/install workflow rather than replacing its resource model or primary operator path. The current user interface should not claim installation capability.

## Broader product-model mismatch

The installed AlphaLink code currently exposes workspace, contact, consent, suppression, audience-list, campaign-revision, server, domain, and installation-plan models. Its browser navigation contains overview, campaigns, audience lists, and server installation. That is a small alternate product model, not a reconstruction of DMS's operating model. The larger DMS source audit already covers the following gaps:

| DMS module and relationship | AlphaLink status | Reference evidence |
|---|---|---|
| ISP → list → one recipient row/list membership, recipient state and access filters | Generic contacts and many-to-many audience memberships; no ISP object | `DATABASE_ARCHITECTURE.md`, `DMS_COMPLETE_CONCLUSION_2026-10-22.md` §1 |
| Server provider → server → discovered IPs → domain/subdomain → VMTA and PMTA controls | Free-text provider and one server IP; no IP/VMTA/ISP mapping or PMTA operations | DMS install sources above; `MAIL_INFRASTRUCTURE.md` |
| Sponsor → offer/creative/suppression → campaign/drop/warmup → send snapshot | Campaign revision shell only; no offers, drops, warmups, or sender configuration path | `CAMPAIGN_ORCHESTRATION.md`, `DMS_COMPLETE_CONCLUSION_2026-10-22.md` §§2–3 |
| Native sender → PMTA listener → PMTA delivery/bounce import | No sender worker, PMTA listener integration, or feedback importer | `DMS_REA_SENDER_RESOLUTION_2026-10-22.md`, `MAIL_INFRASTRUCTURE.md` |
| Tracking mini-app → redirect/open/unsubscribe events → daily statistics; affiliate importer → revenue | No tracking app, event pipeline, aggregation, or sponsor lead importer | `TRACKING_AND_ANALYTICS.md`, `DMS_TRACKING_ATTRIBUTION_FLOW.md`, `EXTERNAL_INTEGRATIONS.md` |
| DNS and cloud provider adapters, server/domain administration, PMTA scheduler/settings/history | Only basic server/domain forms and a blocked preview | `EXTERNAL_INTEGRATIONS.md`, `DMS_REFERENCE_ROUTE_EVIDENCE.md` |

The consent/suppression objects and workspace boundary may be useful AlphaLink improvements, but they must be mapped to DMS's list/recipient/campaign behavior explicitly. They cannot serve as proof that those DMS modules have been reverse engineered into the product.

## PMTA bundle fact

REA inspected the user-supplied ZIP (`source.zip`, SHA-256 `2ea8e4776b705dec618178bddc1ead3cfcb9fcff5a36bc20309dac160eb46e16`). Its complete central-directory inventory contains `pmta/config`, `pmta/license`, and **one** `pmta/pmta.rpm`; it does not contain separate 4.0, 4.5, and 5.0 RPMs. REA evidence: inspection `ev_1ff0298a99e2cce5d7ea8474d5b2457d9a522602bad540121930d429a37337fb`, inventory `ev_27ede3778049a5e6ce41c86cf8dcc51dc9d8673727f594a09860a200116eeeab` (5/5 occurrences, no integrity contradictions). The archive contents were not executed and license text is not reproduced here. The exact RPM version, vendor entitlement, OS compatibility, and separate version packages require verification before enabling installation.

## Implementation order after this correction

1. Rework the **resource model** to represent provider catalogue, multiple server IPs, server eligibility/expiry, owned versus external domain status, and installation state while retaining safe secret storage.
2. Rework the **interface** to follow the DMS list and bulk-install structure: version/switch bar, eligible server rows, domain selector, apply selection, and real per-server progress area. Label capabilities according to their actual state.
3. Reconstruct and implement **IP discovery, subdomain assignment, ISP/VMTA mapping, DNS branches, and exact PMTA version/OS artifact matrix** with isolated tests. Preserve the DMS sequence while adding backup/rollback and verified host identity.
4. Build the **installer worker and event log** only after packages, licensing, clean-host compatibility, and rollback can be verified. Test on disposable servers before any real target.

## Evidence limits

This comparison uses the current read-only DMS source snapshot and the owner's DMS screenshot. DMS runtime server inventory, effective package versions, current provider rows, installed PMTA state, and successful real installations were not observed. The source proves control flow and intended effects, not a live outcome. The current REA session had no DMS browser target; REA was used on the supplied PMTA archive, while PHP source was read directly as required by the REA skill for ordinary source analysis.
