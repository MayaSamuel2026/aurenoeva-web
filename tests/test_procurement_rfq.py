"""AURENOEVA RFQ compliance and inbox contracts; standard library only."""
from __future__ import annotations

import datetime as dt
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from procurement_inbox import prepare_one
from procurement_rfq import create_package, review, VERIFY_KEYS

TODAY = dt.date(2026, 10, 20)


def case():
    return {
        "schema": "aurenoeva-procurement-case-import/1.0",
        "case_reference": "AUR-UZ-20261020-001",
        "customer_name": None, "delivery_location": None,
        "delivery_country": "UZ",
        "sourcing_lines": [
            {"customer_item_number": 1, "option_group": "ITEM-01", "option_type": "REQUESTED",
             "description_original": "MRI scanner 1.5 T", "quantity": 1, "canonical_part_id": None},
            {"customer_item_number": 1, "option_group": "ITEM-01", "option_type": "ALTERNATIVE",
             "description_original": "Alternative MRI", "quantity": None, "canonical_part_id": None},
        ],
    }


def verified():
    return {
        "buyer": {"legal_name": "Example Buyer LLC"},
        "end_user": {"legal_name": "Example Hospital", "installation_address": "Tashkent, example only"},
        "destination": {"final_end_use_country": "UZ"},
        "legal_route": "ORDINARY_LAWFUL_SUPPLY",
        "verification": {
            key: {"result": "VERIFIED_CLEAR", "checked_at": "2026-10-20",
                  "evidence_ref": "TEST-EVIDENCE-" + key, "reviewer": "Compliance reviewer"}
            for key in VERIFY_KEYS
        }
    }


class TestRfqs(unittest.TestCase):
    def test_default_rejected_for_send_without_end_user(self):
        p = create_package(case())
        self.assertEqual(p["compliance"]["status"], "REVIEW_REQUIRED")
        self.assertEqual(p["rfq"]["send_state"], "DRAFT_NOT_SENT")
        self.assertFalse(p["rfq"]["send_allowed"])
        self.assertEqual(len(p["rfq"]["lines"]), 2)
        self.assertEqual(p["rfq"]["lines"][1]["option_type"], "ALTERNATIVE")
        self.assertNotIn("margin", json.dumps(p["rfq"]).casefold())
        self.assertFalse(p["rfq"]["supplier_costs_public"])
        self.assertEqual(p["compliance"]["roles"]["sale_contract"], "CUSTOMER_AND_VENDOR")

    def test_verified_can_be_ready_to_request_quote_but_not_final_release(self):
        e = verified()
        c = case()
        p = create_package(c, e, {"legal_name": "Vendor Ltd", "email": "rfq@example.test"}, today=TODAY)
        self.assertEqual(p["compliance"]["status"], "CLEAR_FOR_RFQ")
        self.assertTrue(p["rfq"]["send_allowed"])
        self.assertEqual(p["rfq"]["send_state"], "DRAFT_NOT_SENT")
        self.assertTrue(p["compliance"]["not_export_authorization"])
        self.assertEqual(p["compliance"]["final_sale_release"], "REQUIRES_FRESH_TRANSACTION_SPECIFIC_COMPLIANCE_REVIEW")

    def test_reexport_risk_and_confirmed_prohibition_block(self):
        e = verified()
        e["documented_diversion_or_false_end_user"] = True
        p = review(case(), e, today=TODAY)
        self.assertEqual(p["status"], "BLOCKED")
        e.pop("documented_diversion_or_false_end_user")
        e["suggests_routing_to_evade_controls"] = True
        self.assertEqual(review(case(), e, today=TODAY)["status"], "BLOCKED")

    def test_authorization_not_assumed_and_stale_check_fails(self):
        e = verified()
        e["legal_route"] = "AUTHORISATION_GRANTED"
        self.assertEqual(review(case(), e, today=TODAY)["status"], "REVIEW_REQUIRED")
        e["authorization"] = {"document_ref": "LICENCE-EXAMPLE", "issuer": "COMPETENT AUTHORITY",
                              "scope_matches_goods_parties_end_use": True}
        self.assertEqual(review(case(), e, today=TODAY)["status"], "CLEAR_FOR_RFQ")
        e["verification"]["goods_export_classification"]["checked_at"] = "2025-01-01"
        self.assertEqual(review(case(), e, today=TODAY)["status"], "REVIEW_REQUIRED")

    def test_repeat_upload_is_idempotent_and_original_is_not_mutated(self):
        with tempfile.TemporaryDirectory() as tmp:
            inbox = Path(tmp) / "inbox"
            inbox.mkdir()
            storage = Path(tmp) / "private"
            source = inbox / "customer.csv"
            content = "No.,Equipment description,Qty.\n1,X-ray unit,1\n,Alternative X-ray unit,\n"
            source.write_text(content, encoding="utf-8")
            first = prepare_one(source, storage, "UZ")
            second = prepare_one(source, storage, "UZ")
            self.assertEqual(first["state"], "PREPARED_NOT_COMMITTED")
            self.assertEqual(second["state"], "ALREADY_PREPARED")
            self.assertEqual(first["count"], 2)
            self.assertFalse(first["rfq_send_allowed"])
            self.assertEqual(source.read_text(encoding="utf-8"), content)
            self.assertEqual(len(list((storage / "cases").glob("*.json"))), 1)
            self.assertEqual(len(list((storage / "rfq-drafts").glob("*.json"))), 1)
            package = json.loads(next((storage / "rfq-drafts").glob("*.json")).read_text())
            self.assertEqual(package["compliance"]["status"], "REVIEW_REQUIRED")


if __name__ == "__main__":
    unittest.main()
