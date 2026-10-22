# AlphaLink — first implementation slice

This Laravel application includes an API and a server-rendered browser console. The API establishes authenticated workspace boundaries, encrypted contact addresses with keyed duplicate lookup, tenant-scoped audience lists with membership history, append-only consent, suppression, membership and audit evidence, immutable campaign content revisions, and transactional outbox storage. The browser console supports session sign-in and read-only workspace, campaign, and audience review. It does **not** send messages, launch campaigns, dispatch outbox messages, export audiences, or call DMS/provider infrastructure.

## Runtime requirements

- PHP 8.3+ with `dom`, `xml`, `xmlwriter`, `mbstring`, `openssl`, `pdo_pgsql`, `zip` (for PMTA staging), and the Laravel-required extensions.
- Composer 2, PostgreSQL, and Redis.
- Node.js 22+ and npm for building the Vite assets.
- Keep each environment's `APP_KEY` and `CONTACT_LOOKUP_KEY` outside source control. `CONTACT_LOOKUP_KEY` must be a stable, independently generated secret of at least 32 characters. It creates a deterministic HMAC blind index; it is not an encryption key.
- Contact addresses and campaign content are encrypted with Laravel's application key. Back up and rotate keys deliberately; changing either key without a migration will make stored values unreadable or unfindable.

The host PHP CLI lacks `dom`, `xml`, `xmlwriter`, and `pdo_pgsql`. Composer dependency resolution was completed with those platform checks bypassed for development tooling. Disposable PHP containers booted the application, listed routes, generated Boost guidelines, ran migrations on SQLite and PostgreSQL, and exercised focused API flows against both databases.

## Local setup

1. Install the PHP extensions above and start PostgreSQL and Redis.
2. Create a private environment directory outside this shared workspace, put a copy of `.env.example` there as `.env`, and set `ALPHALINK_ENV_DIR` to that directory for all application commands. The workspace mount ignores Unix permission changes, so do not place generated secrets in an app-local `.env`. Never copy the workspace's top-level `.env` into this application.
3. Generate `APP_KEY` with `ALPHALINK_ENV_DIR=/secure/path php artisan key:generate`. Generate a separate random `CONTACT_LOOKUP_KEY` in the protected environment file.
4. Install dependencies with `composer install`, then run `php artisan migrate`.
5. Install frontend dependencies with `npm install` and build assets with `npm run build`.
6. Create the first owner and workspace with `php artisan alphalink:bootstrap-owner --name="..." --email="..." --workspace="..."`. The command prompts for a password and prints an eight-hour API token once. Store it securely. The same account signs into the browser console at `/sign-in`.

The root `.env` is excluded by the root `.gitignore`; it is not an AlphaLink configuration source. The app's own `.env` is also ignored.

## Supplied PowerMTA bundle

The owner-supplied ZIP has been reviewed with REA (archive SHA-256 `2ea8e4776b705dec618178bddc1ead3cfcb9fcff5a36bc20309dac160eb46e16`). It contains a PowerMTA `4.0r8` `x86_64` RPM (release `201209102127`), a license, and a configuration file. The original ZIP and staged files are stored outside this shared workspace under `/home/akmed/.local/share/alphalink/pmta/` with restricted permissions. The supplied configuration is retained as a reference and is **not** an install template.

`alphalink:pmta:stage /absolute/path/to/source.zip` validates this exact reviewed ZIP and its extracted file hashes before publishing a private bundle. Set `PMTA_BUNDLE_DIR` to an absolute directory outside the workspace whose parent has mode `0700`. The command is intentionally local and cannot install to a server or expose the license through an API. It refuses a different ZIP and refuses to overwrite staged files. The manifest records the package identity and explicitly marks host compatibility and license validity as unverified.

The RPM declares `/etc/pmta/config`; its postinstall script checks `/etc/pmta/license` and registers SysV init services. Before an installer can use this bundle, the target server's OS, CPU architecture, package manager, PMTA compatibility, license entitlement, and host identity must be checked. The application has no server inventory, SSH credential vault, install job, health check, or rollback flow yet. No live server installation has been performed.

## API surface

All endpoints are under `/api/v1`. Private routes use Sanctum bearer tokens and workspace membership. Non-members receive `404` for workspace-scoped routes. Contact listing returns masked addresses. Writes require an `Idempotency-Key` header.

- `GET /health` — application process status only; it does not certify database, queue, or provider health.
- `GET /me` — authenticated identity.
- `GET /workspaces`, `POST /workspaces` — the user's workspace list and idempotent workspace creation.
- `GET|POST /workspaces/{workspace}/audience-lists`, `GET /workspaces/{workspace}/audience-lists/{list}` — create and inspect tenant-owned audience lists.
- `GET /workspaces/{workspace}/audience-lists/{list}/members`, `POST /workspaces/{workspace}/audience-lists/{list}/members` — list masked members and add an existing workspace contact.
- `POST /workspaces/{workspace}/audience-lists/{list}/members/{contact}/remove` — append a removal event and update current membership state; history remains available at `GET /workspaces/{workspace}/audience-lists/{list}/members/{contact}/events`.
- `GET|POST /workspaces/{workspace}/contacts` and `GET /workspaces/{workspace}/contacts/{contact}` — paginated masked list, masked detail, and contact creation.
- `GET|POST /workspaces/{workspace}/contacts/{contact}/consent-records` — read or append consent evidence; records are not interpreted as legal approval.
- `GET /workspaces/{workspace}/contacts/{contact}/suppression-events` and `POST /workspaces/{workspace}/contacts/{contact}/suppressions` — read or append a workspace-level suppression. There is no release route in this slice.
- `GET|POST /workspaces/{workspace}/campaigns` and `GET /workspaces/{workspace}/campaigns/{campaign}` — list, read, and create drafts.
- `POST /workspaces/{workspace}/campaigns/{campaign}/revisions` — create an idempotent immutable content revision on a draft.

There are deliberately no schedule, approve, test-send, campaign-run, transport, or infrastructure endpoints in this first slice. Legal jurisdiction, consent rules, suppression scope, approval policy, provider choice, and operating volume must be decided before those capabilities are added.

## Browser console

- `/sign-in` uses Laravel's session guard, rotates the session on success, and limits repeated failures.
- `/app` lists active workspace memberships. Every workspace URL rechecks membership server-side; campaign and audience routes also enforce the API role groups.
- The console is read-only. It shows campaign revision fingerprints and audience membership totals/history; it does not show contact email addresses or provide export controls.
- `DESIGN.md` and `UX-CONTRACT.md` record the current interface style and behavior. CSS runtime tokens live in `resources/css/app.css`.

## Current implementation boundary

The migrations are PostgreSQL-oriented. A disposable PostgreSQL audit verified migrations, tenant constraints, nested API flows, revision numbering and retries, and that database triggers reject updates and deletes on evidence tables. SQLite was also used for route and tenant checks. The browser console is a review surface, not a deployable SaaS: no delivery worker, provider adapter, or production deployment is included. No persistent application database or full test suite has been run. Host PHP lacks XML and PostgreSQL PDO extensions; use a PHP runtime with the documented extensions to operate the app. Boost guidelines were generated from the container in `CLAUDE.md`.

The supplied workspace mount ignores permission changes; its top-level `.env` reports permissive mode. Its values were not copied into this app or printed. Keep DMS and AlphaLink secrets in a private environment directory outside the mount, as described above.
