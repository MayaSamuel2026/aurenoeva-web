#!/usr/bin/env python3
"""AURENOEVA private procurement-case intake: XLSX/CSV -> canonical draft JSON.

Standard-library only. Original customer files and output remain private.
This converter never writes to the public catalogue, sends RFQs, or claims stock.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

_NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
_XREL = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"
_PKG = "{http://schemas.openxmlformats.org/package/2006/relationships}"
MAX_BYTES = 20_000_000
MAX_ROWS = 5000


def _xlsx_rows(path: Path) -> list[list[str]]:
    with zipfile.ZipFile(path) as book:
        members = book.infolist()
        if len(members) > 500 or sum(f.file_size for f in members) > 70_000_000:
            raise ValueError("oversized_or_suspicious_workbook")
        wb = ET.fromstring(book.read("xl/workbook.xml"))
        rels = ET.fromstring(book.read("xl/_rels/workbook.xml.rels"))
        mapping = {rel.get("Id"): rel.get("Target") for rel in rels.findall(_PKG + "Relationship")}
        sheets = wb.find(_NS + "sheets")
        if sheets is None or not list(sheets):
            raise ValueError("workbook_has_no_sheets")
        relid = sheets[0].get(_XREL + "id")
        target = mapping.get(relid, "worksheets/sheet1.xml")
        sheet_path = target.lstrip("/") if target.startswith("/xl/") else (
            target.lstrip("/") if target.startswith("xl/") else "xl/" + target
        )
        if not sheet_path.startswith("xl/"):
            sheet_path = "xl/" + sheet_path
        strings = []
        if "xl/sharedStrings.xml" in book.namelist():
            shared = ET.fromstring(book.read("xl/sharedStrings.xml"))
            for node in shared.findall(_NS + "si"):
                strings.append("".join((el.text or "") for el in node.iter(_NS + "t")))
        root = ET.fromstring(book.read(sheet_path))
        result = []
        for row in root.iter(_NS + "row"):
            values = {}
            for cell in row.findall(_NS + "c"):
                ref = cell.get("r", "")
                match = re.match(r"([A-Z]+)", ref)
                if not match:
                    continue
                column = 0
                for char in match[1]:
                    column = column * 26 + ord(char) - 64
                if column > 40:
                    continue
                kind = cell.get("t")
                val = cell.find(_NS + "v")
                inline = cell.find(_NS + "is")
                if kind == "s" and val is not None and val.text:
                    value = strings[int(val.text)]
                elif kind == "inlineStr" and inline is not None:
                    value = "".join((el.text or "") for el in inline.iter(_NS + "t"))
                else:
                    value = val.text if val is not None else ""
                values[column - 1] = value or ""
            result.append([values.get(i, "") for i in range(max(values, default=-1) + 1)])
            if len(result) > MAX_ROWS:
                raise ValueError("too_many_rows")
        return result


def _csv_rows(path: Path) -> list[list[str]]:
    with path.open(encoding="utf-8-sig", newline="") as handle:
        sample = handle.read(4096)
        handle.seek(0)
        try:
            dialect = csv.Sniffer().sniff(sample, delimiters=",;\t")
        except csv.Error:
            dialect = csv.excel
        rows = list(csv.reader(handle, dialect))
    if len(rows) > MAX_ROWS:
        raise ValueError("too_many_rows")
    return rows


def parse_rows(rows: list[list[str]]) -> list[dict]:
    if not rows:
        raise ValueError("empty_input")

    def norm(value):
        return " ".join(str(value or "").casefold().strip().split()).replace(".", "")

    descriptions = {"equipment description", "equipment specification (normalized verbatim)",
                    "description", "description_original"}
    header_idx = next((i for i, row in enumerate(rows[:20]) if any(norm(x) in descriptions for x in row)), None)
    if header_idx is None:
        raise ValueError("unsupported_headers_expected_equipment_description")
    headers = [norm(x) for x in rows[header_idx]]

    def pos(*choices):
        return next((i for i, x in enumerate(headers) if x in choices), None)

    desc_col = pos(*descriptions)
    num_col = pos("no", "buyer no", "customer item number", "customer_item_number")
    qty_col = pos("qty", "quantity")
    selection_col = pos("selection", "option type", "option_type")
    if num_col is None:
        raise ValueError("missing_customer_line_number_column")

    def at(row, index):
        return str(row[index] or "").strip() if index is not None and index < len(row) else ""

    lines = []
    last_no = None
    for source_row, row in enumerate(rows[header_idx + 1:], start=header_idx + 2):
        description = " ".join(at(row, desc_col).split())
        if not description:
            continue
        num = at(row, num_col)
        if re.fullmatch(r"\d+(?:\.0+)?", num):
            item_no = int(float(num))
            alternative = selection_col is not None and at(row, selection_col).casefold().startswith("alternative")
            last_no = item_no
        elif not num and last_no is not None:
            item_no = last_no
            alternative = True
        else:
            raise ValueError(f"invalid_line_number_at_row_{source_row}")

        quantity_raw = at(row, qty_col)
        if quantity_raw:
            if not re.fullmatch(r"\d+(?:\.0+)?", quantity_raw) or int(float(quantity_raw)) < 1:
                raise ValueError(f"invalid_quantity_at_row_{source_row}")
            quantity = int(float(quantity_raw))
        else:
            quantity = None

        lines.append({
            "source_row": source_row,
            "customer_item_number": item_no,
            "option_group": f"ITEM-{item_no:02d}",
            "option_type": "ALTERNATIVE" if alternative else "REQUESTED",
            "description_original": description,
            "quantity": quantity,
            "canonical_part_id": None,
            "technical_status": "UNRESOLVED",
            "rfq_status": "NOT_SENT",
        })
    if not lines:
        raise ValueError("no_equipment_lines")
    return lines


def build_payload(source: Path, case_reference: str, country: str) -> dict:
    if source.stat().st_size > MAX_BYTES:
        raise ValueError("input_too_large")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{3,95}", case_reference):
        raise ValueError("invalid_case_reference")
    if not re.fullmatch(r"[A-Z]{2}", country):
        raise ValueError("country_must_be_ISO_3166_alpha2")
    if source.suffix.lower() == ".xlsx":
        rows = _xlsx_rows(source)
    elif source.suffix.lower() == ".csv":
        rows = _csv_rows(source)
    else:
        raise ValueError("supported_types_xlsx_csv")

    items = parse_rows(rows)
    return {
        "schema": "aurenoeva-procurement-case-import/1.0",
        "case_reference": case_reference,
        "enquiry_country": country,
        "delivery_country": country,
        "customer_name": None,
        "delivery_location": None,
        "source_file_name": source.name,
        "source_sha256": hashlib.sha256(source.read_bytes()).hexdigest(),
        "import_state": "PREPARED_NOT_COMMITTED",
        "catalogue_publication": False,
        "supplier_offers_received": 0,
        "supplier_contact_conducted": False,
        "counts": {
            "numbered_requirements": len({x["customer_item_number"] for x in items}),
            "equipment_entries": len(items),
            "alternative_options": sum(x["option_type"] == "ALTERNATIVE" for x in items),
        },
        "sourcing_lines": items,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path)
    parser.add_argument("--case", required=True)
    parser.add_argument("--country", required=True, help="Destination ISO country code, e.g. UZ")
    parser.add_argument("--output", required=True, type=Path, help="Private destination JSON path")
    args = parser.parse_args()
    payload = build_payload(args.source, args.case, args.country.upper())
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"case_reference": payload["case_reference"], "status": payload["import_state"],
                      **payload["counts"]}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ValueError, zipfile.BadZipFile, KeyError, ET.ParseError) as exc:
        print(f"AURENOEVA intake error: {exc}", file=sys.stderr)
        raise SystemExit(2)
