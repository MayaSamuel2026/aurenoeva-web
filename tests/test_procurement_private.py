"""Tests for canonical AURENOEVA private case writes and official-list screenings."""
from __future__ import annotations
import csv
import datetime as dt
import hashlib
import io
import json
import sqlite3
import sys
import tempfile
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/"tools"))
from procurement_case_store import commit,read
from procurement_hostinger_worker import process
from procurement_sanctions import normalize,parse_names,screen,source_uri_ok


class TestPrivateProcurement(unittest.TestCase):
    def test_atomic_case_import_readback_no_duplicate(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            source=root/"enquiry.csv"
            source.write_text("No.,Equipment description,Qty.\n1,Mindray WATO EX-35,2\n,Alternative ventilator,\n2,1.5T MRI scanner,1\n",encoding="utf-8")
            db=root/"private"/"cases.sqlite3"
            a=commit(source,db,country="UZ")
            b=commit(source,db,country="UZ")
            self.assertTrue(a["ok"])
            self.assertEqual(a["reference"],b["reference"])
            self.assertEqual(a["line_count"],3)
            self.assertEqual(a["requirements"],2)
            self.assertEqual(a["alternatives"],1)
            self.assertEqual(read(db,a["reference"])["payload"]["sourcing_lines"][1]["option_type"],"ALTERNATIVE")
            with sqlite3.connect(db) as conn:
                self.assertEqual(conn.execute("SELECT count(*) FROM procurement_cases").fetchone()[0],1)
                self.assertEqual(conn.execute("SELECT count(*) FROM procurement_case_lines").fetchone()[0],3)
            source.write_text("No.,Equipment description,Qty.\n1,CHANGED CONTENT,1\n",encoding="utf-8")
            with self.assertRaisesRegex(ValueError,"case_reference_conflict"):
                commit(source,db,country="UZ",reference=a["reference"])

    def _make_feed(self,dir:Path, source:str, rows:list[list[str]], now:dt.datetime):
        path=dir/(source+".csv")
        stream=io.StringIO()
        writer=csv.writer(stream,delimiter=";" if source=="EU_FSF" else ",");writer.writerows(rows)
        data=stream.getvalue().encode("utf-8")
        path.write_bytes(data)
        (dir/(source+".json")).write_text(json.dumps({
            "source":source,"url":"https://ofac.treasury.gov/sanctions-list-service",
            "retrieved_at":now.isoformat(),"sha256":hashlib.sha256(data).hexdigest(),
            "names_count":len(parse_names(data,source)),
        }),encoding="utf-8")

    def test_official_list_hits_and_stale_hold(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            now=dt.datetime(2026,10,20,12,tzinfo=dt.timezone.utc)
            eu=[["NameAlias_wholeName"]]+[[f"EU Example Restricted Name {i:02d}"] for i in range(30)]
            ofac=[[str(i),f"OFAC Restricted Sample {i:02d}", "",""] for i in range(100,130)]
            self._make_feed(root,"EU_FSF",eu,now)
            self._make_feed(root,"OFAC_SDN",ofac,now)
            hits=screen([{"legal_name":"OFAC Restricted Sample 104"}],root,now=now)
            self.assertEqual(hits["status"],"POTENTIAL_MATCH_REVIEW_REQUIRED")
            self.assertGreaterEqual(len(hits["matches"]),1)
            self.assertFalse(hits["export_or_transaction_release"])
            clean=screen([{"legal_name":"Nonmatching Example Trading 735"}],root,now=now)
            self.assertEqual(clean["status"],"NO_NAME_MATCH_NOT_CLEARED")
            self.assertTrue(clean["not_a_sanctions_clearance"])
            stale=screen([{"legal_name":"A Buyer"}],root,now=now+dt.timedelta(hours=74))
            self.assertEqual(stale["status"],"SOURCES_UNAVAILABLE_REVIEW_REQUIRED")
            self.assertTrue(stale["sources_errors"])
            self.assertFalse(source_uri_ok("https://malicious.example/data.csv"))

    def test_website_country_subfolders_share_one_canonical_store(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            inbox=root/"inbox";inbox.mkdir()
            for code in ("UZ","DE"):
                d=inbox/code;d.mkdir()
                (d/"list.csv").write_text(
                    "No.,Equipment description,Qty.\n1,Supplier test equipment,1\n",
                    encoding="utf-8"
                )
            db=root/"private"/"cases.sqlite3"
            r=process(inbox,db,root/"sanctions","UZ",root/"parties")
            self.assertTrue(r["ok"])
            self.assertEqual(r["processed"],2)
            with sqlite3.connect(db) as conn:
                countries=[row[0] for row in conn.execute(
                    "SELECT country FROM procurement_cases ORDER BY country")]
            self.assertEqual(countries,["DE","UZ"])

    def test_hostinger_worker_writes_legal_report_for_existing_case(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            inbox=root/"inbox";inbox.mkdir()
            evidence=root/"evidence";evidence.mkdir()
            inbox.joinpath("medical.csv").write_text(
                "No.,Equipment description,Qty.\n1,MRI 1.5 T,1\n2,Dental chair,1\n",encoding="utf-8")
            db=root/"state"/"cases.sqlite3"
            r=process(inbox,db,root/"empty-feeds","UZ",evidence)
            self.assertTrue(r["ok"])
            self.assertEqual(r["processed"],1)
            self.assertEqual(r["cases"][0]["rows"],2)
            self.assertEqual(r["cases"][0]["legal_state"],"REVIEW_REQUIRED")
            self.assertEqual(r["cases"][0]["sanctions_state"],"SOURCES_UNAVAILABLE_REVIEW_REQUIRED")
            case=read(db,r["cases"][0]["reference"])
            self.assertIsNotNone(case["legal_review"])
            self.assertFalse(case["legal_review"]["sanctions"]["export_or_transaction_release"])
            self.assertEqual(process(inbox,db,root/"empty-feeds","UZ",evidence)["processed"],1)
            with sqlite3.connect(db) as conn:
                self.assertEqual(conn.execute("select count(*) from procurement_cases").fetchone()[0],1)


if __name__=="__main__":
    unittest.main()
