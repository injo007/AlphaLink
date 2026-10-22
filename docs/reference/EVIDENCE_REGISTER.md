# Reference evidence register

These IDs index existing reports; they do not imply fresh access to DMS. Each cited report supplies finer source paths, hashes, REA IDs, and limitations.

| ID | Evidence source | Observation and boundary |
|---|---|---|
| E-SRV-01 | `audit/DMS_TO_ALPHALINK_PARITY_2026-10-22.md` § Confirmed DMS operator flow, item 1 | Read-only PHP Blade/controller/action snapshot identifies server form fields, validation and per-IP creation. No live registration performed. |
| E-INST-01 | Same report, items 2–5 and PMTA bundle fact | Bulk selector, options, process/log path and source installation sequence. REA ZIP inventory proved one bundled RPM, not working version compatibility. No installation run. |
| E-AUD-01 | `audit/DATABASE_ARCHITECTURE.md`; `audit/DMS_COMPLETE_CONCLUSION_2026-10-22.md` §1 | Source-described ISP/list/recipient relationships and virtual mixes. Live schema and records were not inspected. |
| E-CAMP-01 | `audit/CAMPAIGN_ORCHESTRATION.md`; `audit/DMS_REA_SENDER_RESOLUTION_2026-10-22.md` | PHP campaign flow and hash-bound managed sender CIL. SMTP-shaped commands were decoded, but live accepted/delivered results and recovery remain unverified. |
| E-TRACK-01 | `audit/TRACKING_AND_ANALYTICS.md`; `audit/DMS_TRACKING_ATTRIBUTION_FLOW.md` | Static mini-app/API/job/aggregation map. Runtime counts and attribution accuracy unverified. |
| E-AFF-01 | `audit/EXTERNAL_INTEGRATIONS.md`; `audit/DMS_COMPLETE_COMPONENT_MAP.md` | Source paths for offer catalog and conversion imports; no provider contacted and active state unknown. |

Evidence strength must be recorded per rule before acceptance: source proves intended control flow, while a runtime or database claim needs its own safe observation. Do not reuse an E-number to support a stronger claim than its boundary allows.
