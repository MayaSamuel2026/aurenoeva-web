#!/usr/bin/env python3
"""Official-source sanctions name screening for AURENOEVA.

Live source retrieval is performed on the Hostinger server, using configured
official EU/OFAC CSV URLs. A missing, stale, unparseable source is NEVER clear.
Name matches are possible hits requiring human identity/ownership assessment.
The result NEVER constitutes transaction or export-control authorisation.
"""
from __future__ import annotations

import argparse
import csv
import datetime as dt
from difflib import SequenceMatcher
import hashlib
import io
import json
import os
from pathlib import Path
import re
import socket
import tempfile
import unicodedata
from urllib.parse import urlsplit
from urllib.request import Request, urlopen

ALLOWED_HOSTS = {
    "ec.europa.eu", "webgate.ec.europa.eu", "data.europa.eu",
    "www.treasury.gov", "treasury.gov", "ofac.treasury.gov",
    "www.ofac.treasury.gov", "sanctionslistservice.ofac.treas.gov",
    # OFAC's official SDN download redirects to its pinned US Gov Cloud S3 bucket.
    "wc2h-sls-prod-public-published.s3.us-gov-west-1.amazonaws.com",
}
REQUIRED = ("EU_FSF", "OFAC_SDN")
MAX_FEED_BYTES = 75_000_000
MAX_AGE_HOURS = 72


def normalize(name: str) -> str:
    text = unicodedata.normalize("NFKD", str(name)).casefold()
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    return " ".join(re.findall(r"[a-z0-9а-яё]+", text))


def source_uri_ok(uri: str) -> bool:
    u = urlsplit(uri)
    return (u.scheme == "https" and u.hostname in ALLOWED_HOSTS
            and u.username is None and u.password is None and u.port in (None,443))


def download(uri: str) -> bytes:
    if not source_uri_ok(uri):
        raise ValueError("nonofficial_or_insecure_sanctions_url")
    # No cross-domain redirects or unknown hosts are accepted.
    import urllib.request
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, fp, code, msg, headers, newurl):
            if not source_uri_ok(newurl):
                raise ValueError("untrusted_sanctions_redirect")
            return super().redirect_request(request, fp, code, msg, headers, newurl)
    req = Request(uri, headers={"User-Agent":"AurenoevaSanctionsPreflight/1.0",
                                "Accept":"text/csv,application/octet-stream,*/*"})
    with urllib.request.build_opener(NoRedirect()).open(req,timeout=30) as response:
        data = response.read(MAX_FEED_BYTES + 1)
    if len(data) > MAX_FEED_BYTES or len(data) < 80:
        raise ValueError("sanctions_feed_bad_size")
    return data


def refresh(feed_dir: Path, source_name: str, url: str) -> dict:
    if source_name not in REQUIRED:
        raise ValueError("unknown_feed")
    contents = download(url)
    # Parse first; an error must not overwrite a previously good snapshot.
    rows = parse_names(contents, source_name)
    if len(rows) < 25:
        raise ValueError("implausibly_short_sanctions_list")
    feed_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    now = dt.datetime.now(dt.timezone.utc).isoformat()
    meta = {
        "source": source_name, "url": url, "retrieved_at": now,
        "sha256": hashlib.sha256(contents).hexdigest(), "names_count":len(rows),
    }
    for path, content in (
        (feed_dir / (source_name + ".csv"), contents),
        (feed_dir / (source_name + ".json"), (json.dumps(meta,sort_keys=True)+"\n").encode()),
    ):
        fd, staging = tempfile.mkstemp(prefix=".sanctions-",dir=feed_dir)
        try:
            os.fchmod(fd, 0o600)
            with os.fdopen(fd,"wb") as f:
                f.write(content)
                f.flush()
                os.fsync(f.fileno())
            os.replace(staging,path)
        finally:
            if os.path.exists(staging):
                os.unlink(staging)
    return meta


def parse_names(contents: bytes, source_name: str) -> set[str]:
    try:
        content = contents.decode("utf-8-sig")
    except UnicodeDecodeError:
        content = contents.decode("latin-1")
    reader = csv.reader(io.StringIO(content), delimiter=";" if source_name == "EU_FSF" else ",")
    rows = list(reader)
    if not rows:
        raise ValueError("empty_sanctions_list")
    names: set[str] = set()
    if source_name == "OFAC_SDN":
        # Official OFAC legacy SDN CSV has entity ID followed by the SDN name.
        for row in rows:
            if len(row) >= 2 and row[0].strip().isdigit():
                name = normalize(row[1])
                if name and len(name) > 2:
                    names.add(name)
    elif source_name == "EU_FSF":
        header = [normalize(x).replace(" ","") for x in rows[0]]
        whole = [i for i,h in enumerate(header) if h in ("namealias_wholename","namealiaswholeName".casefold(),"wholename","name")]
        first = [i for i,h in enumerate(header) if "namealiasfirstname" in h]
        last = [i for i,h in enumerate(header) if "namealiaslastname" in h]
        if not whole and not (first and last):
            raise ValueError("unrecognised_eu_sanctions_columns")
        for row in rows[1:]:
            for col in whole:
                if len(row) > col:
                    name = normalize(row[col])
                    if name and len(name) > 2:
                        names.add(name)
            if first and last and len(row)>max(first[0],last[0]):
                name = normalize(row[first[0]] + " " + row[last[0]])
                if name and len(name)>2: names.add(name)
    else:
        raise ValueError("unsupported_feed")
    if not names:
        raise ValueError("zero_parsed_sanction_names")
    return names


def read_feed(feed_dir: Path, source_name: str, *, now: dt.datetime | None = None) -> tuple[set[str],dict]:
    now = now or dt.datetime.now(dt.timezone.utc)
    meta_path = feed_dir / (source_name + ".json")
    content_path = feed_dir / (source_name + ".csv")
    if not meta_path.is_file() or not content_path.is_file():
        raise ValueError(source_name + "_not_downloaded")
    meta = json.loads(meta_path.read_text(encoding="utf-8"))
    contents = content_path.read_bytes()
    if hashlib.sha256(contents).hexdigest() != meta.get("sha256"):
        raise ValueError(source_name + "_integrity_failed")
    stamp = dt.datetime.fromisoformat(meta["retrieved_at"])
    if stamp.tzinfo is None or not dt.timedelta(0) <= now - stamp <= dt.timedelta(hours=MAX_AGE_HOURS):
        raise ValueError(source_name + "_stale")
    names = parse_names(contents,source_name)
    if len(names) < 25 or len(names) != int(meta["names_count"]):
        raise ValueError(source_name + "_incomplete")
    return names,meta


def screen(parties: list[dict], feed_dir: Path, *, now: dt.datetime | None = None) -> dict:
    now = now or dt.datetime.now(dt.timezone.utc)
    sources = {}
    lists = {}
    errors = []
    for source in REQUIRED:
        try:
            names, meta = read_feed(feed_dir,source,now=now)
            lists[source] = names
            sources[source] = {k:meta[k] for k in ("url","sha256","retrieved_at","names_count")}
        except Exception as exc:
            errors.append(str(exc))
    hits = []
    missing_parties = []
    for idx,party in enumerate(parties):
        if not isinstance(party,dict):
            missing_parties.append(str(idx));continue
        candidates = [str(party.get("legal_name") or "")]
        aliases = party.get("aliases") or []
        if isinstance(aliases,list):
            candidates.extend(str(n) for n in aliases[:40])
        if not candidates[0].strip():
            missing_parties.append(str(idx))
        for candidate in candidates:
            needle = normalize(candidate)
            if len(needle) < 4:
                continue
            for source,names in lists.items():
                if needle in names:
                    hits.append({"party_index":idx,"source":source,"submitted":candidate,
                                 "listed_name":needle,"match":"EXACT","action":"MANUAL_REVIEW"})
                elif len(needle) >= 7:
                    # Optional conservative near-match. Never infer identity from a name.
                    close = [name for name in names if abs(len(name)-len(needle))<=2
                             and name[:3]==needle[:3]]
                    matches = sorted(((SequenceMatcher(None,needle,n).ratio(),n)
                                      for n in close),reverse=True)[:1]
                    if matches and matches[0][0]>=0.92:
                        hits.append({"party_index":idx,"source":source,"submitted":candidate,
                                     "listed_name":matches[0][1],"match":"POSSIBLE","action":"MANUAL_REVIEW"})
    status = ("SOURCES_UNAVAILABLE_REVIEW_REQUIRED" if errors else
              "PARTIES_MISSING_REVIEW_REQUIRED" if not parties or missing_parties else
              "POTENTIAL_MATCH_REVIEW_REQUIRED" if hits else
              "NO_NAME_MATCH_NOT_CLEARED")
    return {
        "status":status,"screened_at":now.isoformat(),"sources":sources,
        "sources_errors":errors,"matches":hits,"missing_party_indices":missing_parties,
        "not_a_sanctions_clearance":True,
        "ownership_and_goods_checks_outstanding":True,
        "export_or_transaction_release":False,
    }


def main() -> int:
    parser=argparse.ArgumentParser()
    parser.add_argument("action",choices=["refresh","screen"])
    parser.add_argument("--feed-dir",type=Path,required=True)
    parser.add_argument("--parties",type=Path)
    parser.add_argument("--output",type=Path)
    args=parser.parse_args()
    if args.action=="refresh":
        urls={
            "EU_FSF": os.getenv("AURENOEVA_EU_FSF_URL","https://webgate.ec.europa.eu/fsd/fsf/public/files/csvFullSanctionsList_1_1/content?token=dG9rZW4tMjAxNw"),
            "OFAC_SDN": os.getenv("AURENOEVA_OFAC_SDN_URL","https://sanctionslistservice.ofac.treas.gov/api/PublicationPreview/exports/SDN.CSV"),
        }
        results={}
        for name,url in urls.items():
            try:
                results[name]=refresh(args.feed_dir,name,url)
            except Exception as exc:
                results[name]={"error":str(exc)}
        result={"feeds":results,"complete":all("sha256" in x for x in results.values())}
    else:
        if not args.parties:
            parser.error("--parties required")
        parties=json.loads(args.parties.read_text(encoding="utf-8"))
        if not isinstance(parties,list):
            parser.error("parties must be a list of legal-name objects")
        result=screen(parties,args.feed_dir)
    if args.output:
        args.output.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
        args.output.write_text(json.dumps(result,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
        args.output.chmod(0o600)
    print(json.dumps(result,ensure_ascii=False))
    return 0 if (args.action=="screen" or result.get("complete")) else 2


if __name__ == "__main__":
    raise SystemExit(main())
