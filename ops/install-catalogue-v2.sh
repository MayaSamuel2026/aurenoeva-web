#!/usr/bin/env bash
# Upgrade existing AURENOEVA catalogue to broad-sitemap discovery; keep prior SQLite.
set -euo pipefail
if [[ "$(id -u)" -ne 0 ]]; then echo "Run as root (sudo bash)."; exit 1; fi
ROOT=/opt/aurenoeva
DB=/var/lib/aurenoeva/catalogue.sqlite3
UNIT=/etc/systemd/system/aurenoeva-catalogue.service
[[ -f "$ROOT/tools/catalogue_worker.py" && -f "$ROOT/config/catalogue_sources.json" && -f "$UNIT" ]] ||
  { echo "Existing crawler installation not found; aborting without changes."; exit 1; }
echo "=== LIVE CATALOGUE BEFORE UPGRADE ==="
python3 - "$DB" <<'PY'
import sqlite3,sys,os
if os.path.isfile(sys.argv[1]):
    with sqlite3.connect(sys.argv[1]) as db:
        print("Actual SQLite product records:",db.execute("select count(*) from products").fetchone()[0])
else:
    print("Existing catalogue database absent")
PY
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/tools" "$WORK/tests" "$WORK/config" "$WORK/ops"
BASE=https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/566800a221eef20d118b7aaebba85cf232fe86fa
for file in tools/catalogue_worker.py tools/catalogue_publish.py tests/test_catalogue_worker.py tests/test_catalogue_publish.py config/catalogue_sources.json ops/aurenoeva-catalogue.service; do
  curl -fsSL --retry 2 --connect-timeout 10 --max-time 45 "$BASE/$file" -o "$WORK/$file"
done
python3 -m py_compile "$WORK/tools/catalogue_worker.py"
python3 -m json.tool "$WORK/config/catalogue_sources.json" >/dev/null
(cd "$WORK" && python3 -m unittest discover -s tests -p 'test_catalogue_*.py' -v)
# Keep original service/database and a rollback copy of each modified file.
BACKUP=".pre-v3-$(date -u +%Y%m%d%H%M%S)"
cp -a "$ROOT/tools/catalogue_worker.py" "$ROOT/tools/catalogue_worker.py$BACKUP"
cp -a "$ROOT/config/catalogue_sources.json" "$ROOT/config/catalogue_sources.json$BACKUP"
cp -a "$UNIT" "$UNIT$BACKUP"
python3 - "$DB" "$DB$BACKUP" <<'PY'
import sqlite3,sys
source=sqlite3.connect(sys.argv[1])
with sqlite3.connect(sys.argv[2]) as backup:
    source.backup(backup)
source.close()
PY
install -m 644 "$WORK/tools/catalogue_worker.py" "$ROOT/tools/catalogue_worker.py"
install -m 644 "$WORK/tools/catalogue_publish.py" "$ROOT/tools/catalogue_publish.py"
install -m 644 "$WORK/config/catalogue_sources.json" "$ROOT/config/catalogue_sources.json"
install -m 644 "$WORK/ops/aurenoeva-catalogue.service" "$UNIT"
mkdir -p /etc/systemd/system/aurenoeva-catalogue.service.d
printf '[Service]\nTimeoutStartSec=2100s\n' > /etc/systemd/system/aurenoeva-catalogue.service.d/zz-catalogue-v3.conf
systemctl daemon-reload
systemctl enable --now aurenoeva-catalogue.timer
if ! systemctl start aurenoeva-catalogue.service; then
  echo "WARNING: crawler service reported failure; diagnostics follow."
fi
echo "=== LAST RUN ==="
journalctl -u aurenoeva-catalogue.service -n 15 --no-pager
echo "=== DATABASE AFTER RUN ==="
python3 - "$DB" <<'PY'
import sqlite3, sys
db = sqlite3.connect(sys.argv[1])
print("Products:", db.execute("SELECT count(*) FROM products").fetchone()[0])
print("Queued source URLs:", db.execute("SELECT count(*) FROM tasks").fetchone()[0])
import json
from pathlib import Path
snapshot=Path("/var/lib/aurenoeva/catalogue-public.json")
if snapshot.is_file():
    try:
        print("Exported searchable catalogue records:",len(json.loads(snapshot.read_text()).get("items",[])))
    except Exception as exc:
        print("Snapshot unreadable:",type(exc).__name__)
else:
    print("Exported searchable catalogue records: NO FILE")
print("Last run:", db.execute("SELECT started_at,processed,succeeded,failed,new_products,state FROM runs ORDER BY id DESC LIMIT 1").fetchone())
if db.execute("SELECT count(*) FROM products").fetchone()[0] == 0:
    print("ALERT: CATALOGUE STILL EMPTY. CHECK SOURCE ERRORS BELOW.")
print("By manufacturer:")
for row in db.execute("SELECT manufacturer,count(*) FROM products GROUP BY manufacturer ORDER BY 2 DESC"):
    print(" ",row[0],row[1])
print("Most common source errors:")
for row in db.execute("SELECT last_error,count(*) FROM tasks WHERE last_error IS NOT NULL GROUP BY last_error ORDER BY 2 DESC LIMIT 8"):
    print(" ",row[1],row[0])
for row in db.execute("SELECT manufacturer,model FROM products ORDER BY manufacturer,model LIMIT 30"):
    print(" | ".join(row))
PY
echo "Catalogue V3 installed and run completed. Website private snapshot sync must be verified separately."
