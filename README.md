# AlphaLink

AlphaLink is an independently operated application; `baseheavy.shop` is its production site. Historical audit material is retained for traceability only. AlphaLink development and operations do not access the original service or depend on its credentials, data, servers, or APIs. See [the master specification](ALPHALINK_MASTER_SPEC.md), [historical workflow matrix](docs/reference/FEATURE_MATRIX.md), and [implementation report](docs/implementation/PARITY_REPORT.md).

## Current code

The Laravel application in [apps/api](apps/api/README.md) uses Laravel 13/PHP 8.3, Sanctum, PostgreSQL, and Redis. It includes workspace management, audience lists, campaigns and sending workflows, tracking, suppression, provider integrations, infrastructure and PMTA tools. Each workflow's implementation and verification status is recorded in the historical workflow matrix and implementation report.

Outbound sending, provider calls, and server operations have independent AlphaLink implementations with separate safety gates and verification status. Consent/legal policy, workload, hosting, and several other release decisions remain open. AlphaLink has no integration with the original service.

## Fresh Ubuntu 26.04 LTS installation

Use the single-file installer in [`install-ubuntu-26.sh`](install-ubuntu-26.sh) on a clean Ubuntu 26.04 LTS host. Point the application hostname to that host before starting, ensure inbound SSH/HTTP/HTTPS are reachable, then copy the script to the server and run `sudo bash install-ubuntu-26.sh`. It prompts for a new PostgreSQL password twice with terminal echo disabled, then installs PHP 8.5, PostgreSQL 18, local Valkey, Nginx and the locked application dependencies; creates a new PostgreSQL database and protected Laravel environment; runs migrations; bootstraps the first owner; and configures HTTPS, Laravel Horizon for Redis-backed default and audience-import jobs, a separate PostgreSQL-backed suppression worker, the scheduler, and a local daily database dump. Horizon's dashboard is available at `/horizon` to the initial owner account. To add operators, set a comma-separated `HORIZON_AUTHORIZED_EMAILS` list, rebuild Laravel's config cache, and restart the Horizon and PHP-FPM services.

The installer is fresh-install only: it refuses to replace an existing AlphaLink checkout, environment, PostgreSQL role, or database. It prompts for a GitHub token only when the repository is private, uses it for cloning, then removes the temporary credential. Do not commit `.env` or pass tokens on a command line. Keep an encrypted off-server copy of `/etc/alphalink/.env`; its keys are needed to read encrypted application values. The scheduled database dump is local to that host and is not disaster recovery by itself. Horizon provides Redis queue supervision and monitoring; it does not implement campaign sending. The installer does not configure PMTA, DNS provider credentials, or production sender infrastructure, so this is a staging/application baseline, not a production sending launch or proof of DMS feature parity.

## Routine update workflow

Make and verify each change in the local checkout, then push the reviewed commit to the configured GitHub branch. On the server, deploy that branch with:

```bash
sudo bash /var/www/alphalink/current/install-ubuntu-26.sh --deploy master
```

For a private repository, the deploy command prompts for a read-only GitHub token without echoing it. It builds an immutable release before downtime, verifies a PostgreSQL backup, applies migrations, checks the HTTPS health endpoint and Horizon, and switches back to the previous release and database backup if deployment fails. Uploaded files remain in shared storage across releases. Use the branch that was actually pushed if it is not `master`. Do not edit application files directly on the server; the local checkout and GitHub branch are the deployment source of truth.

## Archived historical material

- [Advanced AlphaLink reference system](audit/ALPHALINK_ADVANCED_REFERENCE_SYSTEM_2026-10-22.md)
- [Legacy service conclusion](audit/LEGACY_COMPLETE_CONCLUSION_2026-10-22.md)
- [Legacy sender resolution](audit/LEGACY_REA_SENDER_RESOLUTION_2026-10-22.md)
- [Phase 4A decisions register](design/PHASE_4A_DECISIONS.md)

The top-level `.env` is excluded from source control and was not copied into the application.
