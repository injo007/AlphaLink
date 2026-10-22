# Observed and local API contracts

## DMS-SRV-001

The prior read-only DMS source trace E-SRV-01 shows a form/controller/action flow for server creation. It establishes accepted business inputs and one-server-per-IP creation, but the exact wire request and error payload were not recorded. Do not claim HTTP contract parity from this source trace.

AlphaLink's local `POST /api/v1/workspaces/{workspace}/servers` accepts either `ip_address` (one public address, legacy response object) or `ip_addresses` (1–100 distinct public addresses, response array), plus `ssh_username` and exactly one of `root_password` or `ssh_private_key`. The batch uses one `Idempotency-Key` header and creates all rows or none; an exact replay returns HTTP 200, initial creation returns 201, a changed replay returns 409, and validation failures return 422. These are documented local semantics, not established DMS wire semantics. The browser form accepts one address per line and uses the same batch service. Credentials are encrypted; no SSH connection occurs on creation. Preflight checks the observed host fingerprint before attempting credential authentication.
