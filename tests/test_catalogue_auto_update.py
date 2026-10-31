"""Offline regression tests for the existing-VPS catalogue self-updater."""
from unittest.mock import patch
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"tools"))
import catalogue_auto_update as updater
SHA="a"*40

class AutomaticCatalogueUpdateTests(unittest.TestCase):
    def test_staged_offline_tests_include_their_cross_file_fixtures(self):
        self.assertIn("tests/test_catalogue_telemetry.py",updater.TESTS)
        self.assertIn("hotfix/catalogue-discovery/catalogue-discovery.js",updater.TESTS)
        self.assertIn("PYTHONPATH",__import__("inspect").getsource(updater.stage_revision))

    def test_production_receipt_is_safe_fallback_when_github_api_fails(self):
        receipt=("Aurenoeva V8.2.6.11 production "+SHA+"\n").encode()
        with patch.object(updater,"get_bytes",return_value=receipt) as read:
            with patch.object(updater,"get_json",side_effect=OSError("API unavailable")):
                self.assertEqual(updater.qualified_sha(),SHA)
        self.assertIn("/production/VERSION.txt",read.call_args.args[0])

    def test_later_green_qualification_upgrades_without_full_site_release(self):
        green="b"*40
        receipt=("Aurenoeva V8.2.6.11 production "+SHA+"\n").encode()
        def api(url):
            if "/actions/runs" in url:
                return {"workflow_runs":[
                    {"name":"AURENOEVA private procurement qualification",
                     "head_sha":green,"head_branch":"main","event":"push",
                     "status":"completed","conclusion":"success"}]}
            if "/compare/" in url:
                self.assertIn(SHA+"..."+green,url)
                return {"status":"ahead"}
            raise AssertionError("unanticipated API request")
        with patch.object(updater,"get_bytes",return_value=receipt):
            with patch.object(updater,"get_json",side_effect=api):
                self.assertEqual(updater.qualified_sha(),green)
        def downgrade(url):
            if "/actions/runs" in url:
                return {"workflow_runs":[
                    {"name":"AURENOEVA private procurement qualification",
                     "head_sha":green,"head_branch":"main","event":"push",
                     "status":"completed","conclusion":"success"}]}
            if "/compare/" in url:return {"status":"behind"}
            raise AssertionError("unanticipated API request")
        with patch.object(updater,"get_bytes",return_value=receipt):
            with patch.object(updater,"get_json",side_effect=downgrade):
                self.assertEqual(updater.qualified_sha(),SHA)

    def test_qualification_chooses_latest_successful_main_revision(self):
        sha_new = SHA
        sha_old = "b" * 40
        def good(url):
            self.assertIn("/actions/runs?per_page=100", url)
            return {"workflow_runs": [
                {"name":"AURENOEVA private procurement qualification",
                 "head_sha":"c"*40,"head_branch":"main","event":"push",
                 "status":"completed","conclusion":"failure"},
                {"name":"AURENOEVA private procurement qualification",
                 "head_sha":sha_new,"head_branch":"main","event":"push",
                 "status":"completed","conclusion":"success"},
                {"name":"AURENOEVA private procurement qualification",
                 "head_sha":sha_old,"head_branch":"main","event":"push",
                 "status":"completed","conclusion":"success"}
            ]}
        with patch.object(updater,"get_bytes",side_effect=OSError("CDN unavailable")):
            with patch.object(updater,"get_json",side_effect=good):
                self.assertEqual(updater.qualified_sha(),sha_new)
            with patch.object(updater,"get_json",return_value={
                "workflow_runs":[{"name":"AURENOEVA private procurement qualification",
                    "head_sha":SHA,"head_branch":"other","event":"push",
                    "status":"completed","conclusion":"success"}]}):
                self.assertIsNone(updater.qualified_sha())

    def test_install_preserves_database_and_previous_files(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);app=root/"app";state=root/"state";state.mkdir()
            (state/"catalogue.sqlite3").write_bytes(b"IMMUTABLE_DB")
            stage=root/"stage";stage.mkdir()
            for name in updater.FILES:
                before=app/name;before.parent.mkdir(parents=True,exist_ok=True)
                before.write_text("old "+name)
                after=stage/name;after.parent.mkdir(parents=True,exist_ok=True)
                after.write_text("new "+name)
            updater.install_revision(SHA,stage,app,state)
            self.assertEqual((state/"catalogue.sqlite3").read_bytes(),b"IMMUTABLE_DB")
            self.assertEqual((state/"catalogue-qualified-revision.sha").read_text().strip(),SHA)
            for name in updater.FILES:
                self.assertEqual((app/name).read_text(),"new "+name)
            backup=list((state/"catalogue-code-releases").iterdir())
            self.assertEqual(len(backup),1)
            self.assertEqual((backup[0]/updater.FILES[0]).read_text(),"old "+updater.FILES[0])

    def test_mid_install_error_rolls_back_and_creates_no_receipt(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);app=root/"app";state=root/"state";state.mkdir()
            (state/"catalogue.sqlite3").write_bytes(b"PRESERVED")
            stage=root/"stage";stage.mkdir()
            for name in updater.FILES:
                before=app/name;before.parent.mkdir(parents=True,exist_ok=True)
                before.write_text("old "+name)
                after=stage/name;after.parent.mkdir(parents=True,exist_ok=True)
                after.write_text("new "+name)
            atomic=updater.atomic_write
            attempts=[]
            def fail_second(dest,data):
                attempts.append(dest)
                if len(attempts)==2:raise OSError("simulated disk failure")
                return atomic(dest,data)
            with patch.object(updater,"atomic_write",side_effect=fail_second):
                with self.assertRaises(OSError):
                    updater.install_revision(SHA,stage,app,state)
            self.assertFalse((state/"catalogue-qualified-revision.sha").exists())
            self.assertEqual((state/"catalogue.sqlite3").read_bytes(),b"PRESERVED")
            for name in updater.FILES:
                self.assertEqual((app/name).read_text(),"old "+name)

if __name__=="__main__":
    unittest.main()
