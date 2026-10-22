# AlphaLink parity report

**Current result:** 0 features `PARITY_CONFIRMED`. The local Laravel tests in `apps/api/tests` exercise selected AlphaLink behavior, but none compares a full feature contract to the DMS reference. No reference mutation-based test has been run.

| Feature | Difference classification | Evidence and consequence | Required resolution |
|---|---|---|---|
| DMS-SRV-001 | BUG for reference equivalence | E-SRV-01: local API and form create one server per IP with batch rollback/replay; username is stored; password and private-key secrets are encrypted and selected for preflight. Provider is still free text; expiry and separate Test Connection behavior are absent. Key passphrase/format rules and DMS connection-test result semantics are not established by available evidence. | Reacquire the read-only source or a recorded exact form/API contract to resolve key/expiry/provider/test details. Run local preflight tests and real-browser comparison. Keep status `TESTING`. |
| DMS-INST-001 | BUG for reference equivalence | E-INST-01: local TXT gate and preview replace DMS bulk selection/install flow. | Rebuild eligible selection and exact options; keep actual installation disabled until isolated artifacts, host safety and worker behavior are verified. |
| DMS-AUD-001 | UNKNOWN | E-AUD-01 establishes structural conflict; exact migration and runtime membership effects need closer trace. | Trace list/recipient state and filter transitions before changing the target model. |
| DMS-CAMP-001 | UNKNOWN | E-CAMP-01 establishes broad workflow gap; sender outcomes remain unresolved. | Trace configuration and snapshot rules; isolate delivery and test the full local lifecycle before parity claim. |
| DMS-TRACK-001 | BUG for reference equivalence | E-TRACK-01: target has no tracking/statistics flow. | Implement after token, event and aggregation contracts are specified. |
| DMS-AFF-001 | BUG for reference equivalence | E-AFF-01: target has no offer/conversion path. | Implement source-backed adapter behavior with isolated fixtures. |

`JUSTIFIED_MODERNIZATION` candidates such as encrypted credentials, host-key checking and append-only audit records remain implementation safeguards only if they preserve the reference operator outcome. Their business effect has not yet been parity tested. No `APPROVED_DEVIATION` is recorded.
