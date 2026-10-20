#!/usr/bin/env python3
"""Hostinger-native AURENOEVA intake/screening worker.

One run: consume private Excel/CSV inbox -> app-owned canonical SQLite case ->
re-read exact lines -> screen known legal parties against fresh official feeds ->
write legal evidence. Never dispatch supplier email or approve any purchase.
Use the existing VPS cron/systemd, not GitHub Actions scheduling.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
from pathlib import Path
import sqlite3

from procurement_case_store import commit
from procurement_rfq import create_package
from procurement_sanctions import refresh, screen

def process(inbox: Path, db: Path, feed_dir: Path, country: str, party_dir: Path) -> dict:
    if not inbox.is_dir():
        raise ValueError("private_inbox_missing")
    if not db.is_absolute() or not feed_dir.is_absolute():
        raise ValueError("private_paths_must_be_absolute")
    output=[]
    for source in sorted(inbox.iterdir()):
        if source.is_symlink() or not source.is_file() or source.suffix.lower() not in (".xlsx",".csv"):
            continue
        try:
            saved=commit(source,db,country=country)
            # Buyer/end-user/supplier details must come from explicit private
            # evidence; do not invent a medical hospital from the equipment list.
            evidence_path=party_dir / (saved["reference"]+".json")
            evidence={}
            if evidence_path.is_file():
                evidence=json.loads(evidence_path.read_text(encoding="utf-8"))
                if not isinstance(evidence,dict):
                    raise ValueError("invalid_case_evidence_json")
            parties=evidence.get("screening_parties") or []
            if not isinstance(parties,list):
                raise ValueError("invalid_screening_parties")
            screening=screen(parties,feed_dir)
            evidence["sanctions_screening"]=screening
            package=create_package(saved["payload"],evidence)
            report={"compliance":package["compliance"],"sanctions":screening,
                    "rfq_state":"DRAFT_NOT_SENT","case_reference":saved["reference"]}
            with sqlite3.connect(db,timeout=10) as conn:
                conn.execute("PRAGMA foreign_keys=ON")
                conn.execute("INSERT INTO procurement_compliance(case_reference,report_json,checked_at) "
                             "VALUES(?,?,?) ON CONFLICT(case_reference) DO UPDATE "
                             "SET report_json=excluded.report_json,checked_at=excluded.checked_at",
                             (saved["reference"],json.dumps(report,ensure_ascii=False),
                              dt.datetime.now(dt.timezone.utc).isoformat()))
            output.append({"reference":saved["reference"],"rows":saved["line_count"],
                           "legal_state":package["compliance"]["status"],
                           "sanctions_state":screening["status"],"readback":True})
        except Exception as exc:
            output.append({"source":source.name,"readback":False,"error":str(exc)[:150]})
    return {"ok":not any("error" in x for x in output),"cases":output,
            "processed":len(output),"rfqs_dispatched":0}


def main() -> int:
    ap=argparse.ArgumentParser()
    ap.add_argument("--inbox",required=True,type=Path)
    ap.add_argument("--db",required=True,type=Path)
    ap.add_argument("--feed-dir",required=True,type=Path)
    ap.add_argument("--party-dir",required=True,type=Path)
    ap.add_argument("--country",default="UZ")
    ap.add_argument("--refresh-feeds",action="store_true")
    args=ap.parse_args()
    if not args.country.isalpha() or len(args.country)!=2:
        ap.error("country_must_be_ISO2")
    feeds={}
    if args.refresh_feeds:
        for name,url in {
            "EU_FSF":os.getenv("AURENOEVA_EU_FSF_URL",""),
            "OFAC_SDN":os.getenv("AURENOEVA_OFAC_SDN_URL","https://www.treasury.gov/ofac/downloads/sdn.csv"),
        }.items():
            try:
                feeds[name]={"ok":True,"meta":refresh(args.feed_dir,name,url)}
            except Exception as e:
                feeds[name]={"ok":False,"error":str(e)[:150]}
    results=process(args.inbox,args.db,args.feed_dir,args.country.upper(),args.party_dir)
    results["feed_update"]=feeds
    print(json.dumps(results,ensure_ascii=False))
    return 0 if results["ok"] else 2


if __name__=="__main__":
    raise SystemExit(main())
