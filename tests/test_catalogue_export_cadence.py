"""Keep expensive supplier-sidecar exports off the half-hour harvest hot path."""
import os
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from catalogue_harvester import sidecar_export_due


class ExportCadenceTests(unittest.TestCase):
    def test_missing_sidecar_is_exported(self):
        with tempfile.TemporaryDirectory() as tmp:
            self.assertTrue(sidecar_export_due(Path(tmp) / "missing.json", now=100000))

    def test_recent_sidecar_is_reused_without_reexport(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "supplier-evidence.json"
            path.write_text('{"items":[]}', encoding="utf-8")
            os.utime(path, (100000, 100000))
            self.assertFalse(sidecar_export_due(path, now=100000 + 5 * 3600))
            self.assertTrue(sidecar_export_due(path, now=100000 + 6 * 3600))

    def test_empty_or_clock_skewed_file_is_regenerated(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "supplier-evidence.json"
            path.touch()
            os.utime(path, (200000, 200000))
            self.assertTrue(sidecar_export_due(path, now=100000))
            path.write_text('{"items":[]}', encoding="utf-8")
            self.assertTrue(sidecar_export_due(path, now=100000))


if __name__ == "__main__":
    unittest.main()
