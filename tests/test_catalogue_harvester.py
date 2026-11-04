"""Offline integration checks for AURENOEVA's replacement seven-sector harvester."""
import json,sqlite3,tempfile,sys,unittest
from urllib.parse import parse_qs,urlsplit
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"tools"))
from catalogue_harvester import db_init,run,export,pid,parse_page,search_links,technical_product_relevance,seeds,enqueue,source_frontier_pages,suppress_legacy_non_technical_frontier,compact_legacy_unqualified_frontier,retry_wait_seconds
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
    def test_unqualified_worldwide_lead_is_observed_but_never_mirrored(self):
        # A global search result is a single-page lead, not permission to
        # expand arbitrary directory/shop links across an unrelated website.
        # Direct, evidence-backed products on that lead must still be kept.
        url="https://unconfigured.example.com/catalogue"
        self.assertEqual(enqueue(self.db,url,"page","industrial","Machine / process equipment"),1)
        body=('<html><h1>Industrial catalogue</h1>'
              '<script type="application/ld+json">'
              '{"@type":"Product","brand":"Example OEM","mpn":"AX-12345",'
              '"description":"Industrial control unit"}'
              '</script>'
              '<a href="/shop/related-1">Related 1</a>'
              '<a href="/product/related-2">Related 2</a></html>')
        output=run(self.db,{"categories":{},"sources":[]},1,15,1,
                   lambda fetched,kind: body)
        self.assertEqual(output["processed"],1)
        self.assertEqual(output["new_products"],1)
        self.assertEqual(output["new_urls"],0)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0],1)
        self.assertEqual(tuple(self.db.execute(
            "SELECT manufacturer,model FROM products").fetchone()),
            ("Example OEM","AX-12345"))

    def test_clear_consumer_noise_is_not_a_technical_product(self):
        self.assertFalse(technical_product_relevance("laboratory","978-3-12-562482-5",
                         "https://de.pons.com/shop/grammatik/978-3-12-562482-5"))
        self.assertFalse(technical_product_relevance("aviation","100397202",
                         "https://www.lidl.de/p/cutting-board/p100397202"))
        self.assertFalse(technical_product_relevance("marine","Pumps FRANCESCA",
                         "https://www.breuninger.com/de/p/pumps-francesca"))
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

    def test_fair_source_frontier_does_not_let_one_supplier_monopolize_a_batch(self):
        for i in range(50):
            enqueue(self.db,f"https://www.ifm.com/us/en/product/PN{i:04d}","page")
        for i in range(8):
            enqueue(self.db,f"https://www.agilent.com/product/1260-{i}","page")
        initial=self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0]
        chosen=set()
        found=source_frontier_pages(self.db,
            ["www.ifm.com","www.agilent.com"],4,chosen,rotation=0)
        from collections import Counter
        from urllib.parse import urlsplit
        by_host=Counter(urlsplit(row["url"]).hostname for row in found)
        self.assertEqual(dict(by_host),{"www.agilent.com":2,"www.ifm.com":2})
        self.assertEqual(len(chosen),4)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0],initial)
        next_batch=source_frontier_pages(self.db,
            ["www.ifm.com","www.agilent.com"],4,chosen,rotation=1)
        self.assertEqual(len(next_batch),4)
        self.assertTrue(set(row["url"] for row in next_batch).isdisjoint(
            set(row["url"] for row in found)))

    def test_detail_pages_win_over_category_frontier_without_losing_work(self):
        host="www.supplier.example"
        for i in range(30):
            enqueue(self.db,f"https://{host}/categories/section-{i:03d}","page")
        for i in range(3):
            enqueue(self.db,f"https://{host}/product/model-{i:03d}","page")
        before=self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0]
        chosen=set()
        first=source_frontier_pages(self.db,[host],2,chosen,rotation=0)
        self.assertEqual(len(first),2)
        self.assertTrue(all("/product/" in row["url"] for row in first))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0],before)
        self.assertEqual(self.db.execute(
            "SELECT COUNT(*) FROM harvest_queue WHERE url LIKE ?",
            (f"https://{host}/categories/%",)).fetchone()[0],30)

    def test_supplier_fair_scheduler_does_not_yield_to_old_due_backlog(self):
        # Production once had millions of old due URLs, many concentrated
        # on one supplier. Preserve discovery while allocating most requests
        # to distinct configured suppliers with product-detail leads.
        old="2026-10-01T00:00:00Z"
        for i in range(120):
            self.db.execute("""INSERT INTO harvest_queue(url,kind,depth,due)
                VALUES(?,'page',0,?)""",
                (f"https://archive.example.com/catalogue/old-{i:04d}",old))
        cfg={"categories":{"medical":["Diagnostic equipment"]},"sources":[
            {"id":f"oem-{i}","active":True,"manufacturer":f"OEM-{i}",
             "sector":"industrial","category":"Sensors / instrumentation",
             "allowed_hosts":[f"www.supplier{i}.com"],
             "seeds":[f"https://www.supplier{i}.com/product/part-{i}"]}
            for i in range(24)]}
        called=[]
        def offline_page(url,kind):
            called.append((url,kind))
            return "<html><title>Catalogue</title></html>"
        result=run(self.db,cfg,max_pages=30,max_seconds=30,
                   workers=10,fetcher=offline_page)
        source_pages={url for url,kind in called
                      if kind=="page" and url.startswith("https://www.supplier")}
        self.assertEqual(result["processed"],30)
        self.assertEqual(len(source_pages),24)
        self.assertTrue(any(kind.startswith("search") for _,kind in called))
        self.assertEqual(self.db.execute(
            "SELECT COUNT(*) FROM products").fetchone()[0],0)
        # Unprocessed worldwide leads remain durable, not discarded.
        self.assertEqual(self.db.execute(
            "SELECT COUNT(*) FROM harvest_queue WHERE url LIKE 'https://archive.example.com/%'"
            ).fetchone()[0],120)

    def test_consumer_search_noise_is_not_enqueued(self):
        before=self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0]
        self.assertEqual(enqueue(self.db,"https://www.langenscheidt.com/de/woerterbuch","page"),0)
        self.assertEqual(enqueue(self.db,"https://www.limango.de/shop/shoes/123","page"),0)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0],before)
        self.assertEqual(enqueue(self.db,"https://www.agilent.com/en/product/1260","page"),1)

    def test_legacy_non_technical_urls_deferred_once_without_deletion(self):
        from datetime import datetime,timezone,timedelta
        past="2026-10-01T00:00:00Z"
        urls=["https://www.limango.de/shop/123",
              "https://de.pons.com/shop/456",
              "https://www.ifm.com/us/en/product/PN1234"]
        for url in urls:
            self.db.execute("INSERT INTO harvest_queue(url,kind,due) VALUES(?,'page',?)",
                            (url,past))
        self.db.commit()
        now=datetime(2026,11,1,tzinfo=timezone.utc)
        n=suppress_legacy_non_technical_frontier(self.db,now)
        self.assertEqual(n,2)
        self.assertEqual(suppress_legacy_non_technical_frontier(self.db,now),0)
        rows={r["url"]:r["due"] for r in self.db.execute("SELECT url,due FROM harvest_queue")}
        self.assertEqual(len(rows),3)
        self.assertGreater(rows[urls[0]],"2035")
        self.assertGreater(rows[urls[1]],"2035")
        self.assertEqual(rows[urls[2]],past)

    def test_legacy_unqualified_frontier_compacts_only_orphan_work(self):
        # Only anonymous recursive work from unconfigured sites can disappear.
        # Configured catalogues, direct leads, named OEM tasks and actual
        # product evidence are durable across bounded compaction passes.
        rows=[
            ("https://unqualified.example.com/shop/orphan-1",1,""),
            ("https://unqualified.example.com/product/orphan-2",2,""),
            ("https://unqualified.example.com/product/direct",0,""),
            ("https://unqualified.example.com/product/oem",2,"Example OEM"),
            ("https://qualified.example.com/shop/catalogue",2,""),
            ("https://unqualified.example.com/product/proven",2,""),
        ]
        for url,depth,maker in rows:
            self.db.execute("""INSERT INTO harvest_queue
                (url,kind,sector,depth,maker,due) VALUES(?,'page','industrial',?,?,?)""",
                (url,depth,maker,"2026-11-01T00:00:00Z"))
        proof_url=rows[-1][0]
        self.db.execute("""INSERT INTO vendor_product_evidence
            (website,product_id,source_url,manufacturer,model,sector,
             category,evidence_type,last_seen) VALUES(?,?,?,?,?,?,?,?,?)""",
            ("unqualified.example.com","AUR-P-EXAMPLE",proof_url,
             "Example OEM","EX123","industrial","Components",
             "STRUCTURED_PRODUCT","2026-11-01T00:00:00Z"))
        self.db.commit()
        cfg={"sources":[{"id":"qualified","allowed_hosts":["qualified.example.com"],
                         "seeds":["https://qualified.example.com/shop/catalogue"]}]}
        removed=0;scanned=0;complete=False
        for _ in range(8):
            result=compact_legacy_unqualified_frontier(self.db,cfg,limit=2)
            removed+=result["removed"];scanned+=result["scanned"]
            if result["complete"]:
                complete=True
                break
        self.assertTrue(complete)
        self.assertEqual((scanned,removed),(6,2))
        kept={row["url"] for row in self.db.execute("SELECT url FROM harvest_queue")}
        self.assertEqual(kept,{row[0] for row in rows[2:]})
        self.assertEqual(self.db.execute(
            "SELECT COUNT(*) FROM vendor_product_evidence").fetchone()[0],1)
        self.assertEqual(compact_legacy_unqualified_frontier(self.db,cfg),
                         {"scanned":0,"removed":0,"complete":True})
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM products").fetchone()[0],0)

    def test_due_queue_selection_uses_existing_index_without_mass_sort(self):
        # The production queue has millions of overdue page URLs. A due-first
        # index does NOT efficiently find the rarer search/sitemap kinds.
        cases=(
            ("SELECT * FROM harvest_queue WHERE due<=? AND kind=? "
             "ORDER BY due LIMIT ?",
             ("2026-11-02T20:00:00Z","page",12),"harvest_queue_kind_due"),
            ("SELECT * FROM harvest_queue WHERE due<=? AND kind=? "
             "ORDER BY due LIMIT ?",
             ("2026-11-02T20:00:00Z","search",12),"harvest_queue_kind_due"),
            ("SELECT * FROM harvest_queue WHERE due<=? "
             "ORDER BY due LIMIT ?",
             ("2026-11-02T20:00:00Z",12),"harvest_queue_due"),
        )
        for query,params,index in cases:
            plan=[str(row[3]) for row in self.db.execute(
                "EXPLAIN QUERY PLAN "+query,params)]
            self.assertFalse(any("TEMP B-TREE FOR ORDER BY" in step for step in plan),plan)
            self.assertTrue(any(index in step for step in plan),(index,plan))
        source=(Path(__file__).resolve().parents[1]/"tools/catalogue_harvester.py").read_text()
        self.assertNotIn("ORDER BY failures,depth,due",source)
        # Explicit priority suppliers must use the indexed host cursor, not
        # OR-of-URL-LIKE conditions across the enormous due-date backlog.
        self.assertIn("source_frontier_pages(\n                    db,priority_hosts",source)
        self.assertNotIn('host_where=" OR ".join("url LIKE ?" for _ in priority_hosts)',source)

    def test_denials_and_rate_limits_get_polite_cooldowns(self):
        from urllib.error import HTTPError
        self.assertEqual(retry_wait_seconds(PermissionError("robots_disallowed"),0),14*86400)
        self.assertEqual(retry_wait_seconds(PermissionError("robots_denied"),2),14*86400)
        self.assertEqual(retry_wait_seconds(HTTPError("https://example.com",403,"",{},None),0),72*3600)
        self.assertEqual(retry_wait_seconds(HTTPError("https://example.com",429,"",{},None),0),24*3600)
        self.assertEqual(retry_wait_seconds(TimeoutError("temporary"),0),900)
        self.assertEqual(retry_wait_seconds(TimeoutError("temporary"),7),8*3600)

    def test_robots_disallowed_page_is_retried_after_two_weeks(self):
        from datetime import datetime,timezone,timedelta
        url="https://www.ifm.com/us/en/product/PN9999"
        cfg={"categories":{},"sources":[{"id":"ifm","active":True,
             "allowed_hosts":["www.ifm.com"],"seeds":[url]}]}
        result=run(self.db,cfg,1,15,1,
                   lambda url,kind: (_ for _ in ()).throw(PermissionError("robots_disallowed")))
        self.assertEqual(result["failures"],1)
        self.assertEqual(result["new_products"],0)
        stored=self.db.execute("SELECT due,failures FROM harvest_queue WHERE url=?",(url,)).fetchone()
        self.assertEqual(stored["failures"],1)
        until=datetime.fromisoformat(stored["due"].replace("Z","+00:00"))
        self.assertGreater(until-datetime.now(timezone.utc),timedelta(days=13))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0],1)

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
    def test_all_homepage_discovery_topics_seed_both_search_providers(self):
        root=Path(__file__).resolve().parents[1]
        cfg=json.loads((root/"config/catalogue_harvester.json").read_text())
        targets=cfg["discovery_targets"]
        self.assertEqual(sum(x["group"]=="industry" for x in targets),12)
        self.assertEqual(sum(x["group"]=="product_family" for x in targets),18)
        self.assertEqual(len(targets),30)
        self.assertEqual(len({x["name"] for x in targets}),30)
        # Every newly illustrated homepage category is an active discovery topic.
        ui=(root/"hotfix/catalogue-discovery/catalogue-discovery.js").read_text()
        for target in targets:
            self.assertIn("['"+target["name"]+"',",ui)
            self.assertEqual(len(target["queries"]),2)
        seeds(self.db,cfg)
        rows=[dict(x) for x in self.db.execute(
            "SELECT url,kind,sector,category FROM harvest_queue WHERE kind IN ('search','search_ddg')")]
        by_phrase={}
        for row in rows:
            phrase=parse_qs(urlsplit(row["url"]).query).get("q",[""])[0]
            by_phrase.setdefault(phrase,[]).append(row)
        for target in targets:
            for phrase in target["queries"]:
                self.assertEqual({r["kind"] for r in by_phrase[phrase]},
                                 {"search","search_ddg"},phrase)
                for row in by_phrase[phrase]:
                    self.assertEqual((row["sector"],row["category"]),("",""),
                         "A discovery keyword is not technical evidence for a product")
        # Seed repeatedly without creating duplicate tasks or resetting retries.
        total=self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0]
        seeds(self.db,cfg)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0],total)

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

    def test_ersaal_german_labelled_supplier_mpns_from_real_page_structure(self):
        sample=('<html><title>ABB 2CDS781001R4352 | ERSAAL</title>'
                '<h1>2CDS781001R4352</h1>'
                '<dl><p><strong>Hersteller</strong><dd>ABB ↗</dd></p>'
                '<p><strong>Teilenummer / MPN</strong>'
                '<dd><code>2CDS781001R4352</code></dd></p></dl></html>')
        url='https://www.ersaal.de/produkt/abb-2cds781001r4352/'
        products,_,_=parse_page(sample,url,'','industrial','PLC / control modules')
        self.assertEqual(len(products),1)
        self.assertEqual(products[0]['manufacturer'],'ABB')
        self.assertEqual(products[0]['model'],'2CDS781001R4352')
        self.assertEqual(products[0]['identity_evidence'],'SUPPLIER_LABELED_MPN')
        unlabelled=sample.replace('Teilenummer / MPN','Unser Team')
        self.assertEqual(parse_page(unlabelled,url,'','industrial','')[0],[])
        mismatch=sample.replace('<h1>2CDS781001R4352</h1>','<h1>Other product</h1>')
        self.assertEqual(parse_page(mismatch,url,'','industrial','')[0],[])

    def test_source_cursor_advances_scanned_window_without_discarding_due_urls(self):
        host='www.industrial.example'
        for i in range(500):
            enqueue(self.db,f'https://{host}/categories/entry-{i:04d}','page')
        selected=set()
        first=source_frontier_pages(self.db,[host],2,selected)
        self.assertEqual(len(first),2)
        last=self.db.execute('SELECT last_url FROM harvest_source_cursor WHERE host=?',
                             (host,)).fetchone()[0]
        self.assertTrue(last.endswith('entry-0383'),last)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM harvest_queue WHERE due<=?',
                                        ('9999',)).fetchone()[0],500)
        second=source_frontier_pages(self.db,[host],2,selected)
        self.assertEqual(len(second),2)
        self.assertTrue(all(int(x['url'][-4:])>=384 for x in second))

    def test_supplier_specific_detail_paths_win_over_generic_category_pages(self):
        import re
        host='www.powergaskets.com'
        enqueue(self.db,f'https://{host}/en/all-brands/gaskets/0/0/1.php','page')
        enqueue(self.db,f'https://{host}/en/1653/repair-kit/991/00100.html','page')
        chosen=source_frontier_pages(self.db,[host],1,set(),
            detail_patterns_by_host={host:[re.compile(
                r'^/en/[0-9]+/(?:[^?#]+/)*[^/?#]+\.html
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

    def test_documented_test_equipment_supplier_covers_real_catalogue_routes(self):
        # These vendor pages have observed model-specific products; do not
        # silently reduce expansion to a single exact-model listing.
        cfg=json.loads((Path(__file__).resolve().parents[1]/
            "config/catalogue_harvester.json").read_text())
        sources={entry["id"]:entry for entry in cfg["sources"]}
        ids=("testequipmentdepot-measurement","testequipmentdepot-laboratory")
        for key in ids:
            item=sources[key]
            self.assertTrue(item["active"])
            self.assertEqual(item["allowed_hosts"],["www.testequipmentdepot.com"])
            self.assertEqual(item["manufacturer"],"")
            self.assertTrue(item["seeds"])
            self.assertTrue(all(url.startswith("https://www.testequipmentdepot.com/")
                                for url in item["seeds"]))
            self.assertTrue(__import__("re").match(item["follow_regex"],
                "/fluke-87-v-true-rms-industrial-digital-multimeter-with-temperature-measurement.html"))
        self.assertIn("oscilloscopes.html",
            " ".join(sources[ids[0]]["seeds"]))
        self.assertIn("lab-equipment-and-supplies.html",
            " ".join(sources[ids[1]]["seeds"]))

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
)]})
        self.assertIn('/repair-kit/',chosen[0]['url'])

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

    def test_documented_test_equipment_supplier_covers_real_catalogue_routes(self):
        # These vendor pages have observed model-specific products; do not
        # silently reduce expansion to a single exact-model listing.
        cfg=json.loads((Path(__file__).resolve().parents[1]/
            "config/catalogue_harvester.json").read_text())
        sources={entry["id"]:entry for entry in cfg["sources"]}
        ids=("testequipmentdepot-measurement","testequipmentdepot-laboratory")
        for key in ids:
            item=sources[key]
            self.assertTrue(item["active"])
            self.assertEqual(item["allowed_hosts"],["www.testequipmentdepot.com"])
            self.assertEqual(item["manufacturer"],"")
            self.assertTrue(item["seeds"])
            self.assertTrue(all(url.startswith("https://www.testequipmentdepot.com/")
                                for url in item["seeds"]))
            self.assertTrue(__import__("re").match(item["follow_regex"],
                "/fluke-87-v-true-rms-industrial-digital-multimeter-with-temperature-measurement.html"))
        self.assertIn("oscilloscopes.html",
            " ".join(sources[ids[0]]["seeds"]))
        self.assertIn("lab-equipment-and-supplies.html",
            " ".join(sources[ids[1]]["seeds"]))

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
