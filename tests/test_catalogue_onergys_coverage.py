"""Evidence-driven coverage regressions for ONERGYS on the existing harvester.

No live website, no alternative crawler, no supplier authentication, no
invented prices or stock. The fixtures model official OXID-style navigation.
"""
from __future__ import annotations
from datetime import datetime, timedelta, timezone
import json
import sqlite3
import sys
import unittest
from pathlib import Path
from urllib.parse import urlsplit

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"tools"))
from catalogue_harvester import (
    onergys_canonical_url, robots_sitemap_urls, sitemap_entries,
    same_source_hosts, db_init, parse_page, run, seeds, onergys_source_status, pid,
)
from catalogue_prices import extract_public_offers

ROOT="https://www.onergys.de/motortechnik/motor/befestigungsteile/"
PRODUCT=("https://www.onergys.com/en/engine-technology/filters/oil-filters/"
         "oil-filter-element-mwm-12284663-original.html")
HTML_PRODUCT=(
    '<html><title>Oil filter element MWM 12284663 original</title>'
    '<h1>Oil filter element MWM 12284663 original</h1>'
    '<p>Product number: 30002123</p>'
    '<p>Manufacturer: MWM</p>'
    '<p>383,40 EUR List price</p><p>Your Price (Please Login)</p></html>'
)


class OnergysCoverageTests(unittest.TestCase):
    def test_language_domains_and_supplier_shop_link_safety(self):
        mapping={"www.onergys.de":{"www.onergys.de","www.onergys.com"},
                 "www.onergys.com":{"www.onergys.de","www.onergys.com"}}
        self.assertTrue(same_source_hosts("www.onergys.de","www.onergys.com",mapping))
        self.assertFalse(same_source_hosts("www.onergys.de","example.com",mapping))
        self.assertFalse(same_source_hosts("other.example","www.onergys.com",mapping))

    def test_oxid_pagination_is_canonical_and_bounded(self):
        self.assertEqual(onergys_canonical_url(
            ROOT+"?force_sid=SHOULD_NEVER_BE_STORED&pgNr=2&utm_source=irrelevant"),
            ROOT+"?pgNr=2")
        self.assertEqual(onergys_canonical_url(ROOT+"?pgNr=00023&ldtype=list"),
                         ROOT+"?pgNr=23")
        self.assertEqual(onergys_canonical_url(ROOT+"?pgNr=1"),ROOT)
        self.assertEqual(onergys_canonical_url(ROOT+"?force_sid=secret"),ROOT)
        self.assertEqual(onergys_canonical_url(ROOT+"?pgNr=99999"),"")
        self.assertEqual(onergys_canonical_url(ROOT+"?pgNr=2&pgNr=3"),"")
        self.assertEqual(onergys_canonical_url(
            "https://www.onergys.de/account/login/?pgNr=3"),"")
        # Unrelated suppliers still keep their site-specific URL parameters.
        self.assertEqual(onergys_canonical_url(
            "https://other.example.com/catalogue/?page=6"),
            "https://other.example.com/catalogue/?page=6")

    def test_official_robots_sitemaps_and_xml_index_not_filename_guesses(self):
        robots=("User-agent: *\nDisallow: /checkout\n"
                "Sitemap: https://www.onergys.de/de/sitemap-index.xml\n"
                "SITEMAP: https://www.onergys.com/sitemap.xml\n")
        self.assertEqual(robots_sitemap_urls(robots),[
            "https://www.onergys.de/de/sitemap-index.xml",
            "https://www.onergys.com/sitemap.xml"
        ])
        index=('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
               '<sitemap><loc>https://www.onergys.com/products-01.php</loc></sitemap>'
               '</sitemapindex>')
        items=('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
               '<url><loc>'+PRODUCT+'</loc></url></urlset>')
        self.assertEqual(sitemap_entries(index),
                         [("sitemap","https://www.onergys.com/products-01.php")])
        self.assertEqual(sitemap_entries(items),[("page",PRODUCT)])
        self.assertEqual(sitemap_entries("<unrelated><loc>x</loc></unrelated>"),[])

    def test_exact_oem_identity_and_no_implicit_customer_price(self):
        products,_,_=parse_page(HTML_PRODUCT,PRODUCT,"","energy",
                                "Turbine / plant components")
        self.assertEqual(len(products),1)
        self.assertEqual(products[0]["manufacturer"],"MWM")
        self.assertEqual(products[0]["model"],"12284663")
        self.assertEqual(products[0]["identity_evidence"],"SUPPLIER_PART_PAGE")
        self.assertIn("30002123",products[0]["description"])
        # ONERGYS B2B list price in prose is not a logged-in customer quote,
        # and must not leak as a verified public offer without an exact Offer.
        self.assertEqual(extract_public_offers(
            HTML_PRODUCT,PRODUCT,products,datetime.now(timezone.utc).isoformat()),[])

    def test_deep_pagination_discovered_without_consuming_category_depth(self):
        with sqlite3.connect(":memory:") as db:
            db.row_factory=sqlite3.Row
            db_init(db)
            config={"categories":{},"sources":[{
                "id":"onergys-test","active":True,"manufacturer":"","role":"supplier",
                "allowed_hosts":["www.onergys.de","www.onergys.com"],
                "seeds":[ROOT],"follow_paths":[
                    "/motortechnik/","/en/engine-technology/"],
                "follow_regex":r"^/(?:motortechnik|en/engine-technology)/.*\.html$",
                "sector":"energy","category":"Turbine / plant components",
                "max_depth":3,"refresh_hours":24,
                "priority":True,"discover_robots_sitemaps":True
            }]}
            observed=[]
            def fetch(url,kind):
                observed.append((kind,url))
                if kind=="robots":
                    return "User-agent: *\nSitemap: https://www.onergys.com/sitemap-index.xml"
                if kind=="sitemap" and url.endswith("sitemap-index.xml"):
                    return ('<sitemapindex><sitemap><loc>'
                            'https://www.onergys.com/catalogue-0.xml'
                            '</loc></sitemap></sitemapindex>')
                if kind=="sitemap":
                    return ('<urlset><url><loc>'+PRODUCT+'</loc></url></urlset>')
                if url==PRODUCT:
                    return HTML_PRODUCT
                if urlsplit(url).path==urlsplit(ROOT).path:
                    from urllib.parse import parse_qs
                    current=int(parse_qs(urlsplit(url).query).get("pgNr",["1"])[0])
                    if current<=12:
                        return ('<html><h1>Fasteners</h1>'
                            '<a href="?pgNr='+str(current+1)+'&amp;force_sid=UNSAFE">Next</a>'
                            '</html>')
                return "<html><h1>Other source page</h1></html>"
            summary=run(db,config,max_pages=34,max_seconds=15,
                        workers=2,fetcher=fetch)
            self.assertGreaterEqual(summary["processed"],15)
            pages={url for kind,url in observed if kind=="page"}
            self.assertIn(ROOT+"?pgNr=12",pages,
                "Pagination beyond 3 levels should stay discoverable")
            self.assertIn(PRODUCT,pages,
                "Official sitemap cross-domain model was not fetched")
            page12=db.execute("SELECT depth,due FROM harvest_queue WHERE url=?",
                              (ROOT+"?pgNr=12",)).fetchone()
            self.assertIsNotNone(page12)
            self.assertEqual(page12["depth"],0,
                "Same-category pagination incorrectly increased crawl depth")
            self.assertNotIn("force_sid",json.dumps([row[1] for row in observed]))
            self.assertEqual(db.execute("SELECT COUNT(*) FROM products WHERE manufacturer='MWM' "
                                        "AND model='12284663'").fetchone()[0],1)
            status=onergys_source_status(db)
            self.assertGreaterEqual(status["discovered_pagination_urls"],11)
            self.assertEqual(status["evidenced_distinct_parts"],1)
            self.assertEqual(status["active_distinct_parts"],1)
            self.assertEqual(status["active_recent_public_offers"],0)
            self.assertGreaterEqual(status["fetched_pages"],13)
            revisit=datetime.fromisoformat(page12["due"].replace("Z","+00:00"))
            self.assertLess(abs((revisit-datetime.now(timezone.utc)
                                  -timedelta(hours=24)).total_seconds()),30,
                "Source-configured 24-hour refresh must not become 96 hours")

    def test_existing_source_requeue_once_preserves_history_and_other_vendors(self):
        with sqlite3.connect(":memory:") as db:
            db.row_factory=sqlite3.Row
            db_init(db)
            delayed=(datetime.now(timezone.utc)+timedelta(days=4)).isoformat()
            old=ROOT+"?pgNr=14"
            unrelated="https://other.example.com/product/existing.html"
            db.execute("""INSERT INTO harvest_queue
                (url,kind,sector,category,maker,depth,failures,due,last_error)
                VALUES(?,?,?,?,?,?,?,?,?)""",
                (old,"page","energy","Parts","",2,3,delayed,"OLD_BACKOFF_EVIDENCE"))
            db.execute("""INSERT INTO harvest_queue
                (url,kind,sector,category,maker,depth,failures,due,last_error)
                VALUES(?,?,?,?,?,?,?,?,?)""",
                (unrelated,"page","industrial","Parts","",1,0,delayed,""))
            db.commit()
            source={
                "id":"onergys-test","active":True,"manufacturer":"",
                "seeds":[ROOT],"allowed_hosts":["www.onergys.de"],
                "coverage_revision":"one-time-20261028",
                "sector":"energy","category":"Parts"
            }
            config={"categories":{},"sources":[source]}
            seeds(db,config)
            row=db.execute("SELECT * FROM harvest_queue WHERE url=?",(old,)).fetchone()
            scheduled=datetime.fromisoformat(row["due"].replace("Z","+00:00"))
            self.assertLessEqual(scheduled,datetime.now(timezone.utc))
            self.assertEqual(row["failures"],3)
            self.assertEqual(row["last_error"],"OLD_BACKOFF_EVIDENCE")
            self.assertEqual(db.execute("SELECT due FROM harvest_queue WHERE url=?",
                                        (unrelated,)).fetchone()[0],delayed)
            next_due=(datetime.now(timezone.utc)+timedelta(days=2)).isoformat()
            db.execute("UPDATE harvest_queue SET due=? WHERE url=?",(next_due,old))
            seeds(db,config)
            self.assertEqual(db.execute("SELECT due FROM harvest_queue WHERE url=?",
                                        (old,)).fetchone()[0],next_due,
                "Idempotent one-off catch-up must not repeat every 30 minutes")

    def test_real_source_entry_prioritizes_all_major_facets(self):
        config=json.loads((Path(__file__).resolve().parents[1]/
                           "config/catalogue_harvester.json").read_text())
        entry=next(x for x in config["sources"] if x["id"]=="onergys-de-spare-parts")
        self.assertTrue(entry["active"])
        self.assertTrue(entry["discover_robots_sitemaps"])
        self.assertGreaterEqual(entry["max_depth"],9)
        self.assertEqual(entry["refresh_hours"],24)
        for fragment in (
            "/motortechnik/","/anlagentechnik/","/elektrotechnik/",
            "/wartung-service/","/motoren/","/aktionen/",
            "/en/engine-technology/","/en/plant-technology/",
            "/en/electrical-engineering/","/en/maintenance-service/"
        ):
            self.assertIn(fragment,entry["follow_paths"])
        self.assertIn("www.onergys.de",entry["allowed_hosts"])
        self.assertIn("www.onergys.com",entry["allowed_hosts"])


if __name__=="__main__":
    unittest.main()
