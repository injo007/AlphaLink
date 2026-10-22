# AlphaLink server installation: version 1 contract

**Status:** product and engineering contract, 2026-10-22. No remote installation is enabled in the current application.

## Owner workflow

1. **Add server:** enter provider, public IP, SSH port, root login and root password. Save the password only in a protected credential store; the server row holds a credential reference. Collect and pin the server's SSH host-key fingerprint before any remote command. A read-only connection probe records detected OS, release, architecture, package manager, free disk, current MTA, relevant open ports and network reachability. Never assume the provider value proves the OS.
2. **Select domain:** show only domains controlled by this workspace. Select one primary sending domain for each server installation. Check provider DNS access or prepare records for manual publication. Display IP/domain ownership and existing server assignments before proceeding.
3. **Choose package and options:** PowerMTA is mandatory. Present 4.0, 4.5 and 5.0 with exact build, architecture, artifact hash, license status and OS compatibility. Disable a version until its approved package and matching license are present and tested for the detected OS. Optional controls are tracking, DKIM, DMARC, proxy and SPF; HTTPS is required when tracking is enabled. IMAP remains a separate option if needed later. The DMS screenshots do not define what the proxy toggle installs, so AlphaLink must require an explicit proxy type before enabling it.
4. **Preview:** show host changes, package changes, PMTA config diff, DNS records, firewall changes, service actions, credentials used by reference, backup location, expected downtime and recovery steps. Check that a proposed SPF change merges with any existing SPF TXT record rather than creating a second one. The operator explicitly confirms the preview.
5. **Execute:** queue a durable installation job. Acquire a per-server lock and use a stable idempotency key. Run preflight again, back up existing PMTA/config/license, transfer the exact staged artifact, install the RPM, place the correct license, render a per-server PMTA configuration, validate it, apply requested options, start/reload services and run health checks. Do not delete a working MTA, config or spool before backup and an explicit replacement decision. Each step records start/end/outcome and sanitized output. A disconnect or timeout becomes `unknown` until the server is inspected; it is not blindly retried.
6. **Verify and finish:** verify PMTA version/service/listener, SMTP submission policy, domain DNS, DKIM signing, SPF and DMARC records, tracking HTTPS endpoint if selected, proxy egress if selected, and feedback/log paths. DNS propagation can leave the job in `waiting_for_dns`; it must not be reported as complete. Show links to generated records and a step-by-step log. A failed installation reports the failed step and safe repair/rollback action.

The screenshots are a UI and log reference for this sequence. A line such as “End Setting PMTA” is a reported step, not proof of service health or delivery.

## Option semantics

| Option | Installer responsibility | Verification |
|---|---|---|
| PowerMTA, always on | Install approved version, matching license, isolated relay configuration and VMTA/IP mapping. | Package signature/hash, config syntax, service health, expected listener and version. |
| Tracking | Deploy the tracking service and tracking subdomain with TLS; create signed, tenant-scoped click/open identifiers. | HTTPS, redirect/pixel probe, certificate and domain ownership. |
| DKIM | Generate a per-domain private key on the server or protected key store; publish only the public DNS key; configure PMTA signing. | Test signature on a controlled message and verify selector TXT record. |
| DMARC | Publish a record aligned with SPF/DKIM; start with an explicit monitoring policy and report address, then tighten separately. | DNS record and alignment test. |
| SPF | Merge permitted sending IPs/hosts into the domain's single SPF TXT record. | DNS lookup and syntax/lookup-budget check. |
| Proxy | Require an explicit type and topology. Outbound PMTA egress proxy is one candidate; DMS's toggle may represent a different service, which the current evidence has not resolved. Require endpoint, authentication/ACL, source-IP mapping and version-specific support before enabling it. | Verify the selected proxy's actual network path and failover behavior. |

DNS writes require a linked provider credential with narrow scope. Without it, generate records and wait for manual publication/verification. Do not claim DKIM, SPF or DMARC success merely because PMTA installation finished.

## OS and PMTA version decision

**Default candidate for new PMTA servers:** AlmaLinux 9 on x86_64. It has a published security-support horizon to 2032. The installer may actually enable this profile only after the exact PowerMTA build and license have passed a clean-host compatibility test. AlmaLinux 8 x86_64 is a secondary candidate through 2029 for an approved package that requires an older RHEL-compatible environment. These dates are OS support dates, not PowerMTA compatibility claims. Do not use end-of-life CentOS 7 or an unvalidated CentOS Stream 9 path as the default.

| UI version | Current artifact state | Installation policy |
|---|---|---|
| 4.0 | Owner supplied `4.0r8` x86_64 RPM, 2012 release, plus license and reference config; hash verified and staged privately. | Legacy/lab only. No production target OS is approved. Its RPM uses SysV-era install scripts; compatibility and license validity remain unverified. |
| 4.5 | No reviewed package or matching license supplied to AlphaLink. | Show as unavailable until supplied and validated on a maintained target OS. |
| 5.0 | No reviewed package or matching license supplied to AlphaLink. | Preferred PMTA major version for new servers, contingent on current vendor package, valid entitlement and clean-host test. |

The version selector must never map 4.5 or 5.0 to the 4.0 RPM or assume one license covers all three. Every version/build has its own artifact digest, target OS/architecture allowlist and tested installation adapter.

## Persistent records and access

- `servers`: workspace, provider, IP, SSH port, credential reference, pinned host fingerprint, detected host facts, state and timestamps. Never return the root password in an API response or log.
- `domains`: workspace, name, ownership proof, DNS provider reference, DNS state and server assignment history.
- `pmta_packages`: exact version/build, architecture, package digest, license reference, allowed OS builds and validation evidence.
- `installation_plans`: immutable selection of server, domain, version and options; preview fingerprint, actor and approval.
- `installation_runs` and `installation_steps`: state, attempt, per-server lock, sanitized logs, checkpoints, backup references, remote identity and result.

Only workspace owners/admins can register credentials or approve an installation. Operators may create a preview and view progress if granted. Every credential access, plan approval, remote command, DNS change and rollback is audited. Keep the application control plane and target PMTA servers separate.

## Current implementation boundary

The existing `alphalink:pmta:stage` command validates and privately stages only the reviewed 4.0 archive. There are no server/domain/installation endpoints, protected SSH credential store, package registry, 4.5/5.0 artifacts, remote execution workers or DNS adapters in AlphaLink yet. This contract defines the next implementation slice and prevents the version selector or install button from implying unavailable functionality.
