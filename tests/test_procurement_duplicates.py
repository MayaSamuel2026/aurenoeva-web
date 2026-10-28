"""AURENOEVA RFQ duplicate quality gate: no buyer data is ever deleted."""
import datetime as dt
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from procurement_intake import review_duplicate_lines
from procurement_rfq import review, rfq_draft

def line(row, item, description, option="REQUESTED", qty=1):
    return {"source_row": row, "customer_item_number": item,
            "description_original": description, "option_type": option,
            "quantity": qty, "canonical_part_id": None}

class DuplicateReviewTests(unittest.TestCase):
    def test_repeated_model_normalization_and_alternatives(self):
        lines = [
            line(3, 12, "DS.Surg 150 electrosurgical unit"),
            line(13, 22, "DS Surg 150 - Electrosurgical Unit"),
            line(4, 6, "MT5000S flexible endoscope washer"),
            line(5, 6, "MT5000L flexible endoscope washer", "ALTERNATIVE", None),
            line(7, 4, "V8 4 transducers shear wave ECG"),
            line(8, 5, "V6 3 transducers basic"),
        ]
        result = review_duplicate_lines(lines)
        self.assertEqual(result["source_line_count"], 6)
        self.assertEqual(result["alternative_options"], 1)
        self.assertEqual(result["duplicate_pairs"], [{
            "kind": "EXACT_DUPLICATE",
            "source_rows": [3, 13],
            "customer_items": [12, 22],
        }])
        self.assertEqual(len(lines), 6)

    def test_legal_preflight_blocks_duplicate_without_deletion(self):
        lines = [line(1, 12, "DS.Surg 150"), line(2, 22, "DS Surg 150")]
        case = {"schema": "aurenoeva-procurement-case-import/1.0",
                "case_reference": "AUR-UZ-TEST", "delivery_country": "UZ",
                "sourcing_lines": lines}
        assessment = review(case, today=dt.date(2026, 10, 28))
        self.assertIn("Duplicate or redundant equipment", " ".join(assessment["review_items"]))
        self.assertEqual(assessment["status"], "REVIEW_REQUIRED")
        draft = rfq_draft(case, assessment, supplier={"legal_name": "Example", "email": "sales@example.com"})
        self.assertFalse(draft["send_allowed"])
        self.assertTrue(draft["line_review"]["requires_review"])
        self.assertEqual(len(draft["lines"]), 2)

if __name__ == "__main__":
    unittest.main()
