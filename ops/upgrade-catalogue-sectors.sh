#!/usr/bin/env bash
# Upgrade ONE existing AURENOEVA crawler in place; never replace its SQLite history.
set -euo pipefail
[[ $(id -u) -eq 0 ]] || { echo "Run as root on the existing Hostinger VPS"; exit 1; }
ROOT=/opt/aurenoeva
DB=/var/lib/aurenoeva/catalogue.sqlite3
SERVICE=aurenoeva-catalogue.service
TIMER=aurenoeva-catalogue.timer
[[ -f "$DB" && -f "$ROOT/tools/catalogue_worker.py" &&
   -f "$ROOT/config/catalogue_sources.json" ]] || {
  echo "EXISTING CATALOGUE NOT FOUND: no new database was created"; exit 1;
}
id -u aurenoeva >/dev/null
if systemctl is-active --quiet "$SERVICE"; then
  echo "Crawler is still executing. Its current database is untouched."
  exit 1
fi
TMP=$(mktemp -d)
TIMER_RESTORE=0
cleanup() {
  if [[ "$TIMER_RESTORE" = 1 ]]; then systemctl start "$TIMER" || true; fi
  rm -rf "$TMP"
}
trap cleanup EXIT
systemctl stop "$TIMER" || true
TIMER_RESTORE=1
mkdir -p "$TMP/tools" "$TMP/config" "$TMP/tests"
# Tested source revision; not a moving main-branch installer.
BASE=https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/589de25b86911f15418dc7ff954c845fe2f8fa9f
for file in tools/catalogue_worker.py tools/catalogue_publish.py config/catalogue_sources.json tests/test_catalogue_worker.py tests/test_catalogue_publish.py tests/test_catalogue_continuity.py; do
  curl -fsSL --retry 2 --connect-timeout 12 --max-time 45 "$BASE/$file" -o "$TMP/$file"
done
python3 -m py_compile "$TMP/tools/catalogue_worker.py" "$TMP/tools/catalogue_publish.py"
python3 -m json.tool "$TMP/config/catalogue_sources.json" >/dev/null
(cd "$TMP" && python3 -m unittest discover -s tests -p 'test_catalogue_*.py' -v)
echo "=== DATABASE BEFORE UPGRADE ==="
python3 - "$DB" <<'PY'
import sqlite3,sys
c=sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True)
print("Permanent product records:",c.execute("SELECT COUNT(*) FROM products").fetchone()[0])
for maker,n in c.execute("SELECT manufacturer,COUNT(*) FROM products GROUP BY manufacturer ORDER BY 2 DESC"):
    print(n,"|",maker)
print("Saved crawler runs:",c.execute("SELECT COUNT(*) FROM runs").fetchone()[0])
PY
BACKUP="$DB.pre-sector-upgrade-$(date -u +%Y%m%d%H%M%S)"
python3 - "$DB" "$BACKUP" <<'PY'
import sqlite3,sys
with sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True) as src:
    with sqlite3.connect(sys.argv[2]) as dest:
        src.backup(dest)
print("Consistent SQLite backup:",sys.argv[2])
PY
stamp=$(date -u +%Y%m%d%H%M%S)
for file in tools/catalogue_worker.py tools/catalogue_publish.py config/catalogue_sources.json; do
  cp -a "$ROOT/$file" "$ROOT/$file.pre-sector-$stamp"
  install -m 644 "$TMP/$file" "$ROOT/$file"
done
echo "=== UPDATED SOURCES / ORIGINAL DATABASE ==="
# Keep same existing service and timer. The original ExecStartPost publisher remains in place.
systemctl start "$SERVICE"
echo "=== DATABASE AFTER FIRST CRAWL ==="
python3 - "$DB" <<'PY'
import sqlite3,sys
from collections import Counter
c=sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True)
print("All-time product identities:",c.execute("SELECT COUNT(*) FROM products").fetchone()[0])
print("Sector totals:")
for row in c.execute("SELECT COALESCE(NULLIF(sector,''),'unclassified'),COUNT(*) FROM products GROUP BY 1 ORDER BY 1"):
    print(*row,sep=" | ")
print("Latest crawl:")
for row in c.execute("SELECT started_at,processed,succeeded,failed,new_products,state FROM runs ORDER BY id DESC LIMIT 1"):
    print(*row,sep=" | ")
print("Source failures:")
for row in c.execute("SELECT last_error,COUNT(*) FROM tasks WHERE last_error IS NOT NULL GROUP BY last_error ORDER BY 2 DESC LIMIT 8"):
    print(*row,sep=" | ")
PY
echo "=== CATALOGUE DELIVERY ==="
journalctl -u "$SERVICE" -n 15 --no-pager || true
echo "=== PERSISTENCE ASSERTION ==="
python3 - "$DB" "$BACKUP" <<'PY'
import sqlite3,sys
with sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True) as current:
    with sqlite3.connect('file:'+sys.argv[2]+'?mode=ro',uri=True) as prior:
        before=prior.execute('SELECT COUNT(*) FROM products').fetchone()[0]
        after=current.execute('SELECT COUNT(*) FROM products').fetchone()[0]
        prior_ids={r[0] for r in prior.execute('SELECT product_id FROM products')}
        current_ids={r[0] for r in current.execute('SELECT product_id FROM products')}
        lost=prior_ids-current_ids
        if lost: raise SystemExit('FAIL: missing historical product identities after upgrade')
        print('HISTORICAL_PRODUCTS_PRESERVED=PASS', 'before=',before,'after=',after)
PY
echo "=== LIVE WEBSITE SEARCH READBACK ==="
python3 - /var/lib/aurenoeva/catalogue-public.json <<'PY'
import json,sys
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import urlopen
p=Path(sys.argv[1])
if not p.is_file(): raise SystemExit('BLOCKED: no crawler public snapshot available')
items=json.loads(p.read_text()).get('items',[])
if not items: raise SystemExit('BLOCKED: no searchable products in local snapshot')
item=items[0]
url='https://aurenoeva.com/catalogue-search.php?'+urlencode({'q':item['model']})
with urlopen(url,timeout=25) as response:
    result=json.load(response)
if result.get('source')!='AURENOEVA_OWNED_CATALOGUE' or not any(
    x.get('canonical_id')==item.get('canonical_id') for x in result.get('items',[])
):
    raise SystemExit('BLOCKED: live public site has not indexed the expected product')
print('LIVE_WEBSITE_CATALOGUE_SEARCH=PASS',item['manufacturer'],item['model'])
PY
echo "=== EXISTING HISTORICAL CATALOGUE BACKUPS ==="
find /var/lib/aurenoeva -maxdepth 1 -type f -name 'catalogue.sqlite3*' -printf '%f %s bytes\n' | sort
echo "UPGRADE_AND_LIVE_READBACK=PASS — same original SQLite, service, and timer retained."
