# AlphaLink UI behavior contract

This document defines current AlphaLink interface behavior. It is scoped to AlphaLink's own workspace, application data, and permissions.

## Product context

- **Users:** Workspace owners, administrators, campaign operators, and privacy officers.
- **Primary tasks:** Manage audiences and campaigns, review engagement data, configure providers, and operate authorized server controls.
- **Design system:** `resources/css/app.css` owns runtime tokens; `DESIGN.md` documents their use.
- **Language and dates:** English interface; dates use the configured application timezone.
- **Accessibility:** Keep semantic headings and controls, keyboard operation, visible focus, accessible status names, and readable contrast.

## Core workflows

| Area | Current behavior | Verification source |
|---|---|---|
| Sign-in and workspace | Laravel session authentication; users can enter only workspaces where they have active membership. Controllers scope data and actions to that workspace. | Auth and workspace authorization feature tests. |
| Audience lists | Lists are associated with an ISP and country and can hold multiple membership states. Operators can search, import, segment, and review action totals where event data exists. | Audience-list management, import, history, and tracking tests. |
| Campaigns | Campaign content is revisioned. A campaign send selects one ISP and country, with multiple lists or audience segments within that selection. Sending to another ISP or country requires another campaign. | Campaign composer, audience snapshot, and send execution tests. |
| Tracking and analytics | Open, click, lead, unsubscribe, and bot events are recorded through AlphaLink endpoints and shown only where source data exists. Tracking signals can be affected by scanners and privacy proxies; reports should distinguish raw requests from unique recipient actions. | Campaign and drop tracking tests; analytics and visitor tests. |
| Infrastructure | Server registration, provider settings, installation, domain changes, PMTA controls, and logs are workspace-scoped and retain their separate operational gates. | Infrastructure, provider, and installation tests. |
| Settings and operations | General settings, health, application errors, and queue status are shown through workspace-authorized pages. Sensitive configuration values are not prefilled into forms or returned to clients. | Settings, health, and application error tests. |

## Permissions and state

- Navigation visibility is a convenience; direct requests are authorized in controllers and policies.
- Cross-workspace records are not exposed through direct identifiers.
- Destructive or connected operations require their explicit confirmation or readiness gate.
- Empty states must say when no records are available instead of inventing sample data.
- Forms preserve user-authored campaign HTML during editing. Send-time substitutions apply only to declared AlphaLink tracking tokens.

## Verification

Use focused PHPUnit suites for the affected controller, service, persistence, and UI behavior. Run PHP syntax checks and `vendor/bin/pint --dirty --format agent` after PHP changes. For browser work, verify the actual AlphaLink page and state when the local environment is available; synthetic fixtures do not establish production data or delivery behavior.
