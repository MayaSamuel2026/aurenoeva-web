"""AURENOEVA source-specific lifecycle: no accidental deletion of historical records."""
import json
import sqlite3
import tempfile
import sys
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from catalogue_harvester import db_init, pid, verify_source_listings
from catalogue_publish import make_full_database_export, normalized_snapshot


def when(offset_hours=0):
    return (datetime.now(timezone.utc) + timedelta(hours=offset_hours)).isoformat()


class LifecycleTests(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.row_factory = sqlite3.Row
        db_init(self.db)
        self.key = pid("Acme", "ABC-123")
        self.other = pid("Other", "DEF-456")
        self.a = "https://seller-a.example.com/product/ABC-123"
        self.b = "https://seller-b.example.com/product/ABC-123"
        self.add_product(self.key, "Acme", "ABC-123", self.a)
        self.add_product(self.other, "Other", "DEF-456", "https://other.example.com/part/DEF-456")
        self.add_link(self.key, self.a, "seller-a.example.com")

    def tearDown(self):
        self.db.close()

    def add_product(self, key, manufacturer, model, url):
        self.db.execute("""INSERT INTO products(product_id,manufacturer,model,description,
            source_url,content_sha256,first_seen,last_seen)
            VALUES(?,?,?,?,?,?,?,?)""",
            (key, manufacturer, model, model, url, "content", when(-120), when(-1)))

    def add_link(self, key, url, site):
        self.db.execute("""INSERT INTO vendor_product_evidence
            (website,product_id,source_url,manufacturer,model,evidence_type,last_seen)
            VALUES(?,?,?,?,?,?,?)""",
            (site,key,url,"Acme","ABC-123","STRUCTURED_PRODUCT",when(-1)))

    def state(self, key=None, url=None):
        row=self.db.execute("""SELECT listing_status,missing_checks,first_missing_at
            FROM vendor_product_evidence WHERE product_id=? AND source_url=?""",
            (key or self.key,url or self.a)).fetchone()
        return dict(row)

    def test_two_dated_404_checks_withdraw_one_source(self):
        self.assertEqual(verify_source_listings(self.db,self.a,[],when(0),404),0)
        self.assertEqual(self.state()["listing_status"],"RECHECK_REQUIRED")
        self.assertEqual(verify_source_listings(self.db,self.a,[],when(1),404),0)
        self.assertEqual(self.state()["listing_status"],"RECHECK_REQUIRED")
        self.assertEqual(verify_source_listings(self.db,self.a,[],when(26),404),1)
        self.assertEqual(self.state()["listing_status"],"WITHDRAWN")
        self.assertEqual(self.db.execute("SELECT lifecycle_status FROM products WHERE product_id=?",
            (self.key,)).fetchone()[0],"WITHDRAWN")
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM products").fetchone()[0],2)

    def test_other_seller_stays_active_and_keeps_identity_public(self):
        self.add_link(self.key,self.b,"seller-b.example.com")
        verify_source_listings(self.db,self.a,[],when(0),410)
        verify_source_listings(self.db,self.a,[],when(30),410)
        self.assertEqual(self.db.execute("SELECT lifecycle_status,source_url FROM products WHERE product_id=?",
            (self.key,)).fetchone()[:],("UNVERIFIED",self.b))
        self.assertEqual(self.state(self.key,self.b)["listing_status"],"ACTIVE")

    def test_parser_failure_and_network_outage_are_not_deletions(self):
        verify_source_listings(self.db,self.a,[],when(0),200)
        verify_source_listings(self.db,self.a,[],when(72),200)
        self.assertEqual(self.state()["listing_status"],"ACTIVE")

    def test_changed_product_evidence_requires_separated_checks(self):
        verify_source_listings(self.db,self.a,[self.other],when(0),200)
        self.assertEqual(self.state()["listing_status"],"RECHECK_REQUIRED")
        verify_source_listings(self.db,self.a,[self.other],when(28),200)
        self.assertEqual(self.state()["listing_status"],"WITHDRAWN")

    def test_positive_evidence_recovers_listing(self):
        verify_source_listings(self.db,self.a,[],when(0),404)
        verify_source_listings(self.db,self.a,[],when(25),404)
        verify_source_listings(self.db,self.a,[self.key],when(30),200)
        self.assertEqual(self.state()["listing_status"],"ACTIVE")
        self.assertEqual(self.db.execute("SELECT lifecycle_status FROM products WHERE product_id=?",
            (self.key,)).fetchone()[0],"ACTIVE")

    def test_canonical_database_preserved_public_output_excludes_withdrawn(self):
        verify_source_listings(self.db,self.a,[],when(0),404)
        verify_source_listings(self.db,self.a,[],when(25),404)
        with tempfile.TemporaryDirectory() as folder:
            dbpath=Path(folder)/"catalogue.sqlite3"
            disk=sqlite3.connect(dbpath)
            self.db.backup(disk)
            disk.close()
            dest=Path(folder)/"snapshot.json"
            make_full_database_export(dbpath,dest)
            raw,count=normalized_snapshot(dest)
            doc=json.loads(raw)
            self.assertEqual(count,1)
            self.assertEqual(doc["items"][0]["canonical_id"],self.other)
            self.assertEqual(doc["catalogue_health"]["confirmed_withdrawn_products"],1)
            self.assertEqual(doc["catalogue_health"]["confirmed_withdrawn_links"],1)
            self.assertEqual(self.db.execute("SELECT COUNT(*) FROM products").fetchone()[0],2)


if __name__ == "__main__":
    unittest.main()
