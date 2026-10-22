#!/usr/bin/env python3
"""Unattended, private Aurenoeva catalogue snapshot delivery over pinned SSH/SFTP.

Run only after an administrator provisions a restricted site SSH key and a private
destination outside the PHP public_html directory. A failed delivery leaves the
website's last good snapshot intact. Never sends customer or supplier data.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
from urllib.parse import urlsplit
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


def normalized_snapshot(path: Path) -> tuple[bytes, int]:
    if not path.is_file() or path.stat().st_size > 512_000_000:
        raise ValueError("missing_or_oversized_snapshot")
    raw = json.loads(path.read_text(encoding="utf-8"))
    if raw.get("schema") != SCHEMA or not isinstance(raw.get("items"), list):
        raise ValueError("invalid_snapshot_schema")
    records = {}
    for value in raw["items"]:
        if not isinstance(value, dict):
            continue
        ident = str(value.get("canonical_id", ""))
        maker = str(value.get("manufacturer", "")).strip()[:100]
        model = str(value.get("model", "")).strip()[:100]
        source = str(value.get("source_ref", "")).strip()[:500]
        p = urlsplit(source)
        if (not IDENTITY.fullmatch(ident) or not maker or not model
                or model.casefold() in GENERIC
                or p.scheme != "https" or not p.hostname or p.username or p.password):
            continue
        records[ident] = {
            "canonical_id": ident,
            "manufacturer": maker,
            "model": model,
            "sector": str(value.get("sector", "")) if str(value.get("sector", "")) in ("laboratory", "test", "medical", "industrial", "energy", "marine", "aviation") else "",
            "category": str(value.get("category", ""))[:160],
            "description": str(value.get("description", ""))[:240],
            "source_ref": source,
            "lifecycle_status": "UNVERIFIED",
            "last_seen": str(value.get("last_seen", ""))[:35],
        }
    if not records:
        raise ValueError("no_qualifying_catalogue_identities")
    # Product identities travel separately from private vendor evidence.
    # A single combined JSON upload hit its 24 MB cap at ~27,000 products.
    payload = {
        "schema": SCHEMA,
        "items": [records[key] for key in sorted(records)],
        "availability_notice": "Product identity only; no supplier stock or price is implied.",
    }
    return json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8"), len(records)



def product_batches(payload: bytes, max_records: int = 6500,
                    max_bytes: int = 5_000_000):
    """Bound each POST while keeping all historical products in the receiver."""
    doc = json.loads(payload)
    batch = []
    for item in doc["items"]:
        batch.append(item)
        # Preemptively flush to avoid web-host ingress/php memory limits.
        candidate = json.dumps({"schema":SCHEMA,"items":batch},
                               ensure_ascii=False,separators=(",",":")).encode("utf-8")
        if len(batch) >= max_records or len(candidate) >= max_bytes:
            if len(candidate)>15_000_000:
                raise ValueError("single_product_batch_oversized")
            yield candidate,len(batch)
            batch=[]
    if batch:
        yield json.dumps({"schema":SCHEMA,"items":batch},
                         ensure_ascii=False,separators=(",",":")).encode("utf-8"),len(batch)


def vendor_evidence_batches(path: Path, max_records: int = 1500):
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


def publish_https(snapshot: Path, state_dir: Path, env: dict[str, str]) -> dict:
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
            "User-Agent":"AurenoevaCataloguePublisher/2.0",
            "X-Aurenoeva-Sync-Token":token,
        })
        try:
            with urlopen(request,timeout=90) as response:
                if response.status!=200:raise RuntimeError("site_rejected_batch")
                result=json.loads(response.read(16384).decode("utf-8"))
        except (HTTPError,URLError) as exc:
            raise RuntimeError("site_catalogue_https_unavailable") from exc
        if (result.get("ok") is not True or
                result.get("source_sha256")!=hashlib.sha256(body).hexdigest()):
            raise RuntimeError("site_catalogue_batch_readback_failed")
        return result
    accepted=0
    batches=0
    published_total=0
    for batch,number in product_batches(payload):
        result=send(batch,"products")
        if(result.get("status")!="PUBLISHED" or
           result.get("accepted_input_records")!=number or
           result.get("records",0)<number or
           not re.fullmatch(r"[a-f0-9]{64}",str(result.get("sha256","")))):
            raise RuntimeError("site_catalogue_product_batch_not_acknowledged")
        accepted+=number
        batches+=1
        published_total=result["records"]
    if accepted!=count:
        raise RuntimeError("site_catalogue_incomplete_product_batches")
    vendor_batches=0
    evidence=0
    for body,number in vendor_evidence_batches(snapshot):
        result=send(body,"vendors")
        if result.get("status")!="MERGED":
            raise RuntimeError("site_vendor_evidence_batch_not_acknowledged")
        vendor_batches+=1
        evidence+=number
    # This receipt means both product and vendor batches finished, not merely
    # that the website acknowledged one small initial fragment.
    temp=receipt.with_suffix(".new")
    temp.write_text(digest+"\n",encoding="ascii")
    os.replace(temp,receipt)
    return {"status":"PUBLISHED","records":published_total,
            "submitted_products":accepted,"product_batches":batches,
            "vendor_evidence_records":evidence,"vendor_batches":vendor_batches,
            "sha256":digest}


def main() -> int:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--snapshot", type=Path, default=Path("/var/lib/aurenoeva/catalogue-public.json"))
    parser.add_argument("--state-dir", type=Path, default=Path("/var/lib/aurenoeva"))
    args = parser.parse_args()
    try:
        env = dict(os.environ)
        if Path(env.get("AURENOEVA_CATALOGUE_PUSH_TOKEN_FILE",
                         "/var/lib/aurenoeva/catalogue-sync-token")).is_file():
            result = publish_https(args.snapshot, args.state_dir, env)
        else:
            result = publish(args.snapshot, args.state_dir, env)  # preserve existing SFTP option
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as exc:
        print(json.dumps({"status": "DELIVERY_FAILED", "reason": str(exc)}))
        return 1
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
