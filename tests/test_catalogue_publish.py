"""Unattended private publication tests: never use network or real server credentials."""
import hashlib
import json
import sqlite3
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from catalogue_publish import normalized_snapshot, product_batches, vendor_evidence_batches, publish, publish_https, credentials, make_full_database_export, technical_product_relevance

VALID = {
    "schema": "aurenoeva-catalogue/1",
    "items": [
        {"canonical_id": "AUR-P-" + "A" * 20, "manufacturer": "Mindray",
         "model": "WATO EX-35", "description": "Anesthesia machine",
         "source_ref": "https://www.mindray.com/en/products/anesthesia/wato-ex-35",
         "last_seen": "2026-10-20T18:31:13Z", "supplier_private": "NOT FOR PUBLIC"},
        {"canonical_id": "AUR-P-" + "B" * 20, "manufacturer": "Anthos",
         "model": "Surgical carts", "description": "Broad collection",
         "source_ref": "https://www.anthos.it/en/dental-units/cart-chirurgico",
         "last_seen": "2026-10-20T18:31:13Z"},
    ],
}


class PublicationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.source = self.root / "catalogue-public.json"
        self.source.write_text(json.dumps(VALID), encoding="utf-8")

    def tearDown(self):
        self.tmp.cleanup()

    def configured(self):
        key = self.root / "site-key"
        known = self.root / "known_hosts"
        key.write_text("TEST-KEY-NOT-A-REAL-SECRET")
        known.write_text("example.org ssh-ed25519 AAAATEST")
        return {
            "AURENOEVA_SFTP_HOST": "example.org",
            "AURENOEVA_SFTP_USER": "aurpub",
            "AURENOEVA_SFTP_REMOTE_FILE": "/home/aurpub/.aurenoeva-private/catalogue-public.json",
            "AURENOEVA_SFTP_KEY": str(key),
            "AURENOEVA_SFTP_KNOWN_HOSTS": str(known),
        }

    def test_nontechnical_consumer_records_do_not_enter_public_snapshot(self):
        book=dict(VALID["items"][0],canonical_id="AUR-P-"+"D"*20,
                  manufacturer="PONS",model="978-3-12-562482-5",
                  sector="laboratory",category="Sample preparation",
                  source_ref="https://de.pons.com/shop/deutsch/grammatik/book")
        self.source.write_text(json.dumps({"schema":VALID["schema"],
                                           "items":[VALID["items"][0],book]}))
        raw,count=normalized_snapshot(self.source)
        result=json.loads(raw)
        self.assertEqual(count,1)
        self.assertEqual(len(result["items"]),1)

    def test_publisher_repairs_legacy_lab_search_categories(self):
        original = dict(VALID["items"][0])
        original.update(canonical_id="AUR-P-"+"C"*20,
                        manufacturer="Fritsch GmbH",model="P-5 premium",
                        sector="laboratory",category="Chromatography / HPLC / GC",
                        description="Rapid milling and sample preparation")
        self.source.write_text(json.dumps({"schema":VALID["schema"],"items":[original]}))
        data,count=normalized_snapshot(self.source)
        self.assertEqual(count,1)
        item=json.loads(data)["items"][0]
        self.assertEqual(item["category"],"Sample preparation")
        self.assertEqual(item["sector"],"laboratory")

    def test_fast_publication_transfers_complete_snapshot_once(self):
        from catalogue_publish import publish_fast
        key = self.root / "catalogue-sync-token"
        key.write_text("a"*64)
        key.chmod(0o600)
        # Produce more than one transport chunk, as with a growing catalogue.
        doc = json.loads(self.source.read_text())
        doc["items"] = [
            {**VALID["items"][0], "canonical_id": "AUR-P-"+format(i,"020X"),
             "model": "WATO-"+str(i),
             "description": "P" * 360}
            for i in range(1800)
        ]
        self.source.write_text(json.dumps(doc))
        uploaded = bytearray()
        actions = []
        digest = None
        class Response:
            def __init__(self, data):
                self.status = 200
                self.data = data
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def read(self, n): return json.dumps(self.data).encode()
        def fake_open(request, timeout):
            nonlocal digest
            if "catalogue-count.php" in request.full_url:
                actions.append("public_count")
                return Response({"ok":True,"source":"published_catalogue_snapshot",
                                 "searchable_parts":1800})
            if "catalogue-search.php" in request.full_url:
                actions.append("public_search")
                item=json.loads(uploaded)["items"][-1]
                return Response({"source":"AURENOEVA_OWNED_CATALOGUE",
                                 "items":[item]})
            action = request.full_url.split("bulk-")[-1]
            actions.append(action)
            if action == "begin":
                meta = json.loads(request.data)
                digest = meta["sha256"]
                uploaded.clear()
                return Response({"ok": True, "status": "READY",
                                 "offset": 0, "sha256": digest})
            if action == "chunk":
                offset = int(request.get_header("X-aurenoeva-upload-offset"))
                self.assertEqual(offset, len(uploaded))
                uploaded.extend(request.data)
                return Response({"ok": True, "status": "RECEIVED",
                                 "offset": len(uploaded), "sha256": digest})
            if action == "commit":
                meta = json.loads(request.data)
                self.assertEqual(hashlib.sha256(uploaded).hexdigest(), digest)
                self.assertEqual(meta["count"], 1800)
                self.assertEqual(len(json.loads(uploaded)["items"]), 1800)
                return Response({"ok": True, "status": "PUBLISHED",
                                 "records": 1800, "sha256": digest})
            self.fail("unexpected upload action")
        with patch("catalogue_publish.urlopen", fake_open):
            result = publish_fast(self.source, self.root, {
                "AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE": str(key)
            })
        self.assertEqual(result["records"], 1800)
        self.assertEqual(actions[0], "begin")
        self.assertEqual(actions[-3:], ["commit", "public_count", "public_search"])
        self.assertGreater(actions.count("chunk"), 1)
        self.assertEqual((self.root/"catalogue-products-pushed.sha256")
                         .read_text().strip(), digest)

    def test_fast_upload_never_records_success_without_public_readback(self):
        from catalogue_publish import publish_fast
        key=self.root/"catalogue-sync-token"
        key.write_text("a"*64);key.chmod(0o600)
        digest=None
        class Response:
            def __init__(self,record):self.record=record;self.status=200
            def __enter__(self):return self
            def __exit__(self,*args):return False
            def read(self,n):return json.dumps(self.record).encode()
        def fake_open(request,timeout):
            nonlocal digest
            if "catalogue-count.php" in request.full_url:
                return Response({"ok":True,"source":"published_catalogue_snapshot",
                                 "searchable_parts":999999})
            action=request.full_url.split("bulk-")[-1]
            if action=="begin":
                digest=json.loads(request.data)["sha256"]
                return Response({"ok":True,"status":"READY","offset":0,"sha256":digest})
            if action=="chunk":
                return Response({"ok":True,"status":"RECEIVED",
                        "offset":len(request.data)+int(request.get_header(
                        "X-aurenoeva-upload-offset")),"sha256":digest})
            if action=="commit":
                return Response({"ok":True,"status":"PUBLISHED","records":1,
                                 "sha256":digest})
            raise AssertionError("unexpected HTTP request")
        with patch("catalogue_publish.urlopen",fake_open):
            with self.assertRaisesRegex(RuntimeError,"live_website_count_readback_mismatch"):
                publish_fast(self.source,self.root,{
                    "AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE":str(key)})
        self.assertFalse((self.root/"catalogue-products-pushed.sha256").exists())

    def test_canonical_sqlite_repairs_stale_publisher_input_without_mutating_db(self):
        db=self.root/"catalogue.sqlite3"
        with sqlite3.connect(db) as conn:
            conn.execute("""CREATE TABLE products (
                product_id TEXT PRIMARY KEY, manufacturer TEXT, model TEXT,
                description TEXT, source_url TEXT, sector TEXT,
                category TEXT, last_seen TEXT)""")
            for i in range(4):
                conn.execute("INSERT INTO products VALUES(?,?,?,?,?,?,?,?)",
                     ("AUR-P-"+format(i,"020X"),"Mindray","WATO-"+str(i),
                      "Detail","https://example.org/p/"+str(i),
                      "medical","Anesthesia","2026-10-23T11:00:00Z"))
            conn.execute("INSERT INTO products VALUES(?,?,?,?,?,?,?,?)",
                  ("AUR-P-"+"F"*20,"Mindray","Products","Generic",
                   "https://example.org/generic","medical","Anesthesia",""))
        self.source.write_text(json.dumps({
            "schema":"aurenoeva-catalogue/1","items":[VALID["items"][0]]}))
        dst=self.root/"fresh.json"
        result=make_full_database_export(db,dst)
        self.assertEqual(result["db_products"],5)
        raw=json.loads(dst.read_text())
        self.assertEqual(len(raw["items"]),5)
        info={}
        payload,count=normalized_snapshot(dst,info)
        self.assertEqual(count,4)
        pub=json.loads(payload)["public_stats"]
        self.assertEqual(pub["db_products"],5)
        self.assertEqual(pub["exported_products"],5)
        self.assertEqual(pub["eligible_products"],4)
        self.assertEqual(pub["excluded_generic"],1)
        self.assertEqual(pub["excluded_products"],1)
        self.assertEqual(json.loads(self.source.read_text())["items"],
                         [VALID["items"][0]])
        with sqlite3.connect(db) as conn:
            self.assertEqual(conn.execute("SELECT COUNT(*) FROM products").fetchone()[0],5)

    def test_canonical_sqlite_failure_never_uses_stale_export_as_success(self):
        with self.assertRaisesRegex(RuntimeError,"canonical_catalogue_database_missing"):
            make_full_database_export(self.root/"missing.sqlite3",
                                      self.root/"fresh.json")
        self.assertFalse((self.root/"fresh.json").exists())

    def test_reconciliation_explains_filtered_and_duplicate_records(self):
        doc=json.loads(self.source.read_text())
        good=dict(VALID["items"][0])
        doc["items"]=[
            good,
            {**good, "canonical_id":"BAD"},
            {**good, "canonical_id":"AUR-P-"+"A"*20, "manufacturer":""},
            {**good, "canonical_id":"AUR-P-"+"B"*20, "model":""},
            {**good, "canonical_id":"AUR-P-"+"C"*20, "model":"Products"},
            {**good, "canonical_id":"AUR-P-"+"D"*20,
             "source_ref":"http://unverified.example.net/products/1"},
            good,
            None,
        ]
        self.source.write_text(json.dumps(doc))
        diag={}
        payload,count=normalized_snapshot(self.source,diag)
        self.assertEqual(count,1)
        self.assertEqual(diag["exported_input_rows"],8)
        self.assertEqual(diag["publishable_unique_records"],1)
        self.assertEqual(diag["excluded_rows"],7)
        self.assertEqual(diag["exclusion_reasons"],{
            "non_product_record":1,"invalid_canonical_id":1,
            "missing_manufacturer":1,"missing_model":1,
            "generic_heading":1,"invalid_https_source":1,
            "duplicate_canonical_id":1,"nontechnical_catalogue_record":0})
        self.assertEqual(len(json.loads(payload)["items"]),1)

    def test_public_snapshot_drops_generic_and_supplier_private_fields(self):
        data, count = normalized_snapshot(self.source)
        parsed = json.loads(data)
        self.assertEqual(count, 1)
        self.assertEqual(parsed["items"][0]["model"], "WATO EX-35")
        self.assertNotIn("supplier_private", data.decode())
        self.assertNotIn("stock", json.dumps(parsed["items"]))

    def test_named_official_model_without_digits_is_retained(self):
        doc=json.loads(self.source.read_text())
        doc["items"].append({"canonical_id":"AUR-P-"+"C"*20,
          "manufacturer":"Siemens Healthineers", "model":"MAGNETOM Lumina",
          "source_ref":"https://www.siemens-healthineers.com/en-us/magnetic-resonance-imaging/3t-mri-scanner/magnetom-lumina",
          "last_seen":"2026-10-21T07:40:00Z"})
        self.source.write_text(json.dumps(doc))
        data,count=normalized_snapshot(self.source)
        self.assertEqual(count,2)
        self.assertIn("MAGNETOM Lumina",data.decode())

    def test_no_connection_yet_is_explicit_noop(self):
        self.assertEqual(publish(self.source, self.root, {})["status"], "SKIPPED_UNCONFIGURED")

    def test_remote_path_cannot_be_public_or_relative(self):
        env = self.configured()
        for dest in ("public_html/catalogue.json",
                     "/home/aurpub/public_html/catalogue.json",
                     "/home/aurpub/private/../public_html/catalogue.json"):
            env["AURENOEVA_SFTP_REMOTE_FILE"] = dest
            with self.assertRaises(ValueError):
                credentials(env)

    def test_mock_upload_requires_remote_checksum_readback_and_is_idempotent(self):
        env = self.configured()
        calls = []
        def fake_sftp(command, input, **_):
            lines = input.splitlines()
            calls.append(lines)
            self.assertIn("StrictHostKeyChecking=yes", " ".join(command))
            local = Path(lines[0].split(" ")[1])
            readback = Path(lines[2].split(" ")[2])
            readback.write_bytes(local.read_bytes())
            return subprocess.CompletedProcess(command, 0, "", "")
        with patch("catalogue_publish.subprocess.run", fake_sftp):
            first = publish(self.source, self.root, env)
            second = publish(self.source, self.root, env)
        self.assertEqual(first["status"], "PUBLISHED")
        self.assertEqual(first["records"], 1)
        self.assertEqual(second["status"], "UNCHANGED")
        self.assertEqual(len(calls), 1)

    def test_https_push_acknowledgement_and_no_repeat(self):
        key=self.root/'catalogue-sync-token'
        key.write_text('a'*64, encoding='ascii')
        key.chmod(0o600)
        env={'AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE':str(key)}
        calls=[]
        class FakeResponse:
            status=200
            def __init__(self, result): self.result=result
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def read(self, size): return json.dumps(self.result).encode()
        def fake_open(request, timeout):
            calls.append(request)
            self.assertEqual(request.get_header("X-aurenoeva-sync-token"), 'a'*64)
            payload=request.data
            count=len(json.loads(payload)['items'])
            return FakeResponse({'ok':True,'status':'PUBLISHED','records':count,
              'accepted_input_records':count,
              'source_sha256':hashlib.sha256(payload).hexdigest(),
              'sha256':'b'*64})
        with patch('catalogue_publish.urlopen', fake_open):
            first=publish_https(self.source, self.root, env)
            again=publish_https(self.source, self.root, env)
        self.assertEqual(first['status'],'PUBLISHED')
        self.assertEqual(again['status'],'PUBLISHED')
        self.assertEqual(len(calls),2)  # reconcile even after same snapshot: restores from backups

    def test_https_push_rejects_unconfirmed_data(self):
        key=self.root/'catalogue-sync-token'
        key.write_text('a'*64, encoding='ascii')
        key.chmod(0o600)
        class BadResponse:
            status=200
            def __enter__(self): return self
            def __exit__(self,*args): return False
            def read(self,size): return b'{"ok":true,"status":"PUBLISHED","records":0,"sha256":"fake"}'
        with patch('catalogue_publish.urlopen', return_value=BadResponse()):
            with self.assertRaises(RuntimeError):
                publish_https(self.source,self.root,
                    {'AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE':str(key)})
        self.assertFalse((self.root/'catalogue-pushed.sha256').exists())


    def test_product_batches_keep_every_product_and_bound_payload(self):
        doc=json.loads(self.source.read_text())
        doc["items"]=[{**VALID["items"][0],
             "canonical_id":"AUR-P-"+format(i,"020X"),
             "model":"Medical model "+str(i)} for i in range(49)]
        doc["vendor_products"]=[]
        doc["vendor_leads"]=[]
        self.source.write_text(json.dumps(doc))
        output,count=normalized_snapshot(self.source)
        batches=list(product_batches(output,max_records=7,max_bytes=2000))
        combined=[p for b,_ in batches for p in json.loads(b)["items"]]
        self.assertEqual(len(combined),count)
        self.assertEqual(len({p["canonical_id"] for p in combined}),count)
        self.assertGreater(len(batches),1)
        self.assertTrue(all(len(b)<24000000 for b,_ in batches))

    def test_combined_snapshot_larger_than_24mb_no_longer_blocks_product_export(self):
        doc=json.loads(self.source.read_text())
        # Real-world failure was total source size, not just product identities.
        doc["unpublished_vendor_evidence_padding"]="x"*24500000
        self.source.write_text(json.dumps(doc))
        output,count=normalized_snapshot(self.source)
        self.assertEqual(count,1)
        self.assertLess(len(output),2000)
        self.assertNotIn(b"unpublished_vendor_evidence_padding",output)

    def test_vendor_evidence_delivered_separately_in_multiple_batches(self):
        doc=json.loads(self.source.read_text())
        doc["vendor_products"]=[{
            "website":"www.mindray.com",
            "product_id":"AUR-P-"+format(i,"020X"),
            "source_url":f"https://www.mindray.com/product/{i}",
            "manufacturer":"Mindray","model":"Model "+str(i),
            "sector":"medical","category":"Equipment",
            "evidence_type":"STRUCTURED_PRODUCT",
            "last_seen":"2026-10-22T04:33:00Z"} for i in range(16)]
        doc["vendor_leads"]=[{"website":"www.mindray.com",
            "evidence_url":f"https://www.mindray.com/catalog/{i}",
            "sector":"medical","category":"Equipment","checked":"SITE_FETCHED",
            "email":"","phone":"","last_seen":"2026-10-22T04:33:00Z"}
            for i in range(5)]
        self.source.write_text(json.dumps(doc))
        batches=list(vendor_evidence_batches(self.source,max_records=4))
        self.assertEqual(sum(n for _,n in batches),21)
        self.assertGreater(len(batches),1)
        for data,n in batches:
            parsed=json.loads(data)
            self.assertEqual(parsed["schema"],"aurenoeva-vendor-evidence/1")
            self.assertEqual(len(parsed["vendor_products"])+len(parsed["vendor_leads"]),n)

    def test_https_push_produces_product_and_vendor_receipts(self):
        doc=json.loads(self.source.read_text())
        doc["vendor_products"]=[{
            "website":"www.mindray.com",
            "product_id":"AUR-P-"+"A"*20,
            "source_url":"https://www.mindray.com/en/products/anesthesia/wato-ex-35",
            "manufacturer":"Mindray","model":"WATO EX-35",
            "sector":"medical","category":"Anesthesia",
            "evidence_type":"STRUCTURED_PRODUCT","last_seen":"2026-10-22T04:33:00Z"}]
        self.source.write_text(json.dumps(doc))
        key=self.root/"catalogue-sync-token"
        key.write_text("a"*64);key.chmod(0o600)
        requests=[]
        class Response:
            status=200
            def __init__(self,result):self.result=result
            def __enter__(self):return self
            def __exit__(self,*args):return False
            def read(self,size):return json.dumps(self.result).encode()
        def fake_open(request,timeout):
            data=json.loads(request.data)
            requests.append(request.full_url)
            digest=hashlib.sha256(request.data).hexdigest()
            if "?mode=vendors" in request.full_url:
                self.assertEqual(data["schema"],"aurenoeva-vendor-evidence/1")
                return Response({"ok":True,"status":"MERGED","source_sha256":digest})
            return Response({"ok":True,"status":"PUBLISHED","records":1,
                  "accepted_input_records":1,"sha256":"a"*64,
                  "source_sha256":digest})
        with patch("catalogue_publish.urlopen",fake_open):
            result=publish_https(self.source,self.root,{
               "AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE":str(key)})
        self.assertEqual(result["status"],"PUBLISHED")
        self.assertEqual(result["vendor_evidence_records"],1)
        self.assertEqual(result["vendor_batches"],1)
        self.assertEqual(len(requests),2)
        self.assertTrue(requests[1].endswith("?mode=vendors"))


    def test_products_first_and_rfqs_after_preserve_status_accuracy(self):
        doc=json.loads(self.source.read_text())
        doc["vendor_products"]=[{
            "website":"www.mindray.com","product_id":"AUR-P-"+"A"*20,
            "source_url":"https://www.mindray.com/en/products/anesthesia/wato-ex-35",
            "manufacturer":"Mindray","model":"WATO EX-35",
            "sector":"medical","category":"Anesthesia",
            "evidence_type":"STRUCTURED_PRODUCT","last_seen":"2026-10-22T04:33:00Z"}]
        self.source.write_text(json.dumps(doc))
        key=self.root/"catalogue-sync-token"
        key.write_text("a"*64);key.chmod(0o600)
        env={"AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE":str(key)}
        calls=[]
        class Response:
            status=200
            def __init__(self,result):self.result=result
            def __enter__(self):return self
            def __exit__(self,*args):return False
            def read(self,n):return json.dumps(self.result).encode()
        def fake_open(request,timeout):
            parsed=json.loads(request.data)
            calls.append(request.full_url)
            digest=hashlib.sha256(request.data).hexdigest()
            if "mode=vendors" in request.full_url:
                return Response({"ok":True,"status":"MERGED",
                                 "source_sha256":digest})
            count=len(parsed["items"])
            return Response({"ok":True,"status":"PUBLISHED",
                             "accepted_input_records":count,
                             "records":count,"source_sha256":digest,
                             "sha256":"a"*64})
        with patch("catalogue_publish.urlopen",fake_open):
            with self.assertRaises(RuntimeError):
                publish_https(self.source,self.root,env,mode="vendors")
            first=publish_https(self.source,self.root,env,mode="products")
            self.assertEqual(first["status"],"PRODUCTS_PUBLISHED")
            self.assertTrue(first["vendor_pending"])
            self.assertFalse((self.root/"catalogue-pushed.sha256").exists())
            self.assertTrue((self.root/"catalogue-products-pushed.sha256").exists())
            second=publish_https(self.source,self.root,env,mode="vendors")
        self.assertEqual(second["status"],"VENDORS_PUBLISHED")
        self.assertEqual(len(calls),2)
        self.assertTrue((self.root/"catalogue-pushed.sha256").exists())

    def test_oversized_proxy_disconnections_split_until_acked(self):
        from http.client import RemoteDisconnected
        doc=json.loads(self.source.read_text())
        doc["items"]=[{
            **VALID["items"][0],"canonical_id":"AUR-P-"+format(i,"020X"),
            "model":"EX-"+str(i)} for i in range(32)]
        self.source.write_text(json.dumps(doc))
        key=self.root/"catalogue-sync-token"
        key.write_text("a"*64);key.chmod(0o600)
        live=set();attempts=[]
        class Response:
            status=200
            def __init__(self,result):self.result=result
            def __enter__(self):return self
            def __exit__(self,*args):return False
            def read(self,n):return json.dumps(self.result).encode()
        def fake_open(request,timeout):
            data=json.loads(request.data)
            items=data["items"]
            attempts.append(len(items))
            if len(items)>25:
                raise RemoteDisconnected("simulated Hostinger reverse proxy close")
            for item in items:live.add(item["canonical_id"])
            return Response({"ok":True,"status":"PUBLISHED",
                "accepted_input_records":len(items),"records":len(live),
                "sha256":"b"*64,
                "source_sha256":hashlib.sha256(request.data).hexdigest()})
        with patch("catalogue_publish.urlopen",fake_open),\
             patch("catalogue_publish.time.sleep",return_value=None):
            result=publish_https(self.source,self.root,{
               "AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE":str(key)},mode="products")
        self.assertEqual(result["records"],32)
        self.assertEqual(result["submitted_products"],32)
        self.assertEqual(len(live),32)
        self.assertIn(32,attempts)
        self.assertIn(16,attempts)
        self.assertEqual(attempts.count(32),3)
        self.assertTrue((self.root/"catalogue-products-pushed.sha256").exists())

    def test_http_422_rejection_is_split_and_all_records_retained(self):
        from io import BytesIO
        from urllib.error import HTTPError
        doc=json.loads(self.source.read_text())
        doc["items"]=[{
            **VALID["items"][0],"canonical_id":"AUR-P-"+format(i,"020X"),
            "model":"EX-"+str(i)} for i in range(32)]
        self.source.write_text(json.dumps(doc))
        key=self.root/"catalogue-sync-token"
        key.write_text("a"*64);key.chmod(0o600)
        live=set();sizes=[]
        class Response:
            status=200
            def __init__(self,result):self.result=result
            def __enter__(self):return self
            def __exit__(self,*args):return False
            def read(self,n):return json.dumps(self.result).encode()
        def fake_open(request,timeout):
            items=json.loads(request.data)["items"]
            sizes.append(len(items))
            if len(items)>16:
                raise HTTPError(request.full_url,422,"Unprocessable",
                    {},BytesIO(b'{"ok":false,"error":"snapshot_rejected","reason_code":"unexpected_RuntimeException"}'))
            live.update(item["canonical_id"] for item in items)
            return Response({"ok":True,"status":"PUBLISHED","records":len(live),
                "accepted_input_records":len(items),"sha256":"b"*64,
                "source_sha256":hashlib.sha256(request.data).hexdigest()})
        with patch("catalogue_publish.urlopen",fake_open):
            result=publish_https(self.source,self.root,
                {"AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE":str(key)},mode="products")
        self.assertEqual(result["submitted_products"],32)
        self.assertEqual(len(live),32)
        self.assertIn(32,sizes)
        self.assertIn(16,sizes)
        self.assertTrue((self.root/"catalogue-products-pushed.sha256").exists())

    def test_single_rejected_record_blocks_success_receipt(self):
        from io import BytesIO
        from urllib.error import HTTPError
        key=self.root/"catalogue-sync-token"
        key.write_text("a"*64);key.chmod(0o600)
        def reject(request,timeout):
            raise HTTPError(request.full_url,422,"Unprocessable",
                 {},BytesIO(b'{"ok":false,"reason_code":"snapshot_no_products"}'))
        with patch("catalogue_publish.urlopen",reject):
            with self.assertRaisesRegex(ValueError,"snapshot_no_products"):
                publish_https(self.source,self.root,
                    {"AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE":str(key)},mode="products")
        self.assertFalse((self.root/"catalogue-products-pushed.sha256").exists())

    def test_failed_upload_never_records_success_receipt(self):
        env = self.configured()
        with patch("catalogue_publish.subprocess.run",
                   return_value=subprocess.CompletedProcess([], 1, "", "no route")):
            with self.assertRaises(RuntimeError):
                publish(self.source, self.root, env)
        self.assertFalse((self.root / "catalogue-published.sha256").exists())


if __name__ == "__main__":
    unittest.main()
