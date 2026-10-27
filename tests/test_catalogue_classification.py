"""Offline, source-evidence and compatibility regressions for multi-industry facets."""
import json
import sqlite3
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"tools"))
from catalogue_classification import classify, enrich_rows, init_classifications, store_classifications
from catalogue_harvester import db_init, parse_page, pid
from catalogue_publish import normalized_snapshot

SOURCE="https://example.com/products/industrial-marine-hp20"
PID=pid("Example","HP-20")
BASE={"canonical_id":PID,"manufacturer":"Example","model":"HP-20",
      "description":"Industrial and marine hydraulic pump",
      "sector":"industrial","category":"PLC / control modules",
      "source_ref":SOURCE}

class ClassificationTests(unittest.TestCase):
    def test_one_identity_classified_across_two_industries(self):
        hits=classify(BASE)
        self.assertEqual([(x["industry"],x["category"]) for x in hits],[
            ("industrial","Pneumatics / hydraulics"),
            ("marine","Pumps / fluid systems")])
        self.assertTrue(all(x["evidence_url"]==SOURCE for x in hits))
        self.assertTrue(all(x["evidence_field"]=="description" for x in hits))
        self.assertEqual(hits[1]["context_term"].lower(),"marine")

    def test_query_sector_is_never_cross_industry_evidence(self):
        record={**BASE,"sector":"aviation","description":"Hydraulic pump assembly"}
        self.assertEqual(classify(record),[]) # no aerospace claim from a pump
        misleading={**BASE,"sector":"laboratory",
                    "description":"Medical-grade patient monitor"}
        hits=classify(misleading)
        self.assertEqual([hit["industry"] for hit in hits],["medical"])

    def test_no_claim_without_source_documentation(self):
        self.assertEqual(classify({**BASE,"description":"Industrial marine hydraulic pump",
                                  "source_ref":"http://example.com/product"}),[])
        self.assertEqual(classify({**BASE,"description":"Replacement part HP-20"}),[])

    def test_evidence_table_migrates_and_does_not_touch_prices(self):
        db=sqlite3.connect(":memory:")
        db.row_factory=sqlite3.Row
        db_init(db)
        record={**BASE,"product_id":PID,"source_url":SOURCE}
        db.execute("""INSERT INTO products
            (product_id,manufacturer,model,description,source_url,content_sha256,
             first_seen,last_seen,sector,category) VALUES(?,?,?,?,?,?,?,?,?,?)""",
             (PID,"Example","HP-20",BASE["description"],SOURCE,"sha","past","past","industrial","PLC / control modules"))
        db.execute("""INSERT INTO public_offers(product_id,seller_website,seller_name,source_url,
           currency,amount,observed_at) VALUES(?,?,?,?,?,?,?)""",
           (PID,"example.com","Example",SOURCE,"EUR",100.5,"2026-10-27T08:00:00Z"))
        store_classifications(db,record,"2026-10-27T09:00:00Z")
        store_classifications(db,record,"2026-10-27T09:30:00Z")
        self.assertEqual(db.execute("SELECT COUNT(*) FROM product_classifications").fetchone()[0],2)
        self.assertEqual(db.execute("SELECT amount FROM public_offers").fetchone()[0],100.5)
        self.assertEqual(db.execute("SELECT product_id FROM products").fetchone()[0],PID)
        self.assertEqual(db.execute("SELECT category FROM products").fetchone()[0],"PLC / control modules")
        db.close()

    def test_old_products_auto_enriched_in_existing_publisher(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/"snapshot.json"
            old={**BASE,"public_offers":[{
                "seller_website":"example.com","seller_name":"Example seller",
                "source_url":SOURCE,"currency":"EUR","amount":100.5,
                "observed_at":"2026-10-27T08:00:00Z"}]}
            path.write_text(json.dumps({"schema":"aurenoeva-catalogue/1","items":[old]}))
            body,n=normalized_snapshot(path)
            item=json.loads(body)["items"][0]
            self.assertEqual(n,1)
            self.assertEqual(item["canonical_id"],PID)
            self.assertEqual(item["industries"],["industrial","marine"])
            self.assertEqual(item["classification_status"],"EVIDENCE_MATCHED")
            self.assertEqual(item["public_offers"][0]["amount"],100.5)
            self.assertEqual(item["price_status"],"PUBLIC_OFFER")

    def test_jsonld_description_is_used_when_available(self):
        page=('<html><title>HP-20 | Example</title>'+
              '<script type="application/ld+json">'+json.dumps({
                  "@type":"Product","brand":{"name":"Example"},"model":"HP-20",
                  "description":"Industrial and marine hydraulic pump"})+
              '</script></html>')
        parsed,_,_=parse_page(page,SOURCE,"Example","industrial","PLC / control modules")
        self.assertEqual(len(parsed),1)
        self.assertEqual(parsed[0]["description"],BASE["description"])
        self.assertEqual(len(classify(parsed[0])),2)

if __name__=="__main__":
    unittest.main()
