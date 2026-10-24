"""Offline integration checks for AURENOEVA's replacement seven-sector harvester."""
import json,sqlite3,tempfile,sys,unittest
from urllib.parse import parse_qs,urlsplit
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"tools"))
from catalogue_harvester import db_init,run,export,pid,parse_page,search_links,technical_product_relevance,seeds
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
    def test_clear_consumer_noise_is_not_a_technical_product(self):
        self.assertFalse(technical_product_relevance("laboratory","978-3-12-562482-5",
                         "https://de.pons.com/shop/grammatik/978-3-12-562482-5"))
        self.assertFalse(technical_product_relevance("aviation","100397202",
                         "https://www.lidl.de/p/cutting-board/p100397202"))
        self.assertTrue(technical_product_relevance("industrial","6ES7132",
                        "https://www.ersaal.de/en/product/siemens-6es7132"))

    def test_lab_category_follows_product_evidence_not_search_phrase(self):
        cases = [
            ("Fritsch GmbH", "P-5 premium", "Planetary ball mill", "Sample preparation"),
            ("YMC Europe", "YMC SEC (U)HPLC Säulen", "Separation column", "Chromatography / HPLC / GC"),
            ("Sartorius", "Sartocheck 5 Plus", "Filter integrity testing", "General laboratory equipment"),
            ("IKA", "INC 125 FS", "Incubation chamber", "Incubation / ovens / environmental"),
        ]
        for maker, model, desc, expected in cases:
            page = "<html><title>"+desc+"</title><meta name='description' content='"+desc+"'>"
            page += '<script type="application/ld+json">'+json.dumps({
                "@type":"Product","brand":{"name":maker},"model":model})+"</script></html>"
            parts,_,_=parse_page(page,"https://example.com/product/123",maker,
                                  "laboratory","Chromatography / HPLC / GC")
            self.assertEqual(parts[0]["category"],expected,model)

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

    def test_every_configured_model_hint_seeds_exact_part_search(self):
        # Catch source configuration that exists on disk but is ignored by V4.
        cfg=json.loads((Path(__file__).resolve().parents[1]/
            "config/catalogue_harvester.json").read_text())
        only_sources={"categories":{},"sources":cfg["sources"]}
        seeds(self.db,only_sources)
        queries={parse_qs(urlsplit(row["url"]).query).get("q",[""])[0]
                 for row in self.db.execute(
                     "SELECT url FROM harvest_queue WHERE kind='search'")}
        checked=0
        for source in cfg["sources"]:
            if not source.get("active",True):continue
            for part in set(source.get("model_hints",{}).values()):
                expected=(f'"{source["manufacturer"]}" "{part}" '
                          "manufacturer product datasheet part number")
                self.assertIn(expected,queries,source["id"])
                checked+=1
        self.assertGreater(checked,10)

    def test_manufacturer_follow_path_discovers_nonstandard_model_url(self):
        cfg={"categories":{},"sources":[{
            "id":"nonstandard-oem","manufacturer":"Example OEM",
            "sector":"industrial","category":"Sensors / instrumentation",
            "seeds":["https://www.example.com/overview"],
            "follow_paths":["/gb/en/a/"]}]}
        pages={
            "https://www.example.com/overview":
                '<html><a href="/gb/en/a/AB123">AB123</a></html>',
            "https://www.example.com/gb/en/a/AB123":
                '<html><script type="application/ld+json">'
                '{"@type":"Product","brand":"Example OEM","mpn":"AB123"}'
                '</script></html>'}
        output=run(self.db,cfg,4,30,1,lambda url,kind:pages[url])
        self.assertEqual(output["new_products"],1)
        self.assertEqual(self.db.execute(
            "SELECT model FROM products").fetchone()[0],"AB123")

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

    def test_high_yield_manufacturer_sitemaps_are_seeded(self):
        cfg=json.loads((Path(__file__).resolve().parents[1]/
            "config/catalogue_harvester.json").read_text())
        discovered={url for src in cfg["sources"]
                    for url in src.get("sitemap_seeds",[])}
        self.assertTrue({
            "https://www.phoenixcontact.com/crisp_sitemap_index.xml",
            "https://www.keysight.com/aem-sitemap-index.xml",
            "https://www.agilent.com/sitemap.xml",
            "https://www.tek.com/en/sitemap.xml",
            "https://www.ifm.com/us/en/sitemap.xml",
        }.issubset(discovered))

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

    def test_new_products_trigger_automatic_mid_run_publication(self):
        cfg={"categories":{},"sources":[{"id":"mindray","active":True,
            "manufacturer":"Mindray","sector":"medical",
            "category":"Surgical / procedural",
            "seeds":["https://www.mindray.com/en/products/anesthesia/wato-ex-35"]}]}
        calls=[]
        output=run(self.db,cfg,1,20,1,self.crawler,
                   progress=lambda: calls.append("publish") or True,
                   progress_interval=0)
        self.assertEqual(output["new_products"],1)
        self.assertEqual(calls,["publish"])
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM products").fetchone()[0],1)

    def test_failed_progress_publication_keeps_database_and_crawling(self):
        cfg={"categories":{},"sources":[{"id":"mindray","active":True,
            "manufacturer":"Mindray","sector":"medical",
            "category":"Surgical / procedural",
            "seeds":["https://www.mindray.com/en/products/anesthesia/wato-ex-35"]}]}
        def broken_publisher():
            raise TimeoutError("site temporarily unavailable")
        output=run(self.db,cfg,1,20,1,self.crawler,
                   progress=broken_publisher,progress_interval=0)
        self.assertEqual(output["processed"],1)
        self.assertEqual(output["new_products"],1)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM products").fetchone()[0],1)

    def test_no_new_products_skips_progress_upload(self):
        called=[]
        cfg={"categories":{},"sources":[{"id":"directory","active":True,
             "manufacturer":"","sector":"industrial","category":"Electronics",
             "seeds":["https://example.com/catalog"]}]}
        result=run(self.db,cfg,1,20,1,
                   lambda url,kind: "<html><h1>Catalogue</h1></html>",
                   progress=lambda: called.append("upload") or True,
                   progress_interval=0)
        self.assertEqual(result["new_products"],0)
        self.assertEqual(called,[])

    def test_non_product_page_not_exported(self):
        products,_,_=parse_page("<h1>Our products</h1><title>Our products</title>",
                                "https://www.mindray.com/en/products","Mindray","medical","Imaging")
        self.assertEqual(products,[])

    def test_onergys_german_oem_part_keeps_maker_and_retailer_separate(self):
        page=('<html><title>Ölfiltereinsatz MWM 12284663 Original</title>'
              '<h1>Ölfiltereinsatz MWM 12284663 Original</h1>'
              '<p>Artikelnummer: 30002123</p><p>Hersteller: MWM</p></html>')
        url="https://www.onergys.de/motorentechnik/filter/oelfilter/mwm-12284663.html"
        parts,_,_=parse_page(page,url,"","energy","Turbine / plant components")
        self.assertEqual(len(parts),1)
        self.assertEqual(parts[0]["manufacturer"],"MWM")
        self.assertEqual(parts[0]["model"],"12284663")
        self.assertIn("30002123",parts[0]["description"])
        self.assertEqual(parts[0]["identity_evidence"],"SUPPLIER_PART_PAGE")

    def test_onergys_own_product_is_not_misattributed_to_engine_oem(self):
        page=('<html><h1>Ventildeckeldichtung ONE4245 - MAN 51039050135</h1>'
              '<p>Artikelnummer: 30004245</p><p>Hersteller: ONERGYS</p></html>')
        url="https://www.onergys.de/motorentechnik/dichtungen/one4245.html"
        parts,_,_=parse_page(page,url,"","energy","Turbine / plant components")
        self.assertEqual([(x["manufacturer"],x["model"]) for x in parts],
                         [("ONERGYS","ONE4245")])
        self.assertIn("MAN 51039050135",parts[0]["description"])

    def test_powergaskets_detail_uses_own_part_not_oem_crossreference(self):
        page=('<html><title>KUBOTA CYLINDER HEAD GASKET</title><h1>Products</h1>'
              '<ul><li><p>Power Gaskets No</p><p>80.24.155</p></li>'
              '<li><p>Oem No</p><p>16394-03310,1639403310</p></li>'
              '<li><p>Product Group</p><p>KUBOTA CYLINDER HEAD GASKET</p></li>'
              '<li><p>Application</p><p>V1505 / 4D78 - MLS</p></li></ul>'
              '<span>Add to Cart</span></html>')
        url="https://www.powergaskets.com/en/1413/cylinder-head-gasket/16394-03310.html"
        parts,_,_=parse_page(page,url,"","industrial","Machine / process equipment")
        self.assertEqual(len(parts),1)
        self.assertEqual(parts[0]["manufacturer"],"Power Gaskets")
        self.assertEqual(parts[0]["model"],"80.24.155")
        self.assertIn("16394-03310",parts[0]["description"])
        self.assertEqual(parts[0]["identity_evidence"],"MANUFACTURER_PART_PAGE")
        listing=page.replace("<h1>Products</h1>","<h1>All Brands</h1>")
        self.assertEqual(parse_page(listing,
            "https://www.powergaskets.com/en/all-brands/gaskets/0/0/1.php",
            "","industrial","Machine / process equipment")[0],[])

    def test_two_requested_sources_are_seeded_for_same_existing_crawler(self):
        config=json.loads((Path(__file__).resolve().parents[1]/
            "config/catalogue_harvester.json").read_text())
        entries={src["id"]:src for src in config["sources"]}
        self.assertIn("onergys-de-spare-parts",entries)
        self.assertIn("power-gaskets-contasan",entries)
        self.assertIn("https://www.onergys.de/",entries["onergys-de-spare-parts"]["seeds"])
        self.assertIn("www.onergys.com",entries["onergys-de-spare-parts"]["allowed_hosts"])
        self.assertTrue(any("/en/engine-technology/" in u for u in
                            entries["onergys-de-spare-parts"]["seeds"]))
        self.assertEqual(entries["power-gaskets-contasan"]["role"],"manufacturer")
        self.assertTrue(all(x.startswith("https://www.powergaskets.com/en/")
                     for x in entries["power-gaskets-contasan"]["seeds"]))
        self.assertFalse(any("gf-power.net" in x for x in
                          entries["power-gaskets-contasan"]["seeds"]))


    def test_approved_catalogue_seeds_are_not_starved_by_old_search_backlog(self):
        from catalogue_harvester import enqueue
        cfg={"categories":{},"sources":[{
            "id":"power-gaskets-test","active":True,"priority":True,
            "manufacturer":"","allowed_hosts":["www.powergaskets.com"],
            "sector":"industrial","category":"Machine / process equipment",
            "seeds":["https://www.powergaskets.com/en/1413/cylinder-head-gasket/16394-03310.html"]
        }]}
        # Simulate a previously queued ordinary source at the same depth.
        old="https://www.example.com/product/old-100"
        enqueue(self.db,old,"page","industrial","Machine / process equipment")
        self.db.commit()
        called=[]
        def retrieve(url,kind):
            called.append(url)
            if "powergaskets.com" in url:
                return ('<html><h1>Products</h1>'
                        '<p>Power Gaskets No</p><p>80.24.155</p>'
                        '<p>Oem No</p><p>16394-03310</p>'
                        '<p>Product Group</p><p>KUBOTA Cylinder Head Gasket</p>'
                        '<p>Application</p><p>V1505</p>Add to Cart</html>')
            return "<html><title>Old listing</title></html>"
        result=run(self.db,cfg,1,30,1,retrieve)
        self.assertEqual(result["new_products"],1)
        self.assertEqual(called[0],cfg["sources"][0]["seeds"][0])

if __name__=="__main__":unittest.main()
