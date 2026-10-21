#!/usr/bin/env python3
"""AURENOEVA Harvester V4: broad, resumable product and vendor discovery.

Replaces old fixed-domain crawler; reuses its existing SQLite products table and
site snapshot/publisher. Never interprets a discovered listing as actual stock,
a quotation, authorized distribution, or compliance clearance.
"""
from __future__ import annotations
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timedelta, timezone
import fcntl, hashlib, html, ipaddress, json, os, re, socket, sqlite3, time
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode, urlsplit, urljoin, parse_qs, unquote
from urllib.request import Request, urlopen, HTTPRedirectHandler, build_opener
from urllib.error import HTTPError, URLError
from urllib.robotparser import RobotFileParser
from xml.etree import ElementTree as ET

AGENT="AurenoevaDiscovery/4.0 (+https://aurenoeva.com/)"
SECTORS=("laboratory","test","medical","industrial","energy","marine","aviation")
GENERIC={"products","catalog","catalogue","home","all products","equipment","solutions",
         "medical equipment","dental units","surgical carts","devices","parts","accessories"}
ROBOTS={}
def clock():return datetime.now(timezone.utc).replace(microsecond=0)
def stamp(t=None):return (t or clock()).isoformat().replace("+00:00","Z")
def txt(x,n=240):return " ".join(str(x or "").split())[:n]
def pid(maker,model):
    norm=lambda s:re.sub("[^a-z0-9]","",s.casefold())
    return "AUR-P-"+hashlib.sha256((norm(maker)+"|"+norm(model)).encode()).hexdigest()[:20].upper()
def hostof(url):return (urlsplit(url).hostname or "").lower()
def public_url(url):
    p=urlsplit(url)
    if (p.scheme!="https" or not p.hostname or "." not in p.hostname or p.username or
        p.password or p.port not in (None,443) or len(url)>1400):
        raise ValueError("public_https_only")
    try:ipaddress.ip_address(p.hostname);raise ValueError("literal_ip_not_allowed")
    except ValueError as e:
        if str(e)=="literal_ip_not_allowed":raise
    if p.hostname.endswith((".local",".internal")):raise ValueError("internal_host")
    return url
def public_dns(host):
    addrs=socket.getaddrinfo(host,443,type=socket.SOCK_STREAM)
    if not addrs or any(not ipaddress.ip_address(row[4][0]).is_global for row in addrs):
        raise ValueError("nonpublic_dns")
class SafeRedirect(HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,url):
        public_url(url);public_dns(hostof(url))
        return super().redirect_request(req,fp,code,msg,headers,url)
OPENER=build_opener(SafeRedirect())
def fetch(url,kind="page"):
    public_url(url);public_dns(hostof(url))
    if kind not in ("search","search_ddg"):
        host=hostof(url)
        if host not in ROBOTS:
            r=RobotFileParser()
            try:
                with OPENER.open(Request("https://"+host+"/robots.txt",headers={"User-Agent":AGENT}),timeout=7) as resp:
                    r.parse(resp.read(150000).decode("utf-8","replace").splitlines())
            except HTTPError as e:
                if e.code in (401,403):raise PermissionError("robots_denied")
                if e.code==404:r.parse([])
                else:raise
            ROBOTS[host]=r
        if not ROBOTS[host].can_fetch(AGENT,url):raise PermissionError("robots_disallowed")
    with OPENER.open(Request(url,headers={"User-Agent":AGENT,"Accept":"text/html,application/xhtml+xml,application/xml,text/xml"}),timeout=12) as resp:
        typ=resp.headers.get("Content-Type","")
        if not any(x in typ.lower() for x in ("html","xml","text/")):raise ValueError("nontext_content")
        limit=5_000_000 if kind in ("sitemap","search") else 1_000_000
        raw=resp.read(limit+1)
        if len(raw)>limit:raise ValueError("large_page")
        return raw.decode("utf-8","replace")
class Page(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.h1=[];self.title=[];self.links=[];self.structured=[];self.metas={}
        self.capture="";self.buf=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=="a" and a.get("href"):self.links.append((a["href"],""))
        if tag in ("h1","title"):self.capture=tag;self.buf=[]
        if tag=="script" and "ld+json" in a.get("type","").lower():self.capture="jsonld";self.buf=[]
        if tag=="meta":
            key=(a.get("property") or a.get("name") or "").lower()
            if key:self.metas[key]=a.get("content","")
    def handle_data(self,data):
        if self.capture:self.buf.append(data)
    def handle_endtag(self,tag):
        if tag==self.capture or (tag=="script" and self.capture=="jsonld"):
            v="".join(self.buf).strip()
            if v:
                if self.capture=="h1":self.h1.append(txt(v))
                elif self.capture=="title":self.title.append(txt(v))
                else:self.structured.append(v)
            self.capture="";self.buf=[]
def parse_page(body,url,maker,sector,category):
    p=Page();p.feed(body);title=p.title[0] if p.title else ""
    heading=p.h1[0] if p.h1 else ""
    products=[]
    def walk(node):
        if isinstance(node,list):
            for x in node:walk(x)
        elif isinstance(node,dict):
            kinds=node.get("@type",[])
            if isinstance(kinds,str):kinds=[kinds]
            if "Product" in kinds:
                brand=node.get("brand") or node.get("manufacturer") or maker
                if isinstance(brand,dict):brand=brand.get("name","")
                name=txt(node.get("mpn") or node.get("model") or node.get("sku") or node.get("name"),160)
                if name:products.append((txt(brand,100),name,"STRUCTURED_PRODUCT"))
            for key in ("@graph","mainEntity","itemListElement","item","hasVariant"):
                if key in node:walk(node[key])
    for x in p.structured[:20]:
        try:walk(json.loads(x))
        except (ValueError,TypeError,RecursionError):continue
    if not products and maker:
        heading=txt(heading or re.split(r"\s+[|–—]\s+|\s+-\s+",title)[0],160)
        if any(c.isdigit() for c in heading):products.append((maker,heading,"ON_PAGE_MODEL"))
    result=[]
    for brand,model,proof in products:
        model=txt(model,160)
        if not brand or len(model)<2 or model.casefold() in GENERIC or len(model.split())>16:continue
        if not any(c.isdigit() for c in model) and proof!="STRUCTURED_PRODUCT":continue
        result.append({"product_id":pid(brand,model),"manufacturer":brand,"model":model,
                       "description":txt(p.metas.get("description") or title or heading),
                       "sector":sector,"category":category,"source_url":url,
                       "content_sha256":hashlib.sha256(body.encode()).hexdigest(),
                       "identity_evidence":proof})
    return list({x["product_id"]:x for x in result}.values()),[x[0] for x in p.links],title
def published_contact(body,url):
    # Contact must be genuinely published on the fetched website, never inferred
    # from a search snippet or fabricated from a domain.
    host=hostof(url).removeprefix("www.")
    mails=re.findall(r"mailto:([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})",body,re.I)
    plain=re.sub(r"(?is)<script.*?</script>|<style.*?</style>"," ",body)
    mails+=re.findall(r"(?<![A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}",plain,re.I)
    email=""
    for v in mails:
        domain=v.split("@")[-1].lower()
        if (domain==host or host.endswith("."+domain) or domain in ("gmail.com","outlook.com")) and not re.search(r"(?i)no.?reply|example|invalid|test@",v):
            email=v[:240];break
    phone=""
    match=re.search(r"(?i)(?:tel:|phone\s*[: ]|telephone\s*[: ])\s*(\+?[0-9\s().\-]{8,24})",body)
    if match:phone=txt(match.group(1),40)
    return email,phone


def search_links(body,kind):
    found=[]
    if kind=="search":
        try:
            root=ET.fromstring(body)
            for node in root.iter():
                if node.tag.rsplit("}",1)[-1].lower()=="item":
                    for c in node:
                        if c.tag.rsplit("}",1)[-1].lower()=="link" and c.text:found.append(c.text.strip())
        except ET.ParseError:pass
    else:
        p=Page();p.feed(body)
        for href,_ in p.links:
            href=html.unescape(href)
            if href.startswith("//"):href="https:"+href
            if href.startswith("/l/"):href="https://duckduckgo.com"+href
            if "uddg=" in href:
                val=parse_qs(urlsplit(html.unescape(href)).query).get("uddg",[])
                if val:href=val[0]
            if href.startswith("https://"):found.append(href)
    out=[]
    for u in found:
        try:public_url(u)
        except ValueError:continue
        h=hostof(u)
        if any(t in h for t in ("bing.com","duckduckgo.com","google.com")):continue
        if u not in out:out.append(u)
    return out
def db_init(db):
    db.execute("PRAGMA journal_mode=WAL");db.execute("PRAGMA busy_timeout=30000")
    db.executescript("""
    CREATE TABLE IF NOT EXISTS products(
      product_id TEXT PRIMARY KEY,manufacturer TEXT NOT NULL,model TEXT NOT NULL,
      description TEXT NOT NULL,source_url TEXT NOT NULL,content_sha256 TEXT NOT NULL,
      first_seen TEXT NOT NULL,last_seen TEXT NOT NULL,
      sector TEXT NOT NULL DEFAULT '',category TEXT NOT NULL DEFAULT '',
      identity_evidence TEXT NOT NULL DEFAULT 'OFFICIAL_MANUFACTURER_PAGE',
      lifecycle_status TEXT NOT NULL DEFAULT 'UNVERIFIED',UNIQUE(manufacturer,model));
    CREATE TABLE IF NOT EXISTS harvest_queue(
      url TEXT PRIMARY KEY,kind TEXT NOT NULL,sector TEXT NOT NULL DEFAULT '',
      category TEXT NOT NULL DEFAULT '',maker TEXT NOT NULL DEFAULT '',depth INTEGER NOT NULL DEFAULT 0,
      failures INTEGER NOT NULL DEFAULT 0,due TEXT NOT NULL,last_error TEXT NOT NULL DEFAULT '');
    CREATE INDEX IF NOT EXISTS harvest_queue_due ON harvest_queue(due,kind);
    CREATE TABLE IF NOT EXISTS harvest_runs(
      id INTEGER PRIMARY KEY AUTOINCREMENT,started TEXT NOT NULL,finished TEXT,
      pages INTEGER NOT NULL DEFAULT 0,products INTEGER NOT NULL DEFAULT 0,
      vendors INTEGER NOT NULL DEFAULT 0,failed INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS vendor_product_evidence(
      website TEXT NOT NULL,product_id TEXT NOT NULL,source_url TEXT NOT NULL,
      manufacturer TEXT NOT NULL,model TEXT NOT NULL,sector TEXT NOT NULL DEFAULT '',
      category TEXT NOT NULL DEFAULT '',evidence_type TEXT NOT NULL,
      last_seen TEXT NOT NULL,PRIMARY KEY(website,product_id,source_url));
    CREATE TABLE IF NOT EXISTS vendor_leads(
      website TEXT NOT NULL,category TEXT NOT NULL DEFAULT '',sector TEXT NOT NULL DEFAULT '',
      evidence_url TEXT NOT NULL,checked TEXT NOT NULL DEFAULT 'SEARCH_LEAD',
      first_seen TEXT NOT NULL,last_seen TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',phone TEXT NOT NULL DEFAULT '',
    PRIMARY KEY(website,evidence_url));
    """)
    cols={x[1] for x in db.execute("PRAGMA table_info(products)")}
    for name in ("sector","category"):
        if name not in cols:db.execute("ALTER TABLE products ADD COLUMN "+name+" TEXT NOT NULL DEFAULT ''")
    leadcols={x[1] for x in db.execute("PRAGMA table_info(vendor_leads)")}
    for name in ("email","phone"):
        if name not in leadcols:db.execute("ALTER TABLE vendor_leads ADD COLUMN "+name+" TEXT NOT NULL DEFAULT ''")
    db.commit()
def enqueue(db,url,kind,sector="",category="",maker="",depth=0):
    try:public_url(url)
    except ValueError:return 0
    db.execute("""INSERT OR IGNORE INTO harvest_queue(url,kind,sector,category,maker,depth,due)
      VALUES(?,?,?,?,?,?,?)""",(url,kind,sector,category,maker,depth,stamp()))
    return db.execute("SELECT changes()").fetchone()[0]
def import_existing_rfq_vendors(db, token_path=Path("/var/lib/aurenoeva/catalogue-sync-token")):
    """Reuse existing RFQ websites from PHP host, with no additional auth setup."""
    if not token_path.is_file():return {"status":"NO_EXISTING_SYNC_TOKEN","seeded":0}
    if token_path.stat().st_mode & 0o077:raise ValueError("insecure_sync_token_permissions")
    token=token_path.read_text(encoding="ascii").strip()
    if not re.fullmatch(r"[a-f0-9]{64}",token):raise ValueError("invalid_sync_token")
    req=Request("https://aurenoeva.com/catalogue-sync.php?seed_vendors=1",
        headers={"User-Agent":AGENT,"X-Aurenoeva-Sync-Token":token})
    with urlopen(req,timeout=40) as response:
        payload=json.loads(response.read(5_000_000))
    if payload.get("ok") is not True or payload.get("source")!="EXISTING_RFQ_VENDOR_MASTER":
        raise ValueError("unrecognized_rfq_seed_response")
    added=0
    for r in payload.get("seeds",[])[:10000]:
        if not isinstance(r,dict):continue
        url=str(r.get("url",""))
        if not url:continue
        added+=enqueue(db,url,"page",str(r.get("sector",""))[:30],
            str(r.get("category",""))[:160],str(r.get("manufacturer",""))[:100])
    db.commit()
    return {"status":"IMPORTED","seeded":added,"sources_seen":len(payload.get("seeds",[]))}


def seeds(db,config):
    # Every website category receives independent discovery through two providers.
    for sector,categories in config["categories"].items():
        if sector not in SECTORS:continue
        for category in categories:
            for q in (f'{category} {sector} manufacturer model product catalogue',
                      f'{category} {sector} distributor equipment parts catalogue'):
                enqueue(db,"https://www.bing.com/search?"+urlencode({"q":q,"format":"rss"}),
                        "search",sector,category)
                enqueue(db,"https://lite.duckduckgo.com/lite/?"+urlencode({"q":q}),
                        "search_ddg",sector,category)
    for src in config.get("sources",[]):
        if not src.get("active",True):continue
        sector=src.get("sector","");category=src.get("category","")
        maker=src.get("manufacturer","")
        for url in src.get("seeds",[]):enqueue(db,url,"page",sector,category,maker)
        for url in src.get("sitemap_seeds",[]):enqueue(db,url,"sitemap",sector,category,maker)
    db.commit()
def sitemap_urls(body):
    root=ET.fromstring(body);urls=[]
    for loc in root.iter():
        if loc.tag.rsplit("}",1)[-1]=="loc" and loc.text:urls.append(loc.text.strip())
    return urls
def run(db,config,max_pages=1000,max_seconds=1800,workers=10,fetcher=fetch):
    seeds(db,config);started=time.monotonic();seen=stamp()
    rid=db.execute("INSERT INTO harvest_runs(started) VALUES(?)",(seen,)).lastrowid
    db.commit()
    stats={"processed":0,"new_products":0,"new_urls":0,"vendor_leads":0,"failures":0}
    with ThreadPoolExecutor(max_workers=workers) as pool:
        while stats["processed"]<max_pages and time.monotonic()-started<max_seconds:
            left=min(max_pages-stats["processed"],workers*3)
            rows=db.execute("""SELECT * FROM harvest_queue WHERE due<=?
               ORDER BY CASE kind WHEN 'search' THEN 0 WHEN 'search_ddg' THEN 1
                       WHEN 'sitemap' THEN 2 ELSE 3 END,failures,depth,due LIMIT ?""",
                       (stamp(),left)).fetchall()
            if not rows:break
            batch=[dict(x) for x in rows]
            futures={pool.submit(fetcher,row["url"],row["kind"]):row for row in batch}
            for future in as_completed(futures):
                row=futures[future];url=row["url"];kind=row["kind"]
                stats["processed"]+=1; t=stamp()
                try:
                    body=future.result()
                    links=[];products=[]
                    if kind in ("search","search_ddg"):
                        links=search_links(body,kind)
                    elif kind=="sitemap":
                        links=sitemap_urls(body)
                    else:
                        products,links,_=parse_page(body,url,row["maker"],row["sector"],row["category"])
                    if kind=="page":
                        email,phone=published_contact(body,url)
                        host=hostof(url)
                        db.execute("""INSERT INTO vendor_leads
                          (website,sector,category,evidence_url,checked,first_seen,last_seen,email,phone)
                          VALUES(?,?,?,?,?,?,?,?,?)
                          ON CONFLICT(website,evidence_url) DO UPDATE SET
                          checked='SITE_FETCHED',last_seen=excluded.last_seen,
                          email=CASE WHEN excluded.email<>'' THEN excluded.email ELSE vendor_leads.email END,
                          phone=CASE WHEN excluded.phone<>'' THEN excluded.phone ELSE vendor_leads.phone END""",
                          (host,row["sector"],row["category"],url,"SITE_FETCHED",t,t,email,phone))
                    for prod in products:
                        old=db.execute("SELECT 1 FROM products WHERE product_id=?",(prod["product_id"],)).fetchone()
                        db.execute("""INSERT INTO products(
                          product_id,manufacturer,model,description,source_url,content_sha256,
                          first_seen,last_seen,sector,category,identity_evidence)
                          VALUES(?,?,?,?,?,?,?,?,?,?,?)
                          ON CONFLICT(product_id) DO UPDATE SET description=excluded.description,
                          source_url=excluded.source_url,last_seen=excluded.last_seen,
                          content_sha256=excluded.content_sha256,
                          sector=CASE WHEN excluded.sector<>'' THEN excluded.sector ELSE products.sector END,
                          category=CASE WHEN excluded.category<>'' THEN excluded.category ELSE products.category END""",
                          (prod["product_id"],prod["manufacturer"],prod["model"],prod["description"],
                           url,prod["content_sha256"],t,t,prod["sector"],prod["category"],
                           prod["identity_evidence"]))
                        if not old:stats["new_products"]+=1
                        db.execute("""INSERT INTO vendor_product_evidence
                          (website,product_id,source_url,manufacturer,model,sector,category,evidence_type,last_seen)
                          VALUES(?,?,?,?,?,?,?,?,?)
                          ON CONFLICT(website,product_id,source_url) DO UPDATE SET last_seen=excluded.last_seen""",
                          (hostof(url),prod["product_id"],url,prod["manufacturer"],prod["model"],
                           row["sector"],row["category"],prod["identity_evidence"],t))
                    if kind in ("search","search_ddg"):
                        for link in links[:80]:
                            stats["new_urls"]+=enqueue(db,link,"page",row["sector"],row["category"])
                            db.execute("""INSERT INTO vendor_leads(website,sector,category,evidence_url,first_seen,last_seen)
                              VALUES(?,?,?,?,?,?) ON CONFLICT(website,evidence_url) DO UPDATE SET last_seen=excluded.last_seen""",
                              (hostof(link),row["sector"],row["category"],link,t,t))
                            stats["vendor_leads"]+=1
                    elif kind=="sitemap" and row["depth"]<6:
                        for link in links[:12000]:
                            try:public_url(link)
                            except ValueError:continue
                            if hostof(link)!=hostof(url):continue
                            childkind="sitemap" if urlsplit(link).path.lower().endswith((".xml",".xml.gz")) else "page"
                            if childkind=="page" and not any(s in urlsplit(link).path.lower() for s in
                                    ("/product","/item","/catalog","/part","/equipment","/device","/shop","/p/")):
                                continue
                            stats["new_urls"]+=enqueue(db,link,childkind,row["sector"],row["category"],row["maker"],row["depth"]+1)
                    elif kind=="page" and row["depth"]<5:
                        for link in links[:400]:
                            dest=urljoin(url,link).split("#")[0]
                            try:public_url(dest)
                            except ValueError:continue
                            if hostof(dest)!=hostof(url):continue
                            path=urlsplit(dest).path.lower()
                            if not any(s in path for s in ("/product","/item","/catalog","/part","/equipment","/device","/shop","/p/")):continue
                            stats["new_urls"]+=enqueue(db,dest,"page",row["sector"],row["category"],row["maker"],row["depth"]+1)
                    due=stamp(clock()+timedelta(hours=48 if kind in ("search","search_ddg") else 96))
                    db.execute("UPDATE harvest_queue SET due=?,failures=0,last_error='' WHERE url=?",(due,url))
                except Exception as e:
                    # Every failure affects exactly this URL; no quarantine/dead-end.
                    retry=row["failures"]+1
                    wait=min(12*3600,15*60*(2**min(retry-1,5)))
                    due=stamp(clock()+timedelta(seconds=wait))
                    db.execute("UPDATE harvest_queue SET due=?,failures=?,last_error=? WHERE url=?",
                               (due,retry,txt(type(e).__name__+": "+str(e),180),url))
                    stats["failures"]+=1
                db.commit()   # permanently checkpoint each page/lead/product
            db.execute("UPDATE harvest_runs SET pages=?,products=?,vendors=?,failed=? WHERE id=?",
                       (stats["processed"],stats["new_products"],stats["vendor_leads"],stats["failures"],rid))
            db.commit()
    db.execute("UPDATE harvest_runs SET finished=? WHERE id=?",(stamp(),rid));db.commit()
    return stats
def export(db,path):
    rows=[dict(x) for x in db.execute("""SELECT product_id AS canonical_id,manufacturer,model,description,
        source_url AS source_ref,sector,category,lifecycle_status,last_seen FROM products
        ORDER BY manufacturer,model""") if x["model"].casefold() not in GENERIC]
    vendors=[dict(x) for x in db.execute("""SELECT website,product_id,source_url,
       manufacturer,model,sector,category,evidence_type,last_seen
       FROM vendor_product_evidence ORDER BY last_seen DESC LIMIT 25000""")]
    leads=[dict(x) for x in db.execute("""SELECT website,category,sector,evidence_url,last_seen,checked,email,phone
       FROM vendor_leads ORDER BY last_seen DESC LIMIT 5000""")]
    payload={"schema":"aurenoeva-catalogue/1","items":rows,
             "vendor_products":vendors,"vendor_leads":leads,
             "availability_notice":"Product existence or supplier lead, not stock or authorization."}
    path.parent.mkdir(parents=True,exist_ok=True)
    temp=path.with_name(path.name+".tmp-"+str(os.getpid()))
    with temp.open("w") as out:
        json.dump(payload,out,ensure_ascii=False,separators=(",",":"))
        out.flush();os.fsync(out.fileno())
    os.replace(temp,path);os.chmod(path,0o600)
    return {"products":len(rows),"vendor_product_links":len(vendors),"vendor_leads":len(leads)}
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--db",type=Path,required=True)
    ap.add_argument("--sources",type=Path,required=True)
    ap.add_argument("--export",type=Path,required=True)
    ap.add_argument("--max-pages",type=int,default=1500)
    ap.add_argument("--max-seconds",type=int,default=2700)
    ap.add_argument("--workers",type=int,default=10)
    ap.add_argument("--status",action="store_true")
    a=ap.parse_args()
    cfg=json.loads(a.sources.read_text())
    a.db.parent.mkdir(parents=True,exist_ok=True)
    with (a.db.parent/(a.db.name+".lock")).open("w") as lock:
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:print('{"status":"ALREADY_RUNNING"}');return 0
        with sqlite3.connect(a.db) as db:
            db.row_factory=sqlite3.Row;db_init(db)
            if a.status:
                sector={str(row[0] or "unclassified"):int(row[1]) for row in
                        db.execute("SELECT sector,COUNT(*) FROM products GROUP BY sector")}
                last=[dict(row) for row in db.execute(
                    "SELECT * FROM harvest_runs ORDER BY id DESC LIMIT 5")]
                print(json.dumps({
                    "products":db.execute("SELECT COUNT(*) FROM products").fetchone()[0],
                    "by_sector":sector,
                    "queued":db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0],
                    "vendors":db.execute("SELECT COUNT(DISTINCT website) FROM vendor_leads").fetchone()[0],
                    "vendor_product_links":db.execute("SELECT COUNT(*) FROM vendor_product_evidence").fetchone()[0],
                    "retrying_urls":db.execute("SELECT COUNT(*) FROM harvest_queue WHERE failures>0").fetchone()[0],
                    "recent_runs":last},ensure_ascii=False))
                return 0
            try:
                prior=import_existing_rfq_vendors(db)
            except Exception as e:
                prior={"status":"TEMPORARILY_UNAVAILABLE","detail":type(e).__name__,"seeded":0}
            result=run(db,cfg,a.max_pages,a.max_seconds,a.workers)
            result["rfq_reuse"]=prior
            result["export"]=export(db,a.export)
            result["total_products"]=db.execute("SELECT COUNT(*) FROM products").fetchone()[0]
            result["total_vendor_websites"]=db.execute("SELECT COUNT(DISTINCT website) FROM vendor_leads").fetchone()[0]
            print(json.dumps(result))
    return 0
if __name__=="__main__":raise SystemExit(main())
