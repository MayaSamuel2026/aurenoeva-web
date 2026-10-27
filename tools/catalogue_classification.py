"""Evidence-backed, multi-industry technical facets for the existing AURENOEVA catalogue.

Only product model/description text supports a category. Discovery keywords, source
sector, manufacturer identity and URL path alone never create a new classification.
A legacy sector may corroborate a concrete part-type match; an additional industry
requires an explicit application/context match in the same product text.
This module does not change product identifiers, offers, stock or RFQs.
"""
from __future__ import annotations

import re
from urllib.parse import urlsplit

VERSION = "technical-facets/1"
INDUSTRIES = ("laboratory", "test", "medical", "industrial", "energy", "marine", "aviation")
CONTEXT = {
    "laboratory": r"\b(?:laborator(?:y|ies)|lab(?:or)?\b|analytical|research)\b",
    "test": r"\b(?:test(?:ing)?|measurement|calibration|metrology|electronic test)\b",
    "medical": r"\b(?:medical|clinical|patient|hospital|healthcare|diagnostic|surgical)\b",
    "industrial": r"\b(?:industrial|factory|manufacturing|plant automation|process control)\b",
    "energy": r"\b(?:energy|power generation|power grid|substation|renewable|utility|wind turbine)\b",
    "marine": r"\b(?:marine|maritime|ship(?:board)?|vessel|offshore|naval)\b",
    "aviation": r"\b(?:aircraft|aviation|aerospace|avionic|airframe|flight|airport)\b",
}
# Category strings are identical to the existing 49 website sectors/subcategories.
# Rules are deliberately specific: a generic "sensor", "monitor", "control"
# or "pump" without an application does not imply aviation or medical usage.
RULES = {
    "laboratory": (
        ("Mass spectrometry", r"mass spectrom|massenspektrom|\bmaldi\b|\btof[\s-]?ms\b"),
        ("Chromatography / HPLC / GC", r"chromatograph|\b(?:u?hplc|gc-ms|lc-ms|gpc)\b|separation column"),
        ("Spectroscopy", r"spectroscop|\bftir\b|\braman\b|spektroskop"),
        ("Sample preparation", r"centrifug|homogeni|pipett|sample prepar|planetary ball mill|grinding mill"),
        ("Balances & weighing", r"\b(?:analytical balance|precision balance|laboratory scale|weighing|waage)\b"),
        ("Incubation / ovens / environmental", r"incubat|climate chamber|environmental chamber|brutschrank|\b(?:laboratory oven)\b"),
    ),
    "test": (
        ("Oscilloscopes", r"oscilloscop|\b(?:mso|mdo)[- ]?\d{3,}\b"),
        ("Spectrum / signal analyzers", r"spectrum analy[sz]er|signal analy[sz]er|network analy[sz]er"),
        ("Signal sources / generators", r"signal generator|function generator|waveform generator"),
        ("Meters & power analyzers", r"multimeter|power analy[sz]er|clamp meter|\bdmm\s?\d+"),
        ("RF / microwave", r"\b(?:rf|microwave|vector network)\b"),
        ("Calibration equipment", r"calibrat(?:or|ion equipment)|reference standard"),
        ("Data acquisition", r"data acquisition|\bdaq\b|digitizer"),
    ),
    "medical": (
        ("Diagnostic equipment", r"diagnostic|ultrasound|ecg|electrocardiograph"),
        ("Imaging & visualization", r"\b(?:mri|ct scanner|endoscop|x-ray|radiograph)\b"),
        ("Patient monitoring", r"patient monitor|intellivue|benevision|vital signs|pulse oxim"),
        ("Laboratory / pathology", r"clinical laborator|patholog|blood analy[sz]er|diagnostic centrifug"),
        ("Surgical / procedural", r"anesth|anaesth|ventilat|surgical|operating table|\bwato\b"),
        ("Sterilization & reprocessing", r"steriliz|sterilis|autoclave|washer disinfector"),
    ),
    "industrial": (
        ("PLC / control modules", r"\bplc\b|programmable logic controller|simatic|control module"),
        ("Drives / servo / motion", r"servo drive|variable frequency drive|frequency inverter|motion controller"),
        ("Motors / gearboxes", r"gearbox|gearmotor|electric motor|geared motor"),
        ("Sensors / instrumentation", r"pressure transducer|flow sensor|temperature sensor|industrial sensor|proximity sensor"),
        ("Pneumatics / hydraulics", r"hydraulic|pneumatic|compressed air"),
        ("Robotics / automation", r"industrial robot|robotic arm|robot controller"),
        ("Machine / process equipment", r"process equipment|machine tool|conveyor|industrial pump"),
    ),
    "energy": (
        ("Transformers", r"transformer|transformator"),
        ("Switchgear & protection", r"switchgear|circuit breaker|protection relay"),
        ("Generators", r"generator|generating set|alternator"),
        ("Power conversion / inverters", r"power inverter|power converter|rectifier|pv inverter"),
        ("Power quality / monitoring", r"power quality|grid monitor|power meter"),
        ("Turbine / plant components", r"turbine|power plant component"),
        ("Storage / auxiliary systems", r"battery storage|energy storage|ups system"),
    ),
    "marine": (
        ("Engines & propulsion", r"marine engine|propulsion|ship engine|outboard engine"),
        ("Pumps / fluid systems", r"pump|hydraulic|fluid handling"),
        ("Electrical & switchgear", r"switchgear|alternator|marine electrical|power distribution"),
        ("Navigation / instrumentation", r"radar|navigation|sonar|compass|instrumentation|sensor"),
        ("Deck machinery", r"deck crane|winch|windlass"),
        ("HVAC / refrigeration", r"refrigerat|hvac|chiller"),
        ("Safety / auxiliary systems", r"lifeboat|fire suppression|bilge"),
    ),
    "aviation": (
        ("Avionics", r"avionic|transponder|flight control|aircraft radar|ads[\s-]?b"),
        ("Electrical components", r"generator|alternator|electrical|wiring harness"),
        ("Hydraulic / pneumatic", r"hydraulic|pneumatic"),
        ("Engine / powerplant components", r"engine|turbine|powerplant"),
        ("Landing gear / actuation", r"landing gear|actuator"),
        ("Ground support / test equipment", r"ground support|aviation test|aircraft maintenance"),
        ("Traceable specialist components", r"traceable aircraft|aircraft part number"),
    ),
}


# Shared part types are not enough to establish sector-specific suitability.
# Even the inherited crawl sector is merely a discovery hint for these types.
CONTEXT_REQUIRED = {
    "aviation": {"Electrical components", "Hydraulic / pneumatic", "Engine / powerplant components"},
    "marine": {"Pumps / fluid systems", "Electrical & switchgear", "Navigation / instrumentation",
               "HVAC / refrigeration", "Safety / auxiliary systems"},
    "medical": {"Diagnostic equipment"},
}

def classify(record: dict) -> list[dict]:
    """Conservative, reproducible classifications with explicit evidence field."""
    source = str(record.get("source_ref") or record.get("source_url") or "")
    parts = urlsplit(source)
    if parts.scheme != "https" or not parts.hostname or parts.username or parts.password:
        return []
    model = str(record.get("model") or "")[:160]
    description = str(record.get("description") or "")[:240]
    hint = str(record.get("sector") or "")
    results = []
    for industry in INDUSTRIES:
        match_context = re.search(CONTEXT[industry], model + " " + description, re.I)
        # The old source sector alone cannot supply a technical *category*.
        # For a second industry an application must be stated on the product page.
        if industry != hint and not match_context:
            continue
        for category, pattern in RULES[industry]:
            if not match_context and category in CONTEXT_REQUIRED.get(industry, ()):
                continue
            found_model = re.search(pattern, model, re.I)
            found_desc = re.search(pattern, description, re.I) if not found_model else None
            found = found_model or found_desc
            if found is None:
                continue
            results.append({
                "industry": industry, "category": category,
                "evidence_url": source, "evidence_field": "model" if found_model else "description",
                "evidence_term": found.group(0)[:75],
                "context_term": match_context.group(0)[:75] if match_context else "",
                "method": VERSION, "confidence": "MATCHED_TEXT",
            })
            break  # one most-specific category per industry
    return results


def enrich_rows(rows: list[dict]) -> None:
    """Called at export: automatically backfill ALL historical product records."""
    for row in rows:
        matches = classify(row)
        row["classifications"] = matches
        row["industries"] = [entry["industry"] for entry in matches]
        row["classification_status"] = "EVIDENCE_MATCHED" if matches else "UNCLASSIFIED"


def init_classifications(db) -> None:
    db.execute("""CREATE TABLE IF NOT EXISTS product_classifications (
        product_id TEXT NOT NULL, industry TEXT NOT NULL, category TEXT NOT NULL,
        evidence_url TEXT NOT NULL, evidence_field TEXT NOT NULL,
        evidence_term TEXT NOT NULL, context_term TEXT NOT NULL,
        method TEXT NOT NULL, last_checked TEXT NOT NULL,
        PRIMARY KEY(product_id,industry))""")
    db.execute("CREATE INDEX IF NOT EXISTS idx_product_classification_industry ON product_classifications(industry,category)")


def store_classifications(db, record: dict, checked_at: str) -> None:
    """Refresh only this product's facets; no edits to products or offers."""
    product_id = record["product_id"]
    matched = classify(record)
    db.execute("DELETE FROM product_classifications WHERE product_id=?", (product_id,))
    for item in matched:
        db.execute("""INSERT INTO product_classifications
        (product_id,industry,category,evidence_url,evidence_field,evidence_term,
         context_term,method,last_checked) VALUES (?,?,?,?,?,?,?,?,?)""",
         (product_id,item["industry"],item["category"],item["evidence_url"],
          item["evidence_field"],item["evidence_term"],item["context_term"],
          item["method"],checked_at))
