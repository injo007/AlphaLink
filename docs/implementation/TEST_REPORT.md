# Verification record — 2026-10-22

| Check | Result | Meaning |
|---|---|---|
| `php -v` / `composer -V` | Available: PHP 8.3.6, Composer 2.7.1 | Local tooling exists. |
| Host `php artisan test --testsuite=Feature` | **Did not run**: host PHP lacks `dom` and `xmlwriter`. | Host limitation only. |
| Docker PHP 8.3 `php artisan test --testsuite=Feature --compact` with an ephemeral test `APP_KEY` | **10 passed, 98 assertions** after the credential migration and private-key path. The image supplies `dom`, `xmlwriter`, and SQLite; tests use synthetic in-memory data. | Confirms encrypted secret storage and local registration/preflight behavior under mocks. It does not establish a successful key login against a server or DMS parity. |
| `npm run build` in `apps/api` | Passed, Vite 8.3.5, after the latest form update. | Current browser assets compile. |
| Premium UI static audit, strict mode | Passed, 0 findings, after the latest form update. | Static check only; no form was submitted in a real browser. |
| REA `binary_session` | Connected; no target open. | REA can support future artifact work; this check proves no feature behavior. |
| REA `list_browser_targets` | Input validation error: missing CDP endpoint. | No current reference browser observation was made. |
| Reference mutation-based tests | Not run, by read-only rule. | Existing audit reports are the current bounded reference evidence. |

The reference-shaped server form still needs a real-browser exercise and comparison with DMS evidence. The host PHP extension gap remains relevant for running tests outside Docker.
