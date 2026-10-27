"""WP02 public-offer regressions: exact identity, evidence, expiry and publication."""
import json
import sqlite3
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from catalogue_harvester import db_init, pid, parse_page
from catalogue_prices import (
    extract_public_offers, replace_page_offers, attach_offers,
    sanitize_offers, amount_value)
from catalogue_publish import normalized_snapshot, make_full_database_export


class PublicOfferTests(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.row_factory = sqlite3.Row
        db_init(self.db)
        self.url = "https://distributor.example.com/product/abc-123"
        self.now = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
        self.part = {"product_id": pid("Acme", "ABC-123")}
        self.product = {"@type": "Product", "brand": {"name": "Acme"},
                        "mpn": "ABC-123", "name": "Precision module",
                        "offers": {"@type": "Offer", "price": "129.99",
                                   "priceCurrency": "EUR",
                                   "seller": {"name": "Public Distributor"}}}
        self.page = lambda product: ("<html><title>Acme ABC-123</title>"
                                     "<script type='application/ld+json'>"
                                     + json.dumps(product) + "</script></html>")

    def tearDown(self):
        self.db.close()

    def test_price_attaches_only_to_exact_identity_and_public_seller(self):
        sample = self.page(self.product)
        products, _, _ = parse_page(sample, self.url, "", "industrial", "Sensors / instrumentation")
        self.assertEqual(products[0]["product_id"], self.part["product_id"])
        rows = extract_public_offers(sample, self.url, products, self.now)
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["seller_website"], "distributor.example.com")
        self.assertEqual(rows[0]["amount"], 129.99)
        self.assertEqual(rows[0]["seller_name"], "Public Distributor")
        altered = {**self.product, "mpn": "ABC-999"}
        self.assertEqual(extract_public_offers(self.page(altered), self.url, [self.part], self.now), [])

    def test_no_inferred_price_from_prose_or_aggregates(self):
        bad = {**self.product, "offers": {
            "@type": "AggregateOffer", "lowPrice": "1", "priceCurrency": "EUR"}}
        self.assertEqual(extract_public_offers(self.page(bad), self.url, [self.part], self.now), [])
        self.assertEqual(extract_public_offers(
            self.page({**self.product, "offers": []}) + "<p>Price from €999</p>",
            self.url, [self.part], self.now), [])
        for amount in ("0", "Request a quote", "1,000", "999999999"):
            self.assertIsNone(amount_value(amount))
        self.assertEqual(extract_public_offers(
            self.page({**self.product, "offers": {
                "@type": "Offer", "price": "999", "priceCurrency": ""}}),
            self.url, [self.part], self.now), [])

    def test_revisit_withdraws_only_its_own_page_prices(self):
        initial = extract_public_offers(self.page(self.product), self.url, [self.part], self.now)
        other = dict(initial[0], seller_website="competitor.example.com",
                     source_url="https://competitor.example.com/abc-123")
        replace_page_offers(self.db, self.url, [self.part["product_id"]], initial)
        replace_page_offers(self.db, other["source_url"], [self.part["product_id"]], [other])
        replace_page_offers(self.db, self.url, [self.part["product_id"]], [])
        surviving = self.db.execute("SELECT seller_website FROM public_offers WHERE active=1").fetchall()
        self.assertEqual([x[0] for x in surviving], ["competitor.example.com"])

    def test_expiration_and_untrusted_seller_provenance(self):
        record = extract_public_offers(self.page(self.product), self.url, [self.part], self.now)[0]
        self.assertEqual(len(sanitize_offers([record], self.part["product_id"])), 1)
        old = dict(record, observed_at=(datetime.now(timezone.utc)-timedelta(days=15)).isoformat())
        self.assertEqual(sanitize_offers([old], self.part["product_id"]), [])
        spoof = dict(record, seller_website="wrong.example.com")
        self.assertEqual(sanitize_offers([spoof], self.part["product_id"]), [])

    def test_existing_export_keeps_prices_out_of_private_quote_data(self):
        self.db.execute("""INSERT INTO products(product_id,manufacturer,model,description,
            source_url,content_sha256,first_seen,last_seen,sector,category)
            VALUES(?,?,?,?,?,?,?,?,?,?)""",
            (self.part["product_id"], "Acme", "ABC-123", "Precision module",
             self.url, "sha", self.now, self.now, "industrial", "Sensors / instrumentation"))
        offer = extract_public_offers(self.page(self.product), self.url, [self.part], self.now)
        replace_page_offers(self.db, self.url, [self.part["product_id"]], offer)
        self.db.commit()
        with tempfile.TemporaryDirectory() as temp:
            dbpath = Path(temp) / "catalogue.sqlite3"
            disk = sqlite3.connect(dbpath)
            self.db.backup(disk)
            disk.close()
            outfile = Path(temp) / "snapshot.json"
            make_full_database_export(dbpath, outfile)
            doc = json.loads(outfile.read_text())
            self.assertEqual(doc["items"][0]["price_status"], "PUBLIC_OFFER")
            raw, count = normalized_snapshot(outfile)
            item = json.loads(raw)["items"][0]
            self.assertEqual(count, 1)
            self.assertEqual(item["public_offers"][0]["amount"], 129.99)
            self.assertNotIn("seller_email", json.dumps(item))
            # No active public offer => on-request, never a carried-over price.
            self.db.execute("UPDATE public_offers SET active=0")
            self.db.commit()
            destination = sqlite3.connect(dbpath)
            self.db.backup(destination)
            destination.close()
            make_full_database_export(dbpath, outfile)
            raw, count = normalized_snapshot(outfile)
            result = json.loads(raw)["items"][0]
            self.assertEqual(result["price_status"], "ON_REQUEST")
            self.assertEqual(result["public_offers"], [])


if __name__ == "__main__":
    unittest.main()
