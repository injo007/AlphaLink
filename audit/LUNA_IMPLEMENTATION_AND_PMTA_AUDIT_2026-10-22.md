# AlphaLink implementation and supplied PMTA bundle audit

**Date:** 2026-10-22  
**Scope:** Current AlphaLink source in `apps/api`, plus static, read-only inspection of the owner-supplied ZIP. The workspace has no Git history, so changes cannot be attributed reliably to Luna versus later edits. This report does not assert live deployment or server behavior.

## Decision

The current app is a credible first data/API and browser-console slice. It is **not yet a server installation or email delivery SaaS**. The supplied PMTA bundle is staged privately for later use, but it must not be sent to an arbitrary server: target compatibility, license entitlement, host identity, installation and recovery behavior are unverified.

## Findings, ordered by impact

1. **Server provisioning is absent (critical delivery gap).** `routes/api.php` exposes workspaces, contacts, lists and campaign drafts, but no server inventory, credential references, install plans, approvals, execution jobs, health checks or rollback. No code currently installs PMTA. The new `alphalink:pmta:stage` command only validates and stores a local bundle. The advanced reference system's preview/approval/apply/health/reversal workflow is still unimplemented.
2. **Campaign sending and suppression enforcement are absent (critical delivery gap).** `CampaignController` creates drafts/revisions; `SuppressionEventController` records events. There is no campaign launch/scheduler, effective-consent or suppression resolver, recipient snapshot, transport worker, PMTA adapter, feedback ingestion, or delivery ledger. The presence of audit/outbox rows does not mean messages or events are processed. Do not enable sending until these controls exist.
3. **PowerMTA artifact is old and target compatibility is unverified (high).** REA opened the owner-supplied `pmta.zip` at SHA-256 `2ea8e4776b705dec618178bddc1ead3cfcb9fcff5a36bc20309dac160eb46e16` and inventoried four ZIP entries: one directory, an RPM, a license and a config (REA inspection `ev_012e75b7063f5b22a8905401d6cb01834d35d3568875453f561576b3680bff67`, graph manifest `agm_f9364e5affc881690c1aee3b5d22b27ede889e95b770cf676ea688146750eba7`). The RPM header identifies `PowerMTA 4.0r8`, release `201209102127`, architecture `x86_64`. Its file list declares `/etc/pmta/config`, and its postinstall script checks `/etc/pmta/license` and registers SysV init services using `update-rc.d` or `chkconfig`. Those scripts would run with package-install privileges; their static contents do not prove compatibility with a target OS. REA found no ZIP entry-integrity contradiction; it did not execute the RPM or validate the license.
4. **The supplied config is installation-specific (high).** The 14,306-byte config is preserved as `reference-config`, not an install template. Server IPs, domains, credentials, paths, keys, network controls and feedback destinations must be generated and reviewed for each tenant/server. Copying this config directly would risk incorrect routing and disclosure. The license is not printed or committed.
5. **Current app access is bounded but production hardening remains (medium).** API workspace middleware checks active membership, and console detail queries scope by workspace and role. PostgreSQL migration triggers protect append-only evidence. The console has session regeneration and login throttling. There is no multi-factor authentication, per-action approval model, key rotation workflow, secret vault, backup/restore drill, or operational monitoring. These are requirements before real infrastructure control.
6. **Verification coverage is limited (medium).** Previous disposable PostgreSQL and browser checks exercised migrations, tenant boundaries, retries and evidence guards. This pass ran the new staging command against the supplied ZIP in a disposable private destination and independently checked extracted hashes, restrictive permissions and manifest gates. There is no maintained automated suite covering the complete app. No production DMS, AlphaLink, PMTA or target server was modified.

## Implemented in this pass

- Added an exact-hash, allowlisted ZIP staging command under `apps/api/app/Console/Commands/StagePmtaBundle.php`; it checks the reviewed archive and each member hash, rejects duplicate/unexpected entry names and nonregular members, and writes protected files atomically outside the shared workspace.
- Added `PMTA_BUNDLE_DIR` app configuration and instructions. The command leaves the supplied config as a reference and records host compatibility/license validity as unverified.
- Moved the original ZIP from the shared workspace to `/home/akmed/.local/share/alphalink/pmta/source.zip`; staged RPM, license, reference config and manifest in the SHA-named subdirectory. Directory permissions are `0700`, file permissions `0600`. The original ZIP was hash-verified after moving. No package was executed and no remote host was contacted.
- Ignored future `pmta.zip`, PMTA RPM and license paths from source control.

## Remaining installation gate

Create a tenant-scoped server inventory and protected SSH/secret references; collect OS release, CPU architecture, package manager and filesystem layout; verify SSH host key; validate vendor entitlement and RPM package signature/compatibility; render a per-server PMTA config; show a diff and obtain approval; apply as an auditable job; verify service health and feedback paths; provide a tested reversal. Only then can the staged bundle be used in a server installation.

## Evidence limits

REA inspected ZIP central-directory entries and reported that non-entry bytes were outside inventory. RPM name/version/release/architecture were read from the package header without execution. The license contents were not displayed or validated. Current source review cannot prove deployed behavior. The project has no `.git` directory, so this is a current-state audit rather than an authorship diff.
