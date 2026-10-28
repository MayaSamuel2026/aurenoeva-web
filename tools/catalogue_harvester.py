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
import fcntl, gzip, hashlib, html, io, ipaddress, json, os, re, socket, sqlite3, subprocess, time
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode, urlsplit, urljoin, urlunsplit, parse_qs, parse_qsl, unquote
from urllib.request import Request, urlopen, HTTPRedirectHandler, build_opener
from urllib.error import HTTPError, URLError
from urllib.robotparser import RobotFileParser
from xml.etree import ElementTree as ET
from catalogue_prices import init_offers, extract_public_offers, replace_page_offers, attach_offers
from catalogue_classification import init_classifications, store_classifications, enrich_rows

AGENT="AurenoevaDiscovery/4.0 (+https://aurenoeva.com/)"
SECTORS=("laboratory","test","medical","industrial","energy","marine","aviation")
GENERIC={"products","catalog","catalogue","home","all products","equipment","solutions",
         "medical equipment","dental units","surgical carts","devices","parts","accessories"}
ROBOTS={}
# ONERGYS has parallel DE/EN domains; source configuration explicitly approves
# both. Product identity remains OEM+MPN, never the reseller's product SKU.
ONERGYS_HOSTS=frozenset(("onergys.de","www.onergys.de","onergys.com","www.onergys.com"))
ONERGYS_MAX_PAGE=2000

def onergys_canonical_url(url):
    """Drop session/tracking/filter states; retain only bounded category page."""
    if hostof(url) not in ONERGYS_HOSTS:return url
    parsed=urlsplit(url)
    if re.search(r"/(?:account|login|register|checkout|basket|cart|wishlist|my-plant)(?:/|$)",
                 parsed.path,re.I):return ""
    pages=[val for key,val in parse_qsl(parsed.query,keep_blank_values=True)
           if key.lower()=="pgnr"]
    if pages:
        if len(pages)!=1 or not re.fullmatch(r"[0-9]{1,5}",pages[0]):return ""
        number=int(pages[0])
        if number<1 or number>ONERGYS_MAX_PAGE:return ""
        query=urlencode({"pgNr":number}) if number>1 else ""
    else:query=""
    return urlunsplit((parsed.scheme,parsed.netloc,parsed.path,query,""))

def sitemap_entries(body):
    """Use XML structure, not extension, to distinguish sitemap indexes."""
    root=ET.fromstring(body)
    name=root.tag.rsplit("}",1)[-1].lower()
    if name not in ("urlset","sitemapindex"):return []
    kind="sitemap" if name=="sitemapindex" else "page"
    return [(kind,node.text.strip()) for elem in root for node in elem
            if node.tag.rsplit("}",1)[-1].lower()=="loc" and node.text]

def robots_sitemap_urls(body):
    """Only Sitemap declarations; never crawl robots disallow/allow routes."""
    return list(dict.fromkeys(
        line.strip().split(":",1)[1].strip()
        for line in body.splitlines()
        if re.match(r"(?i)^\s*sitemap\s*:",line)))[:50]

def same_source_hosts(parent_host,child_host,source_host_groups):
    if parent_host==child_host:return True
    return child_host in source_host_groups.get(parent_host,())

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
        # An ONERGYS URL must never be treated as ONERGYS product evidence
        # when a redirect ends at an unrelated third-party page.
        if hostof(req.full_url) in ONERGYS_HOSTS and hostof(url) not in ONERGYS_HOSTS:
            raise ValueError("onergys_source_redirect_outside_approved_hosts")
        return super().redirect_request(req,fp,code,msg,headers,url)
OPENER=build_opener(SafeRedirect())
def fetch(url,kind="page"):
    public_url(url);public_dns(hostof(url))
    if kind not in ("search","search_ddg","robots"):
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
        if not any(x in typ.lower() for x in ("html","xml","text/")) and not (kind=="sitemap" and urlsplit(url).path.lower().endswith(".xml.gz")):raise ValueError("nontext_content")
        limit=24_000_000 if kind=="sitemap" else (8_000_000 if kind=="search" else 2_000_000)
        raw=resp.read(limit+1)
        if len(raw)>limit:raise ValueError("large_page")
        if kind=="sitemap" and raw[:2]==b"\x1f\x8b":
            with gzip.GzipFile(fileobj=io.BytesIO(raw)) as compressed:
                raw=compressed.read(limit+1)
            if len(raw)>limit:raise ValueError("large_sitemap_uncompressed")
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
        "deichmann.com", "www.deichmann.com", "gabor.com", "www.gabor.com",
        "breuninger.com", "www.breuninger.com", "zalando.de", "www.zalando.de",
        "aboutyou.de", "www.aboutyou.de",
    }
    if host in nontechnical_sources:
        return False
    if re.search(r"\bpons\b", model, re.I) and re.match(r"^97[89][-0-9]+$", model):
        return False
    if re.search(r"\b(?:stiefelette|sneaker|schuhe?|winterboots|handtasche|umhängetasche)\b", model, re.I):
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

def parse_page(body,url,maker,sector,category):
    p=Page();p.feed(body);title=p.title[0] if p.title else ""
    heading=p.h1[0] if p.h1 else ""
    products=[]
    structured_descriptions={}
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
                if name:
                    products.append((txt(brand,100),name,"STRUCTURED_PRODUCT"))
                    if isinstance(node.get("description"),str):
                        structured_descriptions[(txt(brand,100),name)]=txt(node["description"])
            for key in ("@graph","mainEntity","itemListElement","item","hasVariant"):
                if key in node:walk(node[key])
    for x in p.structured[:20]:
        try:walk(json.loads(x))
        except (ValueError,TypeError,RecursionError):continue
    # These two verified catalogues expose part numbers in HTML instead of
    # consistent Product JSON-LD. Require a product-detail URL and a labelled
    # part number; navigation and listing pages must not become products.
    special_description=""
    path=urlsplit(url).path
    host=hostof(url)
    if ((host=="www.powergaskets.com" or host=="powergaskets.com")
            and re.fullmatch(r"/en/\d+/(?:[^?#]+/)*[^/?#]+\.html",path,re.I)):
        visible=" ".join(html.unescape(re.sub(r"<[^>]*>"," ",re.sub(
            r"<(script|style)\b[^>]*>.*?</\1>"," ",body,flags=re.I|re.S))).split())
        number=re.search(r"\bPower Gaskets No\s+([A-Za-z0-9][A-Za-z0-9./_-]{2,})\b",
                         visible,re.I)
        if number and any(ch.isdigit() for ch in number.group(1)):
            group=re.search(r"\bProduct Group\s+(.{1,110}?)\s+Application\b",visible,re.I)
            oem=re.search(r"\bOem No\s+(.{1,180}?)\s+Product Group\b",visible,re.I)
            app=re.search(r"\bApplication\s+(.{1,90}?)\s+(?:Add to Cart|Add\b|Categories\b)",
                          visible,re.I)
            special_description=txt("; ".join(x for x in (
                group.group(1) if group else "Gasket / engine sealing part",
                "OEM references: "+oem.group(1) if oem else "",
                "Application: "+app.group(1) if app else "") if x),240)
            products=[("Power Gaskets",number.group(1),"MANUFACTURER_PART_PAGE")]
    elif (host in ("www.onergys.de","onergys.de","www.onergys.com","onergys.com")
          and path.endswith(".html") and heading):
        visible=" ".join(html.unescape(re.sub(r"<[^>]*>"," ",re.sub(
            r"<(script|style)\b[^>]*>.*?</\1>"," ",body,flags=re.I|re.S))).split())
        # ONERGYS is the distributor; OEM cross-references (e.g. 'MAN 510...')
        # on its ONE-line products are not the manufacturer of that product.
        sku=re.search(r"\b(?:Artikelnummer|Product number)\s*:\s*(\d{5,12})\b",
                      visible,re.I)
        brand=re.search(r"\b(?:Hersteller|Manufacturer)\s*:\s*([A-Za-z][A-Za-z0-9&.+-]*"
                        r"(?:\s+(?:Penta|Mogul))?)",visible,re.I)
        if sku and brand:
            maker_name=txt(brand.group(1),100)
            own=re.search(r"\b(ONE\d{3,})\b",heading,re.I)
            if own:
                maker_name="ONERGYS"
                model=own.group(1).upper()
            else:
                part=re.search(r"\b"+re.escape(maker_name)+
                    r"\s+([A-Z0-9][A-Z0-9./_-]*\d[A-Z0-9./_-]*)",
                    heading,re.I)
                # A reseller article number is NOT a manufacturer MPN.
                # If the brand is a different OEM and the headline does not
                # prove its model, retain supplier-page evidence but never
                # invent the synthetic OEM part "ONERGYS-<retailer SKU>".
                model=part.group(1) if part else (
                    "SKU-"+sku.group(1) if maker_name.casefold()=="onergys" else "")
            if model:
                special_description=txt(heading+"; ONERGYS article: "+sku.group(1),240)
                products=[(maker_name,model,"SUPPLIER_PART_PAGE")]
    # On ERSAAL the seller is not the manufacturer. Extract an exact MPN
    # only from a product-detail page where the brand and MPN are both
    # explicitly visible in the title/heading; do not invent ERSAAL as OEM.
    if (not products and hostof(url) in ("www.ersaal.de","ersaal.de")
            and urlsplit(url).path.startswith("/en/product/")):
        mpn=txt(heading,160)
        m=re.match(r"^(.{2,100}?)\s+(" + re.escape(mpn) + r")(?:\s|$|[|–—])",
                   title,re.I) if mpn and any(ch.isdigit() for ch in mpn) else None
        if m:
            brand=txt(m.group(1).strip(" -|"),100)
            if brand and brand.casefold() not in GENERIC:
                products.append((brand,mpn,"SUPPLIER_PART_PAGE"))
    if not products and maker:
        headline=txt(heading,160)
        title_model=txt(re.split(r"\s+[|–—]\s+|\s+-\s+",title,maxsplit=1)[0],160)
        model=headline if any(ch.isdigit() for ch in headline) else title_model
        # A title fallback is appropriate only for a product-specific URL.
        path=urlsplit(url).path.lower()
        is_detail=bool(re.search(r"(?:/|[-_])\d{2,}(?:[a-z]|/|[-_.]|$)",path)) or (
            "/product/" in path and path.count("/")>3)
        if any(ch.isdigit() for ch in model) and (
            model==headline or is_detail) and len(model.split())<=12:
            products.append((maker,model,"ON_PAGE_MODEL"))
    result=[]
    for brand,model,proof in products:
        model=txt(model,160)
        if not brand or len(model)<2 or model.casefold() in GENERIC or len(model.split())>16:continue
        if not technical_product_relevance(sector,model,url):continue
        if not any(c.isdigit() for c in model) and proof!="STRUCTURED_PRODUCT":continue
        desc=txt(special_description or structured_descriptions.get((brand,model)) or p.metas.get("description") or title or heading)
        proper_category=refine_lab_category(sector,category,brand,model,desc,url)
        result.append({"product_id":pid(brand,model),"manufacturer":brand,"model":model,
                       "description":desc,
                       "sector":sector,"category":proper_category,"source_url":url,
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
    init_offers(db)
    init_classifications(db)
    # Add state to the EXISTING supplier-product evidence table. Historical
    # records and their original canonical identities are never deleted.
    columns={col[1] for col in db.execute("PRAGMA table_info(vendor_product_evidence)")}
    for name,definition in (
        ("listing_status","TEXT NOT NULL DEFAULT 'ACTIVE'"),
        ("missing_checks","INTEGER NOT NULL DEFAULT 0"),
        ("first_missing_at","TEXT NOT NULL DEFAULT ''"),
        ("last_checked_at","TEXT NOT NULL DEFAULT ''"),
        ("withdrawn_at","TEXT NOT NULL DEFAULT ''"),
        ("last_http_status","INTEGER NOT NULL DEFAULT 0"),
    ):
        if name not in columns:
            db.execute("ALTER TABLE vendor_product_evidence ADD COLUMN "+name+" "+definition)
    db.execute("CREATE INDEX IF NOT EXISTS vendor_evidence_by_source ON vendor_product_evidence(source_url,listing_status)")
    cols={x[1] for x in db.execute("PRAGMA table_info(products)")}
    for name in ("sector","category"):
        if name not in cols:db.execute("ALTER TABLE products ADD COLUMN "+name+" TEXT NOT NULL DEFAULT ''")
    # Older catalogue SQLite schemas predate lifecycle and evidence columns.
    for name,definition in (
        ("identity_evidence","TEXT NOT NULL DEFAULT 'HISTORICAL_SOURCE'"),
        ("lifecycle_status","TEXT NOT NULL DEFAULT 'UNVERIFIED'"),
    ):
        if name not in cols:
            db.execute("ALTER TABLE products ADD COLUMN "+name+" "+definition)
    leadcols={x[1] for x in db.execute("PRAGMA table_info(vendor_leads)")}
    for name in ("email","phone"):
        if name not in leadcols:db.execute("ALTER TABLE vendor_leads ADD COLUMN "+name+" TEXT NOT NULL DEFAULT ''")
    # One-time adoption of legacy catalogue sources, without new databases.
    # Unverified historical source dates stay historical: no false freshness.
    db.execute("CREATE TABLE IF NOT EXISTS catalogue_lifecycle_meta (key TEXT PRIMARY KEY)")
    if not db.execute("SELECT 1 FROM catalogue_lifecycle_meta WHERE key='source_adoption_v1'").fetchone():
        host="""lower(substr(source_url,9,CASE
             WHEN instr(substr(source_url,9),'/')>0
             THEN instr(substr(source_url,9),'/')-1 ELSE 180 END))"""
        db.execute("""INSERT OR IGNORE INTO vendor_product_evidence
          (website,product_id,source_url,manufacturer,model,sector,category,evidence_type,last_seen)
          SELECT """+host+""",product_id,source_url,manufacturer,model,sector,category,
          identity_evidence,last_seen FROM products
          WHERE source_url LIKE 'https://%' AND manufacturer!='' AND model!=''""")
        db.execute("""INSERT OR IGNORE INTO harvest_queue
          (url,kind,sector,category,maker,depth,due)
          SELECT source_url,'page',sector,category,manufacturer,0,?
          FROM products WHERE source_url LIKE 'https://%'""",(stamp(),))
        db.execute("INSERT INTO catalogue_lifecycle_meta(key) VALUES('source_adoption_v1')")
    db.commit()

def verify_source_listings(db, url, found_product_ids, checked_at, http_status=200):
    """Two dated, independent observations before withdrawing a seller listing.

    A 404/410 is negative evidence. A successful page that clearly identifies
    OTHER products is negative for an older missing model. Empty or broken
    parser results and network failures are inconclusive, never deletions.
    Product identities, quotes and historical supplier records remain intact.
    """
    if http_status not in (200,404,410):
        return 0
    existing=db.execute("""SELECT product_id,listing_status,missing_checks,first_missing_at
                           FROM vendor_product_evidence WHERE source_url=?""",(url,)).fetchall()
    positive=set(found_product_ids)
    # Parsed catalogue/index pages can omit products through pagination.
    # Only a single positively identified replacement on an item-detail URL
    # counts as negative evidence for an older seller item on HTTP 200.
    path=urlsplit(url).path.lower()
    individual_page=bool(re.search(r"/(?:products?|parts?|items?|p)/[^?#]+",path)) and len(positive)==1
    current=datetime.fromisoformat(checked_at.replace("Z","+00:00"))
    affected=set(positive)
    withdrawn=0
    for record in existing:
        key=record["product_id"]
        affected.add(key)
        if key in positive:
            db.execute("""UPDATE vendor_product_evidence
                          SET listing_status='ACTIVE',missing_checks=0,first_missing_at='',
                              last_checked_at=?,last_http_status=?,withdrawn_at=''
                          WHERE product_id=? AND source_url=?""",
                       (checked_at,http_status,key,url))
            continue
        # A parsed empty page is NOT proof that the seller withdrew every part.
        # A missing price or temporarily broken markup is not product removal.
        if http_status==200 and not individual_page:
            db.execute("""UPDATE vendor_product_evidence SET last_checked_at=?,
                          last_http_status=200 WHERE product_id=? AND source_url=?""",
                       (checked_at,key,url))
            continue
        first=record["first_missing_at"]
        try:
            elapsed=(current-datetime.fromisoformat(first.replace("Z","+00:00"))).total_seconds()
        except (ValueError,TypeError):
            elapsed=0
        checks=int(record["missing_checks"])+1
        confirmed=(checks>=2 and elapsed>=86400)
        state="WITHDRAWN" if confirmed else "RECHECK_REQUIRED"
        # A definite missing source is sufficient to SUSPEND its price now.
        # Retire the product listing only after the second dated observation.
        db.execute("UPDATE public_offers SET active=0 WHERE product_id=? AND source_url=?",(key,url))
        if state=="WITHDRAWN" and record["listing_status"]!="WITHDRAWN":
            withdrawn+=1
        db.execute("""UPDATE vendor_product_evidence SET listing_status=?,missing_checks=?,
                      first_missing_at=?,last_checked_at=?,last_http_status=?,
                      withdrawn_at=? WHERE product_id=? AND source_url=?""",
                   (state,checks,first or checked_at,checked_at,http_status,
                    checked_at if confirmed else "",key,url))
    # The caller has already inserted positive evidence for newly parsed items.
    for key in affected:
        candidates=db.execute("""SELECT source_url FROM vendor_product_evidence
                         WHERE product_id=? AND listing_status!='WITHDRAWN'
                         ORDER BY last_seen DESC LIMIT 1""",(key,)).fetchone()
        if candidates is None:
            db.execute("UPDATE products SET lifecycle_status='WITHDRAWN' WHERE product_id=?",(key,))
        elif key in positive:
            db.execute("UPDATE products SET lifecycle_status='ACTIVE' WHERE product_id=?",(key,))
        # A retired primary URL must be replaced by the surviving seller source.
        if candidates is not None:
            db.execute("""UPDATE products SET source_url=? WHERE product_id=?
                       AND (lifecycle_status='WITHDRAWN' OR source_url IN
                            (SELECT source_url FROM vendor_product_evidence
                             WHERE product_id=? AND listing_status='WITHDRAWN'))""",
                       (candidates["source_url"],key,key))
            db.execute("""UPDATE products SET lifecycle_status='ACTIVE'
                       WHERE product_id=? AND lifecycle_status='WITHDRAWN'""",(key,))
    return withdrawn

def onergys_source_status(db,now=None):
    """Inspectable actual ONERGYS progress, not the shop's advertised count.

    Read the authoritative existing SQLite source ledger only. Source-specific
    rates, stalled URLs, active evidence and pricing are never inferred from
    total AURENOEVA catalogue size or from the supplier's marketing claims.
    """
    hosts=tuple(sorted(ONERGYS_HOSTS))
    bind=",".join("?" for _ in hosts)
    cutoff=stamp((now or clock())-timedelta(days=14))
    due_at=stamp(now or clock())
    summary={}
    counts={
        "evidenced_distinct_parts":
            ("SELECT COUNT(DISTINCT product_id) FROM vendor_product_evidence "
             "WHERE website IN ("+bind+")",hosts),
        "active_distinct_parts":
            ("SELECT COUNT(DISTINCT product_id) FROM vendor_product_evidence "
             "WHERE website IN ("+bind+") AND listing_status='ACTIVE'",hosts),
        "withdrawn_seller_listings":
            ("SELECT COUNT(*) FROM vendor_product_evidence "
             "WHERE website IN ("+bind+") AND listing_status='WITHDRAWN'",hosts),
        "pending_recheck_listings":
            ("SELECT COUNT(*) FROM vendor_product_evidence "
             "WHERE website IN ("+bind+") AND listing_status='RECHECK_REQUIRED'",hosts),
        "checked_last_14_days":
            ("SELECT COUNT(DISTINCT product_id) FROM vendor_product_evidence "
             "WHERE website IN ("+bind+") AND last_checked_at>=?",hosts+(cutoff,)),
        "fetched_pages":
            ("SELECT COUNT(*) FROM vendor_leads WHERE website IN ("+bind+") "
             "AND checked='SITE_FETCHED'",hosts),
        "latest_fetched_at":
            ("SELECT MAX(last_seen) FROM vendor_leads WHERE website IN ("+bind+") "
             "AND checked='SITE_FETCHED'",hosts),
        "active_recent_public_offers":
            ("SELECT COUNT(*) FROM public_offers WHERE seller_website IN ("+bind+") "
             "AND active=1 AND observed_at>=?",hosts+(cutoff,))
    }
    for label,(sql,params) in counts.items():
        summary[label]=db.execute(sql,params).fetchone()[0]
    queued=db.execute("SELECT url,kind,due,failures,last_error FROM harvest_queue "
                      "WHERE url LIKE 'https://%.onergys.de/%' OR "
                      "url LIKE 'https://onergys.de/%' OR "
                      "url LIKE 'https://%.onergys.com/%' OR "
                      "url LIKE 'https://onergys.com/%'").fetchall()
    summary["queued_urls"]=len(queued)
    summary["due_urls"]=sum(row["due"]<=due_at for row in queued)
    summary["failed_urls"]=sum(row["failures"]>0 for row in queued)
    summary["discovered_pagination_urls"]=sum(
        row["kind"]=="page" and any(k.lower()=="pgnr" for k,_ in
             parse_qsl(urlsplit(row["url"]).query)) for row in queued)
    summary["pending_sitemaps"]=sum(row["kind"]=="sitemap" and
        row["due"]<=due_at for row in queued)
    summary["source_hosts"]=list(hosts)
    return summary

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
                      f'{category} {sector} distributor equipment parts catalogue',
                      f'{category} {sector} OEM MPN part number datasheet',
                      f'{category} {sector} used refurbished manufacturer model suppliers'):
                enqueue(db,"https://www.bing.com/search?"+urlencode({"q":q,"format":"rss"}),
                        "search",sector,category)
                enqueue(db,"https://lite.duckduckgo.com/lite/?"+urlencode({"q":q}),
                        "search_ddg",sector,category)
    # Homepage's 12 additional industries and 18 product families are now
    # active discovery topics, not merely links to an already indexed catalogue.
    # Search keywords are NOT product evidence: leave legacy sector/category
    # empty until the fetched product itself supplies verifiable technical text.
    for target in config.get("discovery_targets", []):
        if not isinstance(target, dict) or target.get("group") not in ("industry", "product_family"):
            continue
        for phrase in target.get("queries", [])[:3]:
            if not isinstance(phrase, str) or not 10 <= len(phrase) <= 180:
                continue
            enqueue(db,"https://www.bing.com/search?"+urlencode({"q":phrase,"format":"rss"}),
                    "search")
            enqueue(db,"https://lite.duckduckgo.com/lite/?"+urlencode({"q":phrase}),
                    "search_ddg")
    for src in config.get("sources",[]):
        if not src.get("active",True):continue
        sector=src.get("sector","");category=src.get("category","")
        maker=src.get("manufacturer","")
        for url in src.get("seeds",[]):enqueue(db,url,"page",sector,category,maker)
        for url in src.get("sitemap_seeds",[]):enqueue(db,url,"sitemap",sector,category,maker)
        if src.get("discover_robots_sitemaps"):
            for host in src.get("allowed_hosts",[]):
                if isinstance(host,str) and re.fullmatch(r"[a-zA-Z0-9.-]+",host):
                    enqueue(db,"https://"+host+"/robots.txt","robots",
                            sector,category,maker)
        # Model hints are configured manufacturer part identities, not product proof.
        # Search exact manufacturer/model pairs to discover independent suppliers;
        # only fetched product pages can become catalogue records.
        hints=src.get("model_hints") or {}
        if maker and isinstance(hints,dict):
            for model in set(hints.values()):
                part=txt(model,100)
                if len(part)<2 or part.casefold() in GENERIC:continue
                query=f'"{maker}" "{part}" manufacturer product datasheet part number'
                enqueue(db,"https://www.bing.com/search?"+urlencode({"q":query,"format":"rss"}),
                        "search",sector,category)
                enqueue(db,"https://lite.duckduckgo.com/lite/?"+urlencode({"q":query}),
                        "search_ddg",sector,category)
        # One-time, non-destructive catch-up after a source's catalogue
        # discovery rules change. Old successful source pages may otherwise
        # remain four days away even when the source refresh is now 24 hours.
        revision=src.get("coverage_revision","")
        if revision:
            if not isinstance(revision,str) or not re.fullmatch(r"[a-zA-Z0-9_-]{1,80}",revision):
                raise ValueError("invalid_source_coverage_revision")
            key="source-coverage-"+str(src["id"])+"-"+revision
            if not db.execute("SELECT 1 FROM catalogue_lifecycle_meta WHERE key=?",(key,)).fetchone():
                for host in src.get("allowed_hosts",[]):
                    if isinstance(host,str) and re.fullmatch(r"[a-zA-Z0-9.-]+",host):
                        db.execute("UPDATE harvest_queue SET due=? WHERE url LIKE ?",
                                   (stamp(),"https://"+host+"/%"))
                db.execute("INSERT INTO catalogue_lifecycle_meta(key) VALUES(?)",(key,))
    db.commit()
def sitemap_urls(body):
    return [url for _,url in sitemap_entries(body)]
def run(db,config,max_pages=1000,max_seconds=1800,workers=10,fetcher=fetch,
        progress=None,progress_interval=300):
    seeds(db,config);started=time.monotonic();seen=stamp()
    last_progress_at=started
    last_published_new=0
    rid=db.execute("INSERT INTO harvest_runs(started) VALUES(?)",(seen,)).lastrowid
    db.commit()
    stats={"processed":0,"new_products":0,"new_urls":0,"vendor_leads":0,"failures":0}
    # Source-aware traversal lets directories expose their real product/detail
    # paths; the old hard-coded /product restriction missed /manufacturers
    # and ERSAAL's /kategorien/ catalogue hierarchy.
    follow_by_host={}
    patterns_by_host={}
    depth_by_host={}
    refresh_by_host={}
    source_host_groups={}
    for source in config.get("sources",[]):
        if not source.get("active",True):continue
        # Bind the full declared set, not just hosts that happen to have seeds.
        # This permits a DE->EN link only if both domains belong to a
        # configured source (ONERGYS) and still pass the approved path rules.
        hosts={str(h).lower() for h in source.get("allowed_hosts",[])
               if isinstance(h,str) and re.fullmatch(r"[a-zA-Z0-9.-]+",h)}
        for seed_url in source.get("seeds",[])+source.get("sitemap_seeds",[]):
            hosts.add(hostof(seed_url))
        depth=min(12,max(1,int(source.get("max_depth",5))))
        refresh=min(14*24,max(12,int(source.get("refresh_hours",96))))
        for host in hosts:
            source_host_groups.setdefault(host,set()).update(hosts)
            depth_by_host[host]=max(depth_by_host.get(host,5),depth)
            refresh_by_host[host]=min(refresh_by_host.get(host,96),refresh)
            follow_by_host.setdefault(host,set()).update(
                str(p) for p in source.get("follow_paths",[])
                if isinstance(p,str) and p.startswith("/")
            )
            pattern=source.get("follow_regex","")
            if pattern:patterns_by_host.setdefault(host,set()).add(pattern)
    # A configured manufacturer pattern is a discovery route, not a product claim.
    patterns_by_host={host:[re.compile(p,re.I) for p in patterns]
                      for host,patterns in patterns_by_host.items()}
    # Give newly approved supplier catalogues a bounded share of each run.
    # An established, much larger backlog must not starve their first import.
    # Remaining page capacity stays available to every existing source.
    priority_hosts=sorted({
        host.lower() for src in config.get("sources",[])
        if src.get("active",True) and src.get("priority")
        for host in src.get("allowed_hosts",[])
        if isinstance(host,str) and re.fullmatch(r"[a-zA-Z0-9.-]+",host)
    })
    priority_remaining=max(1,max_pages//3) if priority_hosts else 0
    with ThreadPoolExecutor(max_workers=workers) as pool:
        while stats["processed"]<max_pages and time.monotonic()-started<max_seconds:
            left=min(max_pages-stats["processed"],workers*3)
            # Fair discovery: reserve most requests for existing product-detail
            # leads, without starving fresh sitemaps and worldwide searches.
            # The previous absolute search-first sort could repeatedly consume
            # the run budget before an already-discovered product was fetched.
            buckets=(("page", 60), ("sitemap", 20), ("robots", 5),
                     ("search", 10), ("search_ddg", 5))
            counts=[left*share//100 for _,share in buckets]
            for i in range(left-sum(counts)):
                counts[i%len(counts)]+=1
            rows=[]
            selected=set()
            for (kind,_),limit in zip(buckets,counts):
                if not limit:continue
                if kind=="page" and priority_remaining:
                    host_where=" OR ".join("url LIKE ?" for _ in priority_hosts)
                    host_params=tuple("https://"+h+"/%" for h in priority_hosts)
                    favored=db.execute("""SELECT * FROM harvest_queue
                        WHERE due<=? AND kind=? AND ("""+host_where+""")
                        ORDER BY failures,depth,due LIMIT ?""",
                        (stamp(),kind,*host_params,min(limit,priority_remaining))).fetchall()
                    for record in favored:
                        if record["url"] not in selected:
                            rows.append(record);selected.add(record["url"])
                    priority_remaining-=len(favored)
                    limit-=len(favored)
                if not limit:continue
                found=db.execute("""SELECT * FROM harvest_queue
                    WHERE due<=? AND kind=? ORDER BY failures,depth,due
                    LIMIT ?""",(stamp(),kind,limit)).fetchall()
                for record in found:
                    if record["url"] not in selected:
                        rows.append(record);selected.add(record["url"])
            # Fill unused quota from any ready kind; no empty slots if a
            # category has few pending URLs.
            if len(rows)<left:
                for record in db.execute("""SELECT * FROM harvest_queue
                    WHERE due<=? ORDER BY failures,depth,due LIMIT ?""",
                    (stamp(),left+len(rows))).fetchall():
                    if record["url"] not in selected:
                        rows.append(record);selected.add(record["url"])
                        if len(rows)>=left:break
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
                        links=sitemap_entries(body)
                    elif kind=="robots":
                        links=robots_sitemap_urls(body)
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
                        store_classifications(db, prod, t)
                        if not old:stats["new_products"]+=1
                        db.execute("""INSERT INTO vendor_product_evidence
                          (website,product_id,source_url,manufacturer,model,sector,category,evidence_type,last_seen)
                          VALUES(?,?,?,?,?,?,?,?,?)
                          ON CONFLICT(website,product_id,source_url) DO UPDATE SET last_seen=excluded.last_seen""",
                          (hostof(url),prod["product_id"],url,prod["manufacturer"],prod["model"],
                           row["sector"],row["category"],prod["identity_evidence"],t))
                    if kind=="page":
                        if products:
                            offers=extract_public_offers(body,url,products,t)
                            replace_page_offers(db,url,[p["product_id"] for p in products],offers)
                        verify_source_listings(db,url,[p["product_id"] for p in products],t)
                    if kind in ("search","search_ddg"):
                        for link in links[:80]:
                            stats["new_urls"]+=enqueue(db,link,"page",row["sector"],row["category"])
                            db.execute("""INSERT INTO vendor_leads(website,sector,category,evidence_url,first_seen,last_seen)
                              VALUES(?,?,?,?,?,?) ON CONFLICT(website,evidence_url) DO UPDATE SET last_seen=excluded.last_seen""",
                              (hostof(link),row["sector"],row["category"],link,t,t))
                            stats["vendor_leads"]+=1
                    elif kind=="robots":
                        # The source's published sitemap locations are authoritative;
                        # do not guess sitemap names or wander into other domains.
                        for link in links:
                            try:public_url(link)
                            except ValueError:continue
                            if not same_source_hosts(hostof(url),hostof(link),source_host_groups):
                                continue
                            stats["new_urls"]+=enqueue(db,link,"sitemap",
                                row["sector"],row["category"],row["maker"],0)
                    elif kind=="sitemap" and row["depth"]<6:
                        for childkind,link in links[:50000]:
                            link=onergys_canonical_url(link)
                            if not link:continue
                            try:public_url(link)
                            except ValueError:continue
                            if not same_source_hosts(hostof(url),hostof(link),source_host_groups):
                                continue
                            if childkind=="page":
                                path=urlsplit(link).path.lower()
                                ordinary=any(s in path for s in
                                    ("/product","/item","/catalog","/part","/equipment","/device","/shop","/p/"))
                                explicit=any(path.startswith(p.lower()) for p in follow_by_host.get(hostof(link),()))
                                patterned=any(p.match(urlsplit(link).path) for p in patterns_by_host.get(hostof(link),()))
                                if not (ordinary or explicit or patterned):continue
                            stats["new_urls"]+=enqueue(db,link,childkind,row["sector"],row["category"],row["maker"],row["depth"]+1)
                    elif kind=="page" and row["depth"]<depth_by_host.get(hostof(url),5):
                        prefixes=follow_by_host.get(hostof(url),set())
                        max_links=2500 if urlsplit(url).path.rstrip("/") in (
                           "/manufacturers","/en/hersteller","/en/kategorien") else 1000
                        # ONERGYS has thousands of paginated category listings.
                        # Ensure page-2 links are not crowded out by global nav
                        # and related-product links. The job still enforces its
                        # existing total page/time budgets.
                        if hostof(url) in ONERGYS_HOSTS:
                            max_links=4000
                            links=sorted(links[:12000],key=lambda link:
                                0 if re.search(r"(?:[?&]|&amp;)pgNr=",link,re.I) else 1)
                        for link in links[:max_links]:
                            dest=onergys_canonical_url(urljoin(url,link).split("#")[0])
                            if not dest:continue
                            try:public_url(dest)
                            except ValueError:continue
                            if not same_source_hosts(hostof(url),hostof(dest),source_host_groups):
                                continue
                            path=urlsplit(dest).path.lower()
                            default=any(key in path for key in
                              ("/product","/item","/catalog","/part","/equipment","/device","/shop","/p/"))
                            patterned=any(p.match(urlsplit(dest).path) for p in patterns_by_host.get(hostof(dest),()))
                            if not (default or any(path.startswith(pref.lower()) for pref in prefixes) or patterned):
                                continue
                            # OXID pagination is a lateral step within the same
                            # category, NOT another level of category nesting.
                            # Otherwise 600-page sections stop after page 9.
                            same_category_page=(hostof(url) in ONERGYS_HOSTS
                                and urlsplit(dest).path==urlsplit(url).path
                                and any(key.lower()=="pgnr" for key,_ in
                                        parse_qsl(urlsplit(dest).query)))
                            next_depth=row["depth"] if same_category_page else row["depth"]+1
                            stats["new_urls"]+=enqueue(db,dest,"page",row["sector"],row["category"],row["maker"],next_depth)
                    refresh=refresh_by_host.get(hostof(url),96)
                    due=stamp(clock()+timedelta(hours=(
                        48 if kind in ("search","search_ddg") else
                        168 if kind=="robots" else refresh)))
                    db.execute("UPDATE harvest_queue SET due=?,failures=0,last_error='' WHERE url=?",(due,url))
                except HTTPError as e:
                    # Two 404/410 observations >=24h apart retire just this seller
                    # source. Other HTTP failures do not affect listing validity.
                    if kind=="page" and e.code in (404,410):
                        verify_source_listings(db,url,[],t,http_status=e.code)
                        due=stamp(clock()+timedelta(hours=48))
                    else:
                        retry=row["failures"]+1
                        wait=min(12*3600,15*60*(2**min(retry-1,5)))
                        due=stamp(clock()+timedelta(seconds=wait))
                    db.execute("UPDATE harvest_queue SET due=?,failures=failures+1,last_error=? WHERE url=?",
                               (due,txt("HTTP "+str(e.code),180),url))
                    stats["failures"]+=1
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
            # Periodic publication during a long run, without another daemon
            # or timer. The existing end-of-run publisher remains authoritative.
            now=time.monotonic()
            if (progress is not None and
                stats["new_products"]>last_published_new and
                now-last_progress_at>=progress_interval):
                last_progress_at=now
                try:
                    if progress():
                        last_published_new=stats["new_products"]
                        print(json.dumps({"event":"CATALOGUE_PROGRESS_SYNC",
                            "new_products":last_published_new,
                            "status":"PUBLISHED"}),flush=True)
                except Exception as exc:
                    print(json.dumps({"event":"CATALOGUE_PROGRESS_SYNC",
                        "status":"RETRY_NEXT_CHECKPOINT",
                        "reason":type(exc).__name__}),flush=True)
    db.execute("UPDATE harvest_runs SET finished=? WHERE id=?",(stamp(),rid));db.commit()
    return stats
def export(db,path):
    rows=[dict(x) for x in db.execute("""SELECT product_id AS canonical_id,manufacturer,model,description,
        source_url AS source_ref,sector,category,lifecycle_status,last_seen FROM products
        ORDER BY manufacturer,model""") if x["model"].casefold() not in GENERIC]
    attach_offers(db, rows)
    enrich_rows(rows)
    vendors=[dict(x) for x in db.execute("""SELECT website,product_id,source_url,
       manufacturer,model,sector,category,evidence_type,last_seen
       FROM vendor_product_evidence WHERE listing_status!='WITHDRAWN'
       ORDER BY last_seen DESC""")]
    leads=[dict(x) for x in db.execute("""SELECT website,category,sector,evidence_url,last_seen,checked,email,phone
       FROM vendor_leads ORDER BY last_seen DESC""")]
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
                top_errors=[{"reason":str(row[0]),"urls":int(row[1])} for row in
                    db.execute("""SELECT substr(last_error,1,100), COUNT(*)
                      FROM harvest_queue WHERE last_error<>''
                      GROUP BY substr(last_error,1,100)
                      ORDER BY COUNT(*) DESC LIMIT 12""")]
                top_failed_hosts=[{"host":str(row[0]),"urls":int(row[1])} for row in
                    db.execute("""SELECT
                         substr(url,instr(url,'//')+2,
                            CASE WHEN instr(substr(url,instr(url,'//')+2),'/')>0
                            THEN instr(substr(url,instr(url,'//')+2),'/')-1
                            ELSE 120 END) AS host,COUNT(*) AS n
                      FROM harvest_queue WHERE failures>0
                      GROUP BY host ORDER BY n DESC LIMIT 12""")]
                print(json.dumps({
                    "products":db.execute("SELECT COUNT(*) FROM products").fetchone()[0],
                    "by_sector":sector,
                    "queued":db.execute("SELECT COUNT(*) FROM harvest_queue").fetchone()[0],
                    "vendors":db.execute("SELECT COUNT(DISTINCT website) FROM vendor_leads").fetchone()[0],
                    "vendor_product_links":db.execute("SELECT COUNT(*) FROM vendor_product_evidence").fetchone()[0],
                    "retrying_urls":db.execute("SELECT COUNT(*) FROM harvest_queue WHERE failures>0").fetchone()[0],
                    "top_errors":top_errors,
                    "top_failed_hosts":top_failed_hosts,
                    "onergys":onergys_source_status(db),
                    "recent_runs":last},ensure_ascii=False))
                return 0
            try:
                prior=import_existing_rfq_vendors(db)
            except Exception as e:
                prior={"status":"TEMPORARILY_UNAVAILABLE","detail":type(e).__name__,"seeded":0}
            def publish_progress():
                # Publishing is only attempted when the existing authenticated
                # site connection is configured; never expose private contacts.
                if not Path("/var/lib/aurenoeva/catalogue-sync-token").is_file():
                    return False
                export(db,a.export)
                command=["/usr/bin/python3",
                         str(Path(__file__).with_name("catalogue_publish.py")),
                         "--snapshot",str(a.export),"--products-only"]
                response=subprocess.run(command,timeout=180,capture_output=True,
                    text=True,check=False)
                if response.returncode:
                    print(json.dumps({"event":"CATALOGUE_CHECKPOINT_DELIVERY",
                         "status":"FAILED","details":response.stdout[-300:]}),
                         flush=True)
                    return False
                print(json.dumps({"event":"CATALOGUE_CHECKPOINT_DELIVERY",
                     "status":"PASS"}),flush=True)
                return True
            result=run(db,cfg,a.max_pages,a.max_seconds,a.workers,
                       progress=publish_progress,progress_interval=300)
            result["rfq_reuse"]=prior
            result["export"]=export(db,a.export)
            result["total_products"]=db.execute("SELECT COUNT(*) FROM products").fetchone()[0]
            result["total_vendor_websites"]=db.execute("SELECT COUNT(DISTINCT website) FROM vendor_leads").fetchone()[0]
            print(json.dumps(result))
    return 0
if __name__=="__main__":raise SystemExit(main())
