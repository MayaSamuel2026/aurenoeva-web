#!/usr/bin/env bash
# AURENOEVA 22 Oct: repair >24MB catalogue publication without recrawling or new DB.
set -euo pipefail
umask 077
[[ $(id -u) == 0 ]] || { echo "ROOT_REQUIRED"; exit 2; }
ROOT=/opt/aurenoeva
STATE=/var/lib/aurenoeva
[[ -s "$STATE/catalogue.sqlite3" && -s "$STATE/catalogue-public.json" &&
   -s "$STATE/catalogue-sync-token" ]] || {
  echo "PRESERVED_CATALOGUE_OR_AUTH_TOKEN_MISSING"; exit 3;
}
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/tools" "$WORK/tests" "$WORK/config" "$WORK/ops"
BASE=https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/main
for file in tools/catalogue_publish.py tools/catalogue_harvester.py \
  tests/test_catalogue_publish.py tests/test_catalogue_harvester.py \
  config/catalogue_harvester.json ops/aurenoeva-catalogue.service; do
  curl -fsSL --retry 3 --connect-timeout 10 --max-time 60 \
     "$BASE/$file" -o "$WORK/$file"
done
python3 -m py_compile "$WORK/tools/catalogue_publish.py" "$WORK/tools/catalogue_harvester.py"
(cd "$WORK" && python3 -m unittest discover -s tests -p 'test_catalogue_*.py' -v)
echo "=== WEBSITE RECEIVER PRE-FLIGHT ==="
# Check token-authenticated NEW vendor-batch receiver before changing working VPS.
python3 - "$STATE/catalogue-sync-token" <<'PY'
import json,sys,urllib.request
token=open(sys.argv[1],encoding="ascii").read().strip()
body=json.dumps({"schema":"aurenoeva-vendor-evidence/1",
                 "vendor_leads":[],"vendor_products":[]}).encode()
req=urllib.request.Request(
  "https://aurenoeva.com/catalogue-sync.php?mode=vendors",
  method="POST",data=body,
  headers={"Content-Type":"application/json","X-Aurenoeva-Sync-Token":token})
try:
    with urllib.request.urlopen(req,timeout=30) as rsp:
        result=json.loads(rsp.read())
except Exception as e:
    raise SystemExit("WEBSITE_NEW_RECEIVER_NOT_READY: "+type(e).__name__+": "+str(e))
if result.get("status")!="MERGED" or result.get("ok") is not True:
    raise SystemExit("WEBSITE_NEW_RECEIVER_NOT_READY: "+str(result))
print("WEBSITE_BATCH_RECEIVER=PASS")
PY
NOW=$(date -u +%Y%m%dT%H%M%SZ)
echo "=== INSTALL UPDATED PUBLISHER WITHOUT RESTARTING CRAWLER ==="
for file in tools/catalogue_publish.py tools/catalogue_harvester.py config/catalogue_harvester.json;do
  if [[ -f "$ROOT/$file" ]];then
      cp -a "$ROOT/$file" "$ROOT/$file.pre-batch-$NOW"
  fi
  install -m 0644 "$WORK/$file" "$ROOT/$file"
done
UNIT=/etc/systemd/system/aurenoeva-catalogue.service
cp -a "$UNIT" "$UNIT.pre-batch-$NOW"
install -m 0644 "$WORK/ops/aurenoeva-catalogue.service" "$UNIT"
mkdir -p "$UNIT.d"
printf '[Service]\nTimeoutStartSec=3300s\n' > "$UNIT.d/zz-harvester-v4.conf"
systemctl daemon-reload
systemctl enable --now aurenoeva-catalogue.timer
echo "=== PRESERVE ONE CONSISTENT PRIVATE EXPORT ==="
SNAP="$STATE/catalogue-repair-$NOW.json"
cp "$STATE/catalogue-public.json" "$SNAP"
chown aurenoeva:aurenoeva "$SNAP"
chmod 0600 "$SNAP"
LOG="$STATE/catalogue-repair-$NOW.log"
touch "$LOG"
chown aurenoeva:aurenoeva "$LOG"
chmod 0600 "$LOG"
trap 'rm -rf "$WORK"; rm -f "$SNAP"' EXIT

echo "=== STAGE 1: RESTORE ALL WEBSITE PRODUCTS (SMALL RETRYABLE BATCHES) ==="
# Site vendor processing is deliberately separated. A large RFQ master must
# not prevent website product search from returning all acquired identities.
if ! runuser -u aurenoeva -- /usr/bin/python3 "$ROOT/tools/catalogue_publish.py" \
  --snapshot "$SNAP" --state-dir "$STATE" --products-only >"$LOG" 2>&1; then
  tail -n 28 "$LOG"
  echo "PRODUCT_PUBLICATION_NOT_YET_COMPLETE. The source SQLite and export are intact."
  exit 1
fi
tail -n 4 "$LOG"
grep -q '"status": "PRODUCTS_PUBLISHED"' "$LOG" ||
  { echo "PRODUCT_PUBLICATION_NOT_CONFIRMED"; exit 1; }

echo "=== STAGE 2: VERIFY REAL HOSTINGER WEBSITE SEARCH ==="
python3 - "$SNAP" <<'PY'
import json,sys,urllib.parse,urllib.request
data=json.load(open(sys.argv[1],encoding="utf-8"))
items=data.get("items",[])
print("PRIVATE_EXPORTED_PRODUCTS=",len(items))
if len(items)<100:raise SystemExit("CATALOGUE_UNEXPECTEDLY_SMALL")
# Check a record near the end, not one of the previous 1,813 products.
search_candidates=[x for x in items[int(len(items)*0.8):]
                   if x.get("model") and len(x["model"])>=4
                   and any(c.isdigit() for c in x["model"])]
if not search_candidates:raise SystemExit("NO_MODEL_TO_VERIFY")
model=search_candidates[0]["model"]
url="https://aurenoeva.com/catalogue-search.php?"+urllib.parse.urlencode({"q":model})
with urllib.request.urlopen(url,timeout=45) as r:
    public=json.loads(r.read(200000))
if public.get("source")!="AURENOEVA_OWNED_CATALOGUE":
    raise SystemExit("LIVE_SITE_NOT_USING_OWNED_CATALOGUE")
if not any(x.get("model")==model for x in public.get("items",[])):
    raise SystemExit("LIVE_SEARCH_READBACK_FAILED_FOR="+model)
print("AURENOEVA_CATALOGUE_SEARCH=PASS model="+model)
PY
echo "=== STAGE 3: ENRICH EXISTING RFQ VENDOR MASTER ==="
# Unlike product search, vendor sync can retry on the next scheduled pass.
# Record partial completion clearly rather than calling it a successful merge.
if runuser -u aurenoeva -- /usr/bin/python3 "$ROOT/tools/catalogue_publish.py" \
  --snapshot "$SNAP" --state-dir "$STATE" --vendors-only >>"$LOG" 2>&1; then
  tail -n 5 "$LOG"
  echo "AURENOEVA_VENDOR_EVIDENCE=COMPLETE"
else
  tail -n 22 "$LOG"
  echo "AURENOEVA_VENDOR_EVIDENCE=RETRY_PENDING"
  echo "Website product catalogue remains published; the 30-minute timer remains enabled."
fi
echo "AURENOEVA_CATALOGUE_REPUBLISHED_AND_SEARCHED=PASS"
echo "Full transfer diagnostics: $LOG"
