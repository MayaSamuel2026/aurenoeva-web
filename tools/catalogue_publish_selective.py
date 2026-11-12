#!/usr/bin/env python3
"""Resource-aware, change-aware wrapper for the existing verified catalogue publisher.

Uses the original SQLite catalogue and original HTTPS publisher/receipts.
Skips identical full snapshots only while the last acknowledged publication
remains fresh. All products are reconciled at least once per three hours.
No new database, daemon, queue, or consumer protocol.
"""
from __future__ import annotations
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time

DB = Path("/var/lib/aurenoeva/catalogue.sqlite3")
STATE = DB.parent
PUBLISHER = Path("/opt/aurenoeva/tools/catalogue_publish.py")
MARKER = STATE / "catalogue-publication-watermark.json"
MAX_AGE_SECONDS = 3 * 3600
MIN_DISK_BYTES = 8 * 1024**3
MIN_MEMORY_BYTES = 1200 * 1024**2
MAX_STEAL_PERCENT = 35.0


def fingerprint(db_path: Path) -> dict:
    """Read-only catalogue digest signals; a changed record forces publication."""
    with sqlite3.connect("file:" + str(db_path) + "?mode=ro", uri=True, timeout=20) as db:
        db.execute("PRAGMA query_only=ON")
        count, last_seen, withdrawn = db.execute(
            """SELECT COUNT(*), COALESCE(MAX(last_seen),''),
                      COALESCE(SUM(CASE WHEN lifecycle_status='WITHDRAWN' THEN 1 ELSE 0 END),0)
               FROM products""").fetchone()
        # Source-backed offers may change without a product identity changing.
        exists = db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='public_offers'").fetchone()
        offers = [0, "", 0]
        if exists:
            offers = list(db.execute(
                """SELECT COUNT(*),COALESCE(MAX(observed_at),''),
                          COALESCE(SUM(active),0) FROM public_offers""").fetchone())
        return {"products": int(count), "last_seen": str(last_seen),
                "withdrawn": int(withdrawn), "offers": offers}


def available_memory() -> int:
    with open("/proc/meminfo", encoding="ascii") as handle:
        for line in handle:
            if line.startswith("MemAvailable:"):
                return int(line.split()[1]) * 1024
    raise RuntimeError("memory_telemetry_missing")


def cpu_steal_percent() -> float:
    def snap() -> tuple[int, int]:
        fields = Path("/proc/stat").read_text().splitlines()[0].split()
        values = [int(x) for x in fields[1:9]]
        return sum(values), values[7]
    before_total, before_steal = snap()
    time.sleep(1)
    after_total, after_steal = snap()
    return 100 * (after_steal - before_steal) / max(1, after_total - before_total)


def load_marker(path: Path) -> dict | None:
    try:
        if path.is_symlink() or path.stat().st_mode & 0o077:
            return None
        value = json.loads(path.read_text(encoding="utf-8"))
        return value if isinstance(value, dict) else None
    except (OSError, ValueError, TypeError):
        return None


def publication_needed(before: dict, marker: dict | None, now: float,
                       pending: bool = False) -> bool:
    if pending or not marker:
        return True
    at = marker.get("acknowledged_at")
    if type(at) not in (int, float) or not 0 <= now - at < MAX_AGE_SECONDS:
        return True
    return marker.get("fingerprint") != before


def resource_reasons(db_dir: Path) -> list[str]:
    reasons = []
    if shutil.disk_usage(db_dir).free < MIN_DISK_BYTES:
        reasons.append("low_disk")
    if available_memory() < MIN_MEMORY_BYTES:
        reasons.append("low_memory")
    if cpu_steal_percent() >= MAX_STEAL_PERCENT:
        reasons.append("high_cpu_steal")
    return reasons


def save_marker(path: Path, before: dict, now: float) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix=".catalogue-publish-watermark-", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as out:
            json.dump({"acknowledged_at": now, "fingerprint": before}, out,
                      sort_keys=True, separators=(",", ":"))
            out.flush()
            os.fsync(out.fileno())
        os.chmod(name, 0o600)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def run(db: Path = DB, state: Path = STATE, publisher: Path = PUBLISHER,
        marker_path: Path = MARKER) -> int:
    if not db.is_file() or not publisher.is_file():
        raise RuntimeError("preserved_catalogue_or_publisher_missing")
    before = fingerprint(db)
    marker = load_marker(marker_path)
    pending = (state / "catalogue-products-pending.json").exists()
    if not publication_needed(before, marker, time.time(), pending=pending):
        print(json.dumps({"event": "CATALOGUE_PUBLICATION_SKIPPED",
                          "reason": "no_changes_fresh_verified_snapshot",
                          "products": before["products"]}), flush=True)
        return 0
    try:
        reasons = resource_reasons(state)
    except (OSError, ValueError, RuntimeError):
        reasons = ["resource_telemetry_unavailable"]
    if reasons:
        print(json.dumps({"event": "CATALOGUE_PUBLICATION_DEFERRED",
                          "reasons": reasons}), flush=True)
        return 0  # Existing timer retries; no data or publication receipt is changed.
    completed = subprocess.run([sys.executable, str(publisher)], check=False)
    if completed.returncode != 0:
        print(json.dumps({"event": "CATALOGUE_PUBLICATION_FAILED",
                          "exit_code": completed.returncode}), flush=True)
        return completed.returncode
    # Never checkpoint a newer catalogue than the snapshot actually published.
    after = fingerprint(db)
    if after == before:
        save_marker(marker_path, before, time.time())
    print(json.dumps({"event": "CATALOGUE_PUBLICATION_ACKNOWLEDGED",
                      "changed_while_publishing": after != before,
                      "products_before": before["products"],
                      "products_after": after["products"]}), flush=True)
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    options = parser.parse_args()
    if options.self_test:
        d = {"products": 4, "last_seen": "2026-11-12", "withdrawn": 0, "offers": [0, "", 0]}
        assert publication_needed(d, None, 100)
        assert not publication_needed(d, {"fingerprint": d, "acknowledged_at": 100}, 101)
        assert publication_needed(d, {"fingerprint": d, "acknowledged_at": 100}, 11000)
        assert publication_needed(d, {"fingerprint": d, "acknowledged_at": 100}, 101, pending=True)
        assert publication_needed(d, {"fingerprint": {**d, "products": 3},
                                      "acknowledged_at": 100}, 101)
        print("CATALOGUE_PUBLISH_SELECTIVE_SELF_TEST=PASS")
        return 0
    return run()


if __name__ == "__main__":
    raise SystemExit(main())
