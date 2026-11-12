"""Regression protection for streaming catalogue export and selective publication."""
from pathlib import Path
from unittest.mock import patch, Mock
import json
import sqlite3
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from catalogue_prices import init_offers, product_key
from catalogue_publish import make_full_database_export, normalized_snapshot
import catalogue_publish_selective as selective


def make_database(path: Path, count: int = 805) -> None:
    with sqlite3.connect(path) as db:
        db.execute("""CREATE TABLE products (
            product_id TEXT PRIMARY KEY, manufacturer TEXT, model TEXT,
            description TEXT, source_url TEXT, sector TEXT, category TEXT,
            last_seen TEXT, lifecycle_status TEXT)""")
        init_offers(db)
        for index in range(count):
            model = f"XM-{index:05}"
            db.execute("INSERT INTO products VALUES (?,?,?,?,?,?,?,?,?)", (
                product_key("Example Medical", model), "Example Medical", model,
                "clinical ultrasound device", f"https://manufacturer.example/{model}",
                "medical", "Diagnostic equipment", "2026-11-12T00:00:00Z", "ACTIVE"))
        first_id = product_key("Example Medical", "XM-00000")
        db.execute("""INSERT INTO public_offers
            (product_id,seller_website,seller_name,source_url,currency,amount,observed_at,active)
            VALUES (?,?,?,?,?,?,?,1)""", (
                first_id, "manufacturer.example", "Example Medical",
                "https://manufacturer.example/XM-00000", "EUR", 3499.0,
                "2026-11-12T00:00:00Z"))
        db.commit()


class SelectivePublicationTests(unittest.TestCase):
    def test_streaming_preserves_every_identity_and_order(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            db_path = base / "catalogue.sqlite3"
            destination = base / "catalogue.json"
            make_database(db_path)
            summary = make_full_database_export(db_path, destination)
            self.assertEqual(summary, {"db_products": 805, "exported_products": 805})
            body = json.loads(destination.read_text())
            self.assertEqual(len(body["items"]), 805)
            self.assertEqual(len(set(row["canonical_id"] for row in body["items"])), 805)
            self.assertEqual([row["model"] for row in body["items"]],
                             sorted(row["model"] for row in body["items"]))
            self.assertEqual(body["items"][0]["price_status"], "PUBLIC_OFFER")
            self.assertEqual(body["items"][0]["public_offers"][0]["amount"], 3499.0)
            self.assertEqual(body["source_catalogue_stats"]["db_products"], 805)

    def test_source_change_forces_retry_and_unchanged_catalogue_skips(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            db_path = base / "catalogue.sqlite3"
            publisher = base / "catalogue_publish.py"
            marker = base / "watermark.json"
            make_database(db_path, count=1)
            publisher.write_text("pass\n")
            receipt = base / "catalogue-products-pushed.sha256"
            def acknowledge(*args, **kwargs):
                receipt.write_text("f" * 64 + "\n")
                return Mock(returncode=0)
            with patch.object(selective, "resource_reasons", return_value=[]):
                with patch.object(selective.subprocess, "run", side_effect=acknowledge) as invoked:
                    self.assertEqual(selective.run(db_path, base, publisher, marker), 0)
                    self.assertEqual(invoked.call_count, 1)
                    self.assertEqual(selective.run(db_path, base, publisher, marker), 0)
                    self.assertEqual(invoked.call_count, 1)
                    with sqlite3.connect(db_path) as db:
                        db.execute("UPDATE products SET last_seen='2026-11-13T00:00:00Z'")
                        db.commit()
                    self.assertEqual(selective.run(db_path, base, publisher, marker), 0)
                    self.assertEqual(invoked.call_count, 2)

    def test_missing_acknowledgement_never_sets_watermark(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            db_path = base / "catalogue.sqlite3"
            publisher = base / "catalogue_publish.py"
            marker = base / "watermark.json"
            make_database(db_path, count=1)
            publisher.write_text("pass\n")
            with patch.object(selective, "resource_reasons", return_value=[]):
                with patch.object(selective.subprocess, "run", return_value=Mock(returncode=0)):
                    self.assertEqual(selective.run(db_path, base, publisher, marker), 1)
            self.assertFalse(marker.exists())

    def test_marker_freshness_does_not_impose_record_limit(self):
        fingerprint = {"products": 10_000_001, "last_seen": "X",
                       "withdrawn": 0, "offers": [0, "", 0]}
        marker = {"fingerprint": fingerprint, "acknowledged_at": 100.0}
        self.assertFalse(selective.publication_needed(fingerprint, marker, 101.0))
        self.assertTrue(selective.publication_needed(fingerprint, marker, 3*3600 + 100.0))


if __name__ == "__main__":
    unittest.main()
