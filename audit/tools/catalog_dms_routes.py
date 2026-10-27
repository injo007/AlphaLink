#!/usr/bin/env python3
"""Build a metadata-only catalog of captured DMS Laravel route declarations.

This parser reads source files only. It does not bootstrap Laravel, query DMS,
read credentials, invoke routes, or infer that an HTTP method is safe.
"""

from __future__ import annotations

import argparse
import json
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable


ROUTE_FILES = (
    "admin.php",
    "api.php",
    "channels.php",
    "console.php",
    "dashboard.php",
    "mailer.php",
    "web.php",
)
ROOT_PREFIXES = {
    "admin.php": "admin",
    "api.php": "api",
    "channels.php": "broadcast",
    "console.php": "console",
    "dashboard.php": "dashboard",
    "mailer.php": "mailer",
    "web.php": "",
}
ENDPOINT_METHODS = {"get", "post", "put", "patch", "delete", "match", "redirect"}
STRUCTURAL_METHODS = {"group", "prefix"}


@dataclass(frozen=True)
class RouteCall:
    method: str
    start: int
    end: int
    expression: str


@dataclass(frozen=True)
class RouteGroup:
    start: int
    end: int
    prefix: str
    name: str


@dataclass(frozen=True)
class ChainedCall:
    method: str
    start: int
    end: int
    expression: str


def _code_mask(source: str) -> str:
    """Replace PHP strings and comments with spaces while preserving offsets."""
    out = list(source)
    i = 0
    state = "code"
    quote = ""
    while i < len(source):
        char = source[i]
        nxt = source[i + 1] if i + 1 < len(source) else ""
        if state == "code":
            if char in "'\"`":
                state, quote = "string", char
                out[i] = " "
            elif char == "/" and nxt == "*":
                state = "block"
                out[i] = out[i + 1] = " "
                i += 1
            elif char == "/" and nxt == "/" or char == "#":
                state = "line"
                out[i] = " "
                if nxt == "/":
                    out[i + 1] = " "
                    i += 1
        elif state == "string":
            out[i] = " " if char != "\n" else "\n"
            if char == "\\" and i + 1 < len(source):
                i += 1
                out[i] = " " if source[i] != "\n" else "\n"
            elif char == quote:
                state = "code"
        elif state == "line":
            out[i] = " " if char != "\n" else "\n"
            if char == "\n":
                state = "code"
        elif state == "block":
            out[i] = " " if char != "\n" else "\n"
            if char == "*" and nxt == "/":
                out[i + 1] = " "
                i += 1
                state = "code"
        i += 1
    return "".join(out)


def _matching(mask: str, opening_index: int, opening: str, closing: str) -> int:
    depth = 0
    for index in range(opening_index, len(mask)):
        if mask[index] == opening:
            depth += 1
        elif mask[index] == closing:
            depth -= 1
            if depth == 0:
                return index + 1
    raise ValueError(f"Unclosed {opening!r} at offset {opening_index}")


def _route_calls(source: str) -> list[RouteCall]:
    mask = _code_mask(source)
    calls: list[RouteCall] = []
    for match in re.finditer(r"\bRoute::([A-Za-z_][A-Za-z0-9_]*)\s*\(", mask):
        open_index = mask.find("(", match.start(), match.end())
        end = _matching(mask, open_index, "(", ")")
        calls.append(RouteCall(match.group(1).lower(), match.start(), end, source[open_index + 1:end - 1]))
    return calls


def _chained_calls(source: str, mask: str, start: int) -> list[ChainedCall]:
    """Read consecutive fluent calls following a Route call, preserving offsets."""
    calls: list[ChainedCall] = []
    cursor = start
    while cursor < len(mask):
        while cursor < len(mask) and mask[cursor].isspace():
            cursor += 1
        if mask[cursor:cursor + 2] != "->":
            break
        method_match = re.match(r"->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(", mask[cursor:])
        if method_match is None:
            break
        open_index = cursor + method_match.end() - 1
        end = _matching(mask, open_index, "(", ")")
        calls.append(ChainedCall(
            method=method_match.group(1).lower(),
            start=cursor,
            end=end,
            expression=source[open_index + 1:end - 1],
        ))
        cursor = end
    return calls


def _split_arguments(expression: str) -> list[str]:
    """Split PHP call arguments at commas outside strings and nested delimiters."""
    arguments: list[str] = []
    start = 0
    stack: list[str] = []
    quote = ""
    escaped = False
    pairs = {")": "(", "]": "[", "}": "{"}
    for index, char in enumerate(expression):
        if quote:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = ""
            continue
        if char in "'\"`":
            quote = char
        elif char in "([{":
            stack.append(char)
        elif char in ")]}":
            if stack and stack[-1] == pairs[char]:
                stack.pop()
        elif char == "," and not stack:
            arguments.append(expression[start:index].strip())
            start = index + 1
    tail = expression[start:].strip()
    if tail or arguments:
        arguments.append(tail)
    return arguments


def _first_string(expression: str) -> str | None:
    strings = _strings(expression)
    return strings[0] if strings else None


def _strings(expression: str) -> list[str]:
    values: list[str] = []
    i = 0
    while i < len(expression):
        if expression[i] not in "'\"`":
            i += 1
            continue
        quote = expression[i]
        i += 1
        value: list[str] = []
        while i < len(expression):
            char = expression[i]
            if char == "\\" and i + 1 < len(expression):
                value.append(expression[i + 1])
                i += 2
                continue
            if char == quote:
                i += 1
                break
            value.append(char)
            i += 1
        values.append("".join(value))
    return values


def _group_range(source: str, mask: str, call: RouteCall) -> RouteGroup | None:
    if call.method == "group":
        body_match = re.search(r"\bfunction\b", mask[call.start:call.end])
        if body_match is None:
            return None
        body_open = mask.find("{", call.start + body_match.end(), call.end)
        if body_open < 0:
            return None
        body_end = _matching(mask, body_open, "{", "}")
        prefix_match = re.search(r"['\"]prefix['\"]\s*=>\s*(['\"])(.*?)\1", call.expression, re.S)
        name_match = re.search(r"['\"]as['\"]\s*=>\s*(['\"])(.*?)\1", call.expression, re.S)
        return RouteGroup(body_open, body_end, prefix_match.group(2) if prefix_match else "", name_match.group(2) if name_match else "")

    if call.method == "prefix":
        chain_calls = _chained_calls(source, mask, call.end)
        group_call = next((chained for chained in chain_calls if chained.method == "group"), None)
        if group_call is None:
            return None
        function_match = re.search(r"\bfunction\b", _code_mask(group_call.expression))
        if function_match is None:
            return None
        body_open = mask.find("{", group_call.start, group_call.end)
        if body_open < 0:
            return None
        body_end = _matching(mask, body_open, "{", "}")
        strings = _strings(call.expression)
        name_call = next((chained for chained in chain_calls if chained.method == "name"), None)
        name = _first_string(name_call.expression) if name_call else ""
        return RouteGroup(body_open, body_end, strings[0] if strings else "", name or "")

    return None


def _normalize_parameter_path(path: str) -> str:
    normalized = re.sub(r"\{([^{}]+)\}", r":\1", path.strip("/"))
    return "/" + normalized if normalized else "/"


def catalog_file(path: Path, source_root: Path) -> tuple[list[dict[str, object]], dict[str, int]]:
    source = path.read_text(encoding="utf-8")
    mask = _code_mask(source)
    calls = _route_calls(source)
    groups = [group for call in calls if (group := _group_range(source, mask, call)) is not None]
    records: list[dict[str, object]] = []
    call_counts: dict[str, int] = {}

    for call in calls:
        call_counts[call.method] = call_counts.get(call.method, 0) + 1
        if call.method not in ENDPOINT_METHODS:
            continue

        arguments = _split_arguments(call.expression)
        if call.method == "match":
            if len(arguments) < 2:
                continue
            http_methods = [value.upper() for value in _strings(arguments[0])]
            uri = _first_string(arguments[1])
            if uri is None:
                continue
            verb = ",".join(http_methods)
        else:
            if not arguments:
                continue
            uri = _first_string(arguments[0])
            if uri is None:
                continue
            verb = "REDIRECT" if call.method == "redirect" else call.method.upper()

        enclosing = sorted((group for group in groups if group.start < call.start < group.end), key=lambda group: group.start)
        prefixes = [ROOT_PREFIXES.get(path.name, ""), *(group.prefix for group in enclosing)]
        route_path = "/" + "/".join(part.strip("/") for part in (*prefixes, uri) if part.strip("/"))
        route_chain = _chained_calls(source, mask, call.end)
        name_call = next((chained for chained in route_chain if chained.method == "name"), None)
        declared_name = _first_string(name_call.expression) if name_call else None
        name_prefixes = [group.name for group in enclosing if group.name]
        route_name_prefix = ".".join(part.strip(".") for part in name_prefixes if part.strip("."))
        route_name = ".".join(
            part.strip(".") for part in (*name_prefixes, declared_name or "") if part.strip(".")
        ) if declared_name else ""

        controller_argument_index = 2 if call.method == "match" else 1
        action_arguments = arguments[controller_argument_index] if len(arguments) > controller_argument_index else ""
        controller_match = re.search(r"([A-Za-z_][A-Za-z0-9_\\]*)::class\s*,\s*(['\"])([A-Za-z_][A-Za-z0-9_]*)\2", action_arguments, re.S)
        if controller_match:
            controller, action = controller_match.group(1).lstrip("\\"), controller_match.group(3)
        else:
            invokable_match = re.search(r"([A-Za-z_][A-Za-z0-9_\\]*)::class", action_arguments)
            if invokable_match:
                controller, action = invokable_match.group(1).lstrip("\\"), "__invoke"
            elif re.search(r"\bfunction\b|\bfn\s*\(", _code_mask(action_arguments)):
                controller, action = "Closure", "inline"
            elif call.method == "redirect":
                controller, action = "Redirect", "redirect"
            else:
                controller, action = "UNKNOWN", "UNKNOWN"

        records.append({
            "id": "",
            "source_file": str(path.relative_to(source_root)),
            "source_line": source.count("\n", 0, call.start) + 1,
            "http_method": verb,
            "path_template": _normalize_parameter_path(route_path),
            "declared_uri": uri,
            "route_name_prefix": route_name_prefix,
            "route_name": route_name,
            "controller": controller,
            "action": action,
            "workflow_unit": "UNASSIGNED",
        })

    return records, call_counts


def build_catalog(source_root: Path) -> dict[str, object]:
    routes_dir = source_root / "routes"
    all_records: list[dict[str, object]] = []
    helper_counts: dict[str, int] = {}
    for filename in ROUTE_FILES:
        path = routes_dir / filename
        if not path.is_file():
            raise FileNotFoundError(path)
        records, counts = catalog_file(path, source_root)
        all_records.extend(records)
        for helper, count in counts.items():
            helper_counts[helper] = helper_counts.get(helper, 0) + count
    all_records.sort(key=lambda record: (str(record["source_file"]), int(record["source_line"])))
    for index, record in enumerate(all_records, 1):
        record["id"] = f"DMSR-{index:04d}"
    return {
        "schema_version": 1,
        "capture_date": "2026-10-22",
        "source_basis": "Captured Laravel route declarations; source paths only, without remote PHP hashes.",
        "route_files": list(ROUTE_FILES),
        "route_helper_counts": dict(sorted(helper_counts.items())),
        "direct_endpoint_declarations": len(all_records),
            "workflow_note": "Each endpoint is mapped later to a user-facing workflow; endpoints are not counted as workflows.",
        "routes": all_records,
    }


def main(argv: Iterable[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", type=Path, required=True, help="Root of the captured DMS source tree")
    parser.add_argument("--output", type=Path, required=True, help="JSON output path")
    args = parser.parse_args(argv)
    catalog = build_catalog(args.source_root)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(catalog, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Cataloged {catalog['direct_endpoint_declarations']} endpoint declarations from {len(ROUTE_FILES)} route files.")
    print(f"Route helper counts: {catalog['route_helper_counts']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
