"""Unattended private publication tests: never use network or real server credentials."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from catalogue_publish import normalized_snapshot, publish, credentials

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

    def test_public_snapshot_drops_generic_and_supplier_private_fields(self):
        data, count = normalized_snapshot(self.source)
        parsed = json.loads(data)
        self.assertEqual(count, 1)
        self.assertEqual(parsed["items"][0]["model"], "WATO EX-35")
        self.assertNotIn("supplier_private", data.decode())
        self.assertNotIn("stock", json.dumps(parsed["items"]))

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

    def test_failed_upload_never_records_success_receipt(self):
        env = self.configured()
        with patch("catalogue_publish.subprocess.run",
                   return_value=subprocess.CompletedProcess([], 1, "", "no route")):
            with self.assertRaises(RuntimeError):
                publish(self.source, self.root, env)
        self.assertFalse((self.root / "catalogue-published.sha256").exists())


if __name__ == "__main__":
    unittest.main()
