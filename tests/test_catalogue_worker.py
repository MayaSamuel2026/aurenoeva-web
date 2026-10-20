"""Tests use synthetic HTML and SQLite, never contact suppliers or customer data."""
import datetime as dt
import json
import sqlite3
import sys
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"tools"))
from catalogue_worker import (check_url,init_db,load_sources,seed,run_worker,import_supplier_inventory,extract,
                              product_id,export_public,status,stamp)

TIME=dt.datetime(2026,10,20,18,0,tzinfo=dt.timezone.utc)
SOURCE={"official":{"id":"official","manufacturer":"Mindray", "hosts":{"example.org"},
                   "seeds":["https://example.org/products/wato-ex35"],"model_hint":"WATO EX-35", "refresh_hours":1}}
HTML="<html><title>WATO EX-35 - Mindray</title><h1>WATO EX-35</h1><p>Anesthesia Machine</p></html>"

class Tests(unittest.TestCase):
  def setUp(self):
    self.tmp=tempfile.TemporaryDirectory()
    self.db=sqlite3.connect(":memory:");self.db.row_factory=sqlite3.Row
    init_db(self.db)
  def tearDown(self):
    self.db.close();self.tmp.cleanup()
  def test_safe_url(self):
    self.assertEqual(check_url("https://example.org/a",{"example.org"},False),"https://example.org/a")
    for url in ["http://example.org","https://localhost/a","https://example.org:8443/a","https://x@example.org/a","https://1.1.1.1/a"]:
      with self.assertRaises(ValueError):check_url(url,{"example.org"},False)
  def test_product_creation_and_idempotence(self):
    f=lambda u,s:(HTML,200)
    one=run_worker(self.db,SOURCE,now=TIME,fetch=f)
    self.assertEqual((one["succeeded"],one["new_products"]),(1,1))
    self.assertEqual(status(self.db)["products"],1)
    inv=self.db.execute("SELECT * FROM inventory").fetchone()
    self.assertIsNone(inv["reported_quantity"])
    self.assertEqual(inv["reported_status"],"UNKNOWN")
    target=Path(self.tmp.name)/"private"/"catalogue.json"
    self.assertEqual(export_public(self.db,target),1)
    exported=json.loads(target.read_text())
    self.assertEqual(exported["items"][0]["canonical_id"],product_id("Mindray","WATO EX-35"))
    self.assertNotIn("inventory",target.read_text())
    self.assertEqual(run_worker(self.db,SOURCE,now=TIME,fetch=f)["processed"],0)
    tomorrow=TIME+dt.timedelta(hours=2)
    again=run_worker(self.db,SOURCE,now=tomorrow,fetch=f)
    self.assertEqual((again["succeeded"],again["new_products"]),(1,0))
  def test_failure_does_not_starve_next_source(self):
    second={**SOURCE["official"],"id":"official2","seeds":["https://example.org/products/other"],"model_hint":"Other"}
    sources={**SOURCE,"official2":second}
    def f(url, source):
      if "wato" in url:raise TimeoutError("timed out")
      return ("<h1>Other</h1><title>Other - Mindray</title>",200)
    outcome=run_worker(self.db,sources,now=TIME,fetch=f)
    self.assertEqual((outcome["processed"],outcome["failed"],outcome["succeeded"]),(2,1,1))
    self.assertEqual(status(self.db)["products"],1)
    self.assertEqual(self.db.execute("SELECT failures FROM tasks WHERE url LIKE '%wato%'").fetchone()[0],1)
  def test_no_false_model_promotion(self):
    outcome=run_worker(self.db,SOURCE,now=TIME,fetch=lambda u,s:("<title>Generic home</title><h1>Catalog</h1>",200))
    self.assertEqual(outcome["new_products"],0)
    self.assertEqual(status(self.db)["products"],0)
  def test_explicit_supplier_stock_does_not_become_verified_inventory(self):
    run_worker(self.db,SOURCE,now=TIME,fetch=lambda u,s:(HTML,200))
    f=Path(self.tmp.name)/"stock.json"
    f.write_text(json.dumps({"schema":"aurenoeva-supplier-stock-report/1",
        "supplier_id":"TEST-SUPPLIER","reported_at":stamp(),
        "items":[{"manufacturer":"Mindray","model":"WATO EX-35","reported_quantity":3,
                  "proof_url":"https://example.org/quote"}]}))
    self.assertEqual(import_supplier_inventory(self.db,f),1)
    row=self.db.execute("SELECT * FROM inventory WHERE supplier_id='TEST-SUPPLIER'").fetchone()
    self.assertEqual(row["reported_status"],"SUPPLIER_REPORTED_IN_STOCK")
    self.assertEqual(row["verification_state"],"REVIEW_REQUIRED")
    dest=Path(self.tmp.name)/"catalogue-public.json"
    export_public(self.db,dest)
    self.assertNotIn("reported_quantity",dest.read_text())
  def test_existing_broad_family_is_not_public_inventory(self):
    # Previously crawled generic headings remain in the audit DB, but
    # must not become a public model or a stock assertion.
    self.db.execute("""INSERT INTO products
       (product_id, manufacturer, model, description, source_url,
        content_sha256,first_seen,last_seen)
       VALUES(?,?,?,?,?,?,?,?)""",
       (product_id("Anthos","Surgical carts"),"Anthos","Surgical carts",
        "Surgical carts","https://www.anthos.it/en/dental-units/cart-chirurgico",
        "abc",stamp(),stamp()))
    target=Path(self.tmp.name)/"public.json"
    self.assertEqual(export_public(self.db,target),0)
    self.assertEqual(status(self.db)["products"],1)

  def test_generic_catalogue_page_does_not_create_product(self):
    sources={"x":{**SOURCE["official"],"id":"x","model_hint":"","seeds":["https://example.org/produkte"]}}
    result=run_worker(self.db,sources,now=TIME,fetch=lambda u,s:("<title>Produkte</title><h1>Produkte</h1>",200))
    self.assertEqual(result["new_products"],0)
  def test_source_loader(self):
    p=Path(self.tmp.name)/"src.json"
    p.write_text(json.dumps({"sources":[{"id":"a","allowed_hosts":["example.org"],"manufacturer":"ABC","seeds":["https://example.org/abc"]}]}))
    self.assertEqual(len(load_sources(p)),1)
  def test_resumes_queue_after_failure(self):
    run_worker(self.db,SOURCE,now=TIME,fetch=lambda u,s:(_ for _ in ()).throw(TimeoutError("timeout")))
    self.assertEqual(run_worker(self.db,SOURCE,now=TIME+dt.timedelta(minutes=1),fetch=lambda u,s:(HTML,200))["processed"],0)
    self.assertEqual(run_worker(self.db,SOURCE,now=TIME+dt.timedelta(minutes=16),fetch=lambda u,s:(HTML,200))["new_products"],1)

if __name__=='__main__': unittest.main()