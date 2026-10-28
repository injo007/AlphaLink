#!/usr/bin/env python3
"""Authenticated, GET-only local browser relay for DMS visual inspection.

The relay reads DMS settings from the repository .env, establishes an
authenticated session through the configured HTTP proxy, and serves only the
explicitly allowlisted reference pages plus static assets. ISP labels and list
rows are replaced with synthetic or empty values. Authentication is the only
upstream POST. Every browser-originated non-GET request is rejected locally.
"""

from __future__ import annotations

import argparse
import html
import ipaddress
import json
import os
import re
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, unquote, urljoin, urlparse, urlunparse

import requests


ROOT = Path(__file__).resolve().parents[2]
ENV_FILE = ROOT / ".env"
LOCAL_HOST = "127.0.0.1"
LOCAL_PORT = int(os.environ.get("DMS_RELAY_PORT", "8799"))
PAGE_PATH = "/admin/lists"
PROFILE_PATH = "/profile"
BULK_INSTALL_PAGE_PATH = "/mailer/clouds/bulk-install"
TEXT_MECHANIC_PAGE_PATH = "/mailer/tools/text-mechanic"
ENCODING_PAGE_PATH = "/mailer/tools/encoding"
ENCODING_POPUP_PAGE_PATH = "/mailer/tools/encoding/popup"
ENCODING_PAGE_PATHS = {ENCODING_PAGE_PATH, ENCODING_POPUP_PAGE_PATH}
SPF_SCANNER_PAGE_PATH = "/mailer/tools/spf-scanner"
SPF_DOMAINS_PAGE_PATH = "/mailer/tools/spf-scanner/domains"
SPF_PAGE_PATHS = {SPF_SCANNER_PAGE_PATH, SPF_DOMAINS_PAGE_PATH}
DOMAINS_CREATE_PAGE_PATH = "/admin/domains/create"
DOMAINS_INDEX_PAGE_PATH = "/admin/domains"
DOMAIN_PROVIDERS_CREATE_PAGE_PATH = "/admin/domains-providers/create"
DOMAIN_PROVIDERS_INDEX_PAGE_PATH = "/admin/domains-providers"
SERVERS_PROVIDERS_INDEX_PAGE_PATH = "/admin/servers-providers"
SERVERS_PROVIDERS_CREATE_PAGE_PATH = "/admin/servers-providers/create"
ISPS_CREATE_PAGE_PATH = "/admin/isps/create"
ISPS_INDEX_PAGE_PATH = "/admin/isps"
SPONSORS_CREATE_PAGE_PATH = "/admin/sponsors/create"
SPONSORS_INDEX_PAGE_PATH = "/admin/sponsors"
MY_SPONSORS_PAGE_PATH = "/mailer/sponsors"
CLOUD_PROVIDERS_INDEX_PAGE_PATH = "/admin/cloud-providers"
INSTALL_SETTINGS_PAGE_PATH = "/admin/miscellaneous/settings"
CAMPAIGNS_INDEX_PAGE_PATH = "/mailer/campaigns"
CAMPAIGNS_CREATE_PAGE_PATH = "/mailer/campaigns/create"
CAMPAIGN_LOGS_PAGE_PATH = "/mailer/campaign-logs"
MAILER_SERVERS_INDEX_PAGE_PATH = "/mailer/servers"
GLOBAL_TESTS_PAGE_PATH = "/mailer/global-tests"
GLOBAL_TEST_HISTORIES_PAGE_PATH = "/mailer/global-tests/histories"
WARMUPS_INDEX_PAGE_PATH = "/mailer/warmups"
WARMUPS_CREATE_PAGE_PATH = "/mailer/warmups/create"
WARMUPS_PROCESSES_PAGE_PATH = "/mailer/warmups/processes"
WARMUPS_SEEDS_PAGE_PATH = "/mailer/warmups/seeds"
WARMUPS_SEEDS_CREATE_PAGE_PATH = "/mailer/warmups/seeds/create"
WARMUPS_SEEDS_CLEAN_PAGE_PATH = "/mailer/warmups/seeds/clean"
WARMUPS_STATISTICS_PAGE_PATH = "/mailer/warmups/statistics"
GMAIL_PROJECTS_PAGE_PATH = "/mailer/gcampaigns/projects"
GMAIL_PROJECTS_CREATE_PAGE_PATH = "/mailer/gcampaigns/projects/create"
GMAIL_ACCOUNTS_PAGE_PATH = "/mailer/gcampaigns/accounts"
GMAIL_ACCOUNTS_CREATE_PAGE_PATH = "/mailer/gcampaigns/accounts/create"
WARMUP_PAGE_PATHS = {
    WARMUPS_INDEX_PAGE_PATH,
    WARMUPS_CREATE_PAGE_PATH,
    WARMUPS_PROCESSES_PAGE_PATH,
    WARMUPS_SEEDS_PAGE_PATH,
    WARMUPS_SEEDS_CREATE_PAGE_PATH,
    WARMUPS_SEEDS_CLEAN_PAGE_PATH,
    WARMUPS_STATISTICS_PAGE_PATH,
}
GMAIL_WORKSPACE_PAGE_PATHS = {
    GMAIL_PROJECTS_PAGE_PATH,
    GMAIL_PROJECTS_CREATE_PAGE_PATH,
    GMAIL_ACCOUNTS_PAGE_PATH,
    GMAIL_ACCOUNTS_CREATE_PAGE_PATH,
}
PMTA_PAGE_PATHS = {
    "/mailer/pmta",
    "/mailer/pmta/scheduler",
    "/mailer/pmta/settings",
    "/mailer/pmta/vmta",
    "/mailer/pmta/smtp",
    "/mailer/pmta/bounces",
    "/mailer/pmta/action-history",
    "/mailer/pmta/config-history",
}
PMTA_EMPTY_DATA_PATHS = {
    "/mailer/pmta/my-vmta",
    "/mailer/pmta/my-smtp",
    "/mailer/pmta/file",
}
OFFERS_INDEX_PAGE_PATH = "/admin/offers"
OFFERS_CREATE_PAGE_PATH = "/admin/offers/create"
READ_ONLY_PAGE_PATHS = {
    PROFILE_PATH,
    "/admin/servers",
    SERVERS_PROVIDERS_INDEX_PAGE_PATH,
    SERVERS_PROVIDERS_CREATE_PAGE_PATH,
    "/admin/servers/create",
    DOMAINS_INDEX_PAGE_PATH,
    DOMAINS_CREATE_PAGE_PATH,
    DOMAIN_PROVIDERS_CREATE_PAGE_PATH,
    DOMAIN_PROVIDERS_INDEX_PAGE_PATH,
    ISPS_CREATE_PAGE_PATH,
    ISPS_INDEX_PAGE_PATH,
    SPONSORS_CREATE_PAGE_PATH,
    SPONSORS_INDEX_PAGE_PATH,
    MY_SPONSORS_PAGE_PATH,
    CLOUD_PROVIDERS_INDEX_PAGE_PATH,
    INSTALL_SETTINGS_PAGE_PATH,
    CAMPAIGNS_INDEX_PAGE_PATH,
    CAMPAIGNS_CREATE_PAGE_PATH,
    CAMPAIGN_LOGS_PAGE_PATH,
    MAILER_SERVERS_INDEX_PAGE_PATH,
    GLOBAL_TESTS_PAGE_PATH,
    GLOBAL_TEST_HISTORIES_PAGE_PATH,
    TEXT_MECHANIC_PAGE_PATH,
    *ENCODING_PAGE_PATHS,
    *SPF_PAGE_PATHS,
    *WARMUP_PAGE_PATHS,
    *GMAIL_WORKSPACE_PAGE_PATHS,
    OFFERS_INDEX_PAGE_PATH,
    OFFERS_CREATE_PAGE_PATH,
    PAGE_PATH,
    BULK_INSTALL_PAGE_PATH,
    *PMTA_PAGE_PATHS,
}
STATIC_PREFIXES = (
    "/assets/", "/plugins/", "/fonts/", "/images/", "/vendor/", "/build/",
    "/v/", "/plug-ins/", "/g/",
)
EXTERNAL_ASSET_HOSTS = {
    "fonts.googleapis.com", "fonts.gstatic.com", "cdn.datatables.net", "cdn.jsdelivr.net",
}
USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36"
)
IPV6_CANDIDATE = re.compile(
    r"(?<![0-9A-Za-z:])(?:[0-9a-f]{0,4}:){2,7}(?:[0-9a-f]{0,4}|(?:[0-9]{1,3}\.){3}[0-9]{1,3})(?:%[0-9A-Za-z_.-]+)?(?![0-9A-Za-z:])",
    re.IGNORECASE,
)


def redact_ipv6_literals(value: str) -> str:
    """Redact valid IPv6 literals, including fully compressed forms such as ::1."""
    def redact(match: re.Match[str]) -> str:
        try:
            return "[redacted-ipv6]" if ipaddress.ip_address(match.group(0)).version == 6 else match.group(0)
        except ValueError:
            return match.group(0)

    return IPV6_CANDIDATE.sub(redact, value)


def read_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw_line in path.read_text().splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")
    return values


CONFIG = read_env(ENV_FILE)
if CONFIG.get("REFERENCE_READ_ONLY", "").lower() not in {"1", "true", "yes", "on"}:
    raise SystemExit("REFUSED: REFERENCE_READ_ONLY is not enabled in .env")

REFERENCE_URL = CONFIG["REFERENCE_APP_URL"].rstrip("/") + "/"
REFERENCE = urlparse(REFERENCE_URL)
REFERENCE_ORIGIN = f"{REFERENCE.scheme}://{REFERENCE.netloc}"
if REFERENCE.scheme != "https" or not REFERENCE.hostname:
    raise SystemExit("REFUSED: expected an HTTPS DMS reference URL")

PROXY_URL = (
    f"{CONFIG['PROXY_PROTOCOL']}://{CONFIG['PROXY_USERNAME']}:{CONFIG['PROXY_PASSWORD']}"
    f"@{CONFIG['PROXY_HOST']}:{CONFIG['PROXY_PORT']}"
)
SESSION = requests.Session()
SESSION.proxies = {"http": PROXY_URL, "https": PROXY_URL}
SESSION.verify = True
SESSION.headers.update({"User-Agent": USER_AGENT})


def establish_read_only_session() -> None:
    login = SESSION.get(REFERENCE_URL, timeout=45, allow_redirects=True)
    token_match = re.search(
        r"<input\b(?=[^>]*\bname=['\"]_token['\"])(?=[^>]*\bvalue=['\"]([^'\"]+))[^>]*>",
        login.text,
        re.IGNORECASE,
    )
    if login.status_code != 200 or not token_match or "login" not in login.text.lower():
        raise SystemExit("REFUSED: DMS login page or CSRF token did not match expected contract")

    form_match = re.search(r"<form\b([^>]*)>", login.text, re.IGNORECASE)
    attrs = form_match.group(1) if form_match else ""
    action_match = re.search(r"\baction=['\"]([^'\"]*)", attrs, re.IGNORECASE)
    login_action = urljoin(login.url, html.unescape(action_match.group(1))) if action_match else login.url
    login_post = SESSION.post(
        login_action,
        data={
            "_token": html.unescape(token_match.group(1)),
            "username": CONFIG["REFERENCE_ADMIN_USERNAME"],
            "password": CONFIG["REFERENCE_ADMIN_PASSWORD"],
        },
        timeout=45,
        allow_redirects=True,
        headers={"Referer": login.url},
    )
    title = re.search(r"<title[^>]*>(.*?)</title", login_post.text, re.IGNORECASE | re.DOTALL)
    if login_post.status_code != 200 or not title or title.group(1).strip() != "Main Dashboard - DMS":
        raise SystemExit("REFUSED: authenticated read-only dashboard check failed")

    target = SESSION.get(urljoin(REFERENCE_URL, PAGE_PATH.lstrip("/")), timeout=45, allow_redirects=True)
    target_title = re.search(r"<title[^>]*>(.*?)</title", target.text, re.IGNORECASE | re.DOTALL)
    if (
        target.status_code != 200
        or not target_title
        or "DMS" not in target_title.group(1)
        or "list" not in target_title.group(1).lower()
    ):
        raise SystemExit("REFUSED: expected DMS lists page was not returned")

    print("DMS read-only session: login form accepted; dashboard GET 200; lists GET 200.", flush=True)
    print(f"Relay ready: http://{LOCAL_HOST}:{LOCAL_PORT}{PAGE_PATH} (browser requests are GET-only).", flush=True)


def local_path_for(url: str, page_url: str) -> str:
    value = url.strip()
    if not value or value.startswith(("#", "data:", "javascript:", "mailto:", "tel:")):
        return value
    try:
        parsed = urlparse(urljoin(page_url, html.unescape(value)))
    except ValueError:
        return "#external-reference-blocked"
    if parsed.scheme not in {"http", "https"}:
        return value
    path = parsed.path or "/"
    query = f"?{parsed.query}" if parsed.query else ""
    if parsed.hostname == REFERENCE.hostname:
        return f"{path}{query}"
    if parsed.hostname in EXTERNAL_ASSET_HOSTS and parsed.scheme == "https":
        host_path = f"/{parsed.hostname}{path}"
        return f"/__remote{host_path}{query}"
    return "#external-reference-blocked"


def is_protected_table_data_query(
    path: str,
    query: str,
    accept: str = "",
    requested_with: str = "",
) -> bool:
    """Keep approved reference list UIs while preventing production rows crossing the relay."""
    if re.fullmatch(r"/admin/lists/\d+/load", path):
        return True
    if path == BULK_INSTALL_PAGE_PATH and query == "synthetic_empty=1":
        return False
    protected_paths = {
        "/admin/servers",
        SERVERS_PROVIDERS_INDEX_PAGE_PATH,
        DOMAINS_INDEX_PAGE_PATH,
        DOMAIN_PROVIDERS_INDEX_PAGE_PATH,
        "/admin/lists",
        ISPS_INDEX_PAGE_PATH,
        SPONSORS_INDEX_PAGE_PATH,
        MY_SPONSORS_PAGE_PATH,
        CLOUD_PROVIDERS_INDEX_PAGE_PATH,
        BULK_INSTALL_PAGE_PATH,
        CAMPAIGNS_INDEX_PAGE_PATH,
        CAMPAIGN_LOGS_PAGE_PATH,
        MAILER_SERVERS_INDEX_PAGE_PATH,
        OFFERS_INDEX_PAGE_PATH,
        GMAIL_PROJECTS_PAGE_PATH,
        GMAIL_ACCOUNTS_PAGE_PATH,
    }
    if path not in protected_paths:
        return False

    return bool(query) or (
        path in {
            DOMAINS_INDEX_PAGE_PATH,
            DOMAIN_PROVIDERS_INDEX_PAGE_PATH,
            SERVERS_PROVIDERS_INDEX_PAGE_PATH,
            CLOUD_PROVIDERS_INDEX_PAGE_PATH,
            CAMPAIGNS_INDEX_PAGE_PATH,
            CAMPAIGN_LOGS_PAGE_PATH,
            MAILER_SERVERS_INDEX_PAGE_PATH,
            OFFERS_INDEX_PAGE_PATH,
            SPONSORS_INDEX_PAGE_PATH,
            MY_SPONSORS_PAGE_PATH,
            GMAIL_PROJECTS_PAGE_PATH,
            GMAIL_ACCOUNTS_PAGE_PATH,
        }
        and (
            requested_with.lower() == "xmlhttprequest"
            or "application/json" in accept.lower()
        )
    )


def is_isp_catalog_data_request(path: str, query: str, accept: str = "", requested_with: str = "") -> bool:
    """Block DMS's ISP JSON response before the global catalog reaches the browser."""
    return path == ISPS_INDEX_PAGE_PATH and (
        bool(query)
        or requested_with.lower() == "xmlhttprequest"
        or "application/json" in accept.lower()
    )


def is_protected_campaign_catalog_request(path: str, query: str) -> bool:
    """Replace Send-form catalog lookups before DMS rows can reach the browser."""
    return path == CAMPAIGNS_CREATE_PAGE_PATH and bool(query)


def synthetic_empty_campaign_catalog_response(query: str) -> bytes:
    """Return the expected empty catalog shape without requesting DMS records."""
    keys = parse_qs(query)
    if "assets" in keys:
        data = {"from": [], "subjects": [], "creatives": [], "unsubscribe": None, "html": []}
    elif "offers" in keys:
        data = {"data": []}
    else:
        data = []

    return json.dumps(data).encode("utf-8")


def synthetic_empty_datatable_response(query: str) -> bytes:
    """Return the DataTables server-side envelope without requesting reference rows."""
    draw_value = parse_qs(query).get("draw", ["0"])[0]
    try:
        draw = max(0, int(draw_value))
    except (TypeError, ValueError):
        draw = 0

    return json.dumps({
        "draw": draw,
        "recordsTotal": 0,
        "recordsFiltered": 0,
        "data": [],
    }).encode("utf-8")


def is_protected_pmta_data_request(path: str, query: str) -> bool:
    """Keep DMS PMTA page chrome while preventing server/config/history data reads."""
    return path == "/mailer/ips/servers" or path in PMTA_EMPTY_DATA_PATHS or (
        path in PMTA_PAGE_PATHS and bool(query) and path != "/mailer/pmta"
    )


def is_protected_global_test_data_request(path: str, query: str) -> bool:
    """Prevent production addresses, server options and test histories reaching the browser."""
    return (
        path == GLOBAL_TESTS_PAGE_PATH and bool(query)
        or path == GLOBAL_TEST_HISTORIES_PAGE_PATH and bool(query)
        or re.fullmatch(r"/mailer/global-tests/history/[^/]+", path) is not None
    )


def is_protected_spf_data_request(path: str, query: str) -> bool:
    """Allow only sanitized SPF pages; never fetch live search, scan, or record rows."""
    return (
        path in SPF_PAGE_PATHS and bool(query)
        or path.startswith(SPF_SCANNER_PAGE_PATH + "/") and path not in SPF_PAGE_PATHS
    )


def synthetic_empty_spf_response(query: str) -> bytes:
    """Return an empty table envelope without querying DMS SPF records."""
    if "draw" in parse_qs(query):
        return synthetic_empty_datatable_response(query)
    return b'{"data": []}'


def synthetic_empty_global_test_response(path: str, query: str) -> bytes:
    """Return an empty DMS-shaped history table without reading test recipients or logs."""
    if path == GLOBAL_TEST_HISTORIES_PAGE_PATH:
        return synthetic_empty_datatable_response(query)
    return b'{"data": []}'


def is_protected_warmup_data_request(
    path: str,
    query: str,
    accept: str = "",
    requested_with: str = "",
) -> bool:
    """Block warmup tables, running-process details, and statistics from the DMS."""
    data_paths = {
        WARMUPS_INDEX_PAGE_PATH,
        WARMUPS_PROCESSES_PAGE_PATH,
        WARMUPS_SEEDS_PAGE_PATH,
        WARMUPS_STATISTICS_PAGE_PATH,
    }
    return path in data_paths and (
        bool(query)
        or requested_with.lower() == "xmlhttprequest"
        or "application/json" in accept.lower()
    )


def synthetic_empty_warmup_response(path: str, query: str) -> bytes:
    """Return empty DMS-shaped warmup data without reading production rows."""
    if path == WARMUPS_SEEDS_PAGE_PATH:
        return synthetic_empty_datatable_response(query)
    if path == WARMUPS_STATISTICS_PAGE_PATH:
        return b"[]"
    return b'{"data": []}'


def sanitize_dms_warmup_html(body: str, path: str) -> str:
    """Keep safe Warmup page structure while stripping production data and executable actions."""
    expected_markers = {
        WARMUPS_INDEX_PAGE_PATH: ("My Warmups", 'id="warmups"'),
        WARMUPS_CREATE_PAGE_PATH: ("CREATE WARMUP", 'id="servers"'),
        WARMUPS_PROCESSES_PAGE_PATH: ("WARMUPS RUNNING PROCESSES", 'id="processes"'),
        WARMUPS_SEEDS_PAGE_PATH: ("Seeds", 'id="seeds"'),
        WARMUPS_SEEDS_CREATE_PAGE_PATH: ("ADD SEED LIST", 'id="name"'),
        WARMUPS_SEEDS_CLEAN_PAGE_PATH: ("CLEAN SEEDS", 'id="servers"'),
        WARMUPS_STATISTICS_PAGE_PATH: ("Warmups Stats", 'id="filters"'),
    }
    if path not in expected_markers:
        raise ValueError("DMS Warmup response used an unexpected path")
    if not all(marker.casefold() in body.casefold() for marker in expected_markers[path]):
        raise ValueError("DMS Warmup response did not match the expected page")

    body = re.sub(r"<script\b[^>]*>.*?</script\s*>", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<script\b[^>]*/\s*>", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"<textarea\b(?P<attrs>[^>]*)>.*?</textarea\s*>",
        r"<textarea\g<attrs>></textarea>",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )

    static_options = {
        "option": (("ip", "IP"), ("vmta", "VMTA"), ("smtp", "SMTP")),
        "type": (("all", "ALL"), ("ipv4", "IPV4"), ("ipv6", "IPV6")),
        "send_type": (
            ("delay", "DELAY"),
            ("delayp", "DELAYP (parallel)"),
            ("delayps", "DELAYPS (parallel)"),
            ("hs", "HS"),
            ("hsp", "HSP (parallel)"),
            ("hsps", "HSPS (parallel by server)"),
            ("pickup", "PICKUP (parallel)"),
            ("pickups", "PICKUPS (parallel by server)"),
        ),
        "filters": (
            ("0", "SELECT FILTER"),
            ("1", "MAILER --- DATE --- DELIVERED, SOFT BOUNCE, HARD BOUNCE"),
            ("2", "PROVIDER --- DATE --- DELIVERED, SOFT BOUNCE, HARD BOUNCE"),
            ("3", "MAILER, PROVIDER, SERVER --- DATE --- DELIVERED, SOFT BOUNCE, HARD BOUNCE"),
        ),
    }

    def sanitize_select(match: re.Match[str]) -> str:
        opening, closing = match.group("open"), match.group("close")
        identifier = re.search(r"\bid=['\"]([^'\"]+)", opening, re.IGNORECASE)
        field = identifier.group(1).casefold() if identifier else ""
        if field not in static_options:
            return opening + closing

        original_options = {}
        for option_match in re.finditer(
            r"<option\b(?P<attrs>[^>]*)>.*?</option\s*>",
            match.group("options"),
            flags=re.IGNORECASE | re.DOTALL,
        ):
            value_match = re.search(r"\bvalue=['\"]([^'\"]*)", option_match.group("attrs"), re.IGNORECASE)
            if value_match:
                original_options[value_match.group(1)] = option_match.group(0)
        options = []
        for value, label in static_options[field]:
            source_option = original_options.get(value, "")
            selected = " selected" if re.search(r"\bselected\b", source_option, re.IGNORECASE) else ""
            if source_option:
                options.append(f'<option value="{html.escape(value, quote=True)}"{selected}>{html.escape(label)}</option>')
        return opening + "".join(options) + closing

    body = re.sub(
        r"(?P<open><select\b(?P<attrs>[^>]*)>)(?P<options>.*?)(?P<close></select\s*>)",
        sanitize_select,
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(
        r"<tr\b[^>]*>.*?</tr\s*>",
        lambda match: re.sub(r"<td\b[^>]*>.*?</td\s*>", "", match.group(0), flags=re.IGNORECASE | re.DOTALL)
        if re.search(r"<th\b", match.group(0), re.IGNORECASE) else "",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(
        r"<input\b[^>]*>",
        lambda match: re.sub(
            r"\s+value\s*=\s*(?:\"[^\"]*\"|'[^']*'|[^\s>]+)",
            "",
            match.group(0),
            flags=re.IGNORECASE,
        ),
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(
        r"\s+(?:data-[\w:-]+|title|action|placeholder|on[a-z]+)\s*=\s*(['\"]).*?\1",
        "",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", "[redacted-email]", body, flags=re.IGNORECASE)
    body = re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "[redacted-ip]", body)
    return body


def synthetic_empty_pmta_response(path: str, query: str) -> bytes:
    """Return empty DMS-shaped PMTA data without querying production rows or hosts."""
    if path == "/mailer/ips/servers":
        return b"[]"
    if path in PMTA_PAGE_PATHS and query:
        return synthetic_empty_datatable_response(query)
    return b'{"data": []}'


def sanitize_dms_pmta_html(body: str) -> str:
    """Remove server and IP catalog rows from read-only PMTA page captures."""
    selector = re.compile(
        r"<select\b(?P<attrs>(?=[^>]*\bid=['\"](?:server|servers|ips)['\"])[^>]*)>"
        r"(?P<options>.*?)</select>",
        re.IGNORECASE | re.DOTALL,
    )

    def sanitize_select(match: re.Match[str]) -> str:
        options = match.group("options")
        safe_all = re.search(
            r"<option\b(?=[^>]*\bvalue=['\"]0['\"])(?=[^>]*\bselected\b)[^>]*>\s*ALL\s*</option>",
            options,
            re.IGNORECASE | re.DOTALL,
        )
        return f"<select{match.group('attrs')}>{safe_all.group(0) if safe_all else ''}</select>"

    return selector.sub(sanitize_select, body)


def sanitize_dms_global_tests_html(body: str) -> str:
    """Keep the DMS Global Tests layout while removing private inputs and executable page data."""
    if not re.search(r"global\s*tests?", body, re.IGNORECASE):
        raise ValueError("DMS Global Tests response did not match the expected page")

    # This read-only capture is for layout and control comparison. None of the
    # selected catalog rows, entered test addresses, message source, or inline
    # catalog payloads are needed to establish those facts.
    body = re.sub(r"<script\b[^>]*>.*?</script\s*>", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<script\b[^>]*/\s*>", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<textarea\b(?P<attrs>[^>]*)>.*?</textarea\s*>", r"<textarea\g<attrs>></textarea>", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<select\b(?P<attrs>[^>]*)>.*?</select\s*>", r"<select\g<attrs>></select>", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"<tr\b[^>]*>.*?</tr\s*>",
        lambda match: match.group(0) if re.search(r"<th\b", match.group(0), re.IGNORECASE) else "",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(r"<option\b[^>]*>.*?</option\s*>", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+value\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+(?:data-[\w:-]+|title|action|placeholder|on[a-z]+)\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"(href\s*=\s*(['\"]).*?)\?.*?\2", r"\1\2", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", "[redacted-email]", body, flags=re.IGNORECASE)
    body = re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "[redacted-ip]", body)
    return body


def sanitize_dms_gmail_workspace_html(body: str, path: str) -> str:
    """Keep DMS GSend page chrome while withholding projects, accounts and credentials."""
    expected_title = {
        GMAIL_PROJECTS_PAGE_PATH: r"gmail\s+projects",
        GMAIL_PROJECTS_CREATE_PAGE_PATH: r"project",
        GMAIL_ACCOUNTS_PAGE_PATH: r"gmail\s+accounts",
        GMAIL_ACCOUNTS_CREATE_PAGE_PATH: r"account",
    }.get(path)
    if expected_title is None or not re.search(expected_title, html.unescape(body), re.IGNORECASE):
        raise ValueError("DMS GSend response did not match the expected page")
    if path in {GMAIL_PROJECTS_CREATE_PAGE_PATH, GMAIL_ACCOUNTS_CREATE_PAGE_PATH} and not re.search(
        r"<(?:form|input|select|textarea)\b", body, re.IGNORECASE
    ):
        raise ValueError("DMS GSend create page did not contain its expected form controls")

    script_paths: list[str] = []
    inline_scripts: list[str] = []

    def remove_script(match: re.Match[str]) -> str:
        opening_tag = match.group(0).split(">", 1)[0]
        src = re.search(r"\bsrc=['\"]([^'\"]+)['\"]", opening_tag, re.IGNORECASE)
        if src:
            parsed = urlparse(urljoin(f"{REFERENCE_ORIGIN}{path}", html.unescape(src.group(1))))
            if parsed.hostname == REFERENCE.hostname and parsed.path.startswith(STATIC_PREFIXES):
                script_paths.append(parsed.path)
        elif path not in {GMAIL_PROJECTS_CREATE_PAGE_PATH, GMAIL_ACCOUNTS_CREATE_PAGE_PATH}:
            script_type = re.search(r"\btype=['\"]([^'\"]+)['\"]", opening_tag, re.IGNORECASE)
            if script_type is None or re.search(r"(?:java|ecma)script", script_type.group(1), re.IGNORECASE):
                closing_start = match.group(0).lower().rfind("</script")
                content = match.group(0)[match.group(0).find(">") + 1:closing_start] if closing_start >= 0 else ""
                content = re.sub(r"/\*.*?\*/|<!--.*?-->", "", content, flags=re.DOTALL)
                content = re.sub(r"(?m)(?<!:)//[^\r\n]*$", "", content)
                content = re.sub(
                    r"(?i)(['\"]?(?:csrf|token|auth|password|secret|credential|api[_-]?key|private[_-]?key|client[_-]?secret|service[_-]?account[_-]?json|refresh[_-]?token|access[_-]?token)['\"]?\s*[:=]\s*)(['\"]).*?\2",
                    r"\1'[redacted]'",
                    content,
                )
                content = re.sub(
                    r"(?i)(['\"]?(?:id|project[_-]?id|account[_-]?id|user[_-]?id|email|username|admin[_-]?email|project[_-]?name|account[_-]?name|display[_-]?name)['\"]?\s*[:=]\s*)(['\"]).*?\2",
                    r"\1'[redacted]'",
                    content,
                )
                content = re.sub(
                    r"(?i)(['\"]?(?:id|project[_-]?id|account[_-]?id|user[_-]?id)['\"]?\s*[:=]\s*)\d+\b",
                    r"\1[redacted]",
                    content,
                )
                content = re.sub(
                    r"(?i)([?&](?:access[_-]?token|token|secret|signature|api[_-]?key|auth)=)[^&'\"\s]+",
                    r"\1[redacted]",
                    content,
                )
                content = re.sub(
                    r"(/mailer/gcampaigns/(?:projects|accounts)/)\d+(?=/|[?\"'])",
                    r"\1[redacted]",
                    content,
                    flags=re.IGNORECASE,
                )
                content = redact_ipv6_literals(content)
                content = re.sub(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", "[redacted-email]", content, flags=re.IGNORECASE)
                content = re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "[redacted-ip]", content)
                if content.strip():
                    inline_scripts.append(content)
        return ""

    body = re.sub(r"<script\b[^>]*>.*?</script\s*>", remove_script, body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<script\b[^>]*/\s*>", remove_script, body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<meta\b(?=[^>]*\bname=['\"]csrf-[^'\"]+['\"])[^>]*>", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<textarea\b(?P<attrs>[^>]*)>.*?</textarea\s*>", r"<textarea\g<attrs>></textarea>", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"<select\b(?P<attrs>[^>]*)>(?P<options>.*?)</select\s*>",
        lambda match: sanitize_dms_gsend_select(match, path),
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(r"<tbody\b(?P<attrs>[^>]*)>.*?</tbody\s*>", r"<tbody\g<attrs>></tbody>", body, flags=re.IGNORECASE | re.DOTALL)
    body = remove_divs_containing_named_input(body, "checked_users", "custom-control")
    body = re.sub(
        r"<input\b[^>]*>",
        lambda match: "" if re.search(r"\btype=['\"]hidden['\"]", match.group(0), re.IGNORECASE)
        else re.sub(r"\s+value\s*=\s*(['\"]).*?\1", "", match.group(0), flags=re.IGNORECASE | re.DOTALL),
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(r"\s+action\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+on[a-z]+\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+data-[\w:-]+\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"(href\s*=\s*(['\"]).*?)\?.*?\2", r"\1\2", body, flags=re.IGNORECASE | re.DOTALL)
    body = remove_element_by_id(body, "page-header-user-dropdown")
    body = re.sub(
        r"\bShowing(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*to(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*of(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*(?:records|entries)\b",
        "Showing 0 to 0 of 0 records",
        body,
        flags=re.IGNORECASE,
    )
    body = redact_ipv6_literals(body)
    body = re.sub(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", "[redacted-email]", body, flags=re.IGNORECASE)
    body = re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "[redacted-ip]", body)

    inert_script_refs = "".join(
        f'<template data-reference-script-path="{html.escape(script_path, quote=True)}"></template>'
        for script_path in dict.fromkeys(script_paths)
    )
    inert_script_refs += "".join(
        f'<template data-reference-inline-script="{index}">{html.escape(script)}</template>'
        for index, script in enumerate(inline_scripts, start=1)
    )
    return body.replace("</body>", inert_script_refs + "</body>") if "</body>" in body else body + inert_script_refs


def sanitize_dms_gsend_select(match: re.Match[str], path: str) -> str:
    """Retain only known static GSend project-type labels; discard all live choices."""
    opening = f"<select{match.group('attrs')}>"
    closing = "</select>"
    attrs = match.group("attrs")
    if path != GMAIL_PROJECTS_CREATE_PAGE_PATH or not re.search(
        r"\b(?:name|id)\s*=\s*['\"]api_type['\"]", attrs, re.IGNORECASE
    ):
        return opening + closing

    safe_labels = {"users": "Users", "users oauth": "Users OAuth", "workspace": "Workspace"}
    options: list[str] = []
    for option in re.finditer(
        r"<option\b[^>]*>(.*?)</option\s*>", match.group("options"), re.IGNORECASE | re.DOTALL
    ):
        label = " ".join(html.unescape(re.sub(r"<[^>]+>", " ", option.group(1))).split())
        safe_label = safe_labels.get(label.casefold())
        if safe_label:
            options.append(f"<option>{html.escape(safe_label)}</option>")
    return opening + "".join(options) + closing


def remove_divs_containing_named_input(body: str, input_name: str, container_class: str) -> str:
    """Remove a repeated live-record control and its label from a sanitized form."""
    tokens = re.compile(r"</?div\b[^>]*>|<input\b[^>]*>", re.IGNORECASE | re.DOTALL)
    stack: list[dict[str, object]] = []
    ranges: list[tuple[int, int]] = []

    for match in tokens.finditer(body):
        token = match.group(0)
        if re.match(r"</div\b", token, re.IGNORECASE):
            if not stack:
                raise ValueError("DMS form has an unmatched div close while removing private controls")
            element = stack.pop()
            if element["remove"]:
                ranges.append((int(element["start"]), match.end()))
            continue

        if re.match(r"<div\b", token, re.IGNORECASE):
            classes = re.search(r"\bclass\s*=\s*(['\"])(.*?)\1", token, re.IGNORECASE | re.DOTALL)
            class_tokens = set(classes.group(2).split()) if classes else set()
            stack.append({"start": match.start(), "class_tokens": class_tokens, "remove": False})
            continue

        name = re.search(r"\bname\s*=\s*(['\"])(.*?)\1", token, re.IGNORECASE | re.DOTALL)
        if name is None or name.group(2) not in {input_name, f"{input_name}[]"}:
            continue
        container = next((item for item in reversed(stack) if container_class in item["class_tokens"]), None)
        if container is None:
            raise ValueError(f"DMS form private {input_name} control did not have its expected container")
        container["remove"] = True

    if stack:
        raise ValueError("DMS form did not close its div structure while removing private controls")

    for start, end in reversed(ranges):
        body = body[:start] + body[end:]
    return body


def remove_element_by_id(body: str, element_id: str) -> str:
    opening = re.search(
        rf"<(?P<tag>[a-z][\w:-]*)\b(?=[^>]*\bid=['\"]{re.escape(element_id)}['\"])[^>]*>",
        body,
        re.IGNORECASE,
    )
    if opening is None:
        return body

    tag = opening.group("tag")
    tag_tokens = re.compile(rf"</?{re.escape(tag)}\b[^>]*>", re.IGNORECASE)
    depth = 1
    for token in tag_tokens.finditer(body, opening.end()):
        if token.group(0).startswith("</"):
            depth -= 1
        elif not token.group(0).rstrip().endswith("/>"):
            depth += 1
        if depth == 0:
            return body[:opening.start()] + body[token.end():]

    raise ValueError(f"DMS page did not close the {element_id} element")


def sanitize_dms_text_mechanic_html(body: str, upstream_url: str) -> str:
    """Keep Text Mechanic rules inert while removing executable and private UI data."""
    if not re.search(r"text\s*mechanic", html.unescape(body), re.IGNORECASE):
        raise ValueError("DMS Text Mechanic response did not match the expected page")

    script_paths: list[str] = []
    inline_scripts: list[str] = []

    def remove_script(match: re.Match[str]) -> str:
        tag = match.group(0).split(">", 1)[0]
        src = re.search(r"\bsrc=['\"]([^'\"]+)['\"]", tag, re.IGNORECASE)
        if src:
            parsed = urlparse(urljoin(upstream_url, html.unescape(src.group(1))))
            if parsed.hostname == REFERENCE.hostname and parsed.path.startswith(STATIC_PREFIXES):
                script_paths.append(parsed.path)
        else:
            script_type = re.search(r"\btype=['\"]([^'\"]+)['\"]", tag, re.IGNORECASE)
            if script_type is None or re.search(r"(?:java|ecma)script", script_type.group(1), re.IGNORECASE):
                closing_start = match.group(0).lower().rfind("</script")
                content = match.group(0)[match.group(0).find(">") + 1:closing_start] if closing_start >= 0 else ""
                content = re.sub(r"/\*.*?\*/|<!--.*?-->", "", content, flags=re.DOTALL)
                content = re.sub(r"(?m)(?<!:)//[^\r\n]*$", "", content)
                content = re.sub(
                    r"(?i)(\b[\w-]*(?:csrf|token|auth|password|secret|credential|api[_-]?key)[\w-]*\b\s*[:=]\s*)(['\"]).*?\2",
                    r"\1'[redacted]'",
                    content,
                )
                content = re.sub(
                    r"(?i)([?&](?:access[_-]?token|token|secret|signature|api[_-]?key|auth)=)[^&'\"\s]+",
                    r"\1[redacted]",
                    content,
                )
                content = redact_ipv6_literals(content)
                content = re.sub(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", "[redacted-email]", content, flags=re.IGNORECASE)
                content = re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "[redacted-ip]", content)
                if content.strip():
                    inline_scripts.append(content)
        return ""

    body = re.sub(r"<script\b[^>]*>.*?</script\s*>", remove_script, body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<script\b[^>]*/\s*>", remove_script, body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"<meta\b(?=[^>]*\bname=['\"]csrf-(?:token|param)['\"])[^>]*>",
        "",
        body,
        flags=re.IGNORECASE,
    )
    body = re.sub(
        r"<textarea\b(?P<attrs>[^>]*)>.*?</textarea\s*>",
        r"<textarea\g<attrs>></textarea>",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(r"<select\b(?P<attrs>[^>]*)>.*?</select\s*>", r"<select\g<attrs>></select>", body, flags=re.IGNORECASE | re.DOTALL)
    body = remove_element_by_id(body, "page-header-user-dropdown")
    body = re.sub(r"<input\b[^>]*\btype=['\"]hidden['\"][^>]*>", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"<input\b[^>]*>",
        lambda match: re.sub(
            r"\s+value\s*=\s*(?:\"[^\"]*\"|'[^']*'|[^\s>]+)",
            "",
            match.group(0),
            flags=re.IGNORECASE,
        ),
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(
        r"<tr\b[^>]*>.*?</tr\s*>",
        lambda match: match.group(0) if re.search(r"<th\b", match.group(0), re.IGNORECASE) else "",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )

    def retain_safe_text_copy_target(match: re.Match[str]) -> str:
        if match.group("name").lower() == "data-clipboard-target" and match.group("value") == "#text":
            return match.group(0)
        return ""

    body = re.sub(
        r"\s+(?P<name>data-[\w:-]+|title|action|on[a-z]+)\s*=\s*(?P<quote>['\"])(?P<value>.*?)\2",
        retain_safe_text_copy_target,
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = redact_ipv6_literals(body)
    body = re.sub(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", "[redacted-email]", body, flags=re.IGNORECASE)
    body = re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "[redacted-ip]", body)

    inert_script_refs = "".join(
        f'<template data-reference-script-path="{html.escape(path, quote=True)}"></template>'
        for path in script_paths
    )
    inert_inline_scripts = "".join(
        f'<template data-reference-inline-script="{index}">{html.escape(script)}</template>'
        for index, script in enumerate(inline_scripts, start=1)
    )
    inert_script_refs += inert_inline_scripts
    return body.replace("</body>", inert_script_refs + "</body>") if "</body>" in body else body + inert_script_refs


def sanitize_dms_spf_html(body: str, path: str) -> str:
    """Keep SPF page layout while removing saved records, addresses, and executable actions."""
    expected_title = {
        SPF_SCANNER_PAGE_PATH: r"spf\s*scanner",
        SPF_DOMAINS_PAGE_PATH: r"spf\s*domains",
    }.get(path)
    if expected_title is None or not re.search(expected_title, html.unescape(body), re.IGNORECASE):
        raise ValueError("DMS SPF response did not match the expected page")

    script_paths: list[str] = []

    def remove_spf_script(match: re.Match[str]) -> str:
        opening_tag = match.group(0).split(">", 1)[0]
        src = re.search(r"\bsrc=['\"]([^'\"]+)['\"]", opening_tag, re.IGNORECASE)
        if src:
            parsed = urlparse(urljoin(f"{REFERENCE_ORIGIN}{path}", html.unescape(src.group(1))))
            if parsed.hostname == REFERENCE.hostname and parsed.path.startswith(STATIC_PREFIXES):
                script_paths.append(parsed.path)
        return ""

    body = re.sub(r"<script\b[^>]*>.*?</script\s*>", remove_spf_script, body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<script\b[^>]*/\s*>", remove_spf_script, body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"<meta\b(?=[^>]*\bname=['\"]csrf-[^'\"]+['\"])[^>]*>",
        "",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(
        r"<textarea\b(?P<attrs>[^>]*)>.*?</textarea\s*>",
        r"<textarea\g<attrs>></textarea>",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(r"<option\b[^>]*>.*?</option\s*>", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"<input\b[^>]*>",
        lambda match: "" if re.search(r"\btype=['\"]hidden['\"]", match.group(0), re.IGNORECASE)
        else re.sub(r"\s+value\s*=\s*(['\"]).*?\1", "", match.group(0), flags=re.IGNORECASE | re.DOTALL),
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(
        r"<tr\b[^>]*>.*?</tr\s*>",
        lambda match: match.group(0) if re.search(r"<th\b", match.group(0), re.IGNORECASE) else "",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(
        r"<(?:ul|nav|div)\b(?=[^>]*\bclass=['\"][^'\"]*\bpagination\b)[^>]*>.*?</(?:ul|nav|div)\s*>",
        "",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(
        r"\bPage(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*of(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*\((?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*records?\)",
        "Page 1 of 1 (0 records)",
        body,
        flags=re.IGNORECASE,
    )
    body = re.sub(
        r"\bShowing(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*to(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*of(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*(?:records|entries)\b",
        "Showing 0 to 0 of 0 records",
        body,
        flags=re.IGNORECASE,
    )
    body = re.sub(r"<pre\b(?P<attrs>[^>]*)>.*?</pre\s*>", r"<pre\g<attrs>></pre>", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+action\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+on[a-z]+\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+data-[\w:-]+\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = remove_element_by_id(body, "page-header-user-dropdown")
    body = re.sub(r"(\b(?:roots|hosts|cidrs)\s+)[\d,]+", r"\1[redacted-count]", body, flags=re.IGNORECASE)
    body = redact_ipv6_literals(body)
    body = re.sub(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", "[redacted-email]", body, flags=re.IGNORECASE)
    body = re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "[redacted-ip]", body)

    inert_script_refs = "".join(
        f'<template data-reference-script-path="{html.escape(script_path, quote=True)}"></template>'
        for script_path in dict.fromkeys(script_paths)
    )
    return body.replace("</body>", inert_script_refs + "</body>") if "</body>" in body else body + inert_script_refs


def sanitize_dms_offers_index_html(body: str) -> str:
    """Keep the Offers register while removing private sponsor and vertical options."""
    replacements = {
        "sponsor_id": '<option value="0" selected>Select Sponsor</option>',
        "vertical": '<option value="0" selected>Select Vertical</option>',
    }
    for field, option in replacements.items():
        pattern = re.compile(
            rf"(?P<open><select\b(?=[^>]*\bid=['\"]{re.escape(field)}['\"])[^>]*>).*?(?P<close></select\s*>)",
            re.IGNORECASE | re.DOTALL,
        )
        body, replaced = pattern.subn(
            lambda match: f"{match.group('open')}{option}{match.group('close')}",
            body,
            count=1,
        )
        if replaced != 1:
            raise ValueError(f"DMS Offers page did not contain the expected {field} filter")

    return body


def sanitize_dms_offer_create_html(body: str) -> str:
    """Keep the Add Offer layout while removing production sponsor choices."""
    sponsor_select = re.compile(
        r"(?P<open><select\b(?=[^>]*\bid=['\"]sponsor['\"])[^>]*>).*?(?P<close></select\s*>)",
        re.IGNORECASE | re.DOTALL,
    )
    body, replaced = sponsor_select.subn(
        lambda match: f"{match.group('open')}<option value=''>Select Sponsor</option>{match.group('close')}",
        body,
        count=1,
    )
    if replaced != 1:
        raise ValueError("DMS Add Offer page did not contain the expected sponsor selector")

    return body


def synthetic_empty_list_response() -> bytes:
    """Return the DMS list-loader envelope without requesting production rows."""
    return b'{"data":[]}'


ATTR_URL = re.compile(
    r"(?P<prefix>\b(?:href|src|action|poster|data-src)\s*=\s*)"
    r"(?P<quote>['\"])(?P<url>.*?)(?P=quote)",
    re.IGNORECASE | re.DOTALL,
)
CSS_URL = re.compile(r"url\(\s*(?P<quote>['\"]?)(?P<url>.*?)(?P=quote)\s*\)", re.IGNORECASE)
ISP_SELECT = re.compile(
    r"(?P<open><select\b(?=[^>]*\bid=['\"]isps['\"])[^>]*>).*?(?P<close></select\s*>)",
    re.IGNORECASE | re.DOTALL,
)
DOMAIN_PROVIDER_SELECT = re.compile(
    r"(?P<open><select\b(?=[^>]*\bid=['\"]provider['\"])[^>]*>).*?(?P<close></select\s*>)",
    re.IGNORECASE | re.DOTALL,
)
CAMPAIGN_PRIVATE_SELECT = re.compile(
    r"(?P<open><select\b(?=[^>]*\bid=['\"](?P<id>sponsor|saved_headers|domain|negative|servers)['\"])[^>]*>)"
    r".*?(?P<close></select\s*>)",
    re.IGNORECASE | re.DOTALL,
)


def sanitize_dms_lists_html(body: str) -> str:
    """Keep the Lists page layout while replacing live ISP labels and table rows."""
    body = ISP_SELECT.sub(
        lambda match: (
            f"{match.group('open')}<option value='' selected>Select Isp ...</option>"
            "<option value='0'>Example ISP</option>"
            f"{match.group('close')}"
        ),
        body,
        count=1,
    )
    return re.sub(
        r"<tbody\b[^>]*>.*?</tbody\s*>",
        "<tbody></tbody>",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )


def sanitize_dms_bulk_install_html(body: str, empty_server_rows: bool = False) -> str:
    """Replace the server/domain rows serialized into the bulk-install view."""
    body, domains_replaced = re.subn(
        r"(ctrlOptions:\s*)\{.*?\}(\s*,\s*ctrlClass:)",
        r'\1{"990001":"example.net | STATUS: NEW | IS OUR: NO"}\2',
        body,
        count=1,
        flags=re.DOTALL,
    )
    server_rows = "[]" if empty_server_rows else '[{"id":987654321,"name":"Example server (synthetic)","ip":"192.0.2.25"}]'
    body, servers_replaced = re.subn(
        r"(initData:\s*)\[.*?\](\s*\}\);)",
        lambda match: f"{match.group(1)}{server_rows}{match.group(2)}",
        body,
        count=1,
        flags=re.DOTALL,
    )
    if domains_replaced != 1 or servers_replaced != 1:
        raise ValueError("DMS bulk-install response did not match the expected data slots")

    return body


def sanitize_dms_domain_create_html(body: str) -> str:
    """Keep the Add Domains form while replacing live provider names and IDs."""
    body, replaced = DOMAIN_PROVIDER_SELECT.subn(
        lambda match: (
            f"{match.group('open')}<option value='987654321'>Example provider (synthetic)</option>"
            f"{match.group('close')}"
        ),
        body,
        count=1,
    )
    if replaced != 1:
        raise ValueError("DMS Add Domains response did not match the expected provider selector")

    return body


def sanitize_dms_campaign_create_html(body: str) -> str:
    """Keep the Send form while replacing server-rendered private catalog options."""
    placeholders = {
        "sponsor": '<option value="">Select Sponsor</option>',
        "saved_headers": '<option value="">Select Header</option>',
        "domain": '<option value="">Select Domain</option>',
        "negative": '<option value="">Select Negative</option>',
        "servers": '<option value="">Select Server</option>',
    }
    counts = {field: 0 for field in placeholders}

    def clear_private_options(match: re.Match[str]) -> str:
        field = match.group("id").lower()
        counts[field] += 1
        return f"{match.group('open')}{placeholders[field]}{match.group('close')}"

    body = CAMPAIGN_PRIVATE_SELECT.sub(clear_private_options, body)
    if any(count != 1 for count in counts.values()):
        raise ValueError("DMS campaign-create response did not match its private catalog selectors")
    if "CREATE CAMPAIGN" not in html.unescape(body).upper():
        raise ValueError("DMS campaign-create response did not contain the expected form")

    return body


def sanitize_dms_campaign_logs_html(body: str) -> str:
    """Keep the Campaign Logs table shell while removing rows and executable actions."""
    plain_text = html.unescape(re.sub(r"<[^>]+>", " ", body)).lower()
    if "campaign" not in plain_text or "log" not in plain_text or "<table" not in body.lower():
        raise ValueError("DMS Campaign Logs response did not match the expected table page")

    script_paths: list[str] = []

    def remove_script(match: re.Match[str]) -> str:
        opening_tag = match.group(0).split(">", 1)[0]
        src = re.search(r"\bsrc=['\"]([^'\"]+)['\"]", opening_tag, re.IGNORECASE)
        if src:
            parsed = urlparse(urljoin(f"{REFERENCE_ORIGIN}{CAMPAIGN_LOGS_PAGE_PATH}", html.unescape(src.group(1))))
            if parsed.hostname == REFERENCE.hostname and parsed.path.startswith(STATIC_PREFIXES):
                script_paths.append(parsed.path)
        return ""

    body = re.sub(r"<script\b[^>]*>.*?</script\s*>", remove_script, body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<script\b[^>]*/\s*>", remove_script, body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<meta\b(?=[^>]*\bname=['\"]csrf-[^'\"]+['\"])[^>]*>", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<tbody\b[^>]*>.*?</tbody\s*>", "<tbody></tbody>", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"<tr\b[^>]*>.*?</tr\s*>",
        lambda match: match.group(0) if re.search(r"<th\b", match.group(0), re.IGNORECASE) else "",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(r"<textarea\b(?P<attrs>[^>]*)>.*?</textarea\s*>", r"<textarea\g<attrs>></textarea>", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"<option\b[^>]*>.*?</option\s*>", "<option></option>", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"<input\b[^>]*>",
        lambda match: "" if re.search(r"\btype=['\"]hidden['\"]", match.group(0), re.IGNORECASE)
        else re.sub(r"\s+value\s*=\s*(['\"]).*?\1", ' value=""', match.group(0), flags=re.IGNORECASE | re.DOTALL),
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(r"\s+href\s*=\s*(['\"])/mailer/campaign-logs/[^'\"]*\1", ' href="#"', body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+action\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+on[a-z]+\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(r"\s+data-[\w:-]+\s*=\s*(['\"]).*?\1", "", body, flags=re.IGNORECASE | re.DOTALL)
    body = remove_element_by_id(body, "page-header-user-dropdown")
    body = re.sub(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", "[redacted-email]", body, flags=re.IGNORECASE)
    body = re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "[redacted-ip]", body)
    body = re.sub(r"\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b", "[redacted-id]", body, flags=re.IGNORECASE)
    body = redact_ipv6_literals(body)
    body = re.sub(
        r"\bShowing(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*to(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*of(?:\s|<[^>]+>)*[\d,]+(?:\s|<[^>]+>)*(?:records|entries)\b",
        "Showing 0 to 0 of 0 records",
        body,
        flags=re.IGNORECASE,
    )

    inert_script_refs = "".join(
        f'<template data-reference-script-path="{html.escape(script_path, quote=True)}"></template>'
        for script_path in dict.fromkeys(script_paths)
    )
    return body.replace("</body>", inert_script_refs + "</body>") if "</body>" in body else body + inert_script_refs


def sanitize_dms_install_settings_html(body: str) -> str:
    """Keep the settings form layout while removing values and sensitive lists."""
    if "settings[pmta_admin_port]" not in body or "settings[proxy_port]" not in body:
        raise ValueError("DMS settings response did not match the expected form")

    def clear_input_values(match: re.Match[str]) -> str:
        tag = re.sub(
            r"\svalue\s*=\s*(?:\"[^\"]*\"|'[^']*'|[^\s>]+)",
            ' value=""',
            match.group(0),
            flags=re.IGNORECASE,
        )
        return tag

    body = re.sub(r"<input\b[^>]*>", clear_input_values, body, flags=re.IGNORECASE | re.DOTALL)
    body = re.sub(
        r"(<textarea\b[^>]*>).*?(</textarea\s*>)",
        r"\1\2",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )

    boolean_selects = {
        "allow_all_monitor",
        "apply_firewall",
        "allow_backup",
        "block_proxies",
        "filter_geo",
        "move_emails_to_dormant",
    }

    def sanitize_select(match: re.Match[str]) -> str:
        opening, closing = match.group("open"), match.group("close")
        identifier = re.search(r"\bid=['\"]([^'\"]+)", opening, re.IGNORECASE)
        field = identifier.group(1) if identifier else ""
        if field == "pmta_version":
            options = (
                '<option value="40">PMTA 4.0</option>'
                '<option value="45">PMTA 4.5</option>'
                '<option value="50">PMTA 5.0</option>'
            )
        elif field in boolean_selects:
            options = '<option value="0">NO</option><option value="1">YES</option>'
        else:
            options = '<option value="synthetic">Example value</option>'

        return opening + options + closing

    body = re.sub(
        r"(?P<open><select\b[^>]*>).*?(?P<close></select\s*>)",
        sanitize_select,
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )
    body = re.sub(
        r"(<small\b[^>]*>).*?(</small\s*>)",
        r"\1Callback URL hidden in read-only reference\2",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    )

    return body


def sanitize_dms_profile_html(body: str) -> str:
    """Expose only the authenticated DMS username needed for install eligibility."""
    matches = list(re.finditer(
        r"<div\b[^>]*>\s*Username\s*</div>\s*<a\b[^>]*>(?P<username>.*?)</a>",
        body,
        flags=re.IGNORECASE | re.DOTALL,
    ))
    if len(matches) != 1:
        raise ValueError("DMS profile response did not contain exactly one username field")

    username = html.unescape(re.sub(r"<[^>]+>", "", matches[0].group("username"))).strip()
    if not username or username.upper() == "USERNAME":
        raise ValueError("DMS profile username was empty")

    return (
        "<!doctype html><html><head><meta charset=\"utf-8\"><title>DMS profile evidence</title></head>"
        "<body><main><h1>DMS installation username</h1>"
        f"<p id=\"dms-username\">{html.escape(username)}</p></main></body></html>"
    )


def rewrite_document(body: str, mime: str, upstream_url: str, empty_bulk_rows: bool = False) -> str:
    if "text/html" in mime.lower():
        if urlparse(upstream_url).path == PROFILE_PATH:
            body = sanitize_dms_profile_html(body)
        if urlparse(upstream_url).path == "/admin/lists":
            body = sanitize_dms_lists_html(body)
        if urlparse(upstream_url).path == BULK_INSTALL_PAGE_PATH:
            body = sanitize_dms_bulk_install_html(body, empty_server_rows=empty_bulk_rows)
        if urlparse(upstream_url).path == DOMAINS_CREATE_PAGE_PATH:
            body = sanitize_dms_domain_create_html(body)
        if urlparse(upstream_url).path == CAMPAIGNS_CREATE_PAGE_PATH:
            body = sanitize_dms_campaign_create_html(body)
        if urlparse(upstream_url).path == CAMPAIGN_LOGS_PAGE_PATH:
            body = sanitize_dms_campaign_logs_html(body)
        if urlparse(upstream_url).path == OFFERS_INDEX_PAGE_PATH:
            body = sanitize_dms_offers_index_html(body)
        if urlparse(upstream_url).path == OFFERS_CREATE_PAGE_PATH:
            body = sanitize_dms_offer_create_html(body)
        if urlparse(upstream_url).path == INSTALL_SETTINGS_PAGE_PATH:
            body = sanitize_dms_install_settings_html(body)
        if urlparse(upstream_url).path in PMTA_PAGE_PATHS:
            body = sanitize_dms_pmta_html(body)
        if urlparse(upstream_url).path == GLOBAL_TESTS_PAGE_PATH:
            body = sanitize_dms_global_tests_html(body)
        if urlparse(upstream_url).path == GLOBAL_TEST_HISTORIES_PAGE_PATH:
            body = sanitize_dms_global_tests_html(body)
        if urlparse(upstream_url).path == TEXT_MECHANIC_PAGE_PATH:
            body = sanitize_dms_text_mechanic_html(body, upstream_url)
        if urlparse(upstream_url).path in SPF_PAGE_PATHS:
            body = sanitize_dms_spf_html(body, urlparse(upstream_url).path)
        if urlparse(upstream_url).path in WARMUP_PAGE_PATHS:
            body = sanitize_dms_warmup_html(body, urlparse(upstream_url).path)
        if urlparse(upstream_url).path in GMAIL_WORKSPACE_PAGE_PATHS:
            body = sanitize_dms_gmail_workspace_html(body, urlparse(upstream_url).path)
        body = re.sub(
            r"<base\b[^>]*>|<meta\b(?=[^>]*http-equiv=['\"]?refresh)[^>]*>",
            "",
            body,
            flags=re.IGNORECASE,
        )
        body = ATTR_URL.sub(
            lambda m: f"{m.group('prefix')}{m.group('quote')}"
            f"{local_path_for(m.group('url'), upstream_url)}{m.group('quote')}",
            body,
        )
        # DMS wraps this fully-qualified URL in a second /admin/ prefix when
        # constructing the server-registration iframe. Keep only the suffix so
        # the resulting read-only relay URL is /admin/servers/create.
        body = body.replace(
            f"{REFERENCE_ORIGIN}/admin/servers/create",
            "servers/create",
        )
        body = body.replace(
            f"{REFERENCE_ORIGIN}/admin/domains/create",
            "domains/create",
        )
        body = body.replace(
            f"{REFERENCE_ORIGIN}/admin/domains-providers/create",
            "domains-providers/create",
        )
        body = body.replace(
            f"{REFERENCE_ORIGIN}/admin/servers-providers/create",
            "servers-providers/create",
        )
        # The server list's DataTables and Add Servers iframe URLs are embedded
        # as JavaScript strings, so rewrite only the DMS origin to this GET-only
        # relay. The allowlist below still decides which paths can be fetched.
        body = body.replace(REFERENCE_ORIGIN, "")
        # Do not expose the production account label in visual-reference captures.
        body = body.replace(
            "</head>",
            '<style>#page-header-user-dropdown{display:none!important}</style></head>',
        )
        return body
    if "text/css" in mime.lower():
        return CSS_URL.sub(
            lambda m: f"url({m.group('quote')}{local_path_for(m.group('url'), upstream_url)}{m.group('quote')})",
            body,
        )
    return body


class ReadOnlyDmsHandler(BaseHTTPRequestHandler):
    server_version = "DMSReadOnlyRelay/1.0"

    def do_GET(self) -> None:  # noqa: N802 - BaseHTTPRequestHandler interface
        parsed = urlparse(self.path)
        path = parsed.path
        empty_bulk_rows = path == BULK_INSTALL_PAGE_PATH and parsed.query == "synthetic_empty=1"
        if is_protected_campaign_catalog_request(path, parsed.query):
            body = synthetic_empty_campaign_catalog_response(parsed.query)
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            print(f"GET {path} -> 200 (synthetic empty campaign catalog; upstream rows blocked)", flush=True)
            return
        if is_protected_pmta_data_request(path, parsed.query):
            body = synthetic_empty_pmta_response(path, parsed.query)
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            print(f"GET {path} -> 200 (synthetic empty PMTA data; upstream rows/config blocked)", flush=True)
            return
        if is_protected_global_test_data_request(path, parsed.query):
            body = synthetic_empty_global_test_response(path, parsed.query)
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            print(f"GET {path} -> 200 (synthetic empty Global Tests data; upstream rows blocked)", flush=True)
            return
        if is_protected_spf_data_request(path, parsed.query):
            body = synthetic_empty_spf_response(parsed.query)
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            print(f"GET {path} -> 200 (synthetic empty SPF data; saved records blocked)", flush=True)
            return
        if is_protected_warmup_data_request(
            path,
            parsed.query,
            self.headers.get("Accept", ""),
            self.headers.get("X-Requested-With", ""),
        ):
            body = synthetic_empty_warmup_response(path, parsed.query)
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            print(f"GET {path} -> 200 (synthetic empty Warmup data; upstream rows/process details blocked)", flush=True)
            return
        if is_isp_catalog_data_request(
            path,
            parsed.query,
            self.headers.get("Accept", ""),
            self.headers.get("X-Requested-With", ""),
        ) or is_protected_table_data_query(
            path,
            parsed.query,
            self.headers.get("Accept", ""),
            self.headers.get("X-Requested-With", ""),
        ):
            # The server page may use its own path for a DataTables GET.
            # Intercept both protected tables before any query can reach DMS.
            body = synthetic_empty_list_response() if re.fullmatch(r"/admin/lists/\d+/load", path) else synthetic_empty_datatable_response(parsed.query)
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            print(f"GET {path} -> 200 (synthetic empty table; upstream rows blocked)", flush=True)
            return
        if path.startswith("/__remote/"):
            remote = path[len("/__remote/"):].split("/", 1)
            host = remote[0].lower()
            if host not in EXTERNAL_ASSET_HOSTS:
                self.send_error(403)
                return
            remote_path = "/" + remote[1] if len(remote) > 1 else "/"
            upstream = urlunparse(("https", host, remote_path, "", parsed.query, ""))
        elif path in READ_ONLY_PAGE_PATHS or any(path.startswith(prefix) for prefix in STATIC_PREFIXES) or path == "/favicon.ico":
            upstream = urljoin(REFERENCE_URL, path.lstrip("/"))
            if (
                parsed.query
                and not empty_bulk_rows
                and path not in PMTA_PAGE_PATHS
                and path not in WARMUP_PAGE_PATHS
                and path != TEXT_MECHANIC_PAGE_PATH
                and path not in ENCODING_PAGE_PATHS
                and path not in SPF_PAGE_PATHS
                and path not in {GMAIL_PROJECTS_CREATE_PAGE_PATH, GMAIL_ACCOUNTS_CREATE_PAGE_PATH}
            ):
                upstream += "?" + parsed.query
        else:
            self.send_error(403)
            return

        try:
            safe_headers = {
                key: self.headers[key]
                for key in ("Accept", "X-Requested-With")
                if self.headers.get(key)
            }
            response = SESSION.get(upstream, timeout=45, allow_redirects=True, headers=safe_headers)
        except requests.RequestException:
            self.send_error(502)
            return
        if path.startswith("/__remote/") and urlparse(response.url).hostname not in EXTERNAL_ASSET_HOSTS:
            self.send_error(502, "External static asset redirected outside the allowlist")
            return

        mime = response.headers.get("Content-Type", "application/octet-stream")
        body = response.content
        if "text/html" in mime.lower() or "text/css" in mime.lower():
            encoding = response.encoding or "utf-8"
            text = body.decode(encoding, errors="replace")
            try:
                body = rewrite_document(text, mime, response.url, empty_bulk_rows=empty_bulk_rows).encode(encoding, errors="replace")
            except ValueError as error:
                self.send_error(502, "Reference page did not match its safe capture contract")
                print(f"GET {path} -> 502 (sanitizer refused page: {error})", flush=True)
                return

        self.send_response(response.status_code)
        self.send_header("Content-Type", mime)
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header(
            "Content-Security-Policy",
            "default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            "style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; "
            "connect-src 'self'; form-action 'none'; frame-src 'self'; object-src 'none'; base-uri 'self'",
        )
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        print(f"GET {path} -> {response.status_code} ({mime.split(';', 1)[0]})", flush=True)

    def do_POST(self) -> None:  # noqa: N802
        self.send_error(405, "Reference relay is GET-only")

    do_PUT = do_POST
    do_PATCH = do_POST
    do_DELETE = do_POST
    do_OPTIONS = do_POST

    def log_message(self, fmt: str, *args: object) -> None:
        # Access log deliberately excludes query strings, cookies and headers.
        message = fmt % args
        print(f"browser relay: {message.split('HTTP/', 1)[0].strip()}", flush=True)


if __name__ == "__main__":
    argparse.ArgumentParser(description=__doc__).parse_args()
    establish_read_only_session()
    ThreadingHTTPServer((LOCAL_HOST, LOCAL_PORT), ReadOnlyDmsHandler).serve_forever()
