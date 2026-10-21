"""Offline integration checks for AURENOEVA's replacement seven-sector harvester."""
import json,sqlite3,tempfile,sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"tools"))
from catalogue_harvester import db_init,run,export,pid,parse_page,search_links
BING="""<?xml version="1.0"?><rss><channel><item>
<title>WATO EX-35 - Mindray</title>
<link>https://www.mindray.com/en/products/anesthesia/wato-ex-35</link>
</item></channel></rss>"""
PRODUCT="""<html><title>WATO EX-35 | Mindray</title><h1>WATO EX-35</h1>
<script type="application/ld+json">{"@type":"Product","brand":{"name":"Mindray"},
"model":"WATO EX-35","description":"Anaesthesia workstation"}</script></html>"""
class Tests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.db=sqlite3.connect(":memory:");self.db.row_factory=sqlite3.Row;db_init(self.db)
        self.config={"categories":{"medical":["Surgical / procedural"]},"sources":[]}
    def tearDown(self):self.db.close();self.tmp.cleanup()
    def crawler(self,url,kind):
        if kind=="search":return BING
        if kind=="search_ddg":return '<a href="https://www.mindray.com/en/products/anesthesia/wato-ex-35">Mindray</a>'
        if url.endswith("/wato-ex-35"):return PRODUCT
        raise TimeoutError("independent failure")
    def test_search_discovery_to_product_vendor_link(self):
        result=run(self.db,self.config,15,30,4,self.crawler)
        self.assertGreaterEqual(result["new_products"],1)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM products").fetchone()[0],1)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM vendor_product_evidence").fetchone()[0],1)
        exported=export(self.db,Path(self.tmp.name)/"snapshot.json")
        self.assertEqual(exported["products"],1)
        self.assertEqual(exported["vendor_product_links"],1)
        doc=json.loads((Path(self.tmp.name)/"snapshot.json").read_text())
        self.assertEqual(doc["vendor_products"][0]["website"],"www.mindray.com")
        self.assertEqual(doc["vendor_products"][0]["product_id"],pid("Mindray","WATO EX-35"))
    def test_prior_records_survive_new_engine_and_failures(self):
        key=pid("Anthos","A7 PLUS")
        self.db.execute("""INSERT INTO products(product_id,manufacturer,model,description,
            source_url,content_sha256,first_seen,last_seen)
            VALUES(?,?,?,?,?,?,?,?)""",(key,"Anthos","A7 PLUS","Historic product",
            "https://www.anthos.it/en/dental-units/a7plus","sha","2026-09-01","2026-09-01"))
        self.db.commit()
        def failing(url,kind):
            if "bing.com" in url:raise TimeoutError("blocked provider")
            return self.crawler(url,kind)
        result=run(self.db,self.config,15,30,4,failing)
        self.assertGreaterEqual(result["failures"],1)
        self.assertIsNotNone(self.db.execute("SELECT * FROM products WHERE product_id=?",(key,)).fetchone())
        self.assertEqual(self.db.execute("SELECT first_seen FROM products WHERE product_id=?",(key,)).fetchone()[0],"2026-09-01")
        self.assertGreaterEqual(self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0],4)
    def test_legacy_classification_migration(self):
        conn=sqlite3.connect(":memory:")
        conn.execute("""CREATE TABLE products(
          product_id TEXT PRIMARY KEY,manufacturer TEXT NOT NULL,model TEXT NOT NULL,
          description TEXT NOT NULL,source_url TEXT NOT NULL,content_sha256 TEXT NOT NULL,
          first_seen TEXT NOT NULL,last_seen TEXT NOT NULL)""")
        conn.execute("INSERT INTO products VALUES(?,?,?,?,?,?,?,?)",(
            pid("Siemens Healthineers","MAGNETOM Lumina"),"Siemens Healthineers","MAGNETOM Lumina",
            "MRI","https://www.siemens-healthineers.com/products/mri","sha","2026-09-01","2026-09-01"))
        db_init(conn);db_init(conn)
        self.assertEqual(conn.execute("SELECT COUNT(*) FROM products").fetchone()[0],1)
        self.assertIn("sector",[x[1] for x in conn.execute("PRAGMA table_info(products)")])
        conn.close()
    def test_49_live_categories_are_configured(self):
        cfg=json.loads((Path(__file__).resolve().parents[1]/"config/catalogue_harvester.json").read_text())
        self.assertEqual(len(cfg["categories"]),7)
        self.assertEqual(sum(map(len,cfg["categories"].values())),49)
        self.assertGreaterEqual(len(cfg["sources"]),19)
    def test_non_product_page_not_exported(self):
        products,_,_=parse_page("<h1>Our products</h1><title>Our products</title>",
                                "https://www.mindray.com/en/products","Mindray","medical","Imaging")
        self.assertEqual(products,[])
if __name__=="__main__":unittest.main()
