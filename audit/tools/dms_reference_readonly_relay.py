#!/usr/bin/env python3
"""Authenticated, GET-only local browser relay for DMS visual inspection.

The relay reads DMS settings from the repository .env, establishes an
authenticated session through the configured HTTP proxy, and serves only the
server-registration page plus static assets. Authentication is the only
upstream POST. Every browser-originated non-GET request is rejected locally.
"""

from __future__ import annotations

import html
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
PAGE_PATH = "/admin/servers/create"
READ_ONLY_PAGE_PATHS = {"/admin/servers", PAGE_PATH}
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
    if target.status_code != 200 or not target_title or target_title.group(1).strip() != "Add Servers - DMS":
        raise SystemExit("REFUSED: expected DMS server-registration page was not returned")

    print("DMS read-only session: login form accepted; dashboard GET 200; registration GET 200.", flush=True)
    print(f"Relay ready: http://{LOCAL_HOST}:{LOCAL_PORT}{PAGE_PATH} (browser requests are GET-only).", flush=True)


def local_path_for(url: str, page_url: str) -> str:
    value = url.strip()
    if not value or value.startswith(("#", "data:", "javascript:", "mailto:", "tel:")):
        return value
    parsed = urlparse(urljoin(page_url, html.unescape(value)))
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


ATTR_URL = re.compile(
    r"(?P<prefix>\b(?:href|src|action|poster|data-src)\s*=\s*)"
    r"(?P<quote>['\"])(?P<url>.*?)(?P=quote)",
    re.IGNORECASE | re.DOTALL,
)
CSS_URL = re.compile(r"url\(\s*(?P<quote>['\"]?)(?P<url>.*?)(?P=quote)\s*\)", re.IGNORECASE)


def rewrite_document(body: str, mime: str, upstream_url: str) -> str:
    if "text/html" in mime.lower():
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
        # The server list's DataTables and Add Servers iframe URLs are embedded
        # as JavaScript strings, so rewrite only the DMS origin to this GET-only
        # relay. The allowlist below still decides which paths can be fetched.
        body = body.replace(REFERENCE_ORIGIN, "")
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
            if parsed.query:
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
            body = rewrite_document(text, mime, response.url).encode(encoding, errors="replace")

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
    establish_read_only_session()
    ThreadingHTTPServer((LOCAL_HOST, LOCAL_PORT), ReadOnlyDmsHandler).serve_forever()
