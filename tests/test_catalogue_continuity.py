"""Regression checks for cumulative product persistence, classification and existing IDs."""
import datetime as dt
import json
import sqlite3
import tempfile
import unittest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from catalogue_worker import init_db, run_worker, export_public, status, product_id

WHEN = dt.datetime(2026, 10, 21, 15, 0, tzinfo=dt.timezone.utc)


class CatalogueContinuityTests(unittest.TestCase):
    def test_existing_sqlite_upgraded_without_losing_historical_product(self):
        with sqlite3.connect(":memory:") as conn:
            conn.row_factory = sqlite3.Row
            conn.execute("""CREATE TABLE products (
                product_id TEXT PRIMARY KEY, manufacturer TEXT NOT NULL,
                model TEXT NOT NULL, description TEXT NOT NULL,
                source_url TEXT NOT NULL, content_sha256 TEXT NOT NULL,
                first_seen TEXT NOT NULL, last_seen TEXT NOT NULL,
                identity_evidence TEXT NOT NULL DEFAULT 'OFFICIAL_MANUFACTURER_PAGE',
                lifecycle_status TEXT NOT NULL DEFAULT 'UNVERIFIED',
                UNIQUE(manufacturer, model))""")
            key = product_id("Mindray", "WATO EX-35")
            conn.execute("""INSERT INTO products
                (product_id,manufacturer,model,description,source_url,content_sha256,first_seen,last_seen)
                VALUES (?,?,?,?,?,?,?,?)""",
                (key, "Mindray", "WATO EX-35", "Old record",
                 "https://example.org/product/wato", "sha", "2026-10-01", "2026-10-01"))
            init_db(conn)
            init_db(conn)  # migration itself must be idempotent
            item = conn.execute("SELECT * FROM products").fetchone()
            self.assertEqual(item["product_id"], key)
            self.assertEqual(item["first_seen"], "2026-10-01")
            self.assertEqual(item["sector"], "")
            self.assertEqual(item["category"], "")

    def test_crawler_classifies_and_keeps_old_products_after_another_scan(self):
        with tempfile.TemporaryDirectory() as tmp, sqlite3.connect(":memory:") as conn:
            conn.row_factory = sqlite3.Row
            init_db(conn)
            key = product_id("Mindray", "WATO EX-35")
            conn.execute("""INSERT INTO products
                (product_id,manufacturer,model,description,source_url,content_sha256,first_seen,last_seen)
                VALUES (?,?,?,?,?,?,?,?)""",
                (key, "Mindray", "WATO EX-35", "Historic",
                 "https://example.org/products/wato", "old", "2026-10-01", "2026-10-01"))
            sources = {"marine": {
                "id": "marine", "manufacturer": "Garmin",
                "hosts": {"example.org"}, "sector": "marine",
                "category": "Navigation / instrumentation",
                "seeds": ["https://example.org/products/gpsmap"], "model_hint": "GPSMAP 9217xsv",
                "refresh_hours": 72}}
            result = run_worker(conn, sources, now=WHEN, fetch=lambda *_: (
                "<title>GPSMAP 9217xsv</title><h1>GPSMAP 9217xsv</h1>", 200))
            self.assertEqual(result["new_products"], 1)
            self.assertEqual(status(conn)["products"], 2)
            self.assertEqual(status(conn)["by_sector"]["marine"], 1)
            export = Path(tmp) / "catalogue.json"
            self.assertEqual(export_public(conn, export), 2)
            rows = json.loads(export.read_text())["items"]
            self.assertEqual(next(r for r in rows if r["manufacturer"] == "Garmin")["sector"], "marine")
            self.assertEqual(next(r for r in rows if r["manufacturer"] == "Garmin")["category"],
                             "Navigation / instrumentation")
            self.assertTrue(any(r["canonical_id"] == key for r in rows))

    def test_existing_record_backfilled_using_original_task_url(self):
        with sqlite3.connect(":memory:") as conn:
            conn.row_factory = sqlite3.Row
            init_db(conn)
            key = product_id("Tektronix", "MSO64B")
            url = "https://example.org/en/products/oscilloscopes/mso64b"
            conn.execute("""INSERT INTO products
                (product_id,manufacturer,model,description,source_url,content_sha256,first_seen,last_seen)
                VALUES (?,?,?,?,?,?,?,?)""",
                (key, "Tektronix", "MSO64B", "Original", url, "hash", "2026-10-01", "2026-10-01"))
            src = {"test": {"id": "test", "manufacturer": "Tektronix",
                            "sector": "test", "category": "Oscilloscopes",
                            "hosts": {"example.org"}, "seeds": [url],
                            "model_hint": "MSO64B", "refresh_hours": 72}}
            run_worker(conn, src, now=WHEN, fetch=lambda *_: (
                "<title>MSO64B</title><h1>MSO64B</h1>", 200))
            row = conn.execute("SELECT * FROM products WHERE product_id=?", (key,)).fetchone()
            self.assertEqual(row["first_seen"], "2026-10-01")
            self.assertEqual(row["sector"], "test")
            self.assertEqual(row["category"], "Oscilloscopes")
            self.assertEqual(status(conn)["products"], 1)

    def test_configured_sources_cover_all_seven_sectors(self):
        sources = json.loads((Path(__file__).resolve().parents[1] /
                              "config/catalogue_sources.json").read_text())["sources"]
        expected = {"laboratory","test","medical","industrial","energy","marine","aviation"}
        self.assertEqual({s["sector"] for s in sources if s.get("active", True)}, expected)
        self.assertEqual(len({s["id"] for s in sources}), len(sources))
        for s in sources:
            self.assertTrue(s.get("allowed_hosts"))
            self.assertTrue(s.get("seeds"))
            self.assertTrue(s.get("category"))


if __name__ == "__main__":
    unittest.main()
