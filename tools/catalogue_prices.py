"""Public, source-proven seller prices for the existing AURENOEVA catalogue.

A price is never stock, a quotation or an AURENOEVA selling price.
Only exact product-level Schema.org Offer evidence is admitted.
"""
from __future__ import annotations

from datetime import datetime, timezone, timedelta
from decimal import Decimal, InvalidOperation
from html.parser import HTMLParser
import hashlib
import json
import re
from urllib.parse import urlsplit

CURRENCIES = frozenset(("EUR", "USD", "GBP", "CHF", "CAD", "AUD", "JPY",
    "CNY", "SEK", "NOK", "DKK", "PLN", "CZK", "HUF", "INR", "SGD", "NZD",
    "AED", "SAR", "ZAR", "BRL", "MXN", "KRW", "TRY", "HKD", "TWD"))
MAX_AGE_DAYS = 14
PRICE_STATUS = "ON_REQUEST"


class JsonLd(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.blocks = []
        self.reading = False
        self.buffer = []

    def handle_starttag(self, tag, attrs):
        if tag == "script" and "ld+json" in dict(attrs).get("type", "").lower():
            self.reading = True
            self.buffer = []

    def handle_data(self, data):
        if self.reading:
            self.buffer.append(data)

    def handle_endtag(self, tag):
        if tag == "script" and self.reading:
            self.blocks.append("".join(self.buffer))
            self.reading = False
            self.buffer = []


def product_key(maker, model):
    norm = lambda x: re.sub("[^a-z0-9]", "", str(x).casefold())
    return "AUR-P-" + hashlib.sha256((norm(maker) + "|" + norm(model)).encode()).hexdigest()[:20].upper()


def valid_source(url):
    try:
        p = urlsplit(url)
        return (p.scheme == "https" and bool(p.hostname) and "." in p.hostname
                and not p.username and not p.password and p.port in (None, 443)
                and len(url) <= 1400)
    except (TypeError, ValueError):
        return False


def amount_value(value):
    if isinstance(value, bool) or not isinstance(value, (str, int, float, Decimal)):
        return None
    s = str(value).strip()
    # Structured numeric values only. Never guess locale punctuation or parse page prose.
    if not re.fullmatch(r"(?:[0-9]{1,9})(?:\.[0-9]{1,2})?", s):
        return None
    try:
        n = Decimal(s)
    except InvalidOperation:
        return None
    return float(n) if Decimal("0") < n <= Decimal("100000000") else None


def fresh(value, now=None):
    try:
        when = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
        now = now or datetime.now(timezone.utc)
        return (when.tzinfo is not None and
                now - timedelta(days=MAX_AGE_DAYS) <= when <= now + timedelta(minutes=5))
    except (ValueError, TypeError):
        return False


def extract_public_offers(body, source_url, products, observed_at):
    """Extract only offers tied to the EXACT manufacturer/model already accepted on page."""
    if not valid_source(source_url) or not products:
        return []
    ids = {p["product_id"] for p in products}
    seller_website = urlsplit(source_url).hostname.lower()
    parser = JsonLd()
    parser.feed(body)
    found = {}

    def walk(node):
        if isinstance(node, list):
            for child in node[:100]:
                walk(child)
            return
        if not isinstance(node, dict):
            return
        kinds = node.get("@type", [])
        if isinstance(kinds, str):
            kinds = [kinds]
        if "Product" in kinds:
            brand = node.get("brand") or node.get("manufacturer")
            if isinstance(brand, dict):
                brand = brand.get("name")
            # A generic name/brand inferred from the site is not an exact match.
            model = node.get("mpn") or node.get("model") or node.get("sku")
            if isinstance(brand, str) and isinstance(model, str):
                key = product_key(brand.strip(), model.strip())
                if key in ids:
                    offers = node.get("offers", [])
                    if isinstance(offers, dict):
                        offers = [offers]
                    if isinstance(offers, list):
                        for offer in offers[:20]:
                            if not isinstance(offer, dict):
                                continue
                            typ = offer.get("@type", "")
                            if isinstance(typ, list):
                                typ = typ[0] if typ else ""
                            if typ != "Offer":  # No AggregateOffer ranges or inferred quotes.
                                continue
                            currency = str(offer.get("priceCurrency", "")).upper().strip()
                            amount = amount_value(offer.get("price"))
                            if currency not in CURRENCIES or amount is None:
                                continue
                            seller = offer.get("seller")
                            seller_name = (seller.get("name") if isinstance(seller, dict)
                                           else seller if isinstance(seller, str) else "")
                            seller_name = " ".join(str(seller_name or seller_website).split())[:120]
                            row = {"product_id": key, "seller_website": seller_website,
                                   "seller_name": seller_name,
                                   "source_url": source_url, "currency": currency,
                                   "amount": amount, "observed_at": observed_at}
                            # One exact product and price per seller/site/currency/source.
                            index = (key, seller_website, source_url, currency)
                            if index not in found or row["amount"] < found[index]["amount"]:
                                found[index] = row
        for k in ("@graph", "mainEntity", "itemListElement", "item", "hasVariant"):
            if k in node:
                walk(node[k])

    for block in parser.blocks[:30]:
        try:
            walk(json.loads(block))
        except (ValueError, TypeError, RecursionError):
            continue
    return list(found.values())


def init_offers(db):
    db.execute("""CREATE TABLE IF NOT EXISTS public_offers (
        product_id TEXT NOT NULL, seller_website TEXT NOT NULL,
        seller_name TEXT NOT NULL, source_url TEXT NOT NULL,
        currency TEXT NOT NULL, amount REAL NOT NULL,
        observed_at TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1,
        PRIMARY KEY(product_id, seller_website, source_url, currency))""")
    db.execute("""CREATE INDEX IF NOT EXISTS public_offers_recent
        ON public_offers(product_id, active, observed_at)""")


def replace_page_offers(db, source_url, product_ids, rows):
    """Refresh ONLY the prices on this page. Do not touch unrelated seller evidence."""
    if not product_ids:
        return
    for key in set(product_ids):
        db.execute("UPDATE public_offers SET active=0 WHERE product_id=? AND source_url=?",
                   (key, source_url))
    for r in rows:
        db.execute("""INSERT INTO public_offers
            (product_id,seller_website,seller_name,source_url,currency,amount,observed_at,active)
            VALUES(?,?,?,?,?,?,?,1)
            ON CONFLICT(product_id,seller_website,source_url,currency) DO UPDATE SET
            seller_name=excluded.seller_name,amount=excluded.amount,
            observed_at=excluded.observed_at,active=1""",
            (r["product_id"],r["seller_website"],r["seller_name"],r["source_url"],
             r["currency"],r["amount"],r["observed_at"]))


def sanitize_offers(value, canonical_id, now=None):
    """Retain up to five current, independently checkable public seller offers."""
    if not isinstance(value, list):
        return []
    out = []
    seen = set()
    for item in value[:30]:
        if not isinstance(item, dict) or item.get("product_id", canonical_id) != canonical_id:
            continue
        source = item.get("source_url", "")
        website = item.get("seller_website", "")
        try:
            host = urlsplit(source).hostname
        except (TypeError, ValueError):
            continue
        amount = amount_value(item.get("amount"))
        currency = str(item.get("currency", "")).upper()
        if not valid_source(source) or host != website or currency not in CURRENCIES:
            continue
        if amount is None or not fresh(item.get("observed_at"), now):
            continue
        key = (website, source, currency)
        if key in seen:
            continue
        seen.add(key)
        out.append({"seller_website": website, "seller_name":
                    " ".join(str(item.get("seller_name") or website).split())[:120],
                    "source_url": source, "currency": currency,
                    "amount": amount, "observed_at": item["observed_at"]})
    return sorted(out, key=lambda x: (x["currency"], x["amount"]))[:5]


def attach_offers(db, rows):
    """Populate the existing export; retain product count and canonical IDs unchanged."""
    by_id = {r["canonical_id"]: r for r in rows}
    for r in rows:
        r["public_offers"] = []
        r["price_status"] = PRICE_STATUS
    if not db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='public_offers'").fetchone():
        return
    for offer in db.execute("""SELECT product_id,seller_website,seller_name,source_url,
                                      currency,amount,observed_at
                               FROM public_offers WHERE active=1 ORDER BY amount ASC"""):
        data = dict(zip(("product_id","seller_website","seller_name","source_url",
                         "currency","amount","observed_at"), offer))
        row = by_id.get(data["product_id"])
        if row is not None and len(row["public_offers"]) < 30:
            row["public_offers"].append(data)
    for row in rows:
        row["public_offers"] = sanitize_offers(row["public_offers"], row["canonical_id"])
        if row["public_offers"]:
            row["price_status"] = "PUBLIC_OFFER"
