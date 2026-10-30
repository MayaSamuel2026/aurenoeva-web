"""Read-only crawler observability travels through the existing catalogue sync."""
import json
import sqlite3
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from catalogue_publish import normalized_snapshot, summarize_crawler_execution


class CatalogueTelemetryTests(unittest.TestCase):
    def test_crawler_24h_counts_distinguish_active_publishing_from_discovery(self):
        now = datetime.now(timezone.utc).replace(microsecond=0)
        stamp = lambda t: t.isoformat().replace("+00:00", "Z")
        with tempfile.TemporaryDirectory() as root:
            with sqlite3.connect(":memory:") as db:
                db.row_factory = sqlite3.Row
                db.executescript("""
                    CREATE TABLE harvest_runs (
                        id INTEGER PRIMARY KEY, started TEXT,finished TEXT,
                        pages INTEGER,products INTEGER,vendors INTEGER,failed INTEGER);
                    CREATE TABLE harvest_queue (
                        url TEXT,kind TEXT,due TEXT,failures INTEGER);
                """)
                db.executemany(
                    "INSERT INTO harvest_runs VALUES(?,?,?,?,?,?,?)", [
                        (1,stamp(now-timedelta(hours=27)),stamp(now-timedelta(hours=26)),
                         100,800,12,2),
                        (2,stamp(now-timedelta(hours=2)),stamp(now-timedelta(hours=1)),
                         2400,3,19,120),
                        (3,stamp(now-timedelta(minutes=2)),None,5,0,0,1),
                    ])
                db.executemany(
                    "INSERT INTO harvest_queue VALUES(?,?,?,?)", [
                        ("https://a.example/product/1","page",stamp(now-timedelta(hours=1)),0),
                        ("https://b.example/search","search",stamp(now-timedelta(hours=1)),2),
                        ("https://c.example/product/2","page",stamp(now+timedelta(hours=1)),0),
                    ])
                status=summarize_crawler_execution(db)
            self.assertEqual(status["crawl_24h_completed_runs"],1)
            self.assertEqual(status["crawl_24h_new_products"],3)
            self.assertEqual(status["crawl_24h_pages"],2400)
            self.assertEqual(status["crawl_24h_failures"],120)
            self.assertEqual(status["crawl_last_run_new_products"],3)
            self.assertEqual(status["crawl_queue_total"],3)
            self.assertEqual(status["crawl_queue_due"],2)
            self.assertEqual(status["crawl_queue_retrying"],1)
            self.assertEqual(status["crawl_queue_due_pages"],1)
            self.assertEqual(status["crawl_queue_due_searches"],1)
            self.assertEqual(status["crawl_last_finished_at_utc"],stamp(now-timedelta(hours=1)))
            self.assertEqual(status["crawl_last_started_at_utc"],stamp(now-timedelta(minutes=2)))
            source=Path(root)/"catalogue.json"
            source.write_text(json.dumps({
                "schema":"aurenoeva-catalogue/1",
                "items":[{
                    "canonical_id":"AUR-P-"+"A"*20,"manufacturer":"Mindray",
                    "model":"WATO EX-35","source_ref":"https://www.mindray.com/product",
                    "sector":"medical"
                }],
                "source_catalogue_stats":{"db_products":1,"crawler":status},
                "catalogue_health":{"lifecycle_version":1,"source_links":4}
            }),encoding="utf-8")
            published,count=normalized_snapshot(source)
            self.assertEqual(count,1)
            doc=json.loads(published)
            self.assertEqual(doc["public_stats"]["crawl_24h_new_products"],3)
            self.assertEqual(doc["catalogue_health"]["source_links"],4)
            self.assertRegex(published.decode("utf-8"),
                             r'"public_stats":\{[^{}]{1,3000}\}\})
            self.assertNotIn("https://a.example",published.decode("utf-8"))

    def test_legacy_database_without_runs_is_not_misreported(self):
        with sqlite3.connect(":memory:") as db:
            db.row_factory=sqlite3.Row
            self.assertIsNone(summarize_crawler_execution(db))


if __name__=="__main__":
    unittest.main()
)
            self.assertNotIn("https://a.example",published.decode("utf-8"))

    def test_legacy_database_without_runs_is_not_misreported(self):
        with sqlite3.connect(":memory:") as db:
            db.row_factory=sqlite3.Row
            self.assertIsNone(summarize_crawler_execution(db))


if __name__=="__main__":
    unittest.main()
