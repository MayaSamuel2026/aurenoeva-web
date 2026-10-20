"""Focused regression tests for AURENOEVA customer procurement intake."""
import tempfile
import unittest
from pathlib import Path
from importlib.util import module_from_spec, spec_from_file_location

MODULE = Path(__file__).resolve().parents[1] / "tools" / "procurement_intake.py"
spec = spec_from_file_location("aurenoeva_procurement_intake", MODULE)
intake = module_from_spec(spec)
spec.loader.exec_module(intake)


class TestProcurementIntake(unittest.TestCase):
    def test_alternatives_are_options_not_duplicate_requirements(self):
        rows = [["No.", "Equipment description", "Qty."],
                ["1", "MRI 1.5T", "1"],
                ["", "Alternative MRI option", ""],
                ["2", "Ultrasound device", "2"]]
        items = intake.parse_rows(rows)
        self.assertEqual(len(items), 3)
        self.assertEqual(items[1]["option_group"], "ITEM-01")
        self.assertEqual(items[1]["option_type"], "ALTERNATIVE")
        self.assertIsNone(items[1]["quantity"])
        self.assertTrue(all(item["rfq_status"] == "NOT_SENT" for item in items))
        self.assertTrue(all(item["canonical_part_id"] is None for item in items))

    def test_csv_dry_run_preserves_country_and_no_quote_claim(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "customer.csv"
            path.write_text("No.,Equipment description,Qty.\n"
                            "1,Mindray WATO EX-35,1\n"
                            ",Equivalent anesthesia unit,\n"
                            "2,Ultrasound V8,2\n", encoding="utf-8")
            case = intake.build_payload(path, "AUR-UZ-TEST-001", "UZ")
            self.assertEqual(case["counts"],
                             {"numbered_requirements": 2, "equipment_entries": 3,
                              "alternative_options": 1})
            self.assertEqual(case["import_state"], "PREPARED_NOT_COMMITTED")
            self.assertFalse(case["catalogue_publication"])
            self.assertFalse(case["supplier_contact_conducted"])
            self.assertEqual(case["supplier_offers_received"], 0)

    def test_invalid_quantity_fails(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "invalid.csv"
            path.write_text("No.,Equipment description,Qty.\n1,X-ray system,0\n",
                            encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "invalid_quantity"):
                intake.build_payload(path, "AUR-UZ-TEST-002", "UZ")


if __name__ == "__main__":
    unittest.main()
