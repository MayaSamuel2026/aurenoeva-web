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
    def test_ersaal_exact_brand_and_mpn_are_distinct_from_vendor(self):
        url="https://www.ersaal.de/en/product/pepperl-fuchs-071801"
        sample=("<html><title>Pepperl+Fuchs #071801 | Request a quote | ERSAAL</title>"
                "<h1>#071801</h1><p>Manufacturer: Pepperl+Fuchs</p></html>")
        products,links,title=parse_page(sample,url,"","industrial","Sensors / instrumentation")
        self.assertEqual(len(products),1)
        self.assertEqual(products[0]["manufacturer"],"Pepperl+Fuchs")
        self.assertEqual(products[0]["model"],"#071801")
        self.assertEqual(products[0]["identity_evidence"],"SUPPLIER_PART_PAGE")
        self.assertNotEqual(products[0]["manufacturer"],"ERSAAL")

    def test_multibrand_directory_recurses_real_paths(self):
        cfg={"categories":{},"sources":[{"id":"ersaal-test","active":True,
            "manufacturer":"","sector":"industrial","category":"PLC / control modules",
            "seeds":["https://www.ersaal.de/en/kategorien/automation"],
            "follow_paths":["/en/kategorien/","/en/product/"]}]}
        pages={
            "https://www.ersaal.de/en/kategorien/automation":
            '<html><h1>Automation</h1><a href="/en/product/pepperl-fuchs-071801">#071801</a></html>',
            "https://www.ersaal.de/en/product/pepperl-fuchs-071801":
            '<html><title>Pepperl+Fuchs #071801 | Request a quote | ERSAAL</title><h1>#071801</h1></html>'}
        output=run(self.db,cfg,10,30,2,lambda url,kind:pages[url])
        self.assertEqual(output["new_products"],1)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM vendor_product_evidence").fetchone()[0],1)

    def test_labx_manufacturer_directory_crawl(self):
        cfg={"categories":{},"sources":[{"id":"labx-test","active":True,
            "manufacturer":"","sector":"laboratory","category":"General laboratory equipment",
            "seeds":["https://www.labx.com/manufacturers"],
            "follow_paths":["/manufacturers","/manufacturers/"]}]}
        pages={
          "https://www.labx.com/manufacturers":
            '<html><h1>Manufacturer Directory</h1><a href="/manufacturers/hettich">Hettich</a></html>',
          "https://www.labx.com/manufacturers/hettich":
            "<html><title>Hettich laboratory marketplace listings</title><h1>Hettich</h1></html>"}
        output=run(self.db,cfg,10,30,2,lambda url,kind:pages[url])
        self.assertEqual(output["processed"],2)
        self.assertEqual(output["new_products"],0)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM vendor_leads").fetchone()[0],2)

    def test_furuno_detail_model_when_h1_is_generic(self):
        sample=('<html><title>MARINE RADAR FAR-15x8 series | Marine Radar | FURUNO</title>'
                '<h1>MARINE RADAR</h1></html>')
        products,_,_=parse_page(sample,
            "https://www.furuno.com/en/products/radar/far-15x8/index.html",
            "Furuno","marine","Navigation / instrumentation")
        self.assertEqual(len(products),1)
        self.assertIn("FAR-15x8",products[0]["model"])
        self.assertEqual(products[0]["manufacturer"],"Furuno")

    def test_wartsila_engine_specific_model_and_source(self):
        sample='<html><title>Wärtsilä 32 marine engine</title><h1>Wärtsilä 32 marine engine</h1></html>'
        products,_,_=parse_page(sample,
            "https://www.wartsila.com/marine/products/engines-and-generating-sets/wartsila-32",
            "Wärtsilä","marine","Engines & propulsion")
        self.assertEqual(len(products),1)
        self.assertEqual(products[0]["sector"],"marine")
        self.assertIn("32",products[0]["model"])

    def test_all_six_sources_configured_as_reusable_discovery(self):
        cfg=json.loads((Path(__file__).resolve().parents[1]/
           "config/catalogue_harvester.json").read_text())
        sources=cfg["sources"]
        domains={__import__("urllib.parse",fromlist=["urlsplit"]).urlsplit(seed).hostname
                 for entry in sources for seed in entry.get("seeds",[])}
        expected={"www.ersaal.de","www.labx.com","www.agilent.com",
                  "www.phoenixcontact.com","www.furuno.com","www.wartsila.com"}
        self.assertTrue(expected.issubset(domains))
        self.assertTrue(all(any(entry.get("follow_paths") and
                     any(__import__("urllib.parse",fromlist=["urlsplit"]).urlsplit(u).hostname==h
                         for u in entry["seeds"])
                     for entry in sources) for h in ("www.ersaal.de","www.labx.com")))

    def test_oem_follow_regex_discovers_non_generic_product_route(self):
        cfg={"categories":{},"sources":[{"id":"factory","manufacturer":"Example OEM",
            "sector":"industrial","category":"Motors / gearboxes",
            "seeds":["https://www.example.com/portfolio"],
            "follow_regex":r"^/gb/en/a/[0-9]+/?$"}]}
        pages={
            "https://www.example.com/portfolio":
                '<html><h1>Portfolio</h1><a href="/gb/en/a/4405">Part 4405</a></html>',
            "https://www.example.com/gb/en/a/4405":
                '<html><title>Example OEM 4405</title><h1>Example 4405</h1>'
                '<script type="application/ld+json">'
                '{"@type":"Product","brand":"Example OEM","sku":"4405"}'
                '</script></html>'}
        result=run(self.db,cfg,10,30,2,lambda url,kind:pages[url])
        self.assertEqual(result["new_products"],1)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM products").fetchone()[0],1)

    def test_sitemap_expansion_uses_oem_follow_regex(self):
        cfg={"categories":{},"sources":[{"id":"oem-sitemap","manufacturer":"Example OEM",
            "sector":"energy","category":"Switchgear & protection",
            "seeds":[],"sitemap_seeds":["https://www.example.com/sitemap.xml"],
            "follow_regex":r"^/en/item-[0-9]+/?$"}]}
        pages={
          "https://www.example.com/sitemap.xml":
              '<urlset><url><loc>https://www.example.com/en/item-7421</loc></url></urlset>',
          "https://www.example.com/en/item-7421":
              '<html><h1>Item 7421</h1><title>Item 7421</title>'
              '<script type="application/ld+json">'
              '{"@type":"Product","manufacturer":"Example OEM","mpn":"7421"}'
              '</script></html>'}
        result=run(self.db,cfg,10,30,2,lambda url,kind:pages[url])
        self.assertEqual(result["new_products"],1)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM vendor_product_evidence").fetchone()[0],1)

    def test_product_page_is_not_starved_by_broad_search_queue(self):
        # A very small run budget used to spend its entire allowance on
        # category searches, leaving known OEM product links untouched.
        config={"categories":{"medical":[f"Equipment family {i}" for i in range(15)]},
                "sources":[{"id":"known-oem","active":True,"manufacturer":"Mindray",
                  "sector":"medical","category":"Surgical / procedural",
                  "seeds":["https://www.mindray.com/en/products/anesthesia/wato-ex-35"]}]}
        called=[]
        def sample(url,kind):
            called.append(kind)
            if kind=="page":return PRODUCT
            if kind=="search":return BING
            return ""
        result=run(self.db,config,1,20,2,sample)
        self.assertEqual(result["processed"],1)
        self.assertEqual(result["new_products"],1)
        self.assertEqual(called,["page"])

    def test_search_still_executes_when_no_product_pages_are_queued(self):
        config={"categories":{"medical":["Imaging"]},"sources":[]}
        called=[]
        def sample(url,kind):
            called.append(kind)
            return BING if kind=="search" else ""
        run(self.db,config,2,20,2,sample)
        self.assertIn("search",called)

    def test_non_product_page_not_exported(self):
        products,_,_=parse_page("<h1>Our products</h1><title>Our products</title>",
                                "https://www.mindray.com/en/products","Mindray","medical","Imaging")
        self.assertEqual(products,[])
if __name__=="__main__":unittest.main()
