---
version: alpha
name: AlphaLink
description: A calm, traceable operations desk for permissioned email work.
colors:
  primary: "#17685F"
  primary-dark: "#0E4F49"
  ink: "#183636"
  muted: "#687773"
  canvas: "#F3F2EB"
  surface: "#FFFEFA"
  accent: "#B84D35"
  signal: "#D8EC78"
  success: "#307760"
  warning: "#8D5716"
  danger: "#A54237"
typography:
  sans:
    fontFamily: "Avenir Next, Avenir, Segoe UI, sans-serif"
  serif:
    fontFamily: "Iowan Old Style, Palatino Linotype, Book Antiqua, Georgia, serif"
  mono:
    fontFamily: "SFMono-Regular, Consolas, Liberation Mono, monospace"
rounded:
  control: "0.45rem"
  panel: "0.7rem"
spacing:
  content-width: "98.75rem"
  panel-padding: "1.125rem"
  button-height: "3rem"
components:
  button:
    backgroundColor: "{colors.primary-dark}"
    textColor: "{colors.surface}"
    rounded: "{rounded.control}"
    height: "{spacing.button-height}"
  field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "2.8125rem"
  panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.panel}"
    padding: "{spacing.panel-padding}"
  navigation:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.primary}"
    width: "15.625rem"
  status:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.muted}"
  status-success:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.success}"
  status-warning:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.warning}"
  status-danger:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.danger}"
  signal:
    backgroundColor: "{colors.signal}"
    textColor: "{colors.primary-dark}"
  annotation:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.surface}"
---

# AlphaLink Design System

## Overview

### Creative North Star

AlphaLink should feel like a well-kept mail operations ledger: a contemporary control desk with the legibility of a printed register. Forest ink, warm paper, restrained terracotta annotations, and small technical labels make evidence and state easy to scan without making the product feel like a marketing site.

### Product context and register

- **Audience and primary job:** Workspace owners, administrators, campaign operators, and privacy officers review permissioned records. Owners and administrators also prepare server installation plans.
- **Target market(s) and evidence:** Global intent; no launch country or jurisdiction has been selected. The interface does not make legal-compliance claims.
- **Locale(s) and language policy:** English is the only implemented UI locale. Dates use the server's configured application timezone; add localization only with an explicit product decision.
- **Usage scene:** Frequent desktop operations work, with a responsive browser shell for quick review on narrow screens.
- **Register:** Product UI. Task clarity, workspace boundaries, and honest lifecycle labels lead.
- **Memorable signature:** A fine signal stripe, warm ledger surface, and serif record headings paired with compact monospaced metadata.
- **Restraint:** Tables, permissions, records, and authentication remain familiar and quiet; no decorative chart or send action suggests capabilities that do not exist.
- **Anti-references:** Generic blue-gradient SaaS dashboards, campaign-launch fireworks, and dense terminal-like admin screens obscure the evidence-first workflow.
- **Token ownership/runtime mapping:** This file documents the accepted values. `resources/css/app.css` is the runtime token owner; its `:root` custom properties and `@theme` font adapters are the mapping. Verify the documented values against those variables when changing the system.

## Colors

Warm canvas (`canvas`) and off-white panels (`surface`) provide a paper-like ground. `ink` and `muted` are semantic text roles; `primary` and `primary-dark` identify navigation and the main action. Terracotta `accent` marks small signal points and exceptions. Lime `signal` is reserved for the brand mark and focus/highlight accents, never for large body text. Success, warning, and danger use distinct status colors. The runtime border role is `#DFE3D9` (CSS `--color-border`); borders carry most static hierarchy. Preserve visible focus and do not rely on color alone for status.

The initial interface is light-only. A dark theme and high-contrast theme are not implemented and should be designed as complete semantic remappings before being offered.

## Typography

Use the system sans stack for controls, labels, and explanatory copy; the serif stack for primary record headings and large counts; and the mono stack for IDs, fingerprints, and compact data labels. All stacks include system fallbacks and do not require a remote font download. Keep labels in sentence case, with uppercase reserved for short technical eyebrows. Use tabular numerals for counts and IDs. English is the current language; future scripts must be checked for line-height and truncation before launch.

## Layout

The desktop shell pairs a collapsible 250px navigation rail with a flexible work area, a slim context bar, and a content width capped near 98.75rem. The navigation width control switches between full and DMS mini states while preserving the active route group. Use a 3-column summary row and two-column information panels when room permits. At tablet widths, the sidebar becomes a top navigation row; at phone widths, metrics stack and tables keep their relationships in a horizontal scroll region. Controls and text wrap before truncating. Keep sticky UI away from focused content and respect reduced-motion preferences.

Spacing is set by content role rather than a universal grid: 1.1875rem is the section gap, 1.125rem the common panel inset, and 2.8125rem the sign-in input height.

## Elevation & Depth

Static content uses thin borders and near-white tonal layers. A low, broad shadow separates record panels from the canvas but must remain nearly invisible. No glass blur or floating-card stack. Navigation is distinguished by fill and text color. Overlays are not part of the current read-only UI.

## Shapes

Controls use a modest 0.45rem radius; panels use 0.7rem. Keep tables square within their panel and use pills only for compact status or role labels. Dividers are 1px and low contrast. The brand mark is a three-bar signal glyph, not an action icon.

## Components

### Foundational visual states

Links and buttons have visible focus, hover, and pressed states. Disabled and busy actions are not used in this read-only slice. Empty states explain what data would appear. Errors use a bordered inline banner and text; status pills pair text with a marker. Pages are server-rendered, so no client skeleton is needed.

### Buttons and actions

Sign-in and infrastructure preparation forms use a filled primary button. Sign-out is a quiet text action. Record links are text-first with an adjacent arrow link where useful. Campaigns use the DMS-shaped register and draft/revision forms; draft save, saved-header management and private negative-file management are available. Launch, send, schedule, export and audience contact-address display remain unavailable until source-equivalent workflow and privacy behavior are proven.

### Navigation and data display

Persistent workspace navigation becomes a labeled horizontal row on narrow screens. Breadcrumbs represent actual route hierarchy. Tables preserve row/column relationships and scroll horizontally when needed. Counts are tabular; workspace and campaign IDs are shown only as short non-secret references. Every table state includes a header and a meaningful empty state.

### Forms and overlays

The sign-in page follows DMS's white, centered login layout with an envelope mark, Username and Password fields, Remember Me, and a full-width LOGIN action. Inputs keep screen-reader labels, password-manager autocomplete, paste, server-owned validation, and an inline live error. AlphaLink accepts an email identifier as a compatibility path for existing accounts. Server installation preparation uses labeled native fields and selects, inline validation, and an immutable preview. Package and host checks appear as explicit blockers. The DMS-shaped campaign composer has a floating action menu, a right-click context menu, a dismissible backdrop, and an inline live status for safe action blockers.

### Iconography

The interface uses small text glyphs and inline typographic symbols, paired with visible labels. Do not introduce unlabeled icon-only actions.

### Motion

Transitions are brief and state-oriented. The interface honors `prefers-reduced-motion`; no animation is required to understand a state change.

### Content and data visualization

Use direct operational language. Say “active members,” “campaign records,” and “content revisions.” Never label an attempt as delivery, a revision as approval, or a campaign count as a send count. The ISP List History page may chart saved membership-state snapshots; these are not delivery or engagement totals. Dashboard charts may combine persisted, scope-labeled campaign open/click/unsubscribe/bot events and Drop visitor events. All Stats, Mailer Revenue and Campaign Statistics provide DMS-style pivots for saved dimensions and supported engagement/lead inputs. Campaign Statistics uses actual lead rows; DMS instead labels each positive-earnings daily row as one conversion. Mailer Revenue and Campaign Statistics expose attributed-lead revenue as a partial earnings measure. CTOR uses clicks / opens; EPC uses attributed lead revenue / clicks and is only a partial proxy for DMS earnings. Campaign leads still depend on DMS SponsorLead attribution; delivery/bounce/seed data, remaining DMS earnings/rates, sender token wiring, and exact MTD/YTD behavior remain open.

## Do's and Don'ts

- **Do:** Keep every record within an explicitly selected workspace.
- **Do:** Pair status with text and show what an empty register means.
- **Don't:** imply that campaigns can be scheduled, sent, or delivered from this interface.
- **Don't:** expose contact addresses in the read-only audience register.
