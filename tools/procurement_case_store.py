#!/usr/bin/env python3
"""AURENOEVA app-owned private case import and authenticated-bridge readback.

CLI only: it must be called by the established CRM session/scope gateway
or a trusted Hostinger-local service, never exposed as a public HTTP endpoint.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import sqlite3

from procurement_intake import build_payload

SCHEMA = """
CREATE TABLE IF NOT EXISTS procurement_cases (
    reference TEXT PRIMARY KEY,
    country TEXT NOT NULL,
    source_sha256 TEXT NOT NULL,
    original_name TEXT NOT NULL,
    payload TEXT NOT NULL,
    line_count INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(country, source_sha256)
);
CREATE TABLE IF NOT EXISTS procurement_case_lines (
    case_reference TEXT NOT NULL REFERENCES procurement_cases(reference),
    ordinal INTEGER NOT NULL,
    customer_item_number INTEGER NOT NULL,
    option_type TEXT NOT NULL,
    description_original TEXT NOT NULL,
    quantity INTEGER,
    content_json TEXT NOT NULL,
    PRIMARY KEY(case_reference, ordinal)
);
CREATE TABLE IF NOT EXISTS procurement_compliance (
    case_reference TEXT PRIMARY KEY REFERENCES procurement_cases(reference),
    report_json TEXT NOT NULL,
    checked_at TEXT NOT NULL
);
"""
MAX_SIZE = 20_000_000


def _db(path: Path) -> sqlite3.Connection:
    if not path.is_absolute():
        raise ValueError("case_database_path_must_be_absolute")
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    if path.exists():
        path.chmod(0o600)
    conn = sqlite3.connect(str(path), timeout=15)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys=ON")
    conn.execute("PRAGMA busy_timeout=15000")
    conn.execute("PRAGMA journal_mode=WAL")
    conn.executescript(SCHEMA)
    path.chmod(0o600)
    return conn


def _case(conn: sqlite3.Connection, reference: str) -> dict | None:
    head = conn.execute(
        "SELECT reference,country,source_sha256,original_name,payload,line_count,created_at "
        "FROM procurement_cases WHERE reference=?", (reference,),
    ).fetchone()
    if head is None:
        return None
    lines = [
        json.loads(row["content_json"])
        for row in conn.execute("SELECT content_json FROM procurement_case_lines "
                                "WHERE case_reference=? ORDER BY ordinal", (reference,))
    ]
    payload = json.loads(head["payload"])
    legal = conn.execute("SELECT report_json,checked_at FROM procurement_compliance "
                         "WHERE case_reference=?", (reference,)).fetchone()
    report = json.loads(legal["report_json"]) if legal else None
    if lines != payload["sourcing_lines"] or len(lines) != head["line_count"]:
        raise RuntimeError("canonical_case_readback_mismatch")
    return {
        "ok": True, "reference": head["reference"], "country": head["country"],
        "source_sha256": head["source_sha256"], "original_name": head["original_name"],
        "created_at": head["created_at"], "line_count": head["line_count"],
        "requirements": len({x["customer_item_number"] for x in lines}),
        "alternatives": sum(x["option_type"] == "ALTERNATIVE" for x in lines),
        "import_state": "PERSISTED_PRIVATE_APP_CASE",
        "payload": payload, "legal_review": report,
    }


def commit(source: Path, database: Path, *, country: str, reference: str | None = None) -> dict:
    if not source.is_file() or source.is_symlink() or source.stat().st_size > MAX_SIZE:
        raise ValueError("invalid_source_file")
    if country != "UZ" and (len(country) != 2 or not country.isascii() or not country.isalpha()):
        raise ValueError("invalid_country")
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    reference = reference or ("AUR-" + country + "-" + digest[:16].upper())
    payload = build_payload(source, reference, country)
    # New case records must not become public product catalogue truth.
    payload["import_state"] = "PERSISTED_PRIVATE_APP_CASE"
    payload["buyer_contracts_vendor_directly"] = True
    payload["legal_status"] = "REVIEW_REQUIRED"
    now = dt.datetime.now(dt.timezone.utc).isoformat()
    with _db(database) as conn:
        conn.execute("BEGIN IMMEDIATE")
        existing = conn.execute(
            "SELECT reference FROM procurement_cases WHERE country=? AND source_sha256=?",
            (country, digest),
        ).fetchone()
        if existing:
            if existing["reference"] != reference:
                # Report the canonical original reference instead of duplicating records.
                reference = existing["reference"]
        else:
            if conn.execute("SELECT 1 FROM procurement_cases WHERE reference=?", (reference,)).fetchone():
                raise ValueError("case_reference_conflict")
            conn.execute(
                "INSERT INTO procurement_cases(reference,country,source_sha256,original_name,payload,line_count,created_at)"
                " VALUES (?,?,?,?,?,?,?)",
                (reference, country, digest, source.name, json.dumps(payload, ensure_ascii=False),
                 len(payload["sourcing_lines"]), now),
            )
            for i, line in enumerate(payload["sourcing_lines"], 1):
                conn.execute(
                    "INSERT INTO procurement_case_lines(case_reference,ordinal,customer_item_number,"
                    "option_type,description_original,quantity,content_json) VALUES (?,?,?,?,?,?,?)",
                    (reference, i, line["customer_item_number"], line["option_type"],
                     line["description_original"], line["quantity"], json.dumps(line, ensure_ascii=False)),
                )
        readback = _case(conn, reference)
        conn.commit()
    if readback is None:
        raise RuntimeError("case_write_not_persisted")
    return readback


def read(database: Path, reference: str) -> dict:
    with _db(database) as conn:
        result = _case(conn, reference)
    if result is None:
        raise ValueError("case_not_found")
    return result


def list_cases(database: Path) -> list[dict]:
    with _db(database) as conn:
        return [dict(row) for row in conn.execute(
            "SELECT reference,country,original_name,line_count,created_at "
            "FROM procurement_cases ORDER BY created_at DESC LIMIT 250"
        )]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("action", choices=("import", "get", "list"))
    ap.add_argument("--db", required=True, type=Path)
    ap.add_argument("--source", type=Path)
    ap.add_argument("--country", default="UZ")
    ap.add_argument("--case")
    args = ap.parse_args()
    if args.action == "import":
        if args.source is None:
            ap.error("source_required")
        result = commit(args.source, args.db, country=args.country.upper(), reference=args.case)
    elif args.action == "get":
        if not args.case:
            ap.error("case_reference_required")
        result = read(args.db, args.case)
    else:
        result = {"ok": True, "cases": list_cases(args.db)}
    print(json.dumps(result, ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
