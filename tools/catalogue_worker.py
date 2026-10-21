#!/usr/bin/env python3
"""Aurenoeva catalogue worker: resumable source discovery and durable inventory evidence.

Standard library only. Runs on the existing Hostinger VPS, not inside GitHub Actions.
No prices, customer data, supplier private data, or unsupported stock claims are exported.
"""
from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import fcntl
import hashlib
from html.parser import HTMLParser
import ipaddress
import json
import os
from pathlib import Path
import re
import socket
import sqlite3
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlsplit
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.robotparser import RobotFileParser

SCHEMA = "aurenoeva-catalogue/1"
AGENT = "AurenoevaCatalogueBot/1.0 (+https://aurenoeva.com/)"
RETRY_MINUTES = (15, 60, 240, 1440)
MAX_HTML = 1_500_000
MAX_FEED = 2_000_000
ROBOTS_CACHE = {}


def utcnow():
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0)


def stamp(t=None):
    return (t or utcnow()).isoformat().replace("+00:00", "Z")


def utc_from(s):
    return dt.datetime.fromisoformat(s.replace("Z", "+00:00"))


def product_id(manufacturer, model):
    norm = lambda s: re.sub(r"[^a-z0-9]+", "", s.casefold())
    key = norm(manufacturer) + "|" + norm(model)
    return "AUR-P-" + hashlib.sha256(key.encode()).hexdigest()[:20].upper()


def check_url(url, allowed_hosts, resolve=True):
    u = urlsplit(url)
    host = (u.hostname or "").lower().rstrip(".")
    if u.scheme != "https" or u.username or u.password or u.port not in (None, 443):
        raise ValueError("source_url_requires_public_https")
    if host not in allowed_hosts:
        raise ValueError("source_host_not_allowlisted")
    if resolve:
        for item in socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM):
            addr = ipaddress.ip_address(item[4][0])
            if not addr.is_global:
                raise ValueError("source_host_resolves_nonpublic")
    return url


class SameHostRedirect(HTTPRedirectHandler):
    def __init__(self, hosts):
        self.hosts = hosts

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        check_url(newurl, self.hosts)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class Page(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.title = []
        self.h1 = []
        self.links = []
        self._capture = None
        self._hidden = 0

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag in ("script", "style"):
            self._hidden += 1
        if tag in ("title", "h1"):
            self._capture = tag
        if tag == "a" and a.get("href"):
            self.links.append(a["href"])

    def handle_endtag(self, tag):
        if tag in ("script", "style"):
            self._hidden = max(0, self._hidden - 1)
        if tag == self._capture:
            self._capture = None

    def handle_data(self, data):
        if not self._hidden and self._capture in ("title", "h1"):
            getattr(self, self._capture).append(data)


def clean(s):
    return " ".join(str(s or "").split()).strip()[:240]


def extract(html):
    parser = Page()
    parser.feed(html)
    return clean(" ".join(parser.h1)), clean(" ".join(parser.title)), parser.links


def init_db(conn):
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=5000")
    conn.executescript("""
    CREATE TABLE IF NOT EXISTS tasks (
      url TEXT PRIMARY KEY, source_id TEXT NOT NULL, manufacturer TEXT NOT NULL,
      model_hint TEXT, follow_regex TEXT, depth INTEGER NOT NULL DEFAULT 0,
      due_at TEXT NOT NULL, failures INTEGER NOT NULL DEFAULT 0,
      state TEXT NOT NULL DEFAULT 'PENDING', last_error TEXT,
      last_http_status INTEGER, updated_at TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_tasks_due ON tasks(due_at, state);
    CREATE TABLE IF NOT EXISTS products (
      product_id TEXT PRIMARY KEY, manufacturer TEXT NOT NULL, model TEXT NOT NULL,
      description TEXT NOT NULL, source_url TEXT NOT NULL, content_sha256 TEXT NOT NULL,
      first_seen TEXT NOT NULL, last_seen TEXT NOT NULL,
      identity_evidence TEXT NOT NULL DEFAULT 'OFFICIAL_MANUFACTURER_PAGE',
      lifecycle_status TEXT NOT NULL DEFAULT 'UNVERIFIED',
      UNIQUE(manufacturer, model)
    );
    CREATE TABLE IF NOT EXISTS inventory (
      product_id TEXT NOT NULL, supplier_id TEXT NOT NULL,
      reported_quantity INTEGER, reported_status TEXT NOT NULL DEFAULT 'UNKNOWN',
      reported_at TEXT, expires_at TEXT, proof_url TEXT,
      verification_state TEXT NOT NULL DEFAULT 'UNVERIFIED',
      PRIMARY KEY(product_id, supplier_id),
      FOREIGN KEY(product_id) REFERENCES products(product_id)
    );
    CREATE TABLE IF NOT EXISTS observations (
      url TEXT PRIMARY KEY, source_id TEXT NOT NULL, sha256 TEXT,
      page_title TEXT, last_checked TEXT NOT NULL, http_status INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS runs (
      id INTEGER PRIMARY KEY AUTOINCREMENT, started_at TEXT NOT NULL,
      ended_at TEXT, processed INTEGER NOT NULL DEFAULT 0,
      succeeded INTEGER NOT NULL DEFAULT 0, failed INTEGER NOT NULL DEFAULT 0,
      new_products INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL DEFAULT 'RUNNING'
    );
    """)
    conn.commit()


def load_sources(file):
    data = json.loads(file.read_text(encoding="utf-8"))
    sources = data.get("sources", [])
    if not isinstance(sources, list):
        raise ValueError("sources_must_be_array")
    byid = {}
    for row in sources:
        if not row.get("active", True):
            continue
        sid = str(row["id"])
        hosts = {h.lower().rstrip(".") for h in row["allowed_hosts"]}
        if not hosts or sid in byid:
            raise ValueError("invalid_source_id_or_hosts")
        for url in row["seeds"]:
            check_url(url, hosts, resolve=False)
        hints = row.get("model_hints", {})
        if not isinstance(hints, dict) or any(u not in row["seeds"] for u in hints):
            raise ValueError("model_hints_must_match_seeds")
        if row.get("follow_regex"):
            re.compile(row["follow_regex"])
        if not 0 <= int(row.get("max_depth", 1)) <= 3:
            raise ValueError("max_depth_out_of_range")
        byid[sid] = {**row, "hosts": hosts}
    return byid


def seed(conn, sources, now):
    """Keep historical tasks, but refresh discovery rules and explicit model hints."""
    for sid, source in sources.items():
        hints = source.get("model_hints", {})
        for url in source["seeds"]:
            hint = hints.get(url, source.get("model_hint", ""))
            pattern = source.get("follow_regex", "")
            conn.execute("""INSERT OR IGNORE INTO tasks
              (url,source_id,manufacturer,model_hint,follow_regex,depth,due_at,updated_at)
              VALUES (?,?,?,?,?,0,?,?)""", (url, sid, source["manufacturer"],
                  hint, pattern, now, now))
            # Existing queued URLs otherwise retain outdated strict discovery rules.
            conn.execute("""UPDATE tasks SET model_hint=?,follow_regex=?,due_at=?,
               state='PENDING',failures=0,updated_at=?
               WHERE url=? AND source_id=?
               AND (model_hint<>? OR follow_regex<>?)""",
               (hint,pattern,now,now,url,sid,hint,pattern))
    conn.commit()

def robots_allowed(url, source, opener):
    u = urlsplit(url)
    robots_url = f"https://{u.hostname}/robots.txt"
    if robots_url in ROBOTS_CACHE:
        return ROBOTS_CACHE[robots_url].can_fetch(AGENT, url)
    check_url(robots_url, source["hosts"])
    try:
        with opener.open(Request(robots_url, headers={"User-Agent": AGENT}), timeout=6) as r:
            raw = r.read(200_000).decode("utf-8", "replace")
    except HTTPError as e:
        if e.code in (401, 403):
            return False
        return e.code == 404  # missing robots.txt does not disallow
    except (URLError, OSError):
        return False  # safely retry rather than assume permission
    parser = RobotFileParser()
    parser.parse(raw.splitlines())
    ROBOTS_CACHE[robots_url] = parser
    return parser.can_fetch(AGENT, url)


def fetch_page(url, source):
    check_url(url, source["hosts"])
    opener = build_opener(SameHostRedirect(source["hosts"]))
    if not robots_allowed(url, source, opener):
        raise PermissionError("robots_disallow_or_unreachable")
    time.sleep(1.0)  # polite per-request pacing, bounded by run wall-clock budget
    with opener.open(Request(url, headers={"User-Agent": AGENT, "Accept": "text/html"}), timeout=12) as r:
        status = r.status
        content_type = r.headers.get("Content-Type", "").lower()
        if "html" not in content_type:
            raise ValueError("unsupported_content_type")
        html = r.read(MAX_HTML + 1)
        if len(html) > MAX_HTML:
            raise ValueError("html_too_large")
        return html.decode("utf-8", "replace"), status


def product_from_page(source, url, html, model_hint=""):
    h1, title, links = extract(html)
    maker = clean(source["manufacturer"])
    normalized = lambda s: re.sub(r"[^a-z0-9]+", "", s.casefold())

    # Manufacturer headings and collection pages are research evidence, not models.
    # A short, model-like official title can rescue pages with a generic H1.
    title_model = clean(re.split(r"\s+[|–—]\s+|\s+-\s+", title, maxsplit=1)[0])
    model = clean(model_hint or h1)
    generic = ("products", "produkte", "home", "dental units", "dental equipment",
               "medical devices", "product catalog", "catalog", "catalogue",
               "all products", "spaltlampen", "biometrie", "anesthesia",
               "patient monitoring", "autoclaves for sterilization")
    def is_model(value):
        return (2 <= len(value) <= 100 and len(value.split()) <= 10
                and any(ch.isdigit() for ch in value)
                and value.casefold() not in generic
                and not re.search(r"(?i)\b(?:catalogue|catalog|collection|all products)\b", value))
    if not is_model(model) and not model_hint:
        model = title_model
    if not is_model(model):
        return None, links, title
    # A hint may guide extraction, but must still be visible on the actual page.
    if normalized(model) not in normalized(h1 + " " + title + " " + html[:50000]):
        return None, links, title
    return {"product_id": product_id(maker, model), "manufacturer": maker, "model": model,
            "description": clean(title or h1)[:240], "source_url": url,
            "content_sha256": hashlib.sha256(html.encode()).hexdigest()}, links, title

def write_product(conn, p, now):
    exists = conn.execute("SELECT 1 FROM products WHERE product_id=?", (p["product_id"],)).fetchone()
    conn.execute("""INSERT INTO products
      (product_id,manufacturer,model,description,source_url,content_sha256,first_seen,last_seen)
      VALUES (:product_id,:manufacturer,:model,:description,:source_url,:content_sha256,:now,:now)
      ON CONFLICT(product_id) DO UPDATE SET
      description=excluded.description, source_url=excluded.source_url,
      content_sha256=excluded.content_sha256,last_seen=excluded.last_seen""", {**p, "now": now})
    # A product page does not imply supplier inventory or stock.
    conn.execute("""INSERT OR IGNORE INTO inventory
        (product_id,supplier_id,reported_status,verification_state)
        VALUES (?, 'UNASSIGNED', 'UNKNOWN', 'UNVERIFIED')""", (p["product_id"],))
    return not bool(exists)


def run_worker(conn, sources, max_pages=30, max_seconds=240, now=None, fetch=fetch_page):
    start = time.monotonic()
    at = stamp(now)
    seed(conn, sources, at)
    conn.execute("UPDATE runs SET state='INTERRUPTED', ended_at=? WHERE state='RUNNING'", (at,))
    conn.commit()
    run_id = conn.execute("INSERT INTO runs(started_at) VALUES (?)", (at,)).lastrowid
    conn.commit()
    outcome = {"run_id": run_id, "processed": 0, "succeeded": 0, "failed": 0, "new_products": 0}
    # A single source may fail repeatedly; do not let it hold up all the others.
    while outcome["processed"] < max_pages and time.monotonic() - start < max_seconds:
        task = conn.execute("""SELECT * FROM tasks WHERE state='PENDING' AND due_at<=?
             ORDER BY CASE WHEN model_hint<>'' THEN 0 ELSE 1 END,
               depth,due_at,failures,url LIMIT 1""", (at,)).fetchone()
        if task is None:
            break
        task = dict(task)
        url, source = task["url"], sources.get(task["source_id"])
        outcome["processed"] += 1
        try:
            conn.execute("SAVEPOINT page_write")
            if source is None:
                raise ValueError("source_disabled")
            html, status = fetch(url, source)
            p, links, title = product_from_page(source, url, html, task["model_hint"] or "")
            digest = hashlib.sha256(html.encode()).hexdigest()
            conn.execute("""INSERT INTO observations(url,source_id,sha256,page_title,last_checked,http_status)
                VALUES (?,?,?,?,?,?) ON CONFLICT(url) DO UPDATE SET
                sha256=excluded.sha256,page_title=excluded.page_title,
                last_checked=excluded.last_checked,http_status=excluded.http_status""",
                (url,task["source_id"],digest,title,at,status))
            if p:
                outcome["new_products"] += int(write_product(conn,p,at))
            # Follow verified on-domain product/category links for bounded depth.
            # Queued pages inherit the source filter so intermediate categories lead
            # to product detail pages, without crawling unrelated site sections.
            if task["depth"] < int(source.get("max_depth", 1)) and task["follow_regex"]:
                pattern = re.compile(task["follow_regex"])
                for href in links[:150]:
                    dest = urljoin(url, href).split("#")[0]
                    if (urlsplit(dest).hostname not in source["hosts"]
                            or not pattern.search(urlsplit(dest).path)):
                        continue
                    try:
                        check_url(dest, source["hosts"], resolve=False)
                    except ValueError:
                        continue
                    conn.execute("""INSERT OR IGNORE INTO tasks
                      (url,source_id,manufacturer,model_hint,follow_regex,depth,due_at,updated_at)
                      VALUES (?,?,?,'',?,?,?,?)""",
                      (dest,task["source_id"],task["manufacturer"],task["follow_regex"],
                       task["depth"]+1,at,at))
            interval = max(1, int(source.get("refresh_hours", 24)))
            due = stamp(utc_from(at) + dt.timedelta(hours=interval))
            conn.execute("""UPDATE tasks SET state='PENDING',due_at=?,failures=0,
                    last_error=NULL,last_http_status=?,updated_at=? WHERE url=?""", (due,status,at,url))
            conn.execute("RELEASE SAVEPOINT page_write")
            outcome["succeeded"] += 1
        except (HTTPError, URLError, OSError, ValueError, PermissionError, TimeoutError) as e:
            conn.execute("ROLLBACK TO SAVEPOINT page_write")
            conn.execute("RELEASE SAVEPOINT page_write")
            failures = task["failures"]+1
            wait = RETRY_MINUTES[min(failures-1,len(RETRY_MINUTES)-1)]
            due = stamp(utc_from(at) + dt.timedelta(minutes=wait))
            state = "PENDING" if failures <= 8 else "QUARANTINED"
            status = e.code if isinstance(e, HTTPError) else 0
            conn.execute("""UPDATE tasks SET state=?,due_at=?,failures=?,last_error=?,
               last_http_status=?,updated_at=? WHERE url=?""", (state,due,failures,
               str(e)[:250],status,at,url))
            outcome["failed"] += 1
        conn.execute("UPDATE runs SET processed=?, succeeded=?,failed=?,new_products=? WHERE id=?",
            (outcome["processed"],outcome["succeeded"],outcome["failed"],outcome["new_products"],run_id))
        conn.commit()  # durable checkpoint after EVERY page, including errors
    conn.execute("UPDATE runs SET ended_at=?,state='FINISHED' WHERE id=?",(stamp(),run_id))
    conn.commit()
    return outcome


def import_supplier_inventory(conn, path):
    """Ingest an explicit private supplier report, never inferred manufacturer stock.

    Product must already exist. Supplier report is a claim awaiting verification,
    not warehouse stock owned by Aurenoeva and never included in public export.
    """
    if path.stat().st_size > MAX_FEED:
        raise ValueError("supplier_feed_too_large")
    feed = json.loads(path.read_text(encoding="utf-8"))
    if feed.get("schema") != "aurenoeva-supplier-stock-report/1":
        raise ValueError("unsupported_feed_schema")
    supplier = clean(feed.get("supplier_id", ""))
    observed = feed.get("reported_at", "")
    if not supplier or len(supplier) > 120 or not observed:
        raise ValueError("supplier_or_date_missing")
    reported = utc_from(observed)
    if reported > utcnow() + dt.timedelta(minutes=10) or reported < utcnow() - dt.timedelta(days=90):
        raise ValueError("supplier_report_date_outside_90d")
    expires = stamp(reported + dt.timedelta(days=2))
    items = feed.get("items", [])
    if not isinstance(items,list) or len(items)>2000:
        raise ValueError("invalid_inventory_items")
    written=0
    with conn:
        for item in items:
            qty = item.get("reported_quantity")
            if qty is not None and (not isinstance(qty,int) or isinstance(qty,bool) or qty < 0 or qty > 1000000):
                raise ValueError("invalid_stock_quantity")
            pid = product_id(item.get("manufacturer", ""),item.get("model", ""))
            if not conn.execute("SELECT 1 FROM products WHERE product_id=?",(pid,)).fetchone():
                continue  # unknown products stay out of inventory; do not create identities from vendor feed
            proof = clean(item.get("proof_url", ""))
            if proof and urlsplit(proof).scheme != "https":
                raise ValueError("invalid_proof_url")
            previous=conn.execute("SELECT reported_at FROM inventory WHERE product_id=? AND supplier_id=?",(pid,supplier)).fetchone()
            if previous and previous[0] and utc_from(previous[0]) > reported:
                continue  # newer claim always wins
            state="SUPPLIER_REPORTED_IN_STOCK" if qty and qty > 0 else (
                "SUPPLIER_REPORTED_OUT_OF_STOCK" if qty == 0 else "UNKNOWN")
            conn.execute("""INSERT INTO inventory (product_id,supplier_id,reported_quantity,
                reported_status,reported_at,expires_at,proof_url,verification_state)
                VALUES (?,?,?,?,?,?,?,'REVIEW_REQUIRED')
                ON CONFLICT(product_id,supplier_id) DO UPDATE SET
                reported_quantity=excluded.reported_quantity,reported_status=excluded.reported_status,
                reported_at=excluded.reported_at,expires_at=excluded.expires_at,
                proof_url=excluded.proof_url,verification_state='REVIEW_REQUIRED'""",
                (pid,supplier,qty,state,stamp(reported),expires,proof))
            written+=1
    return written


def export_public(conn, target):
    rows = [dict(x) for x in conn.execute("""SELECT product_id AS canonical_id, manufacturer,
          model, description, source_url AS source_ref,
          'CANDIDATE' AS relationship_type, lifecycle_status, last_seen
          FROM products ORDER BY manufacturer,model""")]
    # Historical crawls may contain broad page headings (e.g. 'Surgical carts').
    # They remain available for private review, but cannot be sold as models.
    generic = {"surgical carts", "surgical cart", "equipment", "products",
               "dental equipment", "dental units", "medical devices",
               "catalogue", "catalog", "accessories", "solutions"}
    rows = [p for p in rows if p["model"].strip().casefold() not in generic
            and any(char.isdigit() for char in p["model"])]
    payload = {"schema":SCHEMA,"exported_at":stamp(),"items":rows,
               "availability_notice":"Product identity only. No stock or price implied."}
    target.parent.mkdir(parents=True, exist_ok=True)
    tmp = target.with_name(target.name + ".tmp-" + str(os.getpid()))
    try:
        with tmp.open("w", encoding="utf-8") as out:
            json.dump(payload,out,ensure_ascii=False,separators=(",",":"))
            out.flush(); os.fsync(out.fileno())
        os.replace(tmp,target)
        os.chmod(target,0o600)
    finally:
        tmp.unlink(missing_ok=True)
    return len(rows)


def status(conn):
    last = conn.execute("SELECT * FROM runs ORDER BY id DESC LIMIT 1").fetchone()
    return {"last_run":dict(last) if last else None,
            "products":conn.execute("SELECT count(*) FROM products").fetchone()[0],
            "pending":conn.execute("SELECT count(*) FROM tasks WHERE state='PENDING'").fetchone()[0],
            "quarantined":conn.execute("SELECT count(*) FROM tasks WHERE state='QUARANTINED'").fetchone()[0],
            "inventory_verified":conn.execute("SELECT count(*) FROM inventory WHERE verification_state='VERIFIED'").fetchone()[0],
            "supplier_reports_active":conn.execute("SELECT count(*) FROM inventory WHERE reported_at IS NOT NULL AND expires_at>?",(stamp(),)).fetchone()[0],
            "next_due_at":conn.execute("SELECT min(due_at) FROM tasks WHERE state='PENDING'").fetchone()[0]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--db", type=Path, required=True, help="Private SQLite database outside public_html")
    parser.add_argument("--sources",type=Path,required=True)
    parser.add_argument("--export",type=Path, help="Private public-safe snapshot, never customer data")
    parser.add_argument("--max-pages",type=int,default=30)
    parser.add_argument("--max-seconds",type=int,default=240)
    parser.add_argument("--status",action="store_true")
    parser.add_argument("--supplier-feed",type=Path,help="Explicit PRIVATE seller stock report, not web scraped")
    args=parser.parse_args()
    if not 1 <= args.max_pages <= 300 or not 1 <= args.max_seconds <= 1800:
        parser.error("max-pages 1..300, max-seconds 1..1800")
    args.db.parent.mkdir(parents=True,exist_ok=True)
    os.chmod(args.db.parent,0o700)
    with (args.db.parent / (args.db.name+".lock")).open("w") as lock:
        try:
            fcntl.flock(lock,fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print(json.dumps({"status":"ALREADY_RUNNING"}));return 0
        with sqlite3.connect(args.db,timeout=5) as conn:
            conn.row_factory=sqlite3.Row
            init_db(conn)
            if not args.status:
                inventory_writes = import_supplier_inventory(conn,args.supplier_feed) if args.supplier_feed else 0
                sources=load_sources(args.sources)
                result=run_worker(conn,sources,args.max_pages,args.max_seconds)
                result["inventory_claims_recorded"] = inventory_writes
                if args.export:
                    result["exported_products"]=export_public(conn,args.export)
            else:
                result=status(conn)
            print(json.dumps(result,ensure_ascii=False))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())