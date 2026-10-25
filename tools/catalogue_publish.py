#!/usr/bin/env python3
"""Unattended, private Aurenoeva catalogue snapshot delivery over pinned SSH/SFTP.

Run only after an administrator provisions a restricted site SSH key and a private
destination outside the PHP public_html directory. A failed delivery leaves the
website's last good snapshot intact. Never sends customer or supplier data.
"""
from __future__ import annotations

import hashlib
import json
import time
import fcntl
from http.client import RemoteDisconnected, HTTPException
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import tempfile
from urllib.parse import urlsplit, urlencode
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

SCHEMA = "aurenoeva-catalogue/1"
IDENTITY = re.compile(r"^AUR-P-[A-F0-9]{20}$")
SAFE_HOST = re.compile(r"^[a-zA-Z0-9.-]+$")
SAFE_USER = re.compile(r"^[a-zA-Z_][a-zA-Z0-9_.-]*$")
SAFE_PATH = re.compile(r"^/[a-zA-Z0-9_.\/-]+$")
GENERIC = {
    "surgical carts", "surgical cart", "dental carts", "product range",
    "products", "product", "equipment", "dental equipment", "dental units",
    "medical devices", "medical equipment", "catalogue", "catalog",
    "all products", "accessories", "solutions",
}




def technical_product_relevance(sector: str, model: str, source: str) -> bool:
    """Reject clear search-result noise, not unfamiliar industrial OEM products."""
    parsed = urlsplit(source)
    host = (parsed.hostname or "").lower()
    path = parsed.path.lower()
    nontechnical_sources = {
        "de.pons.com", "pons.com", "www.pons.com", "lidl.de", "www.lidl.de",
        "ep.de", "www.ep.de", "notebookcheck.com", "www.notebookcheck.com",
        "pcwelt.de", "www.pcwelt.de", "computerbild.de", "www.computerbild.de",
        "thisoldhouse.com", "www.thisoldhouse.com",
        "langenscheidt.com", "www.langenscheidt.com",
        "tribesigns.de", "www.tribesigns.de",
    }
    if host in nontechnical_sources:
        return False
    if re.search(r"\bpons\b", model, re.I) and re.match(r"^97[89][-0-9]+$", model):
        return False
    if sector == "aviation" and re.search(r"geekom|mini[\s-]?pc|notebook", model, re.I):
        if not re.search(r"avionic|aircraft|ads[\s-]?b|transponder|flight control", model, re.I):
            return False
    if sector == "marine" and (
        re.search(r"\b(?:tacx|forerunner|smart garage)\b|^edge[®\s]", model, re.I)
        or "/fleet-optimisation/" in path
    ):
        return False
    if sector == "test" and host.endswith("ibm.com") and re.search(
        r"ns1 connect|watsonx|api connect", model, re.I
    ):
        return False
    return True

def refine_lab_category(sector: str, category: str, manufacturer: str, model: str, description: str, source: str = "") -> str:
    """Resolve technical facets from model/description/source, not crawler queries."""
    evidence = (model + " " + description + " " + source).casefold()
    if sector == "medical":
        if re.search(r"intellivue|benevision|patient.monitor|vital.signs|pulse.oxim", evidence):
            return "Patient monitoring"
        if re.search(r"anesth|anaesth|ventilat|\bwato\b", evidence):
            return "Surgical / procedural"
    if sector == "test":
        if re.search(r"multimeter|dmm7510|power.analy[sz]er", evidence):
            return "Meters & power analyzers"
        if re.search(r"oscilloscop|mso[0-9]|mdo[0-9]", evidence):
            return "Oscilloscopes"
    if sector != "laboratory":
        return category
    text = " ".join((manufacturer, model, description)).casefold()
    rules = (
        ("Mass spectrometry", r"mass spectrom|massenspektrom|quadrupole mass|\bmaldi\b|\btof[\s-]?ms\b"),
        ("Chromatography / HPLC / GC", r"chromatograph|\b(?:u?hplc|gpc|lc-ms)\b|separation column|chromatographiesäule"),
        ("Spectroscopy", r"spectroscop|spectromet(?!ry)|\bftir\b|\braman\b|spektroskop"),
        ("Balances & weighing", r"weigh|balance|waage|smartcheck|precision scale"),
        ("Incubation / ovens / environmental", r"incubat|\binc\s*125\b|\boven\b|climate chamber|environmental chamber|brutschrank"),
        ("Sample preparation", r"sample prepar|centrifug|grind|granulat|mill\b|milling|comminut|homogeni|pipett|p-[0-9]{1,2}\s+premium|fritsch.{0,80}\bp-[0-9]{1,2}\b"),
    )
    for proper, expression in rules:
        if re.search(expression, text):
            return proper
    if category == "Chromatography / HPLC / GC":
        return "General laboratory equipment"
    valid = ("Mass spectrometry", "Spectroscopy", "Sample preparation",
             "Balances & weighing", "Incubation / ovens / environmental",
             "General laboratory equipment")
    return category if category in valid else "General laboratory equipment"

def normalized_snapshot(path: Path, diagnostics: dict | None = None) -> tuple[bytes, int]:
    """Produce publishable, source-backed identities and aggregate exclusions."""
    if not path.is_file() or path.stat().st_size > 512_000_000:
        raise ValueError("missing_or_oversized_snapshot")
    raw = json.loads(path.read_text(encoding="utf-8"))
    if raw.get("schema") != SCHEMA or not isinstance(raw.get("items"), list):
        raise ValueError("invalid_snapshot_schema")
    records = {}
    reasons = {key: 0 for key in (
        "non_product_record", "invalid_canonical_id", "missing_manufacturer",
        "missing_model", "generic_heading", "invalid_https_source",
        "duplicate_canonical_id", "nontechnical_catalogue_record")}
    for value in raw["items"]:
        if not isinstance(value, dict):
            reasons["non_product_record"] += 1
            continue
        ident = str(value.get("canonical_id", ""))
        maker = str(value.get("manufacturer", "")).strip()[:100]
        model = str(value.get("model", "")).strip()[:100]
        source = str(value.get("source_ref", "")).strip()[:500]
        if not IDENTITY.fullmatch(ident):
            reasons["invalid_canonical_id"] += 1
            continue
        if not maker:
            reasons["missing_manufacturer"] += 1
            continue
        if not model:
            reasons["missing_model"] += 1
            continue
        if model.casefold() in GENERIC:
            reasons["generic_heading"] += 1
            continue
        try:
            p = urlsplit(source)
            valid = p.scheme == "https" and p.hostname and not p.username and not p.password
        except ValueError:
            valid = False
        if not valid:
            reasons["invalid_https_source"] += 1
            continue
        if not technical_product_relevance(str(value.get("sector", "")), model, source):
            reasons["nontechnical_catalogue_record"] += 1
            continue
        if ident in records:
            reasons["duplicate_canonical_id"] += 1
        records[ident] = {
            "canonical_id": ident,
            "manufacturer": maker,
            "model": model,
            "sector": str(value.get("sector", "")) if str(value.get("sector", "")) in (
                "laboratory", "test", "medical", "industrial", "energy", "marine", "aviation") else "",
            "category": refine_lab_category(
                str(value.get("sector", "")), str(value.get("category", ""))[:160],
                maker, model, str(value.get("description", ""))[:240], source),
            "description": str(value.get("description", ""))[:240],
            "source_ref": source,
            "lifecycle_status": "UNVERIFIED",
            "last_seen": str(value.get("last_seen", ""))[:35],
        }
    if diagnostics is not None:
        diagnostics.update({
            "exported_input_rows": len(raw["items"]),
            "publishable_unique_records": len(records),
            "excluded_rows": sum(reasons.values()),
            "exclusion_reasons": reasons,
        })
    if not records:
        raise ValueError("no_qualifying_catalogue_identities")
    # Product identities travel separately from private vendor evidence.
    payload = {
        "schema": SCHEMA,
        "items": [records[key] for key in sorted(records)],
        "availability_notice": "Product identity only; no supplier stock or price is implied.",
    }
    source_stats = raw.get("source_catalogue_stats")
    if isinstance(source_stats, dict):
        db_count = source_stats.get("db_products")
        if type(db_count) is int and 0 < db_count <= 10_000_000:
            # Public aggregate metadata is created from the very SQLite
            # snapshot uploaded here. No supplier/client/customer data.
            payload["public_stats"] = {
                "db_products": db_count,
                "exported_products": len(raw["items"]),
                "eligible_products": len(records),
                "excluded_products": len(raw["items"]) - len(records),
                "excluded_invalid_id": reasons["invalid_canonical_id"],
                "excluded_generic": reasons["generic_heading"],
                "excluded_missing_maker": reasons["missing_manufacturer"],
                "excluded_missing_model": reasons["missing_model"],
                "excluded_source": reasons["invalid_https_source"],
                "excluded_duplicates": reasons["duplicate_canonical_id"],
                "excluded_nonrecord": reasons["non_product_record"],
            }
    return json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8"), len(records)


def make_full_database_export(db_path: Path, dest: Path) -> dict:
    """Read all existing SQLite product identities; never mutate the database.

    The products table is authoritative. This bypasses stale or incomplete
    sidecar exports while preserving exact historical IDs and their evidence.
    """
    if not db_path.is_file():
        raise RuntimeError("canonical_catalogue_database_missing")
    try:
        with sqlite3.connect("file:" + str(db_path) + "?mode=ro", uri=True,
                             timeout=15) as conn:
            conn.row_factory = sqlite3.Row
            rows = [dict(row) for row in conn.execute(
                """SELECT product_id AS canonical_id,manufacturer,model,description,
                          source_url AS source_ref,sector,category,last_seen
                   FROM products ORDER BY manufacturer,model""")]
    except sqlite3.Error as exc:
        raise RuntimeError("canonical_catalogue_database_unreadable") from exc
    if not rows:
        raise RuntimeError("canonical_catalogue_database_empty")
    data = {"schema": SCHEMA, "items": rows,
            "source_catalogue_stats": {"db_products": len(rows)}}
    dest.parent.mkdir(parents=True, exist_ok=True)
    with dest.open("w", encoding="utf-8") as fp:
        json.dump(data, fp, ensure_ascii=False, separators=(",", ":"))
        fp.flush()
        os.fsync(fp.fileno())
    os.chmod(dest, 0o600)
    return {"db_products": len(rows), "exported_products": len(rows)}




def product_batches(payload: bytes, max_records: int = 600,
                    max_bytes: int = 250_000):
    """Bound each POST while keeping all historical products in the receiver."""
    doc = json.loads(payload)
    batch = []
    batch_bytes = 100
    for item in doc["items"]:
        encoded_len=len(json.dumps(item,ensure_ascii=False,separators=(",",":")).encode("utf-8"))
        if batch and (len(batch)>=max_records or batch_bytes+encoded_len>max_bytes):
            yield json.dumps({"schema":SCHEMA,"items":batch},
                             ensure_ascii=False,separators=(",",":")).encode("utf-8"),len(batch)
            batch=[]
            batch_bytes=100
        if encoded_len+100>15_000_000:raise ValueError("single_product_oversized")
        batch.append(item)
        batch_bytes+=encoded_len+1
    if batch:
        yield json.dumps({"schema":SCHEMA,"items":batch},
                         ensure_ascii=False,separators=(",",":")).encode("utf-8"),len(batch)


def vendor_evidence_batches(path: Path, max_records: int = 300):
    """Keep all private vendor evidence, streaming it as small replayable batches.

    Output never contains buyer, case, price, inventory or quote details.
    """
    data=json.loads(path.read_text(encoding="utf-8"))
    if data.get("schema") != SCHEMA:
        raise ValueError("invalid_snapshot_schema")
    records=[]
    def flush():
        payload=json.dumps({"schema":"aurenoeva-vendor-evidence/1",
                            "vendor_products":[v for kind,v in records if kind=="product"],
                            "vendor_leads":[v for kind,v in records if kind=="lead"]},
                           ensure_ascii=False,separators=(",",":")).encode("utf-8")
        if len(payload)>15_000_000:
            raise ValueError("vendor_batch_oversized")
        return payload,len(records)
    for kind,key,fields,urlfield in (
         ("lead","vendor_leads",
          ("website","evidence_url","sector","category","last_seen","checked","email","phone"),
          "evidence_url"),
         ("product","vendor_products",
          ("website","product_id","source_url","manufacturer","model","sector",
           "category","evidence_type","last_seen"),"source_url")):
        for v in data.get(key,[]):
            if not isinstance(v,dict): continue
            url=str(v.get(urlfield,""))
            try:
                parsed=urlsplit(url)
                if(parsed.scheme!="https" or not parsed.hostname or parsed.username or
                   parsed.password or str(v.get("website","")).lower()!=parsed.hostname):
                    continue
            except ValueError:
                continue
            if kind=="product" and not IDENTITY.fullmatch(str(v.get("product_id",""))):
                continue
            entry={k:str(v.get(k,""))[:(600 if k==urlfield else 160)] for k in fields}
            records.append((kind,entry))
            if len(records)>=max_records:
                yield flush()
                records=[]
    if records:
        yield flush()

def credentials(env: dict[str, str]) -> tuple[str, str, str, Path, Path]:
    host = env.get("AURENOEVA_SFTP_HOST", "").strip()
    user = env.get("AURENOEVA_SFTP_USER", "").strip()
    dest = env.get("AURENOEVA_SFTP_REMOTE_FILE", "").strip()
    key = Path(env.get("AURENOEVA_SFTP_KEY", "/not/configured"))
    known = Path(env.get("AURENOEVA_SFTP_KNOWN_HOSTS", "/not/configured"))
    if not host or not user or not dest:
        raise ValueError("site_sftp_not_configured")
    if not SAFE_HOST.fullmatch(host) or not SAFE_USER.fullmatch(user):
        raise ValueError("invalid_site_sftp_host_or_user")
    if (not SAFE_PATH.fullmatch(dest) or "//" in dest or ".." in dest.split("/")
            or "/public_html/" in dest.lower() or dest.lower().endswith("/public_html")):
        raise ValueError("remote_snapshot_must_be_private_absolute_path")
    if not key.is_file() or not known.is_file() or not known.stat().st_size:
        raise ValueError("site_sftp_identity_or_pinned_host_key_missing")
    return host, user, dest, key, known


def publish(snapshot: Path, state_dir: Path, env: dict[str, str]) -> dict:
    if not env.get("AURENOEVA_SFTP_HOST"):
        return {"status": "SKIPPED_UNCONFIGURED", "reason": "No site SFTP connection configured"}
    host, user, remote, key, known = credentials(env)
    raw, count = normalized_snapshot(snapshot)
    digest = hashlib.sha256(raw).hexdigest()
    state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    receipt = state_dir / "catalogue-published.sha256"
    if receipt.is_file() and receipt.read_text().strip() == digest:
        return {"status": "UNCHANGED", "records": count, "sha256": digest}
    with tempfile.TemporaryDirectory(prefix="catalogue-publish-", dir=state_dir) as work:
        local = Path(work) / "catalogue.json"
        local.write_bytes(raw)
        local.chmod(0o600)
        downloaded = Path(work) / "readback.json"
        remote_temp = remote + ".incoming-" + str(os.getpid())
        # OpenSSH sftp rename is atomic on the supported Hostinger SSH/SFTP server.
        # Existing destination remains intact if upload fails before rename.
        batch = "\n".join([
            "put " + str(local) + " " + remote_temp,
            "rename " + remote_temp + " " + remote,
            "get " + remote + " " + str(downloaded),
            "",
        ])
        command = [
            "sftp", "-q", "-b", "-", "-oBatchMode=yes",
            "-oStrictHostKeyChecking=yes", "-oConnectTimeout=12",
            "-oServerAliveInterval=10", "-oServerAliveCountMax=2",
            "-oUserKnownHostsFile=" + str(known), "-i", str(key),
            user + "@" + host,
        ]
        result = subprocess.run(command, input=batch, text=True, capture_output=True,
                                timeout=75, check=False)
        if result.returncode or not downloaded.is_file():
            raise RuntimeError("site_sftp_publish_failed; last known-good site snapshot preserved where possible")
        if hashlib.sha256(downloaded.read_bytes()).hexdigest() != digest:
            raise RuntimeError("site_sftp_readback_checksum_mismatch")
    temp_receipt = receipt.with_suffix(".new")
    temp_receipt.write_text(digest + "\n")
    os.replace(temp_receipt, receipt)
    return {"status": "PUBLISHED", "records": count, "sha256": digest}



def verify_public_catalogue(payload: bytes, count: int, digest: str) -> None:
    """Require actual public count and product search, not merely an upload ACK."""
    root = "https://aurenoeva.com/"
    query = urlencode({"verify": digest[:20]})
    request = Request(root + "catalogue-count.php?" + query, headers={
        "User-Agent": "AurenoevaCataloguePublisher/3.0",
        "Cache-Control": "no-cache"})
    with urlopen(request, timeout=40) as response:
        status = json.loads(response.read(16_384))
    if (status.get("ok") is not True
        or status.get("source") != "published_catalogue_snapshot"
        or status.get("searchable_parts") != count):
        raise RuntimeError("live_website_count_readback_mismatch")
    items = json.loads(payload)["items"]
    candidate = next((p for p in reversed(items)
        if 4 <= len(p["model"]) <= 90 and any(ch.isdigit() for ch in p["model"])
        and len(p["manufacturer"] + " " + p["model"]) < 165), None)
    if candidate is None:
        raise RuntimeError("catalogue_search_probe_has_no_model")
    product_query = urlencode({"q": candidate["manufacturer"] + " " + candidate["model"],
                               "verify": digest[:20]})
    request = Request(root + "catalogue-search.php?" + product_query,
                      headers={"User-Agent": "AurenoevaCataloguePublisher/3.0",
                               "Cache-Control": "no-cache"})
    with urlopen(request, timeout=65) as response:
        result = json.loads(response.read(250_000))
    if (result.get("source") != "AURENOEVA_OWNED_CATALOGUE"
        or not any(item.get("canonical_id") == candidate["canonical_id"]
                   for item in result.get("items", []))):
        raise RuntimeError("live_website_product_search_readback_mismatch")
    print(json.dumps({"event": "LIVE_CATALOGUE_ACCEPTANCE",
                      "status": "PASS", "website_records": count,
                      "searched_model": candidate["model"]}), flush=True)


def publish_fast(snapshot: Path, state_dir: Path, env: dict[str, str]) -> dict:
    """Send the full searchable catalogue once; no per-batch PHP database merges.

    The existing website keeps the previous snapshot until it verifies the new
    checksum and switches the private file atomically. Supplier stock and prices
    are deliberately outside the product publication process.
    """
    token_path = Path(env.get("AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE",
                              "/var/lib/aurenoeva/catalogue-sync-token"))
    if not token_path.is_file():
        return {"status": "SKIPPED_UNCONFIGURED"}
    if token_path.stat().st_mode & 0o077:
        raise ValueError("catalogue_push_key_permissions")
    token = token_path.read_text(encoding="ascii").strip()
    if not re.fullmatch(r"[a-f0-9]{64}", token):
        raise ValueError("catalogue_push_key_invalid")
    diagnostics = {}
    payload, count = normalized_snapshot(snapshot, diagnostics=diagnostics)
    print(json.dumps({"event": "CATALOGUE_ELIGIBILITY_RECONCILIATION",
                      **diagnostics}, sort_keys=True), flush=True)
    digest = hashlib.sha256(payload).hexdigest()
    manifest = json.dumps({"sha256": digest, "bytes": len(payload),
                           "count": count}, separators=(",", ":")).encode()
    root = "https://aurenoeva.com/catalogue-sync.php?mode=bulk-"

    def post(action: str, body: bytes, offset: int = 0) -> dict:
        headers = {
            "User-Agent": "AurenoevaCataloguePublisher/3.0",
            "X-Aurenoeva-Sync-Token": token,
            "Content-Type": "application/octet-stream" if action == "chunk"
                            else "application/json",
        }
        if action == "chunk":
            headers["X-Aurenoeva-Upload-Sha256"] = digest
            headers["X-Aurenoeva-Upload-Offset"] = str(offset)
        for attempt in range(3):
            req = Request(root + action, data=body, method="POST", headers=headers)
            try:
                with urlopen(req, timeout=75) as response:
                    result = json.loads(response.read(16384).decode("utf-8"))
                if result.get("ok") is not True or result.get("sha256") != digest:
                    raise RuntimeError("bulk_site_acknowledgement_invalid")
                return result
            except HTTPError as exc:
                reason = "http_" + str(exc.code)
                try:
                    detail = json.loads(exc.read(2048))
                    reason += ":" + str(detail.get("reason_code", ""))[:90]
                except (ValueError, UnicodeError):
                    pass
                if exc.code in (401, 403, 404, 422):
                    raise RuntimeError("bulk_site_rejected_" + reason) from exc
                if attempt == 2:
                    raise RuntimeError("bulk_site_unavailable_" + reason) from exc
            except (URLError, OSError, HTTPException, TimeoutError) as exc:
                if attempt == 2:
                    raise RuntimeError("bulk_transport_failed_" + type(exc).__name__) from exc
            time.sleep(attempt + 1)
        raise RuntimeError("bulk_transfer_unavailable")

    start = post("begin", manifest)
    if start.get("status") != "READY" or start.get("offset") != 0:
        raise RuntimeError("bulk_site_not_ready")
    print(json.dumps({"event": "CATALOGUE_UPLOAD_STARTED",
                      "records": count, "bytes": len(payload)}), flush=True)

    batches = 0
    for offset in range(0, len(payload), 512 * 1024):
        chunk = payload[offset:offset + 512 * 1024]
        result = post("chunk", chunk, offset=offset)
        if result.get("status") != "RECEIVED" or result.get("offset") != offset + len(chunk):
            raise RuntimeError("bulk_chunk_readback_failed")
        batches += 1

    finished = post("commit", manifest)
    if finished.get("status") != "PUBLISHED" or finished.get("records") != count:
        raise RuntimeError("bulk_published_count_mismatch")
    # Only after real public count AND search do we save a success receipt.
    verify_public_catalogue(payload, count, digest)
    state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    receipt = state_dir / "catalogue-products-pushed.sha256"
    temp = receipt.with_suffix(".new")
    temp.write_text(digest + "\n", encoding="ascii")
    os.replace(temp, receipt)
    print(json.dumps({"event": "PRODUCTS_PUBLISHED", "records": count,
                      "bytes": len(payload), "batches": batches}), flush=True)
    return {"status": "PRODUCTS_PUBLISHED", "records": count,
            "submitted_products": count, "product_batches": batches,
            "sha256": digest, "vendor_pending": True}


def publish_https(snapshot: Path, state_dir: Path, env: dict[str, str], mode: str = "all") -> dict:
    """Publish all products, then all vendor evidence in bounded HTTPS batches.

    Each batch is authenticated and acknowledged. Any failure leaves previous
    records intact; the next run safely repeats unfinished batches.
    """
    token_path=Path(env.get("AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE",
                            "/var/lib/aurenoeva/catalogue-sync-token"))
    if not token_path.is_file():
        return {"status":"SKIPPED_UNCONFIGURED","reason":"Catalogue push key not installed"}
    if token_path.stat().st_mode & 0o077:
        raise ValueError("catalogue_push_key_permissions")
    token=token_path.read_text(encoding="ascii").strip()
    if not re.fullmatch(r"[a-f0-9]{64}",token):
        raise ValueError("catalogue_push_key_invalid")
    payload,count=normalized_snapshot(snapshot)
    digest=hashlib.sha256(payload).hexdigest()
    state_dir.mkdir(mode=0o700,parents=True,exist_ok=True)
    receipt=state_dir/"catalogue-pushed.sha256"
    def send(body,mode):
        uri="https://aurenoeva.com/catalogue-sync.php"
        if mode=="vendors":uri+="?mode=vendors"
        request=Request(uri,data=body,method="POST",headers={
            "Content-Type":"application/json",
            "User-Agent":"AurenoevaCataloguePublisher/2.1",
            "X-Aurenoeva-Sync-Token":token,
        })
        try:
            with urlopen(request,timeout=70) as response:
                if response.status!=200:
                    raise RuntimeError("site_rejected_batch_http_"+str(response.status))
                result=json.loads(response.read(16384).decode("utf-8"))
        except HTTPError as e:
            # Authenticated server provides only a safe reason_code (never
            # private record data, filesystem paths or credentials).
            try:
                detail=json.loads(e.read(2048).decode("utf-8"))
                reason=detail.get("reason_code","")
                if not isinstance(reason,str) or not re.fullmatch(r"[A-Za-z0-9_]{1,90}",reason):
                    reason="unspecified"
            except (ValueError,UnicodeError):
                reason="unspecified"
            tag="site_rejected_http_"+str(e.code)+":"+reason
            if e.code in (401,403):
                raise ValueError("site_auth_rejected_http_"+str(e.code)) from e
            if e.code in (413,429,500,502,503,504):
                raise ConnectionError(tag) from e
            raise ValueError(tag) from e
        except (URLError,OSError,HTTPException,TimeoutError) as exc:
            raise ConnectionError("site_connection_"+type(exc).__name__+":_"+str(exc)[:90]) from exc
        if (result.get("ok") is not True or
                result.get("source_sha256")!=hashlib.sha256(body).hexdigest()):
            raise RuntimeError("site_catalogue_batch_readback_failed")
        return result
    accepted=0
    batches=0
    published_total=0
    vendor_batches=0
    evidence=0
    def transfer(body,number,mode,offset):
        """Retries transient failures; split only after retries exhausted.

        Progress output makes server-side request limits visible in journalctl.
        Does not count any unacknowledged record as published.
        """
        for attempt in range(1,4):
            try:
                result=send(body,mode)
                if mode=="products":
                    if (result.get("status")!="PUBLISHED" or
                        result.get("accepted_input_records")!=number or
                        result.get("records",0)<number or
                        not re.fullmatch(r"[a-f0-9]{64}",str(result.get("sha256","")))):
                        raise ValueError("site_catalogue_product_batch_not_acknowledged")
                elif result.get("status")!="MERGED":
                    raise ValueError("site_vendor_evidence_batch_not_acknowledged")
                print(json.dumps({"event":"BATCH_ACK","kind":mode,"offset":offset,
                     "records":number,"bytes":len(body),"attempt":attempt,
                     "site_records":result.get("records")}),flush=True)
                return result,1
            except ConnectionError as error:
                print(json.dumps({"event":"BATCH_RETRY","kind":mode,
                      "offset":offset,"records":number,"bytes":len(body),
                      "attempt":attempt,"reason":str(error)[:130]}),flush=True)
                if attempt<3: time.sleep(attempt)
            except ValueError as error:
                # Split opaque 422 rejects to distinguish one problematic row
                # from an entire failed batch. Never discard the last row.
                reason=str(error)
                print(json.dumps({"event":"BATCH_REJECTED","kind":mode,
                      "offset":offset,"records":number,"reason":reason[:130]}),flush=True)
                if (not reason.startswith("site_rejected_http_422:") or
                    number<=1 or any(reason.endswith(":"+code) for code in (
                        "existing_catalogue_snapshot_unreadable",
                        "existing_catalogue_schema_invalid",
                        "catalogue_snapshot_write_lock_unavailable",
                        "snapshot_temp_unavailable",
                        "snapshot_write_failed", "snapshot_replace_failed",
                        "snapshot_readback"))):
                    raise
                break
        # If a host/proxy disconnects on medium requests, split in half.
        if number<=25:
            raise RuntimeError("site_transfer_failed_after_retry:"+
                               mode+":offset="+str(offset)+":records="+str(number))
        doc=json.loads(body)
        key="items" if mode=="products" else None
        if key:
            items=[("items",v) for v in doc["items"]]
        else:
            items=[("vendor_leads",v) for v in doc.get("vendor_leads",[])]
            items.extend(("vendor_products",v) for v in doc.get("vendor_products",[]))
        pivot=len(items)//2
        if not pivot:
            raise RuntimeError("site_transfer_cannot_split:"+mode)
        def fragment(part):
            payload={"schema":doc["schema"]}
            if mode=="products": payload["items"]=[v for _,v in part]
            else:
                payload["vendor_leads"]=[v for k,v in part if k=="vendor_leads"]
                payload["vendor_products"]=[v for k,v in part if k=="vendor_products"]
            return json.dumps(payload,ensure_ascii=False,separators=(",",":")).encode("utf-8")
        print(json.dumps({"event":"BATCH_SPLIT","kind":mode,
               "offset":offset,"records":number,"left":pivot,
               "right":len(items)-pivot}),flush=True)
        left,n1=transfer(fragment(items[:pivot]),pivot,mode,offset)
        right,n2=transfer(fragment(items[pivot:]),len(items)-pivot,mode,offset+pivot)
        return right,n1+n2
    products_receipt=state_dir/"catalogue-products-pushed.sha256"
    if mode!="vendors":
        for batch,number in product_batches(payload):
            result,used=transfer(batch,number,"products",accepted)
            accepted+=number
            batches+=used
            published_total=result["records"]
        if accepted!=count:
            raise RuntimeError("site_catalogue_incomplete_product_batches")
        temp=products_receipt.with_suffix(".new")
        temp.write_text(digest+"\n",encoding="ascii")
        os.replace(temp,products_receipt)
        print(json.dumps({"event":"PRODUCTS_PUBLISHED","records":published_total,
             "submitted_products":accepted,"batches":batches}),flush=True)
        if mode=="products":
            return {"status":"PRODUCTS_PUBLISHED","records":published_total,
                    "submitted_products":accepted,"product_batches":batches,
                    "vendor_pending":True,"sha256":digest}
    elif not products_receipt.is_file() or products_receipt.read_text().strip()!=digest:
        raise RuntimeError("vendor_sync_requires_product_publication_for_same_snapshot")
    for body,number in vendor_evidence_batches(snapshot):
        result,used=transfer(body,number,"vendors",evidence)
        vendor_batches+=used
        evidence+=number
    # This receipt means both product and vendor batches finished, not merely
    # that the website acknowledged one small initial fragment.
    temp=receipt.with_suffix(".new")
    temp.write_text(digest+"\n",encoding="ascii")
    os.replace(temp,receipt)
    return {"status":"VENDORS_PUBLISHED" if mode=="vendors" else "PUBLISHED",
            "records":published_total if mode=="all" else None,
            "submitted_products":accepted if mode=="all" else None,
            "product_batches":batches,
            "vendor_evidence_records":evidence,"vendor_batches":vendor_batches,
            "sha256":digest}


def main() -> int:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--snapshot", type=Path, default=Path("/var/lib/aurenoeva/catalogue-public.json"))
    parser.add_argument("--state-dir", type=Path, default=Path("/var/lib/aurenoeva"))
    stage=parser.add_mutually_exclusive_group()
    stage.add_argument("--products-only",action="store_true",
                       help="Restore website search before large vendor evidence transfer")
    stage.add_argument("--vendors-only",action="store_true",
                       help="Sync RFQ vendors after products from the identical snapshot are published")
    args = parser.parse_args()
    try:
        env = dict(os.environ)
        args.state_dir.mkdir(mode=0o700,parents=True,exist_ok=True)
        with (args.state_dir/"catalogue-publisher.lock").open("a+") as lock:
            try:
                fcntl.flock(lock,fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise RuntimeError("ANOTHER_CATALOGUE_PUBLICATION_IN_PROGRESS")
            if Path(env.get("AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE",
                         "/var/lib/aurenoeva/catalogue-sync-token")).is_file():
                # Catalogue publication must never wait on RFQ vendor enrichment.
                # Vendor evidence can still be transferred separately on demand.
                if args.vendors_only:
                    result=publish_https(args.snapshot,args.state_dir,env,mode="vendors")
                elif args.snapshot == Path("/var/lib/aurenoeva/catalogue-public.json"):
                    # Site publishing always reconciles directly against the
                    # canonical SQLite DB, even if a prior JSON export stalled.
                    with tempfile.TemporaryDirectory(prefix="catalogue-full-",
                            dir=args.state_dir) as work:
                        fresh=Path(work)/"products-only.json"
                        counts=make_full_database_export(
                            args.state_dir/"catalogue.sqlite3",fresh)
                        print(json.dumps({"event":"CATALOGUE_CANONICAL_DB_EXPORT",
                            **counts}),flush=True)
                        result=publish_fast(fresh,args.state_dir,env)
                else:
                    result=publish_fast(args.snapshot,args.state_dir,env)
            else:
                if args.products_only or args.vendors_only:
                    raise ValueError("split_stages_require_configured_https_token")
                result=publish(args.snapshot,args.state_dir,env)
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as exc:
        print(json.dumps({"status": "DELIVERY_FAILED", "reason": str(exc)}))
        return 1
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
