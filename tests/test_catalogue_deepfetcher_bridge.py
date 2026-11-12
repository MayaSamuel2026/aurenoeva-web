"""DeepFetcher source candidates become pending URLs, never verified products."""
import io
import json
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
import catalogue_harvester as harvester


class DeepFetcherCandidateBridgeTests(unittest.TestCase):
    def test_cursored_private_source_feed_is_non_destructive(self):
        with tempfile.TemporaryDirectory() as folder:
            token = Path(folder) / "catalogue-sync-token"
            token.write_text("a" * 64)
            token.chmod(0o600)
            db = sqlite3.connect(":memory:")
            db.row_factory = sqlite3.Row
            db.executescript("""
                CREATE TABLE harvest_queue(url TEXT PRIMARY KEY,kind TEXT,sector TEXT,
                    category TEXT,maker TEXT,depth INTEGER,failures INTEGER DEFAULT 0,
                    due TEXT,last_error TEXT DEFAULT '');
                CREATE TABLE harvest_source_cursor(host TEXT PRIMARY KEY,last_url TEXT);
                CREATE TABLE products(product_id TEXT PRIMARY KEY);
            """)
            cursor = "2026-11-12T10:00:00Z|web-aaaaaaaaaaaaaaaa"
            responses = [
                {"ok": True, "source": "DEEPFETCHER_SOURCE_CANDIDATES",
                 "seeds": [{"url": "https://supplier.example/product/XM123",
                            "source": "DEEPFETCHER_SOURCE_CANDIDATE"}],
                 "next_before": cursor},
                {"ok": True, "source": "DEEPFETCHER_SOURCE_CANDIDATES",
                 "seeds": [{"url": "https://another.example/product/ABC",
                            "source": "DEEPFETCHER_SOURCE_CANDIDATE"}],
                 "next_before": None},
            ]
            def deliver(request, timeout=35):
                self.assertTrue(request.get_header("X-aurenoeva-sync-token"))
                return io.BytesIO(json.dumps(responses.pop(0)).encode("utf-8"))
            with patch.object(harvester, "urlopen", side_effect=deliver):
                first = harvester.import_deepfetcher_source_evidence(db, token)
                self.assertTrue(first["has_more"])
                second = harvester.import_deepfetcher_source_evidence(db, token)
                self.assertFalse(second["has_more"])
            self.assertEqual(db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0], 2)
            self.assertEqual(db.execute("SELECT COUNT(*) FROM products").fetchone()[0], 0)
            self.assertEqual(db.execute("SELECT last_url FROM harvest_source_cursor").fetchone()[0], "")
            db.close()

    def test_forged_cursor_never_commits_source_queue(self):
        with tempfile.TemporaryDirectory() as folder:
            token = Path(folder) / "token"
            token.write_text("a" * 64)
            token.chmod(0o600)
            db = sqlite3.connect(":memory:")
            db.row_factory = sqlite3.Row
            db.executescript("""
                CREATE TABLE harvest_queue(url TEXT PRIMARY KEY,kind TEXT,sector TEXT,
                    category TEXT,maker TEXT,depth INTEGER,failures INTEGER DEFAULT 0,
                    due TEXT,last_error TEXT DEFAULT '');
                CREATE TABLE harvest_source_cursor(host TEXT PRIMARY KEY,last_url TEXT);
            """)
            response = {"ok": True,"source": "DEEPFETCHER_SOURCE_CANDIDATES",
                        "seeds": [{"url": "https://supplier.example/product/model"}],
                        "next_before": "../../bad"}
            with patch.object(harvester, "urlopen",
                              return_value=io.BytesIO(json.dumps(response).encode())):
                with self.assertRaises(ValueError):
                    harvester.import_deepfetcher_source_evidence(db, token)
            # Caller owns rollback on failure. At least cursor never advances.
            self.assertIsNone(db.execute("SELECT last_url FROM harvest_source_cursor").fetchone())
            db.close()


if __name__ == "__main__":
    unittest.main()
