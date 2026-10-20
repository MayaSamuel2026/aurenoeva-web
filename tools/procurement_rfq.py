#!/usr/bin/env python3
"""AURENOEVA private procurement RFQ + transaction legal preflight.

Evidence-driven screening, not legal advice or a sanctions-list screening service.
No supplier messages, quotes, transactions or production records are created.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
from pathlib import Path

SOURCES = {
    "dual_use": "https://eur-lex.europa.eu/eli/reg/2021/821/oj",
    "sanctions": "https://finance.ec.europa.eu/eu-and-world/sanctions-restrictive-measures/overview-sanctions-and-related-resources_en",
    "circumvention": "https://finance.ec.europa.eu/publications/guidance-due-diligence_en",
    "no_reexport": "https://eur-lex.europa.eu/eli/reg/2014/833/2026-07-24/eng",
    "uz_registration": "https://lex.uz/en/docs/7861699?ONDATE=26.02.2026",
}
# A legal exception/authorization is never presumed from product category,
# geography, a third-party intermediary, or an asserted humanitarian purpose.
DIRECT_BROKER_ROLES = {
    "sale_contract": "CUSTOMER_AND_VENDOR",
    "equipment_seller": "VENDOR",
    "equipment_buyer": "CUSTOMER",
    "supplier_invoice_to": "CUSTOMER",
    "supplier_payment_from": "CUSTOMER",
    "importer_of_record": "CUSTOMER_OR_DISCLOSED_APPOINTED_IMPORTER",
    "exporter_of_record": "VENDOR_OR_LAWFULLY_APPOINTED_EXPORTER",
    "aurenoeva_role": "DISCLOSED_SOURCING_INTERMEDIARY",
    "aurenoeva_title_to_goods": False,
    "aurenoeva_customer_funds_custody": False,
    "commission": "SEPARATE_BROKERAGE_AGREEMENT; NEVER IN SUPPLIER RFQ",
}
# Requires independent evidence; an item's text is never sufficient
# to decide whether a device is subject to an export licence.
VERIFY_KEYS = (
    "buyer_identity", "end_user_identity", "end_use_and_final_destination",
    "buyer_supplier_beneficial_owner_screening", "goods_export_classification",
    "origin_export_and_brokering_rules", "uzbekistan_import_registration",
    "reexport_diversion_review",
)
BLOCKING_FLAGS = (
    "confirmed_restricted_party", "prohibited_transaction",
    "denied_required_licence", "documented_diversion_or_false_end_user",
)
LEGAL_ROUTES = (
    "ORDINARY_LAWFUL_SUPPLY", "AUTHORISATION_GRANTED",
    "VERIFIED_STATUTORY_EXEMPTION", "GENUINELY_COMPLIANT_PRODUCT_ALTERNATIVE",
)
DISCLOSURE = (
    "AURENOEVA acts solely as a disclosed sourcing intermediary. "
    "Any final sale, goods invoice, payment, delivery, warranties and import/export "
    "responsibilities are agreed directly between the customer and the supplier. "
    "No licence, exemption, customs classification or sanctions clearance is represented "
    "by this RFQ draft. Separate brokerage fees are governed by a separate agreement."
)


def _evidence_verified(entry, now: dt.date) -> bool:
    """Require attributable, dated screening evidence; never infer 'clear'."""
    if not isinstance(entry, dict) or entry.get("result") != "VERIFIED_CLEAR":
        return False
    if not all(str(entry.get(k) or "").strip() for k in ("reviewer", "evidence_ref", "checked_at")):
        return False
    try:
        checked = dt.date.fromisoformat(str(entry["checked_at"])[:10])
    except ValueError:
        return False
    return 0 <= (now - checked).days <= 30


def review(case: dict, evidence: dict | None = None, *, today: dt.date | None = None) -> dict:
    """Conservative pre-RFQ decision; avoids false automated legal clearance."""
    evidence = evidence or {}
    today = today or dt.date.today()
    if case.get("schema") != "aurenoeva-procurement-case-import/1.0":
        raise ValueError("unrecognised_case_schema")
    if not case.get("case_reference") or not isinstance(case.get("sourcing_lines"), list):
        raise ValueError("invalid_case")
    if not case["sourcing_lines"]:
        raise ValueError("empty_case")

    findings: list[str] = []
    blocking = [key for key in BLOCKING_FLAGS if evidence.get(key) is True]
    if blocking:
        findings.extend("BLOCK: " + key for key in blocking)
    country = str(case.get("delivery_country") or "").upper()
    if country != "UZ":
        findings.append("Country-specific review required; current UZ profile not applicable.")
    if not case.get("customer_name") and not (evidence.get("buyer") or {}).get("legal_name"):
        findings.append("Buyer legal name not yet verified.")
    if not case.get("delivery_location") and not (evidence.get("end_user") or {}).get("installation_address"):
        findings.append("End-user installation address missing.")
    if not (evidence.get("end_user") or {}).get("legal_name"):
        findings.append("Real end-user not identified.")
    if not (evidence.get("destination") or {}).get("final_end_use_country"):
        findings.append("Final end-use and any intended onward-transfer destination not confirmed.")

    # Automatic name-screen evidence is a prerequisite, not legal clearance.
    # A potential match, failed feed or absent live screening always holds the RFQ.
    screen = evidence.get("sanctions_screening")
    if not isinstance(screen, dict):
        findings.append("Official-list sanctions screening has not been performed.")
    elif screen.get("sources_errors") or screen.get("matches") or screen.get("status") != "NO_NAME_MATCH_NOT_CLEARED":
        findings.append("Sanctions screening unresolved: " + str(screen.get("status") or "UNKNOWN"))
    elif not all(k in (screen.get("sources") or {}) for k in ("EU_FSF", "OFAC_SDN")):
        findings.append("Required EU and OFAC source readback missing.")
    verification = evidence.get("verification") or {}
    pending = [key for key in VERIFY_KEYS if not _evidence_verified(verification.get(key), today)]
    if pending:
        findings.append("Evidence outstanding or stale: " + ", ".join(pending))

    route = str(evidence.get("legal_route") or "")
    if route not in LEGAL_ROUTES:
        findings.append("No verified lawful supply route classified.")
    elif route in {"AUTHORISATION_GRANTED", "VERIFIED_STATUTORY_EXEMPTION"}:
        permit = evidence.get("authorization") or {}
        if not (permit.get("document_ref") and permit.get("issuer")
                and permit.get("scope_matches_goods_parties_end_use") is True):
            findings.append("Licence/exemption evidence must identify issuer, scope and goods/end user.")
    elif route == "GENUINELY_COMPLIANT_PRODUCT_ALTERNATIVE":
        alt = evidence.get("compliant_alternative") or {}
        if not (alt.get("independent_product_id") and alt.get("classification_evidence_ref")):
            findings.append("Alternative needs independent genuine classification evidence.")
    if evidence.get("suggests_routing_to_evade_controls") is True:
        blocking.append("circumvention_proposal")
        findings.append("BLOCK: routing or substitutions designed to evade controls are impermissible.")

    status = "BLOCKED" if blocking else ("REVIEW_REQUIRED" if findings else "CLEAR_FOR_RFQ")
    # Even CLEAR_FOR_RFQ is not an export or regulatory release decision.
    return {
        "case_reference": case["case_reference"],
        "jurisdiction": country,
        "status": status,
        "assessment_date": today.isoformat(),
        "scope": "PRELIMINARY_SUPPLIER_RFQ_ONLY",
        "not_export_authorization": True,
        "not_final_transaction_clearance": True,
        "review_items": findings,
        "blocking_flags": blocking,
        "missing_verifications": pending,
        "legal_route": route or "UNDETERMINED",
        "roles": DIRECT_BROKER_ROLES,
        "sources": SOURCES,
        "final_sale_release": "REQUIRES_FRESH_TRANSACTION_SPECIFIC_COMPLIANCE_REVIEW",
    }


def rfq_draft(case: dict, assessment: dict, *, supplier: dict | None = None) -> dict:
    """Non-dispatching supplier RFQ draft. Never includes brokerage economics."""
    supplier = supplier or {}
    lines = []
    for line in case["sourcing_lines"]:
        lines.append({
            "customer_item_number": line.get("customer_item_number"),
            "option_group": line.get("option_group"),
            "option_type": line.get("option_type"),
            "description": line.get("description_original", ""),
            "quantity": line.get("quantity"),
            "technical_approval": "PENDING" if not line.get("canonical_part_id") else "PART_ID_TO_VERIFY",
        })
    country = case.get("delivery_country") or "UNKNOWN"
    return {
        "schema": "aurenoeva-supplier-rfq-draft/1.0",
        "case_reference": case["case_reference"],
        "supplier_name": supplier.get("legal_name"),
        "supplier_email": supplier.get("email"),
        "send_state": "DRAFT_NOT_SENT",
        "send_allowed": assessment["status"] == "CLEAR_FOR_RFQ" and bool(supplier.get("legal_name") and supplier.get("email")),
        "compliance_status": assessment["status"],
        "buyer_contracts_directly_with_vendor": True,
        "subject": f"Indicative medical-equipment RFQ — {case['case_reference']}",
        "body": (
            "Please provide a non-binding quotation for the equipment in the attached line schedule. "
            f"Final destination proposed: {country}; installation address and end-user to be confirmed where missing. "
            "Please identify the exact manufacturer/model/configuration, quantity, available condition, "
            "origin, export-control classification where applicable, price and currency, Incoterm, "
            "lead time, freight, Uzbekistan registration/import route, installation, training, "
            "warranty and local service support. Identify any necessary export or brokering "
            "authorisation and any restrictions on end-use or onward re-export. "
            "No order, export authorisation, acceptance or payment is implied. "
            + DISCLOSURE
        ),
        "lines": lines,
        "supplier_costs_public": False,
        "internal_commission_shared_with_supplier": False,
        "created_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "notices": assessment["review_items"],
    }


def create_package(case: dict, evidence: dict | None = None, supplier: dict | None = None,
                   *, today: dt.date | None = None) -> dict:
    assessment = review(case, evidence, today=today)
    return {
        "schema": "aurenoeva-rfq-legal-preflight/1.0",
        "case_reference": case["case_reference"],
        "compliance": assessment,
        "rfq": rfq_draft(case, assessment, supplier=supplier),
    }


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("case_json", type=Path)
    ap.add_argument("--evidence", type=Path, help="Private structured verifications and counterparty evidence")
    ap.add_argument("--supplier", type=Path, help="Private supplier identity; draft only, never sends")
    ap.add_argument("--output", required=True, type=Path)
    args = ap.parse_args()
    case = json.loads(args.case_json.read_text(encoding="utf-8"))
    evidence = json.loads(args.evidence.read_text(encoding="utf-8")) if args.evidence else None
    supplier = json.loads(args.supplier.read_text(encoding="utf-8")) if args.supplier else None
    package = create_package(case, evidence, supplier)
    args.output.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    args.output.write_text(json.dumps(package, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    args.output.chmod(0o600)
    print(json.dumps({
        "case_reference": case["case_reference"],
        "rfq_state": package["rfq"]["send_state"],
        "legal_preflight": package["compliance"]["status"],
        "send_allowed": package["rfq"]["send_allowed"],
        "equipment_options": len(package["rfq"]["lines"]),
    }))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
