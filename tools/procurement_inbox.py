#!/usr/bin/env python3
"""AURENOEVA private inbox: automatically turn incoming Excel/CSV into one case
and evidence-gated RFQ draft. For the existing Hostinger VPS cron/systemd timer.
No external services, email sending, public product writes, or duplicate CRM.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import tempfile

from procurement_intake import build_payload
from procurement_rfq import create_package


def _atomic_private_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, tmp = tempfile.mkstemp(prefix=".incoming-", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(payload, stream, ensure_ascii=False, indent=2)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def prepare_one(source: Path, private_dir: Path, country: str) -> dict:
    """Idempotent private preparation. The *same bytes* never duplicate a case."""
    if source.suffix.lower() not in {".csv", ".xlsx"}:
        raise ValueError("unsupported_customer_file")
    if not source.is_file() or source.is_symlink():
        raise ValueError("input_not_regular_file")
    sha = hashlib.sha256(source.read_bytes()).hexdigest()
    reference = f"AUR-{country.upper()}-{sha[:16].upper()}"
    case_path = private_dir / "cases" / (reference + ".json")
    preflight_path = private_dir / "rfq-drafts" / (reference + ".json")
    if case_path.exists():
        prior = json.loads(case_path.read_text(encoding="utf-8"))
        if prior.get("source_sha256") != sha or prior.get("delivery_country") != country.upper():
            raise ValueError("existing_case_reference_conflict")
        return {"case_reference": reference, "state": "ALREADY_PREPARED",
                "count": len(prior["sourcing_lines"])}
    payload = build_payload(source, reference, country.upper())
    # The input remains unmodified for original-document provenance; no
    # alternative is counted as an additional purchase quantity.
    package = create_package(payload)
    _atomic_private_json(case_path, payload)
    _atomic_private_json(preflight_path, package)
    return {
        "case_reference": reference,
        "state": "PREPARED_NOT_COMMITTED",
        "count": len(payload["sourcing_lines"]),
        "rfq_send_allowed": package["rfq"]["send_allowed"],
        "legal_preflight": package["compliance"]["status"],
    }


def scan(inbox: Path, private_dir: Path, country: str) -> list[dict]:
    if inbox.resolve() == private_dir.resolve() or private_dir.resolve().is_relative_to(inbox.resolve()):
        raise ValueError("private_storage_must_be_outside_customer_inbox")
    results = []
    for source in sorted(inbox.iterdir()):
        if source.is_file() and not source.is_symlink() and source.suffix.lower() in {".xlsx", ".csv"}:
            try:
                results.append({"source_name": source.name, **prepare_one(source, private_dir, country)})
            except Exception as exc:
                # A malformed file does not stop other customer imports; no raw
                # customer data or private names are printed beyond file name.
                results.append({"source_name": source.name, "state": "ERROR", "reason": str(exc)[:180]})
    return results


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--inbox", type=Path, required=True)
    ap.add_argument("--private-dir", type=Path, required=True)
    ap.add_argument("--country", required=True, help="ISO-2 country of intended delivery")
    args = ap.parse_args()
    if not args.inbox.is_dir():
        ap.error("inbox_missing")
    if not args.country.isalpha() or len(args.country) != 2:
        ap.error("invalid_country")
    if not args.private_dir.is_absolute():
        ap.error("private_dir_must_be_absolute")
    result = scan(args.inbox, args.private_dir, args.country.upper())
    print(json.dumps({"results": result, "processed": len(result)}, ensure_ascii=False))
    return 1 if any(x["state"] == "ERROR" for x in result) else 0


if __name__ == "__main__":
    raise SystemExit(main())
