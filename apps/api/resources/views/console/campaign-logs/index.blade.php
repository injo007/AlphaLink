@extends('layouts.admin-shell')

@section('title', 'Campaign Logs')

@push('styles')
    <style>
        .campaign-logs-page { --logs-border: var(--co-border, #e1def4); --logs-ink: var(--co-ink, #1e214d); --logs-muted: var(--co-muted, #64709b); --logs-surface: var(--co-surface, #fff); --logs-purple: var(--co-purple, #7a3cff); --logs-purple-bright: var(--co-purple-bright, #9a4cff); color: var(--logs-ink); max-width: 100%; min-width: 0; width: 100%; }
        .campaign-logs-header { align-items: center; background: var(--logs-surface); border-bottom: 1px solid var(--logs-border); display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; padding: 1.25rem 1.5rem; }
        .campaign-logs-title { align-items: center; display: flex; gap: .9rem; min-width: 0; }
        .campaign-logs-title-mark { align-items: center; background: color-mix(in srgb, var(--co-purple, #7a3cff) 12%, var(--logs-surface)); border: 1px solid color-mix(in srgb, var(--co-purple, #7a3cff) 24%, var(--logs-border)); border-radius: .85rem; color: var(--co-purple, #7a3cff); display: inline-flex; flex: 0 0 2.75rem; height: 2.75rem; justify-content: center; }
        .campaign-logs-title h1 { color: var(--logs-ink); font-size: clamp(1.2rem, 2vw, 1.55rem); font-weight: 750; letter-spacing: -.025em; margin: 0; }
        .campaign-logs-title p { color: var(--logs-muted); font-size: .85rem; margin: .2rem 0 0; }
        .campaign-logs-eyebrow { color: var(--co-purple, #7a3cff); display: inline-block; font-size: .65rem; font-weight: 800; letter-spacing: .12em; margin-bottom: .22rem; }
        .campaign-logs-page .campaign-logs-header > a.btn { align-items: center; background: color-mix(in srgb, var(--logs-purple) 8%, var(--logs-surface)); border: 1px solid color-mix(in srgb, var(--logs-purple) 25%, var(--logs-border)); border-radius: .7rem; box-shadow: 0 3px 10px color-mix(in srgb, var(--logs-purple) 8%, transparent); color: var(--logs-purple); display: inline-flex; font-size: .78rem; font-weight: 750; gap: .35rem; justify-content: center; min-height: 2.45rem; padding: .45rem .8rem; text-decoration: none; transition: background .16s ease, border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
        .campaign-logs-page .campaign-logs-header > a.btn:hover { background: color-mix(in srgb, var(--logs-purple) 14%, var(--logs-surface)); border-color: color-mix(in srgb, var(--logs-purple) 45%, var(--logs-border)); box-shadow: 0 5px 14px color-mix(in srgb, var(--logs-purple) 15%, transparent); color: var(--logs-purple); text-decoration: none; transform: translateY(-1px); }
        .campaign-logs-toolbar { background: color-mix(in srgb, var(--logs-surface) 88%, var(--co-page, #f4f3fb)); border-bottom: 1px solid var(--logs-border); box-sizing: border-box; min-width: 0; padding: 1rem 1.25rem; width: 100%; }
        .campaign-logs-filters { align-items: end; box-sizing: border-box; display: grid; gap: .65rem; grid-template-columns: repeat(2, minmax(0, 1fr)); max-width: 100%; min-width: 0; width: 100%; }
        .campaign-logs-field { display: flex; flex-direction: column; gap: .3rem; min-width: 0; }
        .campaign-logs-field.search-field { grid-column: 1 / -1; }
        .campaign-logs-field label { color: var(--logs-muted); font-size: .72rem; font-weight: 700; letter-spacing: .045em; margin: 0; text-transform: uppercase; }
        .campaign-logs-field.operator-filter-toggle { justify-content: end; }
        .campaign-logs-operator-switch { align-items: center; display: flex; gap: .55rem; min-height: 2.55rem; }
        .campaign-logs-operator-switch input { appearance: none; background: color-mix(in srgb, var(--logs-muted) 17%, var(--logs-surface)); border: 1px solid var(--logs-border); border-radius: 999px; cursor: pointer; flex: 0 0 2.15rem; height: 1.2rem; margin: 0; min-height: 1.2rem; padding: 0; position: relative; transition: background .16s ease, border-color .16s ease, box-shadow .16s ease; width: 2.15rem; }
        .campaign-logs-operator-switch input::after { background: var(--logs-surface); border-radius: 50%; box-shadow: 0 1px 3px rgb(20 25 55 / 20%); content: ''; height: .82rem; left: .15rem; position: absolute; top: .14rem; transition: transform .16s ease; width: .82rem; }
        .campaign-logs-operator-switch input:checked { background: var(--logs-purple); border-color: var(--logs-purple); }
        .campaign-logs-operator-switch input:checked::after { background: #fff; transform: translateX(.92rem); }
        .campaign-logs-operator-switch input:focus-visible { box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--logs-purple) 24%, transparent); outline: 0; }
        .campaign-logs-operator-switch span { color: var(--logs-ink); font-size: .75rem; font-weight: 700; letter-spacing: 0; text-transform: none; }
        .campaign-logs-operator-note { border: 1px solid color-mix(in srgb, var(--co-blue, #4888f4) 25%, var(--logs-border)); border-radius: .6rem; color: var(--logs-ink); font-size: .75rem; margin: .85rem 0 0; padding: .6rem .75rem; background: color-mix(in srgb, var(--co-blue, #4888f4) 7%, var(--logs-surface)); }
        .campaign-logs-field input, .campaign-logs-field select { background: var(--logs-surface); border: 1px solid var(--logs-border); border-radius: .65rem; color: var(--logs-ink); min-height: 2.55rem; padding: .45rem .7rem; transition: border-color .16s ease, box-shadow .16s ease; width: 100%; }
        .campaign-logs-field input:hover, .campaign-logs-field select:hover { border-color: color-mix(in srgb, var(--logs-purple) 35%, var(--logs-border)); }
        .campaign-logs-field.operator-filter-toggle .campaign-logs-operator-switch input[type="checkbox"] { appearance: none; background: color-mix(in srgb, var(--logs-muted) 17%, var(--logs-surface)); border: 1px solid var(--logs-border); border-radius: 999px; flex: 0 0 2.15rem; height: 1.2rem; min-height: 1.2rem; width: 2.15rem; }
        .campaign-logs-field.operator-filter-toggle .campaign-logs-operator-switch input[type="checkbox"]:checked { background: var(--logs-purple); border-color: var(--logs-purple); }
        .campaign-logs-field.operator-filter-toggle .campaign-logs-operator-switch input[type="checkbox"]::after { background: var(--logs-surface); border-radius: 50%; box-shadow: 0 1px 3px rgb(20 25 55 / 20%); content: ''; height: .82rem; left: .15rem; position: absolute; top: .14rem; transition: transform .16s ease; width: .82rem; }
        .campaign-logs-field.operator-filter-toggle .campaign-logs-operator-switch input[type="checkbox"]:checked::after { background: #fff; transform: translateX(.92rem); }
        .campaign-logs-field input:focus, .campaign-logs-field select:focus { border-color: var(--logs-purple); box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--logs-purple) 17%, transparent); outline: 0; }
        .campaign-logs-table-wrap { max-width: 100%; min-width: 0; overflow-x: auto; overscroll-behavior-x: contain; scrollbar-gutter: stable; width: 100%; }
        .campaign-logs-table-wrap:focus-visible { outline: 2px solid var(--co-purple, #7a3cff); outline-offset: -2px; }
        .campaign-logs-table { margin: 0; min-width: 1000px; table-layout: fixed; width: 100%; }
        .campaign-logs-table th:nth-child(1) { width: 7%; }
        .campaign-logs-table th:nth-child(2) { width: 5%; }
        .campaign-logs-table th:nth-child(3) { width: 19%; }
        .campaign-logs-table th:nth-child(4) { width: 7%; }
        .campaign-logs-table th:nth-child(5) { width: 10%; }
        .campaign-logs-table th:nth-child(6) { width: 7%; }
        .campaign-logs-table th:nth-child(7) { width: 17%; }
        .campaign-logs-table th:nth-child(8) { width: 28%; }
        .campaign-logs-table tbody td:nth-child(3) { overflow-wrap: anywhere; }
        .campaign-logs-table thead th { background: linear-gradient(105deg, color-mix(in srgb, var(--logs-purple) 7%, var(--logs-surface)), color-mix(in srgb, var(--logs-surface) 82%, var(--co-page, #f4f3fb))); border-bottom: 1px solid var(--logs-border); color: var(--logs-muted); font-size: .7rem; font-weight: 800; letter-spacing: .06em; padding: .78rem .85rem; text-transform: uppercase; white-space: nowrap; }
        .campaign-logs-table tbody td { border-top: 1px solid var(--logs-border); color: var(--logs-ink); padding: .8rem .85rem; vertical-align: middle; }
        .campaign-logs-table tbody tr:nth-child(even) { background: color-mix(in srgb, var(--logs-purple) 2.5%, var(--logs-surface)); }
        .campaign-logs-table tbody tr:hover { background: color-mix(in srgb, var(--logs-purple) 7%, var(--logs-surface)); }
        .campaign-log-id { color: var(--logs-muted); font-family: var(--font-mono, ui-monospace, monospace); font-size: .8rem; font-variant-numeric: tabular-nums; }
        .campaign-log-status { align-items: center; border: 1px solid var(--logs-border); border-radius: 999px; display: inline-flex; font-size: .7rem; font-weight: 750; gap: .38rem; padding: .27rem .55rem; text-transform: uppercase; white-space: nowrap; }
        .campaign-log-status::before { background: currentColor; border-radius: 50%; content: ''; height: .42rem; width: .42rem; }
        .campaign-log-counts { align-items: center; display: flex; flex-wrap: wrap; gap: .35rem; min-width: 0; }
        .campaign-log-counts form { display: inline-flex; margin: 0; min-width: 0; }
        .campaign-log-action { align-items: center; background: color-mix(in srgb, var(--logs-purple) 8%, var(--logs-surface)); border: 1px solid color-mix(in srgb, var(--logs-purple) 23%, var(--logs-border)); border-radius: .55rem; color: var(--logs-purple); display: inline-flex; font-size: .69rem; font-weight: 800; gap: .27rem; justify-content: center; min-height: 2.1rem; padding: .3rem .48rem; text-decoration: none; transition: background .16s ease, border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
        .campaign-log-action:hover { background: color-mix(in srgb, var(--logs-purple) 15%, var(--logs-surface)); border-color: color-mix(in srgb, var(--logs-purple) 42%, var(--logs-border)); box-shadow: 0 3px 9px color-mix(in srgb, var(--logs-purple) 12%, transparent); color: var(--logs-purple); text-decoration: none; transform: translateY(-1px); }
        .campaign-log-action:focus-visible, .campaign-log-restrict:focus-visible, .campaign-logs-apply:focus-visible { box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--logs-purple) 30%, transparent); outline: 0; }
        .campaign-log-action span { font-variant-numeric: tabular-nums; }
        .campaign-log-restrict { background: color-mix(in srgb, #d9475b 8%, var(--logs-surface)); border: 1px solid color-mix(in srgb, #d9475b 35%, var(--logs-border)); border-radius: .55rem; color: #c7354b; cursor: pointer; display: inline-flex; font-size: .68rem; font-weight: 800; justify-content: center; min-height: 2.1rem; padding: .3rem .58rem; transition: background .16s ease, border-color .16s ease, box-shadow .16s ease, transform .16s ease; white-space: nowrap; }
        .campaign-log-restrict:hover:not(:disabled) { background: color-mix(in srgb, #d9475b 15%, var(--logs-surface)); border-color: #d9475b; box-shadow: 0 3px 9px rgb(217 71 91 / 12%); transform: translateY(-1px); }
        .campaign-log-restrict.is-restricted { background: #d9475b; border-color: #d9475b; color: #fff; }
        .campaign-log-restrict.is-restricted:hover:not(:disabled) { background: #c7354b; border-color: #c7354b; }
        .campaign-log-restrict.is-unrestricted { background: color-mix(in srgb, #d9475b 7%, var(--logs-surface)); }
        .campaign-log-restrict:disabled { cursor: not-allowed; opacity: .48; }
        .campaign-logs-filter-actions { align-items: center; display: flex; flex-wrap: wrap; gap: .55rem; grid-column: 1 / -1; justify-content: flex-start; min-width: 0; width: 100%; }
        .campaign-logs-page .campaign-logs-filter-actions .campaign-logs-apply { align-items: center; background: linear-gradient(110deg, var(--logs-purple-bright), var(--logs-purple)); border: 1px solid var(--logs-purple); border-radius: .62rem; box-shadow: 0 4px 12px color-mix(in srgb, var(--logs-purple) 22%, transparent); color: #fff; display: inline-flex; font-size: .76rem; font-weight: 800; gap: .36rem; justify-content: center; min-height: 2.35rem; padding: .42rem .82rem; transition: box-shadow .16s ease, filter .16s ease, transform .16s ease; }
        .campaign-logs-page .campaign-logs-filter-actions .campaign-logs-apply:hover { box-shadow: 0 6px 16px color-mix(in srgb, var(--logs-purple) 28%, transparent); color: #fff; filter: brightness(1.04); transform: translateY(-1px); }
        .campaign-logs-page .campaign-logs-filter-actions > a.btn { align-items: center; background: var(--logs-surface); border: 1px solid var(--logs-border); border-radius: .62rem; color: var(--logs-muted); display: inline-flex; font-size: .76rem; font-weight: 750; justify-content: center; min-height: 2.35rem; padding: .42rem .82rem; text-decoration: none; transition: background .16s ease, border-color .16s ease, color .16s ease, transform .16s ease; }
        .campaign-logs-page .campaign-logs-filter-actions > a.btn:hover { background: color-mix(in srgb, var(--logs-purple) 7%, var(--logs-surface)); border-color: color-mix(in srgb, var(--logs-purple) 32%, var(--logs-border)); color: var(--logs-purple); text-decoration: none; transform: translateY(-1px); }
        .campaign-logs-footer { align-items: center; color: var(--logs-muted); display: flex; flex-wrap: wrap; gap: .75rem; justify-content: space-between; padding: 1rem 1.25rem; }
        .campaign-logs-footer .pagination { margin: 0; }
        .campaign-logs-page .campaign-logs-footer .pagination { gap: .25rem; }
        .campaign-logs-page .campaign-logs-footer .pagination .page-link { align-items: center; background: var(--logs-surface); border: 1px solid var(--logs-border); border-radius: .55rem; color: var(--logs-muted); display: inline-flex; justify-content: center; min-height: 2.1rem; min-width: 2.1rem; padding: .35rem .62rem; }
        .campaign-logs-page .campaign-logs-footer .pagination .page-item.active .page-link { background: color-mix(in srgb, var(--logs-purple) 10%, var(--logs-surface)); border-color: color-mix(in srgb, var(--logs-purple) 35%, var(--logs-border)); color: var(--logs-purple); font-weight: 800; }
        .campaign-logs-page .campaign-logs-footer .pagination .page-item:not(.active):not(.disabled) .page-link:hover { background: color-mix(in srgb, var(--logs-purple) 7%, var(--logs-surface)); border-color: color-mix(in srgb, var(--logs-purple) 30%, var(--logs-border)); color: var(--logs-purple); }
        .campaign-logs-empty { color: var(--logs-muted); padding: 3rem 1rem !important; text-align: center; }
        .campaign-logs-empty i { color: var(--co-purple, #7a3cff); display: block; font-size: 1.4rem; margin-bottom: .55rem; }
        .campaign-log-dialog { background: var(--logs-surface, #fff); border: 1px solid var(--logs-border, #e1def4); border-radius: 1rem; box-shadow: 0 1.5rem 5rem rgba(24, 22, 58, .28); color: var(--logs-ink, #1e214d); height: min(88vh, 920px); max-height: calc(100dvh - 1.5rem); max-width: min(1120px, calc(100vw - 1.5rem)); overflow: hidden; padding: 0; width: 100%; }
        .campaign-log-dialog::backdrop { background: rgba(20, 21, 45, .56); backdrop-filter: blur(3px); }
        .campaign-log-dialog-shell { display: flex; flex-direction: column; height: 100%; min-height: 0; }
        .campaign-log-dialog-top { align-items: center; background: color-mix(in srgb, var(--co-purple, #7a3cff) 5%, var(--logs-surface, #fff)); border-bottom: 1px solid var(--logs-border, #e1def4); display: flex; flex: 0 0 auto; gap: 1rem; justify-content: space-between; padding: .9rem 1.1rem; }
        .campaign-log-dialog-top h2 { color: var(--logs-ink, #1e214d); font-size: .98rem; font-weight: 800; margin: 0; }
        .campaign-log-dialog-close { align-items: center; background: color-mix(in srgb, var(--co-purple, #7a3cff) 7%, var(--logs-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-purple, #7a3cff) 22%, var(--logs-border, #e1def4)); border-radius: .6rem; color: var(--co-purple, #7a3cff); cursor: pointer; display: inline-flex; height: 2.25rem; justify-content: center; transition: background .16s ease, border-color .16s ease, transform .16s ease; width: 2.25rem; }
        .campaign-log-dialog-close:hover, .campaign-log-dialog-close:focus-visible { border-color: var(--co-purple, #7a3cff); color: var(--co-purple, #7a3cff); outline: 0; }
        .campaign-log-dialog-content { flex: 1 1 auto; min-height: 0; overflow: auto; overscroll-behavior: contain; padding: 1rem 1.1rem 1.1rem; }
        .campaign-log-dialog-content[aria-busy="true"] { cursor: progress; }
        .campaign-log-modal-heading, .campaign-log-config-heading { align-items: center; border-bottom: 1px solid var(--logs-border, #e1def4); display: flex; flex-wrap: wrap; gap: .85rem; justify-content: space-between; margin: -.15rem 0 .85rem; padding: .1rem 0 .85rem; }
        .campaign-log-modal-heading h2, .campaign-log-config-heading h2 { color: var(--logs-ink, #1e214d); font-size: clamp(1.05rem, 2vw, 1.3rem); font-weight: 800; margin: 0; }
        .campaign-log-modal-heading h2 i { color: var(--co-purple, #7a3cff); margin-right: .4rem; }
        .campaign-log-modal-heading p, .campaign-log-config-heading p { color: var(--logs-muted, #64709b); font-size: .8rem; margin: .22rem 0 0; }
        .campaign-log-modal-eyebrow { color: var(--co-purple, #7a3cff); display: block; font-size: .65rem; font-weight: 800; letter-spacing: .1em; margin-bottom: .25rem; text-transform: uppercase; }
        .campaign-log-modal-id { color: var(--logs-muted, #64709b); font-family: var(--font-mono, ui-monospace, monospace); font-size: .75rem; margin-left: .3rem; }
        .campaign-log-modal-count { background: color-mix(in srgb, var(--co-purple, #7a3cff) 10%, var(--logs-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-purple, #7a3cff) 22%, var(--logs-border, #e1def4)); border-radius: 999px; color: var(--co-purple, #7a3cff); font-size: .75rem; font-weight: 800; padding: .35rem .7rem; white-space: nowrap; }
        .campaign-log-history-tabs { border-bottom: 1px solid var(--logs-border, #e1def4); display: flex; flex-wrap: wrap; gap: .35rem; margin: -.15rem 0 .8rem; }
        .campaign-log-history-tabs button { background: transparent; border: 1px solid transparent; border-bottom: 0; border-radius: .5rem .5rem 0 0; color: var(--logs-muted, #64709b); cursor: pointer; font-size: .75rem; font-weight: 750; margin-bottom: -1px; padding: .5rem .8rem; transition: background .16s ease, color .16s ease; }
        .campaign-log-history-tabs button:hover { background: color-mix(in srgb, var(--co-purple, #7a3cff) 5%, var(--logs-surface, #fff)); color: var(--co-purple, #7a3cff); }
        .campaign-log-history-tabs button span { background: color-mix(in srgb, var(--logs-muted, #64709b) 9%, var(--logs-surface, #fff)); border-radius: 99px; font-size: .67rem; margin-left: .25rem; padding: .12rem .36rem; }
        .campaign-log-history-tabs button[aria-current="page"] { background: color-mix(in srgb, var(--co-purple, #7a3cff) 8%, var(--logs-surface, #fff)); border-color: var(--logs-border, #e1def4); color: var(--co-purple, #7a3cff); }
        .campaign-log-modal-table-wrap { max-width: 100%; overflow: auto; overscroll-behavior: contain; }
        .campaign-log-modal-table { border-collapse: collapse; min-width: 730px; width: 100%; }
        .campaign-log-modal-table th { background: color-mix(in srgb, var(--logs-surface, #fff) 83%, var(--co-page, #f4f3fb)); border-bottom: 1px solid var(--logs-border, #e1def4); color: var(--logs-muted, #64709b); font-size: .67rem; font-weight: 800; letter-spacing: .055em; padding: .65rem .7rem; text-align: left; text-transform: uppercase; white-space: nowrap; }
        .campaign-log-modal-table td { border-bottom: 1px solid var(--logs-border, #e1def4); color: var(--logs-ink, #1e214d); font-size: .8rem; padding: .7rem; vertical-align: middle; }
        .campaign-log-modal-table tbody tr:hover { background: color-mix(in srgb, var(--co-purple, #7a3cff) 4%, var(--logs-surface, #fff)); }
        .campaign-log-modal-status { background: color-mix(in srgb, var(--co-blue, #4888f4) 10%, var(--logs-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-blue, #4888f4) 22%, var(--logs-border, #e1def4)); border-radius: 999px; color: var(--co-blue, #3978e8); display: inline-flex; font-size: .65rem; font-weight: 800; padding: .2rem .5rem; white-space: nowrap; }
        .campaign-log-config-button, .campaign-log-config-back { align-items: center; background: color-mix(in srgb, var(--co-purple, #7a3cff) 8%, var(--logs-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-purple, #7a3cff) 24%, var(--logs-border, #e1def4)); border-radius: .55rem; color: var(--co-purple, #7a3cff); cursor: pointer; display: inline-flex; font-size: .74rem; font-weight: 750; gap: .4rem; justify-content: center; min-height: 2.15rem; padding: .35rem .65rem; white-space: nowrap; }
        .campaign-log-config-button:hover, .campaign-log-config-back:hover, .campaign-log-config-button:focus-visible, .campaign-log-config-back:focus-visible { background: color-mix(in srgb, var(--co-purple, #7a3cff) 15%, var(--logs-surface, #fff)); outline: 2px solid color-mix(in srgb, var(--co-purple, #7a3cff) 40%, transparent); outline-offset: 1px; }
        .campaign-log-modal-empty { color: var(--logs-muted, #64709b) !important; padding: 2rem !important; text-align: center; }
        .campaign-log-modal-footer { align-items: center; color: var(--logs-muted, #64709b); display: flex; flex-wrap: wrap; gap: .75rem; justify-content: space-between; padding: .8rem .15rem 0; }
        .campaign-log-modal-footer .pagination { margin: 0; }
        .campaign-log-config-heading { align-items: flex-start; }
        .campaign-log-config-heading > div { flex: 1 1 16rem; min-width: 0; }
        .campaign-log-config-back { flex: 0 0 auto; }
        .campaign-log-config-unavailable { background: color-mix(in srgb, #edaa3a 10%, var(--logs-surface, #fff)); border: 1px solid color-mix(in srgb, #edaa3a 32%, var(--logs-border, #e1def4)); border-radius: .65rem; color: var(--logs-ink, #1e214d); margin-bottom: .85rem; padding: .7rem .8rem; }
        .campaign-log-config-grid { display: grid; gap: .8rem; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: .8rem; }
        .campaign-log-config-card { background: var(--logs-surface, #fff); border: 1px solid var(--logs-border, #e1def4); border-radius: .75rem; min-width: 0; padding: .85rem; }
        .campaign-log-config-card h3 { align-items: center; color: var(--logs-ink, #1e214d); display: flex; font-size: .86rem; font-weight: 800; gap: .5rem; margin: 0 0 .65rem; }
        .campaign-log-config-card h3 i { color: var(--co-purple, #7a3cff); }
        .campaign-log-config-card h4 { color: var(--logs-muted, #64709b); font-size: .68rem; font-weight: 800; letter-spacing: .05em; margin: .65rem 0 .35rem; text-transform: uppercase; }
        .campaign-log-config-card dl { display: grid; gap: .35rem; margin: 0; }
        .campaign-log-config-card dl > div { align-items: start; border-bottom: 1px solid color-mix(in srgb, var(--logs-border, #e1def4) 70%, transparent); display: grid; gap: .6rem; grid-template-columns: minmax(7rem, .75fr) minmax(0, 1.25fr); padding: .3rem 0; }
        .campaign-log-config-card dt { color: var(--logs-muted, #64709b); font-size: .72rem; font-weight: 700; }
        .campaign-log-config-card dd { color: var(--logs-ink, #1e214d); font-size: .76rem; margin: 0; overflow-wrap: anywhere; }
        .campaign-log-server-note { color: var(--logs-muted, #64709b); display: block; font-size: .66rem; line-height: 1.4; margin-top: .25rem; }
        .campaign-log-value-chip { background: color-mix(in srgb, var(--co-purple, #7a3cff) 8%, var(--logs-surface, #fff)); border: 1px solid var(--logs-border, #e1def4); border-radius: .4rem; display: inline-flex; margin: 0 .25rem .2rem 0; padding: .14rem .4rem; }
        .campaign-log-route-list > div { border-top: 1px solid var(--logs-border, #e1def4); display: flex; flex-wrap: wrap; gap: .3rem .7rem; justify-content: space-between; padding: .45rem 0; }
        .campaign-log-route-list strong { color: var(--logs-ink, #1e214d); font-size: .73rem; }
        .campaign-log-route-list span, .campaign-log-config-muted { color: var(--logs-muted, #64709b); font-size: .72rem; }
        .campaign-log-config-message { margin-top: .8rem; }
        .campaign-log-config-message pre { background: color-mix(in srgb, var(--co-page, #f4f3fb) 70%, var(--logs-surface, #fff)); border: 1px solid var(--logs-border, #e1def4); border-radius: .55rem; color: var(--logs-ink, #1e214d); font-family: var(--font-mono, ui-monospace, monospace); font-size: .72rem; line-height: 1.5; margin: 0; max-height: 15rem; overflow: auto; padding: .7rem; white-space: pre-wrap; overflow-wrap: anywhere; }
        .campaign-log-modal-loading, .campaign-log-modal-error { align-items: center; color: var(--logs-muted, #64709b); display: flex; gap: .65rem; justify-content: center; min-height: 10rem; padding: 2rem; text-align: center; }
        .campaign-log-modal-error { flex-direction: column; }
        @media (max-width: 1450px) {
            .campaign-logs-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .campaign-logs-field.search-field { grid-column: 1 / -1; }
            .campaign-logs-filter-actions { align-self: end; }
        }
        @media (max-width: 640px) {
            .campaign-log-dialog { border-radius: .75rem; height: calc(100dvh - 1rem); max-height: calc(100dvh - 1rem); max-width: calc(100vw - 1rem); }
            .campaign-log-dialog-top { padding: .75rem .85rem; }
            .campaign-log-dialog-content { padding: .8rem; }
            .campaign-log-config-grid { grid-template-columns: minmax(0, 1fr); }
            .campaign-log-modal-heading { align-items: flex-start; }
            .campaign-log-modal-footer { align-items: flex-start; flex-direction: column; }
            .campaign-log-config-card dl > div { grid-template-columns: minmax(6rem, .7fr) minmax(0, 1.3fr); }
            .campaign-logs-header { align-items: flex-start; padding: 1rem; }
            .campaign-logs-toolbar { padding: .85rem; }
            .campaign-logs-filters { grid-template-columns: minmax(0, 1fr); }
            .campaign-logs-field.search-field { grid-column: auto; }
            .campaign-logs-filter-actions { grid-column: auto; }
            .campaign-logs-filter-actions > * { flex: 1 1 8rem; }
            .campaign-logs-footer { align-items: flex-start; flex-direction: column; padding: .85rem; }
        }
        @media (prefers-reduced-motion: reduce) { .campaign-log-action { transition: none; } }
    </style>
@endpush

@section('content')
    <section class="block block-rounded campaign-logs-page" aria-labelledby="campaign-logs-title">
        <header class="campaign-logs-header">
            <div class="campaign-logs-title">
                <span class="campaign-logs-title-mark" aria-hidden="true"><i class="fas fa-history"></i></span>
                <div>
                    <span class="campaign-logs-eyebrow">CAMPAIGN STUDIO</span>
                    <h1 id="campaign-logs-title">Campaign Logs</h1>
                    <p>Review campaign tests, saved updates, send runs, and restriction status.</p>
                </div>
            </div>
            <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.mailer.campaigns', $operatorCampaignViewQuery) }}"><i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>My campaigns</a>
        </header>

        <div class="campaign-logs-toolbar">
            <form method="GET" action="{{ route('console.campaign-logs.index', $workspace->id) }}" class="campaign-logs-filters" aria-label="Filter campaign logs">
                <div class="campaign-logs-field">
                    <label for="campaign-logs-from">From</label>
                    <input id="campaign-logs-from" type="date" name="from" value="{{ $from->toDateString() }}" max="{{ today()->toDateString() }}">
                </div>
                <div class="campaign-logs-field">
                    <label for="campaign-logs-to">To</label>
                    <input id="campaign-logs-to" type="date" name="to" value="{{ $to->toDateString() }}" max="{{ today()->toDateString() }}">
                </div>
                <div class="campaign-logs-field search-field">
                    <label for="campaign-logs-search">Search</label>
                    <input id="campaign-logs-search" type="search" name="q" value="{{ $search }}" placeholder="Campaign, ID, or user">
                </div>
                <div class="campaign-logs-field">
                    <label for="campaign-logs-page-size">Rows</label>
                    <select id="campaign-logs-page-size" name="per_page">
                        @foreach ([20, 50, 100] as $pageSize)
                            <option value="{{ $pageSize }}" @selected($perPage === $pageSize)>Show {{ $pageSize }} rows</option>
                        @endforeach
                    </select>
                </div>
                @if ($isAdmin)
                    <div class="campaign-logs-field operator-filter-toggle">
                        <label class="campaign-logs-operator-switch" for="campaign-logs-operator-campaigns">
                            <input id="campaign-logs-operator-campaigns" name="view_operator_campaigns" type="checkbox" value="1" @checked($showOperatorCampaigns) onchange="this.form.submit()">
                            <span>Show operator campaigns</span>
                        </label>
                    </div>
                    @if ($showOperatorCampaigns)
                        <div class="campaign-logs-field">
                            <label for="campaign-logs-operator-select">Operator</label>
                            <select id="campaign-logs-operator-select" name="operator_id" aria-label="Select operator" onchange="this.form.submit()">
                                <option value="">All operators</option>
                                @foreach ($operatorUsers as $operator)
                                    <option value="{{ $operator->id }}" @selected($selectedOperatorId === (int) $operator->id)>{{ $operator->name ?: $operator->username }}{{ ! $operator->is_active ? ' (Inactive)' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                @endif
                <div class="campaign-logs-filter-actions">
                    <button class="btn btn-sm btn-primary campaign-logs-apply" type="submit"><i class="fas fa-filter mr-1" aria-hidden="true"></i>Apply filters</button>
                    <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.mailer.campaign-logs', $operatorCampaignViewQuery) }}">Reset</a>
                </div>
            </form>
            @if ($showOperatorCampaigns)
                <p class="campaign-logs-operator-note" role="status">Read-only campaign history for {{ $selectedOperator ? ($selectedOperator->name ?: $selectedOperator->username) : 'all workspace operators' }}.</p>
            @endif
        </div>

        @if (session('status'))
            <div class="alert alert-success m-3" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->has('is_restricted'))
            <div class="alert alert-warning m-3" role="alert">{{ $errors->first('is_restricted') }}</div>
        @endif

        <div class="campaign-logs-table-wrap" role="region" aria-label="Scrollable Campaign Logs table" tabindex="0">
            <table class="table table-sm campaign-logs-table" aria-label="Campaign log register">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">ISP</th>
                        <th scope="col">Offer</th>
                        <th scope="col">User</th>
                        <th scope="col">Send status</th>
                        <th scope="col">Send left</th>
                        <th scope="col">Created at</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $campaign)
                        @php
                            $sendRun = $campaign->logs_send_run;
                            $processed = $sendRun ? (int) $sendRun->accepted_count + (int) $sendRun->rejected_count + (int) $sendRun->suppressed_count + (int) $sendRun->uncertain_count : 0;
                            $sendLeft = $sendRun ? max(0, (int) $sendRun->recipient_count - $processed) : null;
                            $sendState = $sendRun?->state ? strtoupper(str_replace('_', ' ', $sendRun->state)) : strtoupper(str_replace('_', ' ', $campaign->status));
                            $offerLabel = $campaign->logs_offer ? trim('('.$campaign->logs_offer->offer_id.') '.$campaign->logs_offer->name) : '—';
                        @endphp
                        <tr>
                            <td><span class="campaign-log-id" title="{{ $campaign->id }}">{{ strtoupper(substr((string) $campaign->id, 0, 8)) }}</span></td>
                            <td>{{ $campaign->logs_isp ?: '—' }}</td>
                            <td>{{ $offerLabel }}</td>
                            <td>{{ $campaign->creator?->name ?: ($campaign->creator?->username ?: '—') }}</td>
                            <td><span class="campaign-log-status">{{ $sendState }}</span></td>
                            <td class="text-nowrap">@if ($sendRun) {{ number_format($sendLeft) }} / {{ number_format((int) $sendRun->recipient_count) }} @else — @endif</td>
                            <td class="text-nowrap">{{ $campaign->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                            <td>
                                <div class="campaign-log-counts" role="group" aria-label="History actions for {{ $campaign->name }}">
                                    @if ($canReviewHistory)
                                    <a class="campaign-log-action" href="{{ route('console.campaign-logs.history', array_merge([$workspace->id, $campaign->id, 'tests'], $operatorCampaignViewQuery)) }}" data-campaign-history-url="{{ route('console.campaign-logs.history', array_merge([$workspace->id, $campaign->id, 'tests'], array_merge($operatorCampaignViewQuery, ['fragment' => 1]))) }}" title="Open test history" aria-label="Open {{ $campaign->tests_count }} tests for {{ $campaign->name }}"><i class="fas fa-vial" aria-hidden="true"></i><span>T:{{ $campaign->tests_count }}</span></a>
                                    <a class="campaign-log-action" href="{{ route('console.campaign-logs.history', array_merge([$workspace->id, $campaign->id, 'updates'], $operatorCampaignViewQuery)) }}" data-campaign-history-url="{{ route('console.campaign-logs.history', array_merge([$workspace->id, $campaign->id, 'updates'], array_merge($operatorCampaignViewQuery, ['fragment' => 1]))) }}" title="Open update history" aria-label="Open {{ $campaign->updates_count }} updates for {{ $campaign->name }}"><i class="fas fa-pen-to-square" aria-hidden="true"></i><span>U:{{ $campaign->updates_count }}</span></a>
                                    <a class="campaign-log-action" href="{{ route('console.campaign-logs.history', array_merge([$workspace->id, $campaign->id, 'sends'], $operatorCampaignViewQuery)) }}" data-campaign-history-url="{{ route('console.campaign-logs.history', array_merge([$workspace->id, $campaign->id, 'sends'], array_merge($operatorCampaignViewQuery, ['fragment' => 1]))) }}" title="Open send history" aria-label="Open {{ $campaign->sends_count }} sends for {{ $campaign->name }}"><i class="fas fa-paper-plane" aria-hidden="true"></i><span>S:{{ $campaign->sends_count }}</span></a>
                                    @else
                                        <span class="text-muted small">Tests: {{ $campaign->tests_count }} · Updates: {{ $campaign->updates_count }} · Sends: {{ $campaign->sends_count }}</span>
                                    @endif
                                    @if ($canManageRestrictions)
                                    <form method="POST" action="{{ route('console.campaign-logs.restriction', [$workspace->id, $campaign->id]) }}" onsubmit="return confirm(this.dataset.confirmMessage)" data-confirm-message="{{ $campaign->is_restricted ? 'Unrestrict this campaign so new sends can be started?' : 'Restrict this campaign from new sends?' }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_restricted" value="{{ $campaign->is_restricted ? 0 : 1 }}">
                                        <button class="campaign-log-restrict {{ $campaign->is_restricted ? 'is-restricted' : 'is-unrestricted' }}" type="submit" @disabled($campaign->logs_has_active_send && ! $campaign->is_restricted) title="{{ $campaign->logs_has_active_send && ! $campaign->is_restricted ? 'Stop the active send before restricting this campaign.' : ($campaign->is_restricted ? 'Allow new sends' : 'Block new sends') }}" aria-label="{{ $campaign->is_restricted ? 'Unrestrict' : 'Restrict' }} {{ $campaign->name }}">
                                            {{ $campaign->is_restricted ? 'UNRESTRICT' : 'RESTRICT' }}
                                        </button>
                                    </form>
                                    @elseif ($campaign->is_restricted)
                                        <span class="campaign-log-restrict is-restricted" aria-label="Campaign restricted">RESTRICTED</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="campaign-logs-empty"><i class="fas fa-inbox" aria-hidden="true"></i>No campaign logs match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="campaign-logs-footer">
            <span>Showing {{ $campaigns->count() }} of {{ $campaigns->total() }} campaigns</span>
            {{ $campaigns->links() }}
        </footer>
    </section>
    <dialog id="campaign-log-dialog" class="campaign-log-dialog" aria-labelledby="campaign-log-dialog-title" aria-modal="true">
        <div class="campaign-log-dialog-shell">
            <header class="campaign-log-dialog-top"><h2 id="campaign-log-dialog-title">Campaign activity</h2><button class="campaign-log-dialog-close" type="button" data-campaign-dialog-close aria-label="Close campaign activity"><i class="fas fa-times" aria-hidden="true"></i></button></header>
            <div id="campaign-log-dialog-content" class="campaign-log-dialog-content" aria-live="polite" aria-busy="false"><p class="campaign-log-modal-loading">Choose Tests, Updates, or Sends to review campaign activity.</p></div>
        </div>
    </dialog>
@endsection

@push('scripts')
<script>
(() => {
    const dialog = document.getElementById('campaign-log-dialog');
    const content = document.getElementById('campaign-log-dialog-content');
    if (!dialog || !content) return;

    let activeRequest;
    let lastOpener;
    const load = async (rawUrl, focusSelector = null) => {
        activeRequest?.abort();
        activeRequest = new AbortController();
        const url = new URL(rawUrl, window.location.href);
        url.searchParams.set('fragment', '1');
        dialog.dataset.currentUrl = url.toString();
        content.setAttribute('aria-busy', 'true');
        content.innerHTML = '<p class="campaign-log-modal-loading" role="status"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading campaign history…</p>';
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, signal: activeRequest.signal });
            if (!response.ok || new URL(response.url).origin !== window.location.origin || /\/(sign-in|login)(\/|$)/i.test(new URL(response.url).pathname)) {
                throw new Error('Campaign history could not be loaded. Refresh the page and try again.');
            }
            content.innerHTML = await response.text();
            content.setAttribute('aria-busy', 'false');
            if (focusSelector) content.querySelector(focusSelector)?.focus({ preventScroll: true });
        } catch (error) {
            if (error.name === 'AbortError') return;
            content.setAttribute('aria-busy', 'false');
            content.innerHTML = '<div class="campaign-log-modal-error" role="alert"><span>Campaign history could not be loaded.</span><button class="campaign-log-config-button" type="button" data-campaign-modal-retry>Try again</button></div>';
        }
    };

    document.addEventListener('click', (event) => {
        const historyLink = event.target.closest('[data-campaign-history-url]');
        if (historyLink) {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            lastOpener = historyLink;
            dialog.dataset.historyUrl = historyLink.dataset.campaignHistoryUrl;
            document.getElementById('campaign-log-dialog-title').textContent = 'Campaign activity';
            if (!dialog.open) dialog.showModal();
            load(dialog.dataset.historyUrl, '.campaign-log-modal-heading h2');
            return;
        }

        const detailButton = event.target.closest('[data-campaign-detail-url]');
        if (detailButton && dialog.contains(detailButton)) {
            load(detailButton.dataset.campaignDetailUrl, '#campaign-log-config-title');
            return;
        }

        const historyTab = event.target.closest('[data-campaign-history-tab]');
        if (historyTab && dialog.contains(historyTab)) {
            dialog.dataset.historyUrl = historyTab.dataset.campaignHistoryTab;
            load(dialog.dataset.historyUrl, '.campaign-log-modal-heading h2');
            return;
        }

        if (event.target.closest('[data-campaign-history-back]') && dialog.open) {
            load(dialog.dataset.historyUrl, '.campaign-log-modal-heading h2');
            return;
        }

        const pageLink = event.target.closest('.campaign-log-modal-footer .pagination a');
        if (pageLink && dialog.contains(pageLink)) {
            event.preventDefault();
            dialog.dataset.historyUrl = pageLink.href;
            load(dialog.dataset.historyUrl, '.campaign-log-modal-heading h2');
            return;
        }

        if (event.target.closest('[data-campaign-modal-retry]') && dialog.dataset.currentUrl) {
            load(dialog.dataset.currentUrl);
            return;
        }

        if (event.target.closest('[data-campaign-dialog-close]')) dialog.close();
    });

    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => {
        activeRequest?.abort();
        content.setAttribute('aria-busy', 'false');
        lastOpener?.focus();
    });
})();
</script>
@endpush
